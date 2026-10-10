<?php
/**
 * Flexicorp TEITOK overlay: quantitative / stats UI (frequency, future collocations, keyness, …).
 * Loaded via require_once from flexicorp.php (same directory).
 */

	if ( ! function_exists( 'tt_flexicorp_teitok_unwrap_pando_freq_envelope' ) ) {
		/**
		 * Unwrap run_program_json shape { operation: "freq", result: { rows, … } } to the inner
		 * result. FQS does this before TEITOK sees JSON; local flexicorp-pando may still return
		 * the wrapped form — promotion + Stats need the flat shape.
		 */
		function tt_flexicorp_teitok_unwrap_pando_freq_envelope( &$result ) {
			if ( ! is_array( $result ) || ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
				return;
			}
			$opTag = isset( $result['operation'] ) ? strtolower( (string) $result['operation'] ) : '';
			$inner = $result['result'];
			$innerIsTable = isset( $inner['rows'] ) || isset( $inner['compare_queries'] ) || isset( $inner['totals_per_query'] );
			if ( $opTag === 'freq' || $innerIsTable ) {
				// Preserve the outer operation tag (count/dist/dcoll/keyness/freq/coll) on the
				// flattened inner so downstream introspection knows what actually ran. Without
				// this every aggregation looked like "freq" once unwrapped.
				if ( $opTag !== '' && ! isset( $inner['operation'] ) ) {
					$inner['operation'] = $opTag;
				}
				$result = $inner;
			}
		}
	}

	if ( ! function_exists( 'tt_flexicorp_done_errors_non_empty' ) ) {
		/**
		 * Only non-empty error strings count as failures. Some stacks enqueue empty placeholders;
		 * those must not block promote-to-Stats for combined query+frequency scripts.
		 */
		function tt_flexicorp_done_errors_non_empty( $done ) {
			if ( ! is_array( $done ) || ! isset( $done['errors'] ) || ! is_array( $done['errors'] ) ) {
				return array();
			}
			$out = array();
			foreach ( $done['errors'] as $e ) {
				if ( is_string( $e ) && trim( $e ) !== '' ) {
					$out[] = $e;
				} elseif ( ! is_string( $e ) && $e !== null && $e !== false && $e !== '' ) {
					$out[] = $e;
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_freq_rows') ) {
		function tt_flexicorp_freq_rows($result) {
			if ( !is_array($result) ) return array();
			// Prefer nested table rows (many engines put pct/ipm there); then items / rows.
			if ( isset($result['table']) && is_array($result['table']) && isset($result['table']['rows']) && is_array($result['table']['rows']) && count($result['table']['rows']) > 0 ) {
				$rows = $result['table']['rows'];
			} else if ( isset($result['items']) && is_array($result['items']) && count($result['items']) > 0 ) {
				$rows = $result['items'];
			} else if ( isset($result['rows']) && is_array($result['rows']) && count($result['rows']) > 0 ) {
				$rows = $result['rows'];
			} else {
				$rows = $result;
			}
			if ( !is_array($rows) ) return array();
			$out = array();
			foreach ( array_values($rows) as $row ) {
				if ( !is_array($row) ) continue;
				// Guard against backend payload structures (e.g. groups/hits arrays)
				// being misread as frequency rows.
				$hasCountLike = isset($row['count']) || isset($row['freq']) || isset($row['n']);
				$hasValueLike = isset($row['value']) || isset($row['label']) || isset($row['key']);
				// Pando multi-query freq: counts live under row.queries[Q].count (no top-level count).
				if ( !$hasCountLike && !$hasValueLike && isset( $row['queries'] ) && is_array( $row['queries'] ) ) {
					foreach ( $row['queries'] as $qq ) {
						if ( is_array( $qq ) && ( isset( $qq['count'] ) || isset( $qq['freq'] ) || isset( $qq['n'] ) ) ) {
							$hasCountLike = true;
							break;
						}
					}
				}
				if ( !$hasCountLike && !$hasValueLike ) continue;
				// Normalize common key names across backends.
				$val = isset($row['value']) ? trim((string)$row['value']) : '';
				if ( $val === '' ) {
					if ( isset($row['label']) && trim((string)$row['label']) !== '' ) $row['value'] = (string)$row['label'];
					else if ( isset($row['key']) && trim((string)$row['key']) !== '' ) $row['value'] = (string)$row['key'];
				}
				// CQP-style totals row may be prefixed as "(all) ...".
				if ( isset($row['value']) && is_scalar($row['value']) ) {
					$row['value'] = preg_replace('/^\(all\)\s*/i', '', (string)$row['value']);
				}
				$out[] = $row;
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_freq_total') ) {
		/**
		 * Grand total for the frequency distribution (denominator for %, “Others”, pie slice).
		 * Backends use different keys; scan common names and nested table metadata.
		 */
		function tt_flexicorp_freq_total( $result ) {
			if ( !is_array($result) ) {
				return null;
			}
			$keys = array(
				'total',
				'total_matches',
				'total_count',
				'n_total',
				'match_total',
				'hits_total',
				'total_tokens',
				'token_total',
				'freq_total',
				'sum',
				'sum_count',
			);
			foreach ( $keys as $k ) {
				if ( isset($result[$k]) && is_numeric($result[$k]) ) {
					$v = (int) $result[$k];
					if ( $v > 0 ) {
						return $v;
					}
				}
			}
			if ( isset($result['table']) && is_array($result['table']) ) {
				$t = $result['table'];
				foreach ( $keys as $k ) {
					if ( isset($t[$k]) && is_numeric($t[$k]) ) {
						$v = (int) $t[$k];
						if ( $v > 0 ) {
							return $v;
						}
					}
				}
			}
			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_freq_returned') ) {
		function tt_flexicorp_freq_returned( $result ) {
			if ( !is_array($result) ) return null;
			if ( isset($result['returned']) && is_numeric($result['returned']) ) return (int) $result['returned'];
			$rows = tt_flexicorp_freq_rows($result);
			return count($rows);
		}
	}

	if ( !function_exists('tt_flexicorp_freq_result_augment_metrics') ) {
		/**
		 * Pando (and other) freq payloads often omit pct/ipm per row; the Stats UI only shows those
		 * columns when sample rows carry these keys (see flexicorp_freqs.js; templates use $data.* for x-for scope).
		 * Derive pct from total hit count and ipm from corpus token count when possible.
		 *
		 * @param array $result Frequency result object (mutated in place).
		 */
		function tt_flexicorp_freq_result_augment_metrics( &$result, $projectRoot ) {
			if ( !is_array($result) ) {
				return;
			}
			$rowsRef = null;
			if ( isset($result['table']['rows']) && is_array($result['table']['rows']) && count($result['table']['rows']) > 0 ) {
				$rowsRef = &$result['table']['rows'];
			} elseif ( isset($result['items']) && is_array($result['items']) && count($result['items']) > 0 ) {
				$rowsRef = &$result['items'];
			} elseif ( isset($result['rows']) && is_array($result['rows']) && count($result['rows']) > 0 ) {
				$rowsRef = &$result['rows'];
			}
			if ( $rowsRef === null ) {
				return;
			}

			$total = null;
			if ( isset($result['total']) && is_numeric($result['total']) ) {
				$total = (float) $result['total'];
			}
			if ( isset($result['total_matches']) && is_numeric($result['total_matches']) && ( $total === null || $total <= 0 ) ) {
				$total = (float) $result['total_matches'];
			}
			if ( $total === null || $total <= 0 ) {
				$sum = 0.0;
				foreach ( $rowsRef as $r ) {
					if ( !is_array($r) ) {
						continue;
					}
					$c = isset($r['count']) ? (float) $r['count'] : ( isset($r['freq']) ? (float) $r['freq'] : ( isset($r['n']) ? (float) $r['n'] : 0.0 ) );
					if ( $c > 0 ) {
						$sum += $c;
					}
				}
				if ( $sum > 0 ) {
					$total = $sum;
				}
			}
			if ( $total === null || $total <= 0 ) {
				return;
			}

			$ct = null;
			if ( isset($result['corpus_tokens']) && is_numeric($result['corpus_tokens']) ) {
				$ct = (float) $result['corpus_tokens'];
			}
			foreach ( array( 'tokens_count', 'corpus_size', 'n_tokens', 'tokens', 'size' ) as $ck ) {
				if ( ( $ct === null || $ct <= 0 ) && isset( $result[ $ck ] ) && is_numeric( $result[ $ck ] ) ) {
					$ct = (float) $result[ $ck ];
					break;
				}
			}
			if ( ( $ct === null || $ct <= 0 ) && isset( $result['info'] ) && is_array( $result['info'] ) ) {
				$info = $result['info'];
				foreach ( array( 'corpus_tokens', 'tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens' ) as $ck ) {
					if ( isset( $info[ $ck ] ) && is_numeric( $info[ $ck ] ) ) {
						$ct = (float) $info[ $ck ];
						break;
					}
				}
			}
			if ( ( $ct === null || $ct <= 0 ) && function_exists('tt_flexicorp_corpus_tokens_for_ipm') ) {
				$ct = tt_flexicorp_corpus_tokens_for_ipm( $projectRoot );
			}
			if ( $ct !== null && $ct > 0 && !isset($result['corpus_tokens']) ) {
				$result['corpus_tokens'] = (int) round( $ct );
			}

			foreach ( $rowsRef as $idx => $r ) {
				if ( !is_array($r) ) {
					continue;
				}
				$count = isset($r['count']) ? (float) $r['count'] : ( isset($r['freq']) ? (float) $r['freq'] : ( isset($r['n']) ? (float) $r['n'] : 0.0 ) );
				$hasPct = isset($r['pct']) || isset($r['percent']) || isset($r['percentage']);
				if ( !$hasPct && $total > 0 && $count >= 0 ) {
					$rowsRef[$idx]['pct'] = ( $count / $total ) * 100.0;
				}
				$hasIpm = isset($r['ipm']) || isset($r['IPM']);
				if ( !$hasIpm && $ct !== null && $ct > 0 && $count >= 0 ) {
					$rowsRef[$idx]['ipm'] = ( $count / $ct ) * 1000000.0;
				}
			}
		}
	}

	if ( !function_exists('tt_flexicorp_teitok_query_ends_with_freq_clause') ) {
		/**
		 * True when the last semicolon-separated statement is a Pando-style "freq by <field>" clause.
		 * Used to route combined query+frequency scripts to the Stats tab instead of the Search table view.
		 */
		function tt_flexicorp_teitok_query_ends_with_freq_clause( $query ) {
			$q = trim( (string) $query );
			if ( $q === '' ) {
				return false;
			}
			$parts = preg_split( '/\s*;\s*/', $q, -1, PREG_SPLIT_NO_EMPTY );
			if ( !is_array( $parts ) || !count( $parts ) ) {
				return false;
			}
			$last = trim( (string) end( $parts ) );
			return $last !== '' && preg_match( '/^(?:freq|count|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i', $last );
		}
	}

	if ( !function_exists('tt_flexicorp_teitok_parse_freq_field_from_query' ) ) {
		/**
		 * Extract the attribute name(s) from the trailing "freq by <fields>" clause (everything after "by").
		 */
		function tt_flexicorp_teitok_parse_freq_field_from_query( $query ) {
			$q = trim( (string) $query );
			if ( $q === '' ) {
				return '';
			}
			$parts = preg_split( '/\s*;\s*/', $q, -1, PREG_SPLIT_NO_EMPTY );
			if ( !is_array( $parts ) || !count( $parts ) ) {
				return '';
			}
			$last = trim( (string) end( $parts ) );
			if ( $last === '' || !preg_match( '/^(?:freq|count|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+(.+)$/i', $last, $m ) ) {
				return '';
			}
			$tok = trim( (string) $m[1], " \t\"'`" );
			return $tok;
		}
	}

	if ( !function_exists('tt_flexicorp_teitok_parse_freq_query_names_from_query' ) ) {
		/**
		 * Extract the optional query name(s) from a "freq Q1, Q2 by <fields>" clause.
		 */
		function tt_flexicorp_teitok_parse_freq_query_names_from_query( $query ) {
			$q = trim( (string) $query );
			if ( $q === '' ) return '';
			$parts = preg_split( '/\s*;\s*/', $q, -1, PREG_SPLIT_NO_EMPTY );
			if ( !is_array( $parts ) || !count( $parts ) ) return '';
			$last = trim( (string) end( $parts ) );
			if ( $last === '' || !preg_match( '/^(?:freq|count|group)(?:\s+([A-Za-z0-9_,\s]+))?\s+(?:by|match)\s+/i', $last, $m ) ) {
				return '';
			}
			if ( isset($m[1]) ) {
				$names = trim((string)$m[1]);
				if ( $names !== '' ) return $names;
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_teitok_sanitize_query_aggregations' ) ) {
		/**
		 * Removes trailing aggregation commands (freq by, group by, coll, tabulate, sort, count, show, size) from a query.
		 * Prevents visitors from running arbitrary expensive aggregations directly, and
		 * prevents conflicting clauses when the GUI appends its own 'freq by'.
		 */
		function tt_flexicorp_teitok_sanitize_query_aggregations( $query ) {
			$q = trim( (string) $query );
			if ( $q === '' ) {
				return '';
			}
			$parts = preg_split( '/\s*;\s*/', $q, -1, PREG_SPLIT_NO_EMPTY );
			if ( !is_array( $parts ) || !count( $parts ) ) {
				return '';
			}
			$filtered = array();
			foreach ( $parts as $p ) {
				$p = trim($p);
				if ( $p === '' ) continue;
				// Match common CQP / Pando aggregations/interactive commands
				if ( preg_match( '/^(freq|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i', $p ) ) continue;
				if ( preg_match( '/^coll\s+/i', $p ) ) continue;
				if ( preg_match( '/^tabulate\s+/i', $p ) ) continue;
				if ( preg_match( '/^sort\s+/i', $p ) ) continue;
				if ( preg_match( '/^count\b/i', $p ) ) continue;
				if ( preg_match( '/^show\s+/i', $p ) ) continue;
				if ( preg_match( '/^size\b/i', $p ) ) continue;
				$filtered[] = $p;
			}
			return implode('; ', $filtered);
		}
	}

	if ( !function_exists( 'tt_flexicorp_teitok_query_is_aa_statement' ) ) {
		/**
		 * True for a single semicolon-separated statement that is aggregation/association (AA),
		 * aligned with tt_flexicorp_teitok_sanitize_query_aggregations.
		 */
		function tt_flexicorp_teitok_query_is_aa_statement( $part ) {
			$p = trim( (string) $part );
			if ( $p === '' ) {
				return false;
			}
			if ( preg_match( '/^(freq|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i', $p ) ) {
				return true;
			}
			if ( preg_match( '/^coll\s+/i', $p ) ) {
				return true;
			}
			if ( preg_match( '/^tabulate\s+/i', $p ) ) {
				return true;
			}
			if ( preg_match( '/^sort\s+/i', $p ) ) {
				return true;
			}
			if ( preg_match( '/^count\b/i', $p ) ) {
				return true;
			}
			if ( preg_match( '/^show\s+/i', $p ) ) {
				return true;
			}
			if ( preg_match( '/^size\b/i', $p ) ) {
				return true;
			}
			return false;
		}
	}

	if ( !function_exists( 'tt_flexicorp_teitok_query_aa_statements' ) ) {
		/**
		 * Returns aggregation/association statements in order (CLI may run each as a separate step).
		 */
		function tt_flexicorp_teitok_query_aa_statements( $query ) {
			$q = trim( (string) $query );
			if ( $q === '' ) {
				return array();
			}
			$parts = preg_split( '/\s*;\s*/', $q, -1, PREG_SPLIT_NO_EMPTY );
			$out   = array();
			if ( ! is_array( $parts ) ) {
				return $out;
			}
			foreach ( $parts as $p ) {
				$p = trim( (string) $p );
				if ( $p === '' ) {
					continue;
				}
				if ( tt_flexicorp_teitok_query_is_aa_statement( $p ) ) {
					$out[] = $p;
				}
			}
			return $out;
		}
	}

	if ( !function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
		/**
		 * Pando CLI can emit multiple JSON blobs for chained AA steps; the web UI consumes one result.
		 * When there are 2+ AA statements, run only: (non-AA base) + (last AA statement).
		 * Single AA or no AA: return the query unchanged (keeps promote-to-Stats scripts intact).
		 */
		function tt_flexicorp_teitok_query_effective_pando_search_query( $query ) {
			$q = trim( (string) $query );
			if ( $q === '' ) {
				return '';
			}
			$aa = tt_flexicorp_teitok_query_aa_statements( $query );
			// Plain token query with only a trailing semicolon should run as a search query,
			// not as a program script; keep AA scripts unchanged.
			if ( count( $aa ) === 0 ) {
				return rtrim( $q, " \t\n\r\0\x0B;" );
			}
			if ( count( $aa ) === 1 ) {
				return $q;
			}
			$base = tt_flexicorp_teitok_sanitize_query_aggregations( $query );
			$last = $aa[ count( $aa ) - 1 ];
			$base = trim( (string) $base );
			if ( $base === '' ) {
				return $last;
			}
			return $base . '; ' . $last;
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_meta_label') ) {
		function tt_flexicorp_cfg_meta_label( $node, $fallback ) {
			if ( function_exists( 'tt_flexicorp_fn_cfg_meta_label' ) ) {
				return tt_flexicorp_fn_cfg_meta_label( $node, $fallback );
			}
			$label = '';
			if ( is_array($node) ) {
				foreach ( array('@display', 'display', '@label', 'label', '@name', 'name') as $k ) {
					if ( isset($node[$k]) && is_scalar($node[$k]) ) {
						$label = trim((string)$node[$k]);
						if ( $label !== '' ) break;
					}
				}
			}
			if ( $label === '' ) $label = (string)$fallback;
			return $label;
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_has_display_title' ) ) {
		/**
		 * True when the attribute node has an explicit @display or display title (not label/name only).
		 * Used to filter the frequency-distribution field list to human-titled options only.
		 */
		function tt_flexicorp_cfg_has_display_title( $node ) {
			if ( function_exists( 'tt_flexicorp_fn_cfg_has_display_title' ) ) {
				return tt_flexicorp_fn_cfg_has_display_title( $node );
			}
			if ( ! is_array( $node ) ) {
				return false;
			}
			foreach ( array( '@display', 'display' ) as $k ) {
				if ( isset( $node[ $k ] ) && is_scalar( $node[ $k ] ) && trim( (string) $node[ $k ] ) !== '' ) {
					return true;
				}
			}
			return false;
		}
	}

	if ( !function_exists('tt_flexicorp_freq_prefix_sattr_display_enabled' ) ) {
		/**
		 * Whether s-attribute labels should be prefixed with the region display label
		 * (e.g. "Document Search: Document type").
		 *
		 * Default: disabled (use attribute display only).
		 * Enable by setting one of:
		 * - getset('flexicorp/freq_prefix_sattr_display')
		 * - getset('flexicorp/prefix_sattr_display')
		 */
		function tt_flexicorp_freq_prefix_sattr_display_enabled() {
			$raw = null;
			if ( function_exists( 'getset' ) ) {
				$raw = getset( 'flexicorp/freq_prefix_sattr_display', null );
				if ( $raw === null || $raw === '' ) {
					$raw = getset( 'flexicorp/prefix_sattr_display', null );
				}
			}
			return tt_flexicorp_cfg_truthy( $raw );
		}
	}

	if ( !function_exists('tt_flexicorp_freq_field_label_map_display_only' ) ) {
		/**
		 * [ fieldName => label ] for cqp/pattributes and cqp/sattributes entries that define @display or display.
		 *
		 * @return array<string, string>
		 */
		function tt_flexicorp_freq_field_label_map_display_only() {
			if ( function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
				$cat = tt_flexicorp_fn_attribute_catalog();
				if ( is_array( $cat ) && isset( $cat['searchable_display_labels'] ) && is_array( $cat['searchable_display_labels'] ) ) {
					return $cat['searchable_display_labels'];
				}
			}
			$out     = array();
			$pattrs  = function_exists( 'getset' ) ? getset( 'cqp/pattributes', array() ) : array();
			if ( is_array( $pattrs ) ) {
				foreach ( $pattrs as $k => $v ) {
					if ( ! is_string( $k ) || ! tt_flexicorp_cfg_is_attr_key( $k ) || ! is_array( $v ) ) {
						continue;
					}
					if ( ! tt_flexicorp_cfg_has_display_title( $v ) || tt_flexicorp_cfg_is_nosearch( $v ) ) {
						continue;
					}
					$out[ $k ] = tt_flexicorp_cfg_meta_label( $v, $k );
				}
			}
			$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
			if ( is_array( $sattrs ) ) {
				foreach ( $sattrs as $regionKey => $regionNode ) {
					if ( ! is_string( $regionKey ) || ! tt_flexicorp_cfg_is_attr_key( $regionKey ) || ! is_array( $regionNode ) ) {
						continue;
					}
					$regionLabel = tt_flexicorp_cfg_meta_label( $regionNode, $regionKey );
					$withPrefix  = tt_flexicorp_freq_prefix_sattr_display_enabled();
					foreach ( $regionNode as $attrKey => $attrNode ) {
						if ( ! is_string( $attrKey ) || ! tt_flexicorp_cfg_is_attr_key( $attrKey ) ) {
							continue;
						}
						if ( ! is_array( $attrNode ) || ! tt_flexicorp_cfg_has_display_title( $attrNode ) || tt_flexicorp_cfg_is_nosearch( $attrNode ) ) {
							continue;
						}
						$value          = $regionKey . '_' . $attrKey;
						$attrLabel      = tt_flexicorp_cfg_meta_label( $attrNode, $attrKey );
						$out[ $value ] = $withPrefix ? ( $regionLabel . ': ' . $attrLabel ) : $attrLabel;
					}
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_is_attr_key') ) {
		function tt_flexicorp_cfg_is_attr_key( $key ) {
			if ( function_exists( 'tt_flexicorp_fn_cfg_is_attr_key' ) ) {
				return tt_flexicorp_fn_cfg_is_attr_key( $key );
			}
			$key = trim((string)$key);
			if ( $key === '' ) return false;
			if ( $key[0] === '@' ) return false;
			return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_:-]*$/', $key);
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_truthy') ) {
		function tt_flexicorp_cfg_truthy( $value ) {
			if ( function_exists( 'tt_flexicorp_fn_cfg_truthy' ) ) {
				return tt_flexicorp_fn_cfg_truthy( $value );
			}
			if ( is_bool($value) ) return $value;
			if ( is_int($value) || is_float($value) ) return ((float)$value) !== 0.0;
			$s = strtolower(trim((string)$value));
			if ( $s === '' ) return false;
			return !in_array($s, array('0', 'false', 'no', 'off', 'none', 'null'), true);
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_is_nosearch') ) {
		function tt_flexicorp_cfg_is_nosearch( $node ) {
			if ( function_exists( 'tt_flexicorp_fn_cfg_is_nosearch' ) ) {
				return tt_flexicorp_fn_cfg_is_nosearch( $node );
			}
			if ( !is_array($node) ) return false;
			if ( array_key_exists('@nosearch', $node) && tt_flexicorp_cfg_truthy($node['@nosearch']) ) return true;
			if ( array_key_exists('nosearch', $node) && tt_flexicorp_cfg_truthy($node['nosearch']) ) return true;
			return false;
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_is_noshow') ) {
		function tt_flexicorp_cfg_is_noshow( $node ) {
			if ( function_exists( 'tt_flexicorp_fn_cfg_is_noshow' ) ) {
				return tt_flexicorp_fn_cfg_is_noshow( $node );
			}
			if ( !is_array($node) ) return false;
			if ( array_key_exists('@noshow', $node) && tt_flexicorp_cfg_truthy($node['@noshow']) ) return true;
			if ( array_key_exists('noshow', $node) && tt_flexicorp_cfg_truthy($node['noshow']) ) return true;
			return false;
		}
	}

	if ( !function_exists('tt_flexicorp_noshow_fields') ) {
		/**
		 * Returns an array of keys marked as noshow in settings (pattributes and sattributes).
		 */
		function tt_flexicorp_noshow_fields() {
			if ( function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
				$cat = tt_flexicorp_fn_attribute_catalog();
				if ( is_array( $cat ) && isset( $cat['noshow_keys'] ) && is_array( $cat['noshow_keys'] ) ) {
					return array_values( array_unique( array_filter( array_map( 'strval', $cat['noshow_keys'] ) ) ) );
				}
			}
			$out = array();
			$pattrs = function_exists( 'getset' ) ? getset( 'cqp/pattributes', array() ) : array();
			if ( is_array($pattrs) ) {
				foreach ( $pattrs as $k => $v ) {
					if ( is_string($k) && tt_flexicorp_cfg_is_attr_key($k) && tt_flexicorp_cfg_is_noshow($v) ) {
						$out[] = $k;
					}
				}
			}
			$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
			if ( is_array($sattrs) ) {
				foreach ( $sattrs as $regionKey => $regionNode ) {
					if ( !is_string($regionKey) || !tt_flexicorp_cfg_is_attr_key($regionKey) || !is_array($regionNode) ) continue;
					foreach ( $regionNode as $attrKey => $attrNode ) {
						if ( !is_string($attrKey) || !tt_flexicorp_cfg_is_attr_key($attrKey) ) continue;
						if ( tt_flexicorp_cfg_is_noshow($attrNode) ) {
							$out[] = $regionKey . '_' . $attrKey;
						}
					}
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_pattr_labels') ) {
		/**
		 * Parse getset('cqp/pattributes') to [value => label].
		 */
		function tt_flexicorp_cfg_pattr_labels( $raw ) {
			if ( function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
				$cat = tt_flexicorp_fn_attribute_catalog();
				if ( is_array( $cat ) && isset( $cat['searchable_labels'] ) && is_array( $cat['searchable_labels'] ) ) {
					$out = array();
					if ( is_array( $raw ) ) {
						foreach ( $raw as $k => $_v ) {
							if ( is_string( $k ) && isset( $cat['searchable_labels'][ $k ] ) ) {
								$out[ $k ] = (string) $cat['searchable_labels'][ $k ];
							}
						}
					}
					return $out;
				}
			}
			$out = array();
			if ( is_array($raw) ) {
				foreach ( $raw as $k => $v ) {
					if ( is_string($k) && tt_flexicorp_cfg_is_attr_key($k) ) {
						if ( tt_flexicorp_cfg_is_nosearch($v) ) continue;
						$out[$k] = tt_flexicorp_cfg_meta_label($v, $k);
					} else if ( is_string($v) && tt_flexicorp_cfg_is_attr_key($v) ) {
						if ( !isset($out[$v]) ) $out[$v] = $v;
					}
				}
			} else if ( is_string($raw) ) {
				$parts = preg_split('/[\s,;]+/', $raw);
				if ( is_array($parts) ) {
					foreach ( $parts as $part ) {
						$part = trim((string)$part);
						if ( tt_flexicorp_cfg_is_attr_key($part) && !isset($out[$part]) ) $out[$part] = $part;
					}
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_cfg_sattr_labels') ) {
		/**
		 * Parse getset('cqp/sattributes') to [region_attr => "Region: AttrLabel"].
		 */
		function tt_flexicorp_cfg_sattr_labels( $raw ) {
			if ( function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
				$cat = tt_flexicorp_fn_attribute_catalog();
				if ( is_array( $cat ) && isset( $cat['searchable_labels'] ) && is_array( $cat['searchable_labels'] ) ) {
					$out = array();
					if ( is_array( $raw ) ) {
						foreach ( $raw as $regionKey => $regionNode ) {
							if ( ! is_string( $regionKey ) || ! is_array( $regionNode ) ) continue;
							foreach ( $regionNode as $attrKey => $_attrNode ) {
								if ( ! is_string( $attrKey ) ) continue;
								$composite = $regionKey . '_' . $attrKey;
								if ( isset( $cat['searchable_labels'][ $composite ] ) ) {
									$out[ $composite ] = (string) $cat['searchable_labels'][ $composite ];
								}
							}
						}
					}
					return $out;
				}
			}
			$out = array();
			if ( !is_array($raw) ) return $out;
			$withPrefix = tt_flexicorp_freq_prefix_sattr_display_enabled();
			foreach ( $raw as $regionKey => $regionNode ) {
				if ( !is_string($regionKey) || !tt_flexicorp_cfg_is_attr_key($regionKey) ) continue;
				if ( !is_array($regionNode) ) continue;
				$regionLabel = tt_flexicorp_cfg_meta_label($regionNode, $regionKey);
				foreach ( $regionNode as $attrKey => $attrNode ) {
					if ( !is_string($attrKey) || !tt_flexicorp_cfg_is_attr_key($attrKey) ) continue;
					if ( tt_flexicorp_cfg_is_nosearch($attrNode) ) continue;
					$value = $regionKey . '_' . $attrKey;
					$attrLabel = tt_flexicorp_cfg_meta_label($attrNode, $attrKey);
					$out[$value] = $withPrefix ? ( $regionLabel . ': ' . $attrLabel ) : $attrLabel;
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_parse_pando_corpus_info_file') ) {
		/**
		 * Read pando/corpus.info (key=value lists) — same keys as Pando's CorpusInfo reader.
		 *
		 * @return array{positional: string[], region_attrs: string[], structural: string[]}|null
		 */
		function tt_flexicorp_parse_pando_corpus_info_file( $projectRoot ) {
			$path = rtrim( (string) $projectRoot, '/' ) . '/pando/corpus.info';
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				return null;
			}
			$positional = array();
			$region_attrs = array();
			$structural = array();
			$lines = @file( $path, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $lines ) ) {
				return null;
			}
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				if ( $line === '' || ( isset( $line[0] ) && $line[0] === '#' ) ) {
					continue;
				}
				$eq = strpos( $line, '=' );
				if ( $eq === false ) {
					continue;
				}
				$key = trim( substr( $line, 0, $eq ) );
				$val = trim( substr( $line, $eq + 1 ) );
				if ( $key !== 'positional' && $key !== 'region_attrs' && $key !== 'structural' ) {
					continue;
				}
				$parts = preg_split( '/\s*,\s*/', $val );
				if ( ! is_array( $parts ) ) {
					continue;
				}
				foreach ( $parts as $tok ) {
					$tok = trim( (string) $tok );
					if ( $tok === '' ) {
						continue;
					}
					if ( $key === 'positional' ) {
						$positional[] = $tok;
					} elseif ( $key === 'region_attrs' ) {
						$region_attrs[] = $tok;
					} else {
						$structural[] = $tok;
					}
				}
			}
			return array(
				'positional'   => $positional,
				'region_attrs' => $region_attrs,
				'structural'   => $structural,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_region_attrs_to_sattributes_by_region') ) {
		/**
		 * Group flat region_attr composite names (e.g. u_who, s_id) under TEITOK-style region keys
		 * using structural= names as prefixes (longest match first).
		 *
		 * @param string[] $region_attrs
		 * @param string[] $structural
		 * @return array<string, array<string, array<string, string>>>
		 */
		function tt_flexicorp_region_attrs_to_sattributes_by_region( $region_attrs, $structural ) {
			if ( ! is_array( $region_attrs ) || ! count( $region_attrs ) ) {
				return array();
			}
			$structs = is_array( $structural ) ? array_values( $structural ) : array();
			usort(
				$structs,
				function ( $a, $b ) {
					return strlen( (string) $b ) - strlen( (string) $a );
				}
			);
			$out = array();
			foreach ( $region_attrs as $composite ) {
				$composite = trim( (string) $composite );
				if ( $composite === '' ) {
					continue;
				}
				$region = '';
				$attr   = '';
				foreach ( $structs as $s ) {
					$s = (string) $s;
					if ( $composite === $s ) {
						$region = $s;
						$attr   = '';
						break;
					}
					$pref = $s . '_';
					if ( strlen( $composite ) > strlen( $pref ) && strncmp( $composite, $pref, strlen( $pref ) ) === 0 ) {
						$region = $s;
						$attr   = substr( $composite, strlen( $pref ) );
						break;
					}
				}
				if ( $region === '' ) {
					$p = strpos( $composite, '_' );
					if ( $p !== false && $p > 0 ) {
						$region = substr( $composite, 0, $p );
						$attr   = substr( $composite, $p + 1 );
					}
				}
				if ( $region === '' || $attr === '' ) {
					continue;
				}
				if ( ! isset( $out[ $region ] ) ) {
					$out[ $region ] = array();
				}
				if ( ! isset( $out[ $region ][ $attr ] ) ) {
					$out[ $region ][ $attr ] = array( '@display' => $attr );
				}
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_pando_merge_corpus_info_into_result') ) {
		if ( !function_exists('tt_flexicorp_pando_vocab_count_from_index') ) {
			/**
			 * Approximate distinct value count via <attr>.lex.idx offsets (count = offsets-1).
			 */
			function tt_flexicorp_pando_vocab_count_from_index( $indexDir, $attrName ) {
				$attr = trim( (string) $attrName );
				if ( $attr === '' ) {
					return null;
				}
				$idx = rtrim( (string) $indexDir, '/' ) . '/' . $attr . '.lex.idx';
				if ( ! is_file( $idx ) || ! is_readable( $idx ) ) {
					return null;
				}
				$size = @filesize( $idx );
				if ( ! is_int( $size ) || $size < 16 || ( $size % 8 ) !== 0 ) {
					return null;
				}
				$n = (int) ( $size / 8 ) - 1;
				return $n >= 0 ? $n : null;
			}
		}
		if ( !function_exists('tt_flexicorp_pando_region_stats_from_rgn') ) {
			/**
			 * Read <region>.rgn (pairs of uint64 start/end token positions).
			 *
			 * @return array{count:int,token_count:int}|null
			 */
			function tt_flexicorp_pando_region_stats_from_rgn( $indexDir, $region ) {
				$reg = trim( (string) $region );
				if ( $reg === '' ) {
					return null;
				}
				$path = rtrim( (string) $indexDir, '/' ) . '/' . $reg . '.rgn';
				if ( ! is_file( $path ) || ! is_readable( $path ) ) {
					return null;
				}
				$raw = @file_get_contents( $path );
				if ( ! is_string( $raw ) || $raw === '' ) {
					return null;
				}
				$len = strlen( $raw );
				if ( $len < 16 || ( $len % 16 ) !== 0 ) {
					return null;
				}
				$count = (int) ( $len / 16 );
				$tokTotal = 0;
				for ( $i = 0; $i < $len; $i += 16 ) {
					$chunk = substr( $raw, $i, 16 );
					$vals = @unpack( 'Pstart/Pend', $chunk );
					if ( ! is_array( $vals ) || ! isset( $vals['start'] ) || ! isset( $vals['end'] ) ) {
						continue;
					}
					$s = (int) $vals['start'];
					$e = (int) $vals['end'];
					if ( $e > $s ) {
						$tokTotal += ( $e - $s );
					}
				}
				return array( 'count' => $count, 'token_count' => $tokTotal );
			}
		}
		if ( !function_exists('tt_flexicorp_xidx_region_types_set') ) {
			/**
			 * Region types materially present in xidx (one per line).
			 *
			 * @return array<string,bool>
			 */
			function tt_flexicorp_xidx_region_types_set( $projectRoot ) {
				$out = array();
				$path = rtrim( (string) $projectRoot, '/' ) . '/xidx/region_types.tbl';
				if ( ! is_file( $path ) || ! is_readable( $path ) ) {
					return $out;
				}
				$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				if ( ! is_array( $lines ) ) {
					return $out;
				}
				foreach ( $lines as $line ) {
					$n = trim( (string) $line );
					if ( $n !== '' ) {
						$out[ $n ] = true;
					}
				}
				return $out;
			}
		}
		/**
		 * Enrich flexicorp-pando info payload with attributes listed in the on-disk Pando index (corpus.info).
		 */
		function tt_flexicorp_pando_merge_corpus_info_into_result( $projectRoot, array &$doneResult ) {
			$parsed = tt_flexicorp_parse_pando_corpus_info_file( $projectRoot );
			if ( ! is_array( $parsed ) ) {
				return;
			}
			$root = rtrim( (string) $projectRoot, '/' );
			$indexDir = isset( $doneResult['index_dir'] ) && is_string( $doneResult['index_dir'] ) && trim( $doneResult['index_dir'] ) !== ''
				? rtrim( (string) $doneResult['index_dir'], '/' )
				: ( $root . '/pando' );
			$doneResult['index_dir'] = $indexDir;
			if ( ! empty( $parsed['positional'] ) ) {
				$doneResult['pattributes']       = $parsed['positional'];
				$doneResult['positional_attrs'] = $parsed['positional'];
				$attrs = array();
				foreach ( $parsed['positional'] as $pname ) {
					$pname = trim( (string) $pname );
					if ( $pname === '' ) continue;
					$attrs[] = array(
						'name' => $pname,
						'vocab' => tt_flexicorp_pando_vocab_count_from_index( $indexDir, $pname ),
						'type' => 'pattribute',
					);
				}
				if ( count( $attrs ) ) {
					$doneResult['attributes'] = $attrs;
				}
			}
			if ( ! empty( $parsed['region_attrs'] ) ) {
				$doneResult['region_attrs'] = $parsed['region_attrs'];
			}
			if ( ! empty( $parsed['structural'] ) ) {
				$doneResult['native_structures'] = $parsed['structural'];
			}
			$byRegion = tt_flexicorp_region_attrs_to_sattributes_by_region( $parsed['region_attrs'], $parsed['structural'] );
			if ( ! empty( $byRegion ) ) {
				foreach ( $byRegion as $reg => &$attrs ) {
					if ( ! is_array( $attrs ) ) continue;
					foreach ( $attrs as $ak => &$node ) {
						if ( ! is_array( $node ) ) $node = array();
						$rawName = $reg . '_' . $ak;
						$node['vocab'] = tt_flexicorp_pando_vocab_count_from_index( $indexDir, $rawName );
						$node['type'] = 'sattribute';
					}
					unset( $node );
				}
				unset( $attrs );
				$doneResult['sattributes_by_region'] = $byRegion;
			}
			if ( ! empty( $parsed['structural'] ) ) {
				$xidxTypes = tt_flexicorp_xidx_region_types_set( $projectRoot );
				$tokensTotalForHeuristic = isset( $doneResult['tokens_count'] ) && is_numeric( $doneResult['tokens_count'] )
					? (int) $doneResult['tokens_count']
					: null;
				if ( ( $tokensTotalForHeuristic === null || $tokensTotalForHeuristic <= 0 ) ) {
					$infoPathHeu = $indexDir . '/corpus.info';
					if ( is_file( $infoPathHeu ) && is_readable( $infoPathHeu ) ) {
						$linesHeu = @file( $infoPathHeu, FILE_IGNORE_NEW_LINES );
						if ( is_array( $linesHeu ) ) {
							foreach ( $linesHeu as $lineHeu ) {
								$lineHeu = trim( (string) $lineHeu );
								if ( $lineHeu === '' || ( isset( $lineHeu[0] ) && $lineHeu[0] === '#' ) ) continue;
								$eqHeu = strpos( $lineHeu, '=' );
								if ( $eqHeu === false ) continue;
								$kHeu = trim( substr( $lineHeu, 0, $eqHeu ) );
								$vHeu = trim( substr( $lineHeu, $eqHeu + 1 ) );
								if ( $kHeu === 'size' && is_numeric( $vHeu ) ) {
									$tokensTotalForHeuristic = (int) $vHeu;
									break;
								}
							}
						}
					}
				}
				$structures = array();
				foreach ( $parsed['structural'] as $sname ) {
					$sname = trim( (string) $sname );
					if ( $sname === '' ) continue;
					$st = array(
						'name' => $sname,
						'type' => 'region',
						'region_type' => 'region',
					);
					$stats = tt_flexicorp_pando_region_stats_from_rgn( $indexDir, $sname );
					if ( is_array( $stats ) ) {
						$st['regions'] = isset( $stats['count'] ) ? (int) $stats['count'] : null;
						$st['tokens'] = isset( $stats['token_count'] ) ? (int) $stats['token_count'] : null;
					}
					$ras = array();
					if ( isset( $byRegion[ $sname ] ) && is_array( $byRegion[ $sname ] ) ) {
						foreach ( $byRegion[ $sname ] as $ak => $node ) {
							$row = array(
								'name' => (string) $ak,
								'display' => ( is_array($node) && isset($node['@display']) ) ? (string)$node['@display'] : (string)$ak,
								'vocab' => ( is_array($node) && array_key_exists('vocab', $node) ) ? $node['vocab'] : null,
								'type' => ( is_array($node) && isset($node['type']) ) ? (string)$node['type'] : 'sattribute',
							);
							$ras[] = $row;
						}
					}
					$st['region_attrs'] = $ras;
					$regionsN = isset( $st['regions'] ) ? (int) $st['regions'] : 0;
					$tokensN  = isset( $st['tokens'] ) ? (int) $st['tokens'] : 0;
					$nearFullCorpus = (
						$tokensTotalForHeuristic !== null
						&& $tokensTotalForHeuristic > 0
						&& $tokensN >= max( 1, (int) floor( $tokensTotalForHeuristic * 0.98 ) )
					);
					$isMaterialized = isset( $xidxTypes[ $sname ] );
					// Declared in corpus.info but effectively unused: one all-covering span, no attrs, absent in xidx region_types.
					if ( ! $isMaterialized && count( $ras ) === 0 && $regionsN <= 1 && $nearFullCorpus ) {
						continue;
					}
					$structures[] = $st;
				}
				if ( count( $structures ) ) {
					$doneResult['structures'] = $structures;
				}
			}
			// Keep a compact corpus_info object so UI can show backend-agnostic summary rows.
			$doneResult['corpus_info'] = array(
				'positional' => isset( $parsed['positional'] ) ? $parsed['positional'] : array(),
				'region_attrs' => isset( $parsed['region_attrs'] ) ? $parsed['region_attrs'] : array(),
				'structural' => isset( $parsed['structural'] ) ? $parsed['structural'] : array(),
			);
			// tokens_count from pando/corpus.info size=
			$infoPath = $indexDir . '/corpus.info';
			if ( is_file( $infoPath ) && is_readable( $infoPath ) ) {
				$lines = @file( $infoPath, FILE_IGNORE_NEW_LINES );
				if ( is_array( $lines ) ) {
					foreach ( $lines as $line ) {
						$line = trim( (string) $line );
						if ( $line === '' || ( isset( $line[0] ) && $line[0] === '#' ) ) {
							continue;
						}
						$eq = strpos( $line, '=' );
						if ( $eq === false ) {
							continue;
						}
						$key = trim( substr( $line, 0, $eq ) );
						$val = trim( substr( $line, $eq + 1 ) );
						if ( $key === 'size' && is_numeric( $val ) ) {
							$n = (int) $val;
							if ( $n > 0 ) {
								$doneResult['tokens_count'] = $n;
							}
							break;
						}
					}
				}
			}
			// docs_count from xidx/docs.tbl
			$docsTbl = $root . '/xidx/docs.tbl';
			if ( is_file( $docsTbl ) && is_readable( $docsTbl ) ) {
				$docs = @file( $docsTbl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				if ( is_array( $docs ) ) {
					$dn = count( $docs );
					if ( $dn > 0 ) {
						$doneResult['docs_count'] = $dn;
						if ( ! isset( $doneResult['doc_count'] ) ) {
							$doneResult['doc_count'] = $dn;
						}
						if ( ! isset( $doneResult['documents_count'] ) ) {
							$doneResult['documents_count'] = $dn;
						}
					}
				}
			}
		}
	}

	if ( !function_exists('tt_flexicorp_freq_field_guess_label') ) {
		/**
		 * Human-readable label for a composite region_attr not present in TEITOK cqp/sattributes.
		 */
		function tt_flexicorp_freq_field_guess_label( $key ) {
			$key = trim( (string) $key );
			if ( strpos( $key, '_' ) === false ) {
				return $key;
			}
			$parts = explode( '_', $key, 2 );
			$rest  = isset( $parts[1] ) ? str_replace( '_', ' · ', $parts[1] ) : '';
			return $parts[0] . ( $rest !== '' ? ' · ' . $rest : '' );
		}
	}

	if ( !function_exists('tt_flexicorp_freq_field_label_map_from_settings') ) {
		/**
		 * Display names from TEITOK cqp/pattributes + cqp/sattributes (when the key is configured).
		 *
		 * @return array<string, string>
		 */
		function tt_flexicorp_freq_field_label_map_from_settings() {
			if ( function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
				$cat = tt_flexicorp_fn_attribute_catalog();
				if ( is_array( $cat ) && isset( $cat['searchable_labels'] ) && is_array( $cat['searchable_labels'] ) ) {
					return $cat['searchable_labels'];
				}
			}
			$names  = array();
			$pattrs = function_exists( 'getset' ) ? getset( 'cqp/pattributes', array() ) : array();
			$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
			foreach ( tt_flexicorp_cfg_pattr_labels( $pattrs ) as $value => $label ) {
				$names[ $value ] = $label;
			}
			foreach ( tt_flexicorp_cfg_sattr_labels( $sattrs ) as $value => $label ) {
				if ( ! isset( $names[ $value ] ) ) {
					$names[ $value ] = $label;
				}
			}
			return $names;
		}
	}

	if ( !function_exists('tt_flexicorp_freq_field_candidates_settings_only') ) {
		/**
		 * @return string[]
		 */
		function tt_flexicorp_freq_field_candidates_settings_only() {
			if ( function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
				$cat = tt_flexicorp_fn_attribute_catalog();
				if ( is_array( $cat ) && isset( $cat['searchable_labels'] ) && is_array( $cat['searchable_labels'] ) ) {
					$list = array_keys( $cat['searchable_labels'] );
					sort( $list, SORT_NATURAL | SORT_FLAG_CASE );
					return $list;
				}
			}
			$names  = array();
			$pattrs = function_exists( 'getset' ) ? getset( 'cqp/pattributes', array() ) : array();
			$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
			foreach ( tt_flexicorp_cfg_pattr_labels( $pattrs ) as $value => $_l ) {
				$names[ $value ] = true;
			}
			foreach ( tt_flexicorp_cfg_sattr_labels( $sattrs ) as $value => $_l ) {
				$names[ $value ] = true;
			}
			$list = array_keys( $names );
			sort( $list, SORT_NATURAL | SORT_FLAG_CASE );
			return $list;
		}
	}

	if ( !function_exists('tt_flexicorp_freq_field_candidates_from_sources') ) {
		/**
		 * Prefer attributes present in the indexed corpus (info result and/or pando/corpus.info);
		 * TEITOK settings supply labels only (see tt_flexicorp_freq_field_label_map_from_settings).
		 *
		 * @param array|null $info_result flexicorp info corpus result
		 * @param string|null $project_root
		 * @param string|null $backend When `pando` / `flexicorp-pando`, merge attributes from pando/corpus.info.
		 * @return string[]|null null = no corpus-derived list — caller may fall back to settings-only
		 */
		function tt_flexicorp_freq_field_candidates_from_sources( $info_result, $project_root, $backend = null ) {
			$positional = array();
			$region     = array();
			$seenP      = array();
			$seenR      = array();
			$addP       = function ( $name ) use ( &$positional, &$seenP ) {
				$name = trim( (string) $name );
				if ( $name === '' || isset( $seenP[ $name ] ) ) {
					return;
				}
				$seenP[ $name ] = true;
				$positional[]   = $name;
			};
			$addR       = function ( $name ) use ( &$region, &$seenR ) {
				$name = trim( (string) $name );
				if ( $name === '' || isset( $seenR[ $name ] ) ) {
					return;
				}
				$seenR[ $name ] = true;
				$region[]       = $name;
			};

			if ( is_array( $info_result ) ) {
				if ( ! empty( $info_result['attributes'] ) && is_array( $info_result['attributes'] ) ) {
					foreach ( $info_result['attributes'] as $attr ) {
						if ( is_array( $attr ) && isset( $attr['name'] ) ) {
							$addP( $attr['name'] );
						} else if ( is_string( $attr ) ) {
							$addP( $attr );
						}
					}
				} else {
					foreach ( array( 'pattributes', 'positional_attrs', 'native_pattributes' ) as $k ) {
						if ( ! empty( $info_result[ $k ] ) && is_array( $info_result[ $k ] ) ) {
							foreach ( $info_result[ $k ] as $x ) {
								$addP( $x );
							}
						}
					}
				}
				if ( ! empty( $info_result['region_attrs'] ) && is_array( $info_result['region_attrs'] ) ) {
					foreach ( $info_result['region_attrs'] as $x ) {
						if ( is_string( $x ) ) {
							$addR( $x );
						}
					}
				}
				if ( ! empty( $info_result['structures'] ) && is_array( $info_result['structures'] ) ) {
					foreach ( $info_result['structures'] as $st ) {
						if ( is_array( $st ) && ! empty( $st['name'] ) ) {
							$sname = $st['name'];
							if ( ! empty( $st['region_attrs'] ) && is_array( $st['region_attrs'] ) ) {
								foreach ( $st['region_attrs'] as $ra ) {
									if ( is_array( $ra ) && isset( $ra['name'] ) ) {
										$addR( $sname . '_' . $ra['name'] );
									}
								}
							} else if ( ! empty( $st['attrs'] ) && is_array( $st['attrs'] ) ) {
								foreach ( $st['attrs'] as $a ) {
									if ( is_string( $a ) ) {
										$addR( $sname . '_' . $a );
									}
								}
							}
						}
					}
				}
				if ( ! empty( $info_result['sattributes_by_region'] ) && is_array( $info_result['sattributes_by_region'] ) ) {
					foreach ( $info_result['sattributes_by_region'] as $reg => $attrs ) {
						if ( ! is_string( $reg ) || ! is_array( $attrs ) ) {
							continue;
						}
						foreach ( $attrs as $ak => $_node ) {
							if ( ! is_string( $ak ) || $ak === '' || ( isset( $ak[0] ) && $ak[0] === '@' ) ) {
								continue;
							}
							$addR( $reg . '_' . $ak );
						}
					}
				}
			}

			$readPandoFile = is_string( $project_root ) && $project_root !== ''
				&& is_string( $backend ) && in_array( $backend, array( 'pando', 'flexicorp-pando' ), true );
			if ( $readPandoFile ) {
				$parsed = tt_flexicorp_parse_pando_corpus_info_file( $project_root );
				if ( is_array( $parsed ) ) {
					foreach ( $parsed['positional'] as $x ) {
						$addP( $x );
					}
					foreach ( $parsed['region_attrs'] as $x ) {
						$addR( $x );
					}
				}
			}

			if ( ! count( $positional ) && ! count( $region ) ) {
				return null;
			}

			$prio    = array( 'lemma', 'form', 'word' );
			$ordered = array();
			foreach ( $prio as $p ) {
				if ( isset( $seenP[ $p ] ) ) {
					$ordered[] = $p;
				}
			}
			$rest = array();
			foreach ( $positional as $name ) {
				if ( ! in_array( $name, $ordered, true ) ) {
					$rest[] = $name;
				}
			}
			sort( $rest, SORT_NATURAL | SORT_FLAG_CASE );
			$ordered = array_merge( $ordered, $rest );

			$regionSorted = $region;
			sort( $regionSorted, SORT_NATURAL | SORT_FLAG_CASE );

			return array_merge( $ordered, $regionSorted );
		}
	}

	if ( !function_exists('tt_flexicorp_teitok_freq_field_is_region_sattr') ) {
		/**
		 * True when $field is a region-level composite key (indexed region_attr), e.g. text_genre, u_who.
		 * Prefer the corpus index (pando/corpus.info region_attrs); fall back to cqp/sattributes keys.
		 */
		function tt_flexicorp_teitok_freq_field_is_region_sattr( $field, $project_root = null ) {
			$field = trim( (string) $field );
			if ( $field === '' || strpos( $field, '_' ) === false ) {
				return false;
			}
			static $cache = array();
			if ( is_string( $project_root ) && $project_root !== '' ) {
				if ( ! isset( $cache[ $project_root ] ) ) {
					$parsed = tt_flexicorp_parse_pando_corpus_info_file( $project_root );
					$keys   = ( is_array( $parsed ) && ! empty( $parsed['region_attrs'] ) ) ? $parsed['region_attrs'] : array();
					$cache[ $project_root ] = array_flip( $keys );
				}
				if ( isset( $cache[ $project_root ][ $field ] ) ) {
					return true;
				}
			}
			if ( ! function_exists( 'tt_flexicorp_cfg_sattr_labels' ) ) {
				return false;
			}
			$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
			$keys   = array_keys( tt_flexicorp_cfg_sattr_labels( $sattrs ) );
			return in_array( $field, $keys, true );
		}
	}

	if ( !function_exists('tt_flexicorp_freq_field_options_html') ) {
		/**
		 * Build frequency / collocate field dropdown options: corpus index first, TEITOK settings for labels.
		 *
		 * @param array|null $info_result Result of flexicorp info corpus (merged for flexicorp-pando).
		 * @param string|null $project_root Used to read pando/corpus.info when info is thin (Pando backends only).
		 * @param string|null $backend
		 */
		function tt_flexicorp_freq_field_options_html( $info_result = null, $project_root = null, $backend = null ) {
			$candidates = tt_flexicorp_freq_field_candidates_from_sources( $info_result, $project_root, $backend );
			if ( $candidates === null || ! count( $candidates ) ) {
				$candidates = tt_flexicorp_freq_field_candidates_settings_only();
			}
			$labelMap = tt_flexicorp_freq_field_label_map_from_settings();
			if ( empty( $candidates ) ) {
				return '<option value="">(no corpus or CQP attributes)</option>';
			}
			$html = '';
			foreach ( $candidates as $name ) {
				$safeVal = tt_flexicorp_h( $name );
				$label   = isset( $labelMap[ $name ] ) ? (string) $labelMap[ $name ] : tt_flexicorp_freq_field_guess_label( $name );
				if ( trim( $label ) === '' ) {
					$label = $name;
				}
				$safeLabel = tt_flexicorp_h( $label );
				$html     .= '<option value="' . $safeVal . '">' . $safeLabel . '</option>';
			}
			return $html;
		}
	}

	if ( !function_exists( 'tt_flexicorp_freq_field_frequency_checkboxes_html' ) ) {
		if ( !function_exists('tt_flexicorp_freq_field_is_time_like') ) {
			/**
			 * Heuristic for timeline-capable fields.
			 */
			function tt_flexicorp_freq_field_is_time_like( $fieldName, $fieldLabel = '' ) {
				$name = strtolower( trim( (string) $fieldName ) );
				$lbl  = strtolower( trim( (string) $fieldLabel ) );
				$hay  = $name . ' ' . $lbl;
				return (bool) preg_match( '/(^|[_\\W])(date|year|century|month|day|time|timestamp)([_\\W]|$)/i', $hay );
			}
		}
		if ( !function_exists('tt_flexicorp_freq_field_add_pando_time_expr_variants') ) {
			if ( !function_exists('tt_flexicorp_freq_field_supports_time_expr') ) {
				/**
				 * Granularity sanity gate: do not derive finer buckets from coarser fields.
				 */
				function tt_flexicorp_freq_field_supports_time_expr( $fieldName, $fieldLabel, $granularity ) {
					$name = strtolower( trim( (string) $fieldName ) );
					$lbl  = strtolower( trim( (string) $fieldLabel ) );
					$hay  = $name . ' ' . $lbl;
					$gran = strtolower( trim( (string) $granularity ) );
					if ( $gran === 'decade' ) {
						// Do not propose decade(<century-field>).
						if ( preg_match( '/(^|[_\\W])(century|cent)([_\\W]|$)/i', $hay ) ) {
							return false;
						}
					}
					return true;
				}
			}
			if ( !function_exists('tt_flexicorp_freq_field_stem_from_time_field') ) {
				/**
				 * Build a conservative stem for related time fields.
				 */
				function tt_flexicorp_freq_field_stem_from_time_field( $fieldName ) {
					$name = strtolower( trim( (string) $fieldName ) );
					if ( $name === '' ) return '';
					$stem = preg_replace( '/(?:_)?(?:date|year|month|day|time|timestamp|century|cent|decade|dec)$/i', '', $name );
					$stem = trim( (string) $stem, '_' );
					return $stem !== '' ? $stem : $name;
				}
			}
			if ( !function_exists('tt_flexicorp_freq_field_has_concrete_time_equivalent') ) {
				/**
				 * Avoid duplicate semantic options: for a given stem and granularity, prefer concrete field.
				 */
				function tt_flexicorp_freq_field_has_concrete_time_equivalent( $fieldName, $granularity, $seen ) {
					$name = strtolower( trim( (string) $fieldName ) );
					$gran = strtolower( trim( (string) $granularity ) );
					if ( $name === '' || $gran === '' || ! is_array( $seen ) || ! count( $seen ) ) {
						return false;
					}
					if ( $gran === 'century' && preg_match( '/(^|_)(century|cent)(_|$)/i', $name ) ) {
						return true;
					}
					if ( $gran === 'decade' && preg_match( '/(^|_)(decade|dec)(_|$)/i', $name ) ) {
						return true;
					}
					$stem = tt_flexicorp_freq_field_stem_from_time_field( $name );
					$candidates = array();
					if ( $gran === 'century' ) {
						$candidates = array( $stem . '_century', $stem . '_cent', $stem . 'century', $stem . 'cent' );
					} elseif ( $gran === 'decade' ) {
						$candidates = array( $stem . '_decade', $stem . '_dec', $stem . 'decade', $stem . 'dec' );
					}
					foreach ( $candidates as $cand ) {
						if ( isset( $seen[ $cand ] ) ) {
							return true;
						}
					}
					return false;
				}
			}
			/**
			 * Backend-gated derived time expressions (D7): expose decade(<field>) for Pando.
			 *
			 * @param array<int,array{name:string,label:string}> $rows
			 * @return array<int,array{name:string,label:string}>
			 */
			function tt_flexicorp_freq_field_add_pando_time_expr_variants( $rows, $backend ) {
				$be = strtolower( trim( (string) $backend ) );
				if ( $be !== 'pando' && $be !== 'flexicorp-pando' ) {
					return $rows;
				}
				if ( ! is_array( $rows ) || ! count( $rows ) ) {
					return $rows;
				}
				$seen = array();
				foreach ( $rows as $r ) {
					if ( is_array( $r ) && isset( $r['name'] ) ) {
						$seen[ trim( (string) $r['name'] ) ] = true;
					}
				}
				$extra = array();
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) ) continue;
					$name  = isset( $r['name'] ) ? trim( (string) $r['name'] ) : '';
					$label = isset( $r['label'] ) ? trim( (string) $r['label'] ) : $name;
					if ( $name === '' ) continue;
					if ( ! tt_flexicorp_freq_field_is_time_like( $name, $label ) ) continue;
					if (
						tt_flexicorp_freq_field_supports_time_expr( $name, $label, 'decade' ) &&
						! tt_flexicorp_freq_field_has_concrete_time_equivalent( $name, 'decade', $seen )
					) {
						$expr = 'decade(' . $name . ')';
						if ( ! isset( $seen[ $expr ] ) ) {
							$extra[] = array(
								'name'  => $expr,
								'label' => 'Decade (' . $label . ')',
							);
							$seen[ $expr ] = true;
						}
					}
					if ( ! tt_flexicorp_freq_field_has_concrete_time_equivalent( $name, 'century', $seen ) ) {
						$expr = 'century(' . $name . ')';
						if ( ! isset( $seen[ $expr ] ) ) {
							$extra[] = array(
								'name'  => $expr,
								'label' => 'Century (' . $label . ')',
							);
							$seen[ $expr ] = true;
						}
					}
				}
				if ( count( $extra ) ) {
					$rows = array_merge( $rows, $extra );
				}
				return $rows;
			}
		}
		/**
		 * Frequency-distribution field picker: one checkbox per field, only for attributes that have
		 * an explicit @display|display in TEITOK cqp/pattributes or cqp/sattributes and that appear
		 * in the corpus (or settings-only) candidate list.
		 *
		 * @param array|null  $info_result
		 * @param string|null $project_root
		 * @param string|null $backend
		 * @return string HTML
		 */
		function tt_flexicorp_freq_field_frequency_checkboxes_html( $info_result = null, $project_root = null, $backend = null ) {
			$displayMap = tt_flexicorp_freq_field_label_map_display_only();
			$candidates = tt_flexicorp_freq_field_candidates_from_sources( $info_result, $project_root, $backend );
			if ( $candidates === null || ! count( $candidates ) ) {
				$candidates = tt_flexicorp_freq_field_candidates_settings_only();
			}
			$rows = array();
			if ( is_array( $candidates ) ) {
				foreach ( $candidates as $name ) {
					$n = trim( (string) $name );
					if ( $n === '' || ! isset( $displayMap[ $n ] ) ) {
						continue;
					}
					$rows[] = array(
						'name'  => $n,
						'label' => (string) $displayMap[ $n ],
					);
				}
			}
			$rows = tt_flexicorp_freq_field_add_pando_time_expr_variants( $rows, $backend );
			if ( ! count( $rows ) ) {
				$nc = is_array( $candidates ) ? count( $candidates ) : 0;
				$nd = count( $displayMap );
				if ( $nc === 0 && $nd === 0 ) {
					$msg = 'No candidate fields. Run corpus info or add <code>cqp/pattributes</code> / <code>cqp/sattributes</code> in TEITOK, and give each frequency field a <code>@display</code> (or <code>display</code>) title.';
				} elseif ( $nd === 0 ) {
					$msg = 'Indexed fields exist, but none have a <code>@display</code> (or <code>display</code>) title in TEITOK CQP settings. Add display names in <strong>cqp/pattributes</strong> and <strong>cqp/sattributes</strong> to list them here.';
				} else {
					$msg = 'No overlap between display-titled settings and the current corpus field list. Check that attribute names in settings match the backend (including region keys like <code>region_attr</code>).';
				}
				return '<p class="flexicorp-panel-note flexicorp-freq-field-checkboxes--empty" style="margin:0;">' . $msg . '</p>';
			}
			$names = array();
			foreach ( $rows as $r ) {
				$names[] = $r['name'];
			}
			$jsonKeys = function_exists( 'wp_json_encode' ) ? wp_json_encode( $names ) : json_encode( $names );
			if ( ! is_string( $jsonKeys ) || $jsonKeys === '' ) {
				$jsonKeys = '[]';
			}
			$html  = '<fieldset class="flexicorp-freq-field-checkboxes" aria-labelledby="fc-freq-field-group" x-init="typeof initFrequencyFieldCheckboxes === \'function\' && initFrequencyFieldCheckboxes($el)" data-field-keys="' . tt_flexicorp_h( $jsonKeys ) . '">';
			$html .= '<div class="flexicorp-freq-field-checkboxes__grid" role="group" aria-label="Select fields for frequency distribution">';
			$idx   = 0;
			foreach ( $rows as $r ) {
				$idx      += 1;
				$safeName  = tt_flexicorp_h( $r['name'] );
				$safeLabel = tt_flexicorp_h( $r['label'] );
				$id        = 'fc-freq-fld-' . (int) $idx;
				$html     .= '<label class="flexicorp-freq-field-cb" for="' . $id . '">';
				$html     .= '<input x-bind:type="frequencyUseRadioFields() ? \'radio\' : \'checkbox\'" x-bind:name="frequencyUseRadioFields() ? \'freq_field\' : \'freq_field[]\'" value="' . $safeName . '" x-bind:checked="isFrequencyFieldSelected(\'' . $safeName . '\')" x-on:change="onFrequencyFieldInputChange(\'' . $safeName . '\', $event.target.checked)" id="' . $id . '">';
				$html     .= '<span class="flexicorp-freq-field-cb__text">' . $safeLabel . '</span>';
				$html     .= '</label>';
			}
			$html .= '</div></fieldset>';
			return $html;
		}
	}

	if ( !function_exists('tt_flexicorp_json_value_end_offset' ) ) {
		/**
		 * End offset (inclusive) of the first top-level JSON object/array starting at $start,
		 * respecting strings so braces inside "..." do not affect depth.
		 *
		 * @return int|null
		 */
		function tt_flexicorp_json_value_end_offset( $raw, $start ) {
			$raw   = (string) $raw;
			$len   = strlen( $raw );
			$start = (int) $start;
			if ( $start < 0 || $start >= $len ) {
				return null;
			}
			$first = $raw[ $start ];
			if ( $first !== '{' && $first !== '[' ) {
				return null;
			}
			$stack      = array();
			$stack[]    = ( $first === '{' ) ? '}' : ']';
			$in_string  = false;
			$escape     = false;
			for ( $i = $start + 1; $i < $len; $i++ ) {
				$c = $raw[ $i ];
				if ( $in_string ) {
					if ( $escape ) {
						$escape = false;
						continue;
					}
					if ( $c === '\\' ) {
						$escape = true;
						continue;
					}
					if ( $c === '"' ) {
						$in_string = false;
					}
					continue;
				}
				if ( $c === '"' ) {
					$in_string = true;
					continue;
				}
				if ( $c === '{' ) {
					$stack[] = '}';
					continue;
				}
				if ( $c === '[' ) {
					$stack[] = ']';
					continue;
				}
				if ( $c === '}' || $c === ']' ) {
					$expect = array_pop( $stack );
					if ( $expect === null || $c !== $expect ) {
						return null;
					}
					if ( count( $stack ) === 0 ) {
						return $i;
					}
				}
			}
			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_decode_first_json_value' ) ) {
		/**
		 * Parse the first JSON object/array in $raw (handles extra text before/after the value).
		 *
		 * @return array|null
		 */
		function tt_flexicorp_decode_first_json_value( $raw ) {
			$raw = (string) $raw;
			$len = strlen( $raw );
			for ( $i = 0; $i < $len; $i++ ) {
				$c = $raw[ $i ];
				if ( $c !== '{' && $c !== '[' ) {
					continue;
				}
				$end = tt_flexicorp_json_value_end_offset( $raw, $i );
				if ( $end === null ) {
					continue;
				}
				$chunk  = substr( $raw, $i, $end - $i + 1 );
				$parsed = json_decode( $chunk, true );
				if ( is_array( $parsed ) ) {
					return $parsed;
				}
			}
			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_extract_json_payload_from_mixed_output') ) {
		/**
		 * Recover API JSON when debug/noise lines are printed before the final JSON object.
		 */
		function tt_flexicorp_extract_json_payload_from_mixed_output( $raw ) {
			$raw = (string) $raw;
			$trim = trim( $raw );
			if ( $trim === '' ) return null;
			$direct = json_decode( $trim, true );
			if ( is_array( $direct ) ) return $direct;

			$positions = array();
			$pos = strrpos( $raw, "\n{" );
			while ( $pos !== false ) {
				$positions[] = $pos + 1;
				$prefix = substr( $raw, 0, $pos );
				$pos = strrpos( $prefix, "\n{" );
			}
			if ( strlen( $raw ) > 0 && $raw[0] === '{' ) $positions[] = 0;
			$positions = array_values( array_unique( $positions ) );
			sort( $positions );
			$positions = array_reverse( $positions );
			foreach ( $positions as $start ) {
				$candidate = trim( substr( $raw, (int) $start ) );
				if ( $candidate === '' || $candidate[0] !== '{' ) continue;
				$parsed = json_decode( $candidate, true );
				if ( is_array( $parsed ) ) return $parsed;
				$end = tt_flexicorp_json_value_end_offset( $raw, (int) $start );
				if ( $end !== null ) {
					$chunk = substr( $raw, (int) $start, $end - (int) $start + 1 );
					$try   = json_decode( $chunk, true );
					if ( is_array( $try ) ) return $try;
				}
			}
			$first = tt_flexicorp_decode_first_json_value( $raw );
			if ( is_array( $first ) ) {
				return $first;
			}
			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_run_freq') ) {
		/**
		 * Frequency wrapper with tolerant JSON recovery for mixed debug+JSON stdout.
		 */
		function tt_flexicorp_run_freq( $backend, $projectRoot, $extraArgs, $queryEngine, $queryLanguage, $corpusFormat ) {
			$call = tt_flexicorp_run(
				array('freq'),
				$backend,
				$projectRoot,
				$extraArgs,
				$queryEngine,
				$queryLanguage,
				$corpusFormat
			);
			if ( !is_array( $call ) ) return $call;
			$err = isset($call['error']) ? (string) $call['error'] : '';
			if ( $err !== 'flexicorp did not return valid JSON.' ) return $call;
			$raw = isset($call['raw']) ? (string) $call['raw'] : '';
			$data = tt_flexicorp_extract_json_payload_from_mixed_output( $raw );
			if ( !is_array( $data ) ) return $call;
			$call['data'] = $data;
			$call['ok'] = !empty( $data['success'] );
			$call['error'] = '';
			return $call;
		}
	}

	if ( !function_exists('tt_flexicorp_run_coll') ) {
		/**
		 * Collocation stats (Pando `coll by …`); parameters come from the current request (see flexicorp.php).
		 */
		function tt_flexicorp_run_coll( $backend, $projectRoot, $extraArgs, $queryEngine, $queryLanguage, $corpusFormat ) {
			return tt_flexicorp_run(
				array('coll'),
				$backend,
				$projectRoot,
				$extraArgs,
				$queryEngine,
				$queryLanguage,
				$corpusFormat
			);
		}
	}

	if ( !function_exists('tt_flexicorp_coll_rows') ) {
		/**
		 * Unwrap collocation result wrappers (done/result envelopes) to the payload that contains
		 * collocates / rows / table. Handles nested `result.result...` shapes.
		 *
		 * @param mixed $result
		 * @return array|null
		 */
		function tt_flexicorp_coll_result_unwrap( $result ) {
			if ( ! is_array( $result ) ) {
				return null;
			}
			$cur = $result;
			for ( $depth = 0; $depth < 4; $depth++ ) {
				if ( isset( $cur['collocates'] ) || isset( $cur['rows'] ) || isset( $cur['table'] ) ) {
					return is_array( $cur ) ? $cur : null;
				}
				if ( isset( $cur['result'] ) && is_array( $cur['result'] ) ) {
					$cur = $cur['result'];
					continue;
				}
				break;
			}
			return is_array( $cur ) ? $cur : null;
		}

		/**
		 * Normalize collocation JSON into table rows for the Stats UI.
		 * Accepts both:
		 * - Pando map form: result.collocates = { word -> metrics }
		 * - Table form: rows (+ optional columns), possibly nested under result.table
		 */
		function tt_flexicorp_coll_rows( $result ) {
			$result = tt_flexicorp_coll_result_unwrap( $result );
			if ( !is_array( $result ) ) {
				return array();
			}
			$rows = array();

			// Native collocate map: { "word": {...metrics...}, ... }
			if ( isset( $result['collocates'] ) && is_array( $result['collocates'] ) ) {
				foreach ( $result['collocates'] as $word => $metrics ) {
					if ( is_array( $metrics ) ) {
						$row = $metrics;
						// Ensure word column is present for table/chart labels.
						if ( !isset( $row['word'] ) ) {
							$row['word'] = is_string( $word ) ? $word : (string) $word;
						}
						$rows[] = $row;
					} else if ( is_scalar( $metrics ) ) {
						$rows[] = array(
							'word' => is_string( $word ) ? $word : (string) $word,
							'obs'  => $metrics,
						);
					}
				}
				if ( count( $rows ) ) {
					return array_values( $rows );
				}
			}

			$table = null;
			if ( isset( $result['table'] ) && is_array( $result['table'] ) ) {
				$table = $result['table'];
			}
			$rawRows = null;
			if ( isset( $result['rows'] ) && is_array( $result['rows'] ) ) {
				$rawRows = $result['rows'];
			} else if ( is_array( $table ) && isset( $table['rows'] ) && is_array( $table['rows'] ) ) {
				$rawRows = $table['rows'];
			}
			if ( !is_array( $rawRows ) ) {
				return array();
			}

			$columns = array();
			if ( isset( $result['columns'] ) && is_array( $result['columns'] ) ) {
				$columns = array_values( $result['columns'] );
			} else if ( is_array( $table ) && isset( $table['columns'] ) && is_array( $table['columns'] ) ) {
				$columns = array_values( $table['columns'] );
			}

			foreach ( $rawRows as $r ) {
				if ( is_array( $r ) ) {
					// Already associative (or list without explicit columns).
					if ( array_keys( $r ) !== range( 0, count( $r ) - 1 ) || !count( $columns ) ) {
						$rows[] = $r;
						continue;
					}
					// Positional row + explicit column names -> map to object row.
					$row = array();
					for ( $i = 0; $i < count( $r ); $i++ ) {
						$col = isset( $columns[ $i ] ) ? (string) $columns[ $i ] : ('c' . (string) $i);
						$row[ $col ] = $r[ $i ];
					}
					$rows[] = $row;
				}
			}
			return array_values( $rows );
		}
	}

	if ( !function_exists('tt_flexicorp_coll_matches_total') ) {
		/**
		 * Matches count for collocations, tolerant to nested result wrappers.
		 *
		 * @param mixed $result
		 * @return int|null
		 */
		function tt_flexicorp_coll_matches_total( $result ) {
			$r = tt_flexicorp_coll_result_unwrap( $result );
			if ( ! is_array( $r ) ) {
				return null;
			}
			if ( isset( $r['matches'] ) ) {
				$n = intval( $r['matches'] );
				if ( $n >= 0 ) {
					return $n;
				}
			}
			if ( isset( $r['total'] ) ) {
				$n = intval( $r['total'] );
				if ( $n >= 0 ) {
					return $n;
				}
			}
			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_coll_measure_ids_allowed' ) ) {
		/**
		 * Pando association measure ids (see ProgramOptions / CLI --measures).
		 */
		function tt_flexicorp_coll_measure_ids_allowed() {
			return array( 'logdice', 'mi', 'mi3', 'tscore', 'll', 'dice' );
		}
	}

	if ( !function_exists('tt_flexicorp_coll_measures_list_from_request') ) {
		/**
		 * Parse coll_measures from request: comma-separated string, or array (multi-select / coll_measures[]).
		 * Unknown ids are dropped; empty selection defaults to logdice.
		 */
		function tt_flexicorp_coll_measures_list_from_request() {
			$allowedOrder = tt_flexicorp_coll_measure_ids_allowed();
			$allowed = array_flip( $allowedOrder );
			// Prefer $_POST: fetch(FormData) populates POST; some stacks omit array keys from $_REQUEST.
			$raw = 'logdice';
			if ( isset( $_POST['coll_measures'] ) ) {
				$raw = $_POST['coll_measures'];
			} elseif ( isset( $_REQUEST['coll_measures'] ) ) {
				$raw = $_REQUEST['coll_measures'];
			}
			if ( is_array( $raw ) ) {
				$parts = array();
				foreach ( $raw as $one ) {
					$t = trim( (string) $one );
					if ( $t !== '' ) {
						$parts[] = $t;
					}
				}
			} else {
				$parts = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
			}
			$selected = array();
			foreach ( $parts as $p ) {
				if ( isset( $allowed[ $p ] ) ) {
					$selected[ $p ] = true;
				}
			}
			$out = array();
			foreach ( $allowedOrder as $id ) {
				if ( ! empty( $selected[ $id ] ) ) {
					$out[] = $id;
				}
			}
			if ( ! count( $out ) ) {
				$out = array( 'logdice' );
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_stats_help_html') ) {
		/**
		 * Help dialog fragment: quantitative analyses (inserted into flexicorp help template).
		 */
		function tt_flexicorp_stats_help_html() {
			return '
				<h3 class="flexicorp-subtitle">Quantitative analysis</h3>
				<p class="flexicorp-panel-note">
					After you run a search, open the <strong>Stats</strong> tab for <strong>frequency distributions</strong> and other quantitative measures.
					FlexiCorp decouples TEITOK from any single backend; in most corpora you will use one configured backend/index. The active backend is shown in the top bar.
				</p>
				<p class="flexicorp-panel-note">
					<strong>CWB/CQP structural fields</strong> (names like <code>text_genre</code> from TEITOK’s <code>cqp/sattributes</code>) are <em>document-level</em> s-attributes, not token positional attributes.
					They work in the frequency field list because flexicorp uses CQP <code>group … match …</code> on the indexed name.
					In <strong>search queries</strong>, <code>[form="…" &amp; text_genre="…"]</code> is usually wrong: <code>text_genre</code> is not a token attribute inside <code>[ ]</code>.
					Filter by text-level metadata using CQP patterns for s-attributes (often involving regions or the <code>match</code> anchor); see the CWB CQP manual section on structural attributes.
				</p>';
		}
	}

	if ( !function_exists('tt_flexicorp_stats_settings_freq_limit_row') ) {
		/**
		 * Settings dialog row: default limit for frequency-distribution requests.
		 */
		function tt_flexicorp_stats_settings_freq_limit_row() {
			return '
					<div class="flexicorp-form-row">
						<label for="fc-settings-freq">Default limit for frequency distributions</label>
						<input id="fc-settings-freq" type="number" min="1" max="200" name="freq_limit" x-model="settingsDraft.freqLimit">
					</div>';
		}
	}

	if ( !function_exists('tt_flexicorp_stats_tab_panel_html') ) {
		/**
		 * Main “Stats” tab panel: quantitative analysis (frequency distribution, placeholders for further measures).
		 *
		 * @param array|null $info_result flexicorp info corpus result (for corpus-first field list).
		 * @param string|null $project_root For reading pando/corpus.info when info is thin.
		 * @param string|null $backend
		 */
		function tt_flexicorp_stats_tab_panel_html( $actionName, $info_result = null, $project_root = null, $backend = null, $stats_geo_map_json = '{}' ) {
			$action = tt_flexicorp_h( $actionName );
			$fieldOptionsHtml   = tt_flexicorp_freq_field_options_html( $info_result, $project_root, $backend );
			$freqFieldCheckHtml = tt_flexicorp_freq_field_frequency_checkboxes_html( $info_result, $project_root, $backend );
			$stats_geo_map_json = is_string( $stats_geo_map_json ) ? $stats_geo_map_json : '{}';
			if ( $stats_geo_map_json === '' ) {
				$stats_geo_map_json = '{}';
			}
			$stats_maps_module_panel = '';
			$stats_contrast_module_panel = '';
			$stats_dcoll_module_panel = '';
			if ( is_file( __DIR__ . '/advanced_maps.php' ) ) {
				require_once __DIR__ . '/advanced_maps.php';
				if ( function_exists( 'tt_flexicorp_adv_maps_render_stats_panel' ) ) {
					$stats_maps_module_panel = tt_flexicorp_adv_maps_render_stats_panel();
				}
			}
			if ( is_file( __DIR__ . '/advanced_contrast.php' ) ) {
				require_once __DIR__ . '/advanced_contrast.php';
				if ( function_exists( 'tt_flexicorp_contrast_render_stats_panel' ) ) {
					$stats_contrast_module_panel = tt_flexicorp_contrast_render_stats_panel();
				}
			}
			if ( is_file( __DIR__ . '/advanced_dcoll.php' ) ) {
				require_once __DIR__ . '/advanced_dcoll.php';
				if ( function_exists( 'tt_flexicorp_adv_dcoll_render_stats_panel' ) ) {
					$stats_dcoll_module_panel = tt_flexicorp_adv_dcoll_render_stats_panel();
				}
			}
			$html = <<<'HTML'
		<section class="flexicorp-panel" x-show="activeTab === 'frequency'">
			<div class="flexicorp-panel-title-row">
				<h2 class="flexicorp-panel-title">Quantitative analysis</h2>
				<div style="display:flex;gap:0.35rem;align-items:center;flex-wrap:wrap;position:relative;">
					<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="shareVisualizationSnapshot()" title="Share visualization link" aria-label="Share visualization" style="display:inline-flex;align-items:center;justify-content:center;">
						<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false" style="display:block;">
							<path d="M14 3h7v7h-2V6.41l-7.29 7.3-1.42-1.42 7.3-7.29H14V3z" fill="currentColor"></path>
							<path d="M5 5h7v2H7v10h10v-5h2v7H5V5z" fill="currentColor"></path>
						</svg>
					</button>
					<button type="button" class="btn btn-sm btn-outline-secondary" x-show="hasRaw('frequency')" x-on:click="openResponse('frequency', 'Frequency distribution response')">Raw</button>
					<div x-show="visualizationShareOpen" x-on:click.outside="closeVisualizationShare()" style="position:absolute;right:0;top:2.1rem;z-index:40;background:#fff;border:1px solid rgba(0,0,0,0.15);border-radius:0.4rem;padding:0.55rem;min-width:20rem;box-shadow:0 8px 18px rgba(0,0,0,0.12);">
						<div class="flexicorp-panel-note" style="margin:0 0 0.35rem 0;"><strong>Share visualization</strong></div>
						<input type="text" readonly x-model="visualizationShareUrl" class="flexicorp-form-control flexicorp-form-control-sm" style="width:100%;margin-bottom:0.45rem;">
						<div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
							<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="copyVisualizationShareUrl()">Copy URL</button>
							<button type="button" class="btn btn-sm btn-outline-secondary" x-show="canNativeShareVisualization()" x-on:click="nativeShareVisualization()">Share…</button>
							<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="emailVisualizationShare()">Email</button>
							<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="closeVisualizationShare()">Close</button>
						</div>
						<p class="flexicorp-panel-note" x-show="visualizationShareStatus" x-text="visualizationShareStatus" style="margin:0.4rem 0 0 0;"></p>
					</div>
				</div>
			</div>
			<p class="flexicorp-panel-note" x-show="statsSearchHasHits()">{%These statistics use your last successful search.}</p>
			<p class="flexicorp-panel-note" x-show="!search.ran">{%Run a search first, then open this tab to see statistics for your results.}</p>
			<p class="flexicorp-panel-note" x-show="search.ran && !statsSearchHasHits()">{%Your search returned no matches. Statistics need at least one hit — try a broader query.}</p>

			<div class="flexicorp-stats-base-query" x-show="statsSearchHasHits()" x-init="statsSearchScopeRehydrateFromSearch()">
				<div class="flexicorp-stats-base-query__label">{%Search scope}</div>
				<div style="margin:0.25rem 0 0.45rem 0;">
					<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="statsOpenQueriesEditor()">{%Edit}</button>
				</div>
				<div class="flexicorp-stats-search-scope" style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:0.35rem;">
					<template x-for="row in (statsSearchScopeStore && statsSearchScopeStore.queries) ? statsSearchScopeStore.queries : []" :key="row.id">
						<div
							class="flexicorp-stats-search-scope__row"
							style="display:inline-flex;align-items:flex-start;gap:0.35rem;"
							:class="row.active === false ? 'flexicorp-stats-search-scope__row--muted' : ''"
						>
							<div
								class="flexicorp-stats-base-query__query flexicorp-query-highlight-code"
								style="display:inline-block;max-width:min(100%,46rem);border:1px solid rgba(0,0,0,0.08);border-radius:0.3rem;padding:0.35rem 0.45rem;cursor:pointer;white-space:pre-wrap;"
								x-html="statsSearchScopeCardHtml(row)"
								x-on:click="statsSearchScopeCardClick(row)"
								:title="row.active === false ? 'Click to enable this query in scope' : 'Click to disable this query in scope'"
							></div>
							<span aria-hidden="true" style="font-weight:600;opacity:0.75;align-self:center;">;</span>
						</div>
					</template>
				</div>
				<p class="flexicorp-panel-note" style="margin-top:0.45rem;">{%Queries available for statistics. Statistics options will appear based on the number and type of queries.}</p>
			</div>
			<p class="flexicorp-panel-note" x-show="!search.ran && statsSubTab !== 'corpus'">{%Run a search first. Frequency, collocations, and other quantitative tools use that query as their scope.}</p>
			<p class="flexicorp-panel-note" x-show="search.ran && !statsSearchHasHits() && statsSubTab !== 'corpus'">{%Your search returned no matches. Frequency, collocations, and other quantitative tools need at least one hit.}</p>

			<div class="flexicorp-stats-subtabs" role="tablist" aria-label="{%Quantitative analysis type}">
				<button type="button" role="tab" class="flexicorp-tab flexicorp-tab--sub" :class="statsSubTab === 'corpus' ? 'flexicorp-tab--active' : ''" :aria-selected="statsSubTab === 'corpus' ? 'true' : 'false'" x-on:click.prevent="setStatsSubTab('corpus'); if (typeof fetchCorpusStatsInfo === 'function') fetchCorpusStatsInfo(true);">{%Corpus stats}</button>
				<button type="button" role="tab" class="flexicorp-tab flexicorp-tab--sub" x-show="statsCanShowQueriesSubtab()" :class="statsSubTab === 'queries' ? 'flexicorp-tab--active' : ''" :aria-selected="statsSubTab === 'queries' ? 'true' : 'false'" x-on:click.prevent="setStatsSubTab('queries')">{%Queries}</button>
				<button type="button" role="tab" class="flexicorp-tab flexicorp-tab--sub" :class="statsSubTab === 'freq' ? 'flexicorp-tab--active' : ''" :aria-selected="statsSubTab === 'freq' ? 'true' : 'false'" :disabled="!statsSearchHasHits()" x-on:click.prevent="setStatsSubTab('freq')">{%Frequency}</button>
				<button type="button" role="tab" class="flexicorp-tab flexicorp-tab--sub" x-show="statsCapabilityCollocations()" :class="statsSubTab === 'coll' ? 'flexicorp-tab--active' : ''" :aria-selected="statsSubTab === 'coll' ? 'true' : 'false'" :disabled="!statsSearchHasHits()" x-on:click.prevent="setStatsSubTab('coll')">{%Collocations}</button>
				<template x-for="mod in availableStatsModules()" :key="'sm-' + mod.id">
					<button
						type="button"
						role="tab"
						class="flexicorp-tab flexicorp-tab--sub"
						:class="statsSubTab === mod.id ? 'flexicorp-tab--active' : ''"
						:aria-selected="statsSubTab === mod.id ? 'true' : 'false'"
						:disabled="!statsSearchHasHits()"
						x-on:click.prevent="setStatsSubTab(mod.id)"
						x-text="mod.label"
					></button>
				</template>
				<button type="button" role="tab" class="flexicorp-tab flexicorp-tab--sub" x-show="statsCapabilityOther()" :class="statsSubTab === 'other' ? 'flexicorp-tab--active' : ''" :aria-selected="statsSubTab === 'other' ? 'true' : 'false'" :disabled="!statsSearchHasHits()" x-on:click.prevent="setStatsSubTab('other')" :title="other && other.operation ? ('{%Aggregation: }' + other.operation) : '{%Other aggregation result}'"><span>{%Other}</span></button>
			</div>
			<span id="flexicorp-stats-maps-bootstrap" class="flexicorp-hidden" data-flexicorp-maps-geo-config="__FLEXICORP_STATS_MAPS_GEO_JSON__" style="display:none;" aria-hidden="true"></span>

			<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'queries'">
				<div class="flexicorp-stats-block">
					<h3 class="flexicorp-subtitle">{%Queries}</h3>
					<p class="flexicorp-panel-note">{%Edit names and query text here. Search Scope cards are only for quick on/off toggling.}</p>
					<div style="display:flex;flex-direction:column;gap:0.45rem;" x-init="statsSearchScopeRehydrateFromSearch()">
						<template x-for="row in (statsSearchScopeStore && statsSearchScopeStore.queries) ? statsSearchScopeStore.queries : []" :key="'qed-' + row.id">
							<div style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:flex-start;border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.45rem;">
								<input type="text" class="form-control form-control-sm" style="max-width:10rem;" placeholder="{%Name}" x-model="row.name" x-on:blur="statsSearchScopeRenameCommitted(row)">
								<textarea class="form-control form-control-sm" style="flex:1 1 18rem;min-height:2.8rem;resize:vertical;font-family:ui-monospace,monospace;" rows="2" x-model="row.text" x-on:blur="statsSearchScopePersistToSearch()"></textarea>
								<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="statsSearchScopeToggleActive(row.id, !(row.active !== false))" x-text="row.active === false ? 'Enable' : 'Disable'"></button>
								<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="statsSearchScopeRemoveRow(row.id)" :disabled="!statsSearchScopeStore || statsSearchScopeStore.queries.length <= 1">Remove</button>
							</div>
						</template>
						<div>
							<button type="button" class="btn btn-sm btn-outline-primary" x-on:click="statsSearchScopeAddRow()">+ {%Add query}</button>
						</div>
					</div>
				</div>
				<div class="flexicorp-stats-block" style="margin-top:0.65rem;">
					<h3 class="flexicorp-subtitle">{%Recent}</h3>
					<template x-if="!statsNamedSourcesRecent().length">
						<p class="flexicorp-panel-note">{%No recent queries for this query language yet.}</p>
					</template>
					<ul class="list-unstyled" style="margin:0;max-height:12rem;overflow:auto;">
						<template x-for="rec in statsNamedSourcesRecent()" :key="'sr-' + rec.name + '-' + (rec.query || '').slice(0,12)">
							<li style="margin:0.25rem 0;">
								<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="statsAddNamedSourcePick(rec)">{%Add}</button>
								<code style="margin-left:0.35rem;" x-text="(rec.query || '').length > 96 ? (rec.query.slice(0,95) + '…') : (rec.query || '')"></code>
							</li>
						</template>
					</ul>
				</div>
				<div class="flexicorp-stats-block" style="margin-top:0.65rem;">
					<h3 class="flexicorp-subtitle">{%Stored (TEITOK)}</h3>
					<template x-if="!statsNamedSourcesStored().length">
						<p class="flexicorp-panel-note">{%No stored queries for this query language.}</p>
					</template>
					<ul class="list-unstyled" style="margin:0;max-height:14rem;overflow:auto;">
						<template x-for="rec in statsNamedSourcesStored()" :key="'ss-' + rec.name + '-' + (rec.query || '').slice(0,12)">
							<li style="margin:0.25rem 0;">
								<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="statsAddNamedSourcePick(rec)">{%Add}</button>
								<span style="margin-left:0.35rem;font-weight:600;" x-text="rec.name"></span>
								<code style="margin-left:0.35rem;" x-text="(rec.query || '').length > 96 ? (rec.query.slice(0,95) + '…') : (rec.query || '')"></code>
							</li>
						</template>
					</ul>
				</div>
			</div>

			<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'freq'">
			<div class="flexicorp-stats-block">
				<h3 class="flexicorp-subtitle">Frequency distribution</h3>
				<p class="flexicorp-panel-note">Select one or more fields, then count the results of the querie(s) above by those fields.</p>
				<div class="flexicorp-stats-base-query" x-show="statsShouldShowAggregationCommand()" :class="statsHasMultipleAggregationClauses() ? 'flexicorp-stats-base-query--aa-multi' : ''" :title="statsHasMultipleAggregationClauses() ? statsMultiAaNotice() : null">
					<div class="flexicorp-stats-base-query__label">Aggregation command</div>
					<div class="flexicorp-stats-base-query__query flexicorp-query-highlight-code" x-html="statsAggregationClauseDisplayHtml()"></div>
					<p class="flexicorp-panel-note" style="margin-top:0.35rem;" x-show="statsFrequencyFieldLabelsLine()" x-text="statsFrequencyFieldLabelsLine()"></p>
				</div>
				<div class="flexicorp-stats-base-query flexicorp-stats-base-query--note" x-show="statsSearchHasHits() && !statsShouldShowAggregationCommand() && frequency.ran && statsFrequencyFieldLabelsLine()">
					<p class="flexicorp-panel-note" style="margin:0;" x-text="statsFrequencyFieldLabelsLine()"></p>
				</div>
				<form action="" method="post" class="flexicorp-form" x-on:submit.prevent="submitFrequency($event)">
					<input type="hidden" name="action" value="__FLEXICORP_ACTION__">
					<input type="hidden" name="backend" :value="settings.backend">
					<input type="hidden" name="blacklab_url" :value="backendOverrides.blacklab_url || ''">
					<input type="hidden" name="blacklab_corpus" :value="backendOverrides.blacklab_corpus || ''">
					<input type="hidden" name="blacklab_user" :value="backendOverrides.blacklab_user || ''">
					<input type="hidden" name="blacklab_password" :value="backendOverrides.blacklab_password || ''">
					<input type="hidden" name="blacklab_field" :value="backendOverrides.blacklab_field || ''">
					<input type="hidden" name="docs_limit" :value="settings.docsLimit">
					<input type="hidden" name="kwic_limit" :value="settings.kwicLimit">
					<input type="hidden" name="window" :value="settings.kwicWindow">
					<input type="hidden" name="active_tab" value="frequency">
					<input type="hidden" name="run" value="freq">
					<div x-show="!frequency.ran || frequencyShowSetupForm">
					<div class="flexicorp-freq-setup">
						<div class="flexicorp-freq-setup__fields">
							<div class="flexicorp-form-row">
								<span class="flexicorp-form-row__label" id="fc-freq-field-group">Field(s)</span>
								__FREQ_FIELD_CHECKBOXES__
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-freq-limit">Limit</label>
								<input id="fc-freq-limit" type="number" min="1" max="200" name="freq_limit" x-model="frequency.limit">
							</div>
						</div>
						<div class="flexicorp-freq-setup__actions">
							<button type="button" class="btn btn-sm btn-primary flexicorp-freq-setup__run" x-on:click="submitFrequencyFromButton($el)" :disabled="isLoading('frequency') || isBusy() || (!statsSearchHasHits()) || !frequencyFieldSelectionOk()" x-text="isLoading('frequency') ? 'Executing…' : 'Run frequency distribution'"></button>
						</div>
					</div>
					</div>
				</form>
				<div class="flexicorp-form-row flexicorp-query-collapsed-row" x-show="frequency.ran && !frequencyShowSetupForm && frequency.rows.length && !callHasErrors(frequency.response)">
					<div class="flexicorp-query-display-row" style="display:flex;align-items:flex-start;gap:0.75rem;flex-wrap:wrap;">
						<p class="flexicorp-panel-note flexicorp-search-summary" style="margin:0;flex:1 1 14rem;">
							<span x-text="frequencySetupSummaryText()"></span>
						</p>
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="frequencyShowSetupForm = true">Change</button>
					</div>
				</div>
				<div class="flexicorp-freq-viz-wrap" x-show="frequency.ran && frequency.rows.length && !callHasErrors(frequency.response)">
							<div class="flexicorp-freq-setup__viz">
								<div class="flexicorp-freq-setup__viz-row flexicorp-freq-setup__viz-row--primary">
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Frequency view">
										<span class="flexicorp-inline-label">View</span>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'table' }" :aria-pressed="frequencyVizMode === 'table' ? 'true' : 'false'" x-on:click="setFrequencyVizMode('table')">Table</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'pivot' }" :aria-pressed="frequencyVizMode === 'pivot' ? 'true' : 'false'" x-show="$data.frequencyFieldsArray().length >= 2" x-on:click="setFrequencyVizMode('pivot')" title="Pivot table (crosstab)">Pivot</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'bar' }" :aria-pressed="frequencyVizMode === 'bar' ? 'true' : 'false'" x-on:click="setFrequencyVizMode('bar')" title="Horizontal bar chart (Chart.js)">H-bar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'stacked-bar' }" :aria-pressed="frequencyVizMode === 'stacked-bar' ? 'true' : 'false'" x-show="$data.frequencyHasCompareQueries()" x-on:click="setFrequencyVizMode('stacked-bar')" title="Stacked horizontal bar chart (Chart.js)">H-Stack</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'vbar' }" :aria-pressed="frequencyVizMode === 'vbar' ? 'true' : 'false'" x-on:click="setFrequencyVizMode('vbar')" title="Vertical bar chart (Chart.js)">V-bar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'vbar-error' }" :aria-pressed="frequencyVizMode === 'vbar-error' ? 'true' : 'false'" x-show="!$data.frequencyHasCompareQueries() && $data.frequencySupportsErrorBars()" x-on:click="setFrequencyVizMode('vbar-error')" title="Vertical bars with 95% uncertainty intervals from count/subcorpus size">Error bars</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'stacked-vbar' }" :aria-pressed="frequencyVizMode === 'stacked-vbar' ? 'true' : 'false'" x-show="$data.frequencyHasCompareQueries()" x-on:click="setFrequencyVizMode('stacked-vbar')" title="Stacked vertical bar chart (Chart.js)">V-Stack</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'radar' }" :aria-pressed="frequencyVizMode === 'radar' ? 'true' : 'false'" x-show="$data.frequencyHasCompareQueries()" x-on:click="setFrequencyVizMode('radar')" title="Radar chart (Chart.js)">Radar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'line' }" :aria-pressed="frequencyVizMode === 'line' ? 'true' : 'false'" x-on:click="setFrequencyVizMode('line')" title="Line chart (Chart.js)">Line</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'polar' }" :aria-pressed="frequencyVizMode === 'polar' ? 'true' : 'false'" x-show="!$data.frequencyHasCompareQueries()" x-on:click="setFrequencyVizMode('polar')" title="Polar area chart (Chart.js)">Polar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'pie' }" :aria-pressed="frequencyVizMode === 'pie' ? 'true' : 'false'" x-show="!$data.frequencyHasCompareQueries()" x-on:click="setFrequencyVizMode('pie')" title="Pie chart (Chart.js)">Pie</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyVizMode === 'doughnut' }" :aria-pressed="frequencyVizMode === 'doughnut' ? 'true' : 'false'" x-show="!$data.frequencyHasCompareQueries()" x-on:click="setFrequencyVizMode('doughnut')" title="Donut chart (Chart.js)">Donut</button>
									</div>
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="frequencyVizMode !== 'table' && frequencyChartLibraryAvailable()">
										<span class="flexicorp-inline-label">Export</span>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadFrequencyChartPNG()" title="Download chart as PNG">PNG</button>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadFrequencyChartJPEG()" title="Download chart as JPEG">JPEG</button>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadFrequencyChartPDF()" title="Download chart as PDF (or print dialog if PDF library fails)">PDF</button>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadFrequencyChartPrintForPdf()" title="Open chart in a new window for printing / Save as PDF">Print…</button>
									</div>
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="frequencyVizMode === 'table'">
										<span class="flexicorp-inline-label">Export</span>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadFrequencyTableCSV()" title="Download table as CSV">CSV</button>
									</div>
								</div>
								<div class="flexicorp-freq-setup__viz-row flexicorp-freq-setup__viz-row--scale" x-show="$data.frequencySupportsRelativeScale() || (frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot')">
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Value scale">
										<template x-if="$data.frequencySupportsRelativeScale() && frequencyVizMode !== 'table'">
											<span class="flexicorp-inline-label" x-text="frequencyVizMode === 'table' ? 'Values' : 'Y values'"></span>
										</template>
										<template x-if="$data.frequencySupportsRelativeScale() && frequencyVizMode !== 'table'">
											<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyChartValueScale === 'relative' }" :aria-pressed="frequencyChartValueScale === 'relative' ? 'true' : 'false'" x-on:click="setFrequencyChartValueScale('relative')" title="IPM (or % of hits) — default for fair comparison across groups">Relative</button>
										</template>
										<template x-if="$data.frequencySupportsRelativeScale() && frequencyVizMode !== 'table'">
											<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyChartValueScale === 'absolute' }" :aria-pressed="frequencyChartValueScale === 'absolute' ? 'true' : 'false'" x-on:click="setFrequencyChartValueScale('absolute')" title="Raw hit counts per value">Absolute</button>
										</template>
										<template x-if="frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot'">
											<span class="flexicorp-inline-label" x-bind:style="$data.frequencySupportsRelativeScale() ? 'margin-left:0.6rem;' : ''">Order</span>
										</template>
										<template x-if="frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot'">
											<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyChartOrder === 'size' }" :aria-pressed="frequencyChartOrder === 'size' ? 'true' : 'false'" x-on:click="setFrequencyChartOrder('size')" x-bind:title="frequencyChartValueScale === 'relative' ? 'Sort by relative size (IPM/%)' : 'Sort by absolute size (raw count)'">Size</button>
										</template>
										<template x-if="frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot'">
											<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyChartOrder === 'value' }" :aria-pressed="frequencyChartOrder === 'value' ? 'true' : 'false'" x-on:click="setFrequencyChartOrder('value')" title="Sort by category value (natural/alphanumeric)">Natural</button>
										</template>
										<template x-if="frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot' && $data.frequencyCanUseTimeOrder()">
											<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': frequencyChartOrder === 'time' }" :aria-pressed="frequencyChartOrder === 'time' ? 'true' : 'false'" x-on:click="setFrequencyChartOrder('time')" title="Sort by temporal value when labels look like years/decades/centuries">Time</button>
										</template>
									</div>
								</div>
							</div>
				</div>
				<p class="flexicorp-panel-note" x-show="!((search.query || '').trim()) && !((search.value || '').trim())">Run a search query first to enable frequency distribution.</p>
				<p class="flexicorp-panel-note" x-show="isLoading('frequency')">executing</p>
				<div class="flexicorp-call-errors" x-show="frequency.ran && callHasIssues(frequency.response)">
					<template x-for="msg in callMessages(frequency.response)" :key="msg">
						<p class="wrong" x-text="msg"></p>
					</template>
				</div>
				<div x-show="frequency.ran">
					<div class="flexicorp-stats-freq-results-head">
						<div class="flexicorp-stats-freq-results-head__top flexicorp-stats-freq-results-head__top--title-only">
							<h4 class="flexicorp-subtitle flexicorp-stats-freq-results-head__title">Frequency distribution — results</h4>
						</div>
						<div class="flexicorp-stats-freq-table-controls" x-show="frequency.rows.length && frequencyVizMode === 'table'" style="margin-top: 0.5rem; margin-bottom: 0.5rem; display: flex; justify-content: flex-end;">
							<input type="text" x-model="frequencyTableSearch" placeholder="Filter rows..." class="flexicorp-form-control flexicorp-form-control-sm" style="max-width: 250px;">
						</div>
						<div class="flexicorp-stats-freq-table-controls" x-show="frequency.rows.length && frequencyVizMode === 'table' && $data.frequencyHasCompareQueries()" style="margin-top: 0.25rem; margin-bottom: 0.5rem; display: flex; justify-content: flex-end; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
							<span class="flexicorp-inline-label">Columns</span>
							<template x-for="opt in $data.frequencyCompareMetricOptions()" :key="'cmp-col-opt-' + opt.key">
								<label x-show="opt.available" style="display:inline-flex;align-items:center;gap:0.2rem;">
									<input type="checkbox" :checked="$data.isFrequencyCompareMetricSelected(opt.key)" x-on:change="$data.onFrequencyCompareMetricToggle(opt.key, $event.target.checked)">
									<span x-text="opt.label"></span>
								</label>
							</template>
						</div>
						<p class="flexicorp-panel-note flexicorp-freq-viz-note" x-show="frequency.rows.length && !frequencyChartLibraryAvailable() && !callHasErrors(frequency.response)">Chart views need the Chart.js script (check network / CSP).</p>
						<p class="flexicorp-panel-note" x-show="!callHasErrors(frequency.response) && (frequency.returned !== null || frequency.total !== null)"
						   x-text="$data.frequencyDistributionMetaLine()"></p>
					</div>
					<p class="flexicorp-panel-note" x-show="frequency.rows.length && frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot' && !callHasErrors(frequency.response)">
						Charts use your frequency limit and support ordering by <strong>Size</strong>, <strong>Natural</strong>, or <strong>Time</strong>. <strong>Others</strong> remains at the end (except in Time order). Relative value scale options are shown when subcorpus size/relative metrics are available; <strong>Absolute</strong> uses raw counts.
					</p>
					<p class="flexicorp-panel-note" x-show="frequency.rows.length && frequencyVizMode === 'vbar-error' && !callHasErrors(frequency.response)">
						Error bars show 95% uncertainty intervals estimated from each row's count and subcorpus size.
					</p>
					<p class="flexicorp-panel-note" x-show="frequency.rows.length && frequencyVizMode === 'pivot' && !callHasErrors(frequency.response)">
						Pivot table showing the intersection of fields. Values use the Y-axis scale setting: <strong>Relative</strong> (IPM or %) vs <strong>Absolute</strong> (raw counts).
					</p>
					<table class="flexicorp-table" x-show="frequency.rows.length && frequencyVizMode === 'table'">
						<thead>
							<tr>
								<template x-for="(fName, fIdx) in $data.frequencyFieldsArray()" :key="'fh-' + fIdx">
									<th style="cursor:pointer;" x-on:click="setFrequencyTableSort('value')">
										<span x-text="fName"></span>
										<span x-show="frequencyTableSort.col === 'value'" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
									</th>
								</template>
								<template x-if="!$data.frequencyHasCompareQueries()">
									<th style="cursor:pointer;" x-on:click="setFrequencyTableSort('count')">
										<span>Count</span>
										<span x-show="frequencyTableSort.col === 'count'" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
									</th>
								</template>
								<template x-if="!$data.frequencyHasCompareQueries()">
									<th style="cursor:pointer;" x-show="$data.frequencyHasProvidedPct()" x-on:click="setFrequencyTableSort('pct')">
										<span>%</span>
										<span x-show="frequencyTableSort.col === 'pct'" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
									</th>
								</template>
								<template x-if="!$data.frequencyHasCompareQueries()">
									<th style="cursor:pointer;" x-show="$data.frequencyHasNormalizedIpm()" x-on:click="setFrequencyTableSort('nipm')">
										<span>Normalized (IPM)</span>
										<span x-show="frequencyTableSort.col === 'nipm'" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
									</th>
								</template>
								
								<template x-if="$data.frequencyHasCompareQueries()">
									<template x-for="q in $data.frequencyCompareQueries()" :key="'ch-' + q">
										<th style="cursor:pointer;" x-show="$data.frequencyCompareMetricVisible('count')" x-on:click="setFrequencyTableSort('count_' + q)">
											<span x-text="q + ' Count'"></span>
											<span x-show="frequencyTableSort.col === 'count_' + q" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
										</th>
									</template>
								</template>
								<template x-if="$data.frequencyHasCompareQueries()">
									<template x-for="q in $data.frequencyCompareQueries()" :key="'ch-pct-' + q">
										<th style="cursor:pointer;" x-show="$data.frequencyCompareMetricVisible('pct')" x-on:click="setFrequencyTableSort('pct_' + q)">
											<span x-text="q + ' %'"></span>
											<span x-show="frequencyTableSort.col === 'pct_' + q" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
										</th>
									</template>
								</template>
								<template x-if="$data.frequencyHasCompareQueries()">
									<template x-for="q in $data.frequencyCompareQueries()" :key="'ch-relf-' + q">
										<th style="cursor:pointer;" x-show="$data.frequencyCompareMetricVisible('relf')" x-on:click="setFrequencyTableSort('relf_' + q)">
											<span x-text="q + ' RelFreq %'"></span>
											<span x-show="frequencyTableSort.col === 'relf_' + q" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
										</th>
									</template>
								</template>
								<template x-if="$data.frequencyHasCompareQueries()">
									<template x-for="q in $data.frequencyCompareQueries()" :key="'ch-ipm-' + q">
										<th style="cursor:pointer;" x-show="$data.frequencyCompareMetricVisible('nipm')" x-on:click="setFrequencyTableSort('nipm_' + q)">
											<span x-text="q + ' (Normalized IPM)'"></span>
											<span x-show="frequencyTableSort.col === 'nipm_' + q" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
										</th>
									</template>
								</template>
								<template x-if="$data.frequencyHasCompareQueries()">
									<template x-for="q in $data.frequencyCompareQueries()" :key="'ch-ripm-' + q">
										<th style="cursor:pointer;" x-show="$data.frequencyCompareMetricVisible('ripm')" x-on:click="setFrequencyTableSort('ripm_' + q)">
											<span x-text="q + ' (Relative Subcorpus IPM)'"></span>
											<span x-show="frequencyTableSort.col === 'ripm_' + q" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
										</th>
									</template>
								</template>

								<th x-show="$data.frequencyHasProvidedSubcorpusSize()">Subcorpus size</th>
								<th style="cursor:pointer;" x-show="!$data.frequencyHasCompareQueries() && $data.frequencyHasRelativeSubcorpusIpm()" x-on:click="setFrequencyTableSort('ripm')">
									<span>Relative (Subcorpus IPM)</span>
									<span x-show="frequencyTableSort.col === 'ripm'" x-text="frequencyTableSort.asc ? ' ↑' : ' ↓'"></span>
								</th>
							</tr>
						</thead>
						<tbody>
							<template x-for="(row, idx) in frequencyDisplayRows()" :key="idx">
								<tr>
									<template x-for="(colVal, cIdx) in $data.frequencyRowValuesArray(row)" :key="'fc-' + idx + '-' + cIdx">
										<td x-text="colVal"></td>
									</template>
									
									<template x-if="!$data.frequencyHasCompareQueries()">
										<td x-text="row.count || ''"></td>
									</template>
									<template x-if="!$data.frequencyHasCompareQueries()">
										<td x-show="$data.frequencyHasProvidedPct()" x-text="$data.frequencyRowPercent(row)"></td>
									</template>
									<template x-if="!$data.frequencyHasCompareQueries()">
										<td x-show="$data.frequencyHasNormalizedIpm()" x-text="$data.frequencyRowNormalizedIpm(row)"></td>
									</template>

									<template x-if="$data.frequencyHasCompareQueries()">
										<template x-for="q in $data.frequencyCompareQueries()" :key="'cc-' + idx + '-' + q">
											<td x-show="$data.frequencyCompareMetricVisible('count')" x-text="$data.frequencyRowQueryCount(row, q)"></td>
										</template>
									</template>
									<template x-if="$data.frequencyHasCompareQueries()">
										<template x-for="q in $data.frequencyCompareQueries()" :key="'cc-pct-' + idx + '-' + q">
											<td x-show="$data.frequencyCompareMetricVisible('pct')" x-text="$data.frequencyRowQueryPct(row, q)"></td>
										</template>
									</template>
									<template x-if="$data.frequencyHasCompareQueries()">
										<template x-for="q in $data.frequencyCompareQueries()" :key="'cc-relf-' + idx + '-' + q">
											<td x-show="$data.frequencyCompareMetricVisible('relf')" x-text="$data.frequencyRowQueryDerivedRelfreq(row, q)"></td>
										</template>
									</template>
									<template x-if="$data.frequencyHasCompareQueries()">
										<template x-for="q in $data.frequencyCompareQueries()" :key="'cc-ipm-' + idx + '-' + q">
											<td x-show="$data.frequencyCompareMetricVisible('nipm')" x-text="$data.frequencyRowQueryNormalizedIpm(row, q)"></td>
										</template>
									</template>
									<template x-if="$data.frequencyHasCompareQueries()">
										<template x-for="q in $data.frequencyCompareQueries()" :key="'cc-ripm-' + idx + '-' + q">
											<td x-show="$data.frequencyCompareMetricVisible('ripm')" x-text="$data.frequencyRowQueryRelativeSubcorpusIpm(row, q)"></td>
										</template>
									</template>

									<td x-show="$data.frequencyHasProvidedSubcorpusSize()" x-text="$data.frequencyRowSubcorpusSize(row)"></td>
									<td x-show="!$data.frequencyHasCompareQueries() && $data.frequencyHasRelativeSubcorpusIpm()" x-text="$data.frequencyRowRelativeSubcorpusIpm(row)"></td>
								</tr>
							</template>
							<tr x-show="$data.frequencyHasRestBucket() && !$data.frequencyHasCompareQueries()" class="flexicorp-row-rest">
								<td :colspan="$data.frequencyFieldsArray().length">Others</td>
								<td x-text="$data.frequencyRestCount()"></td>
								<td x-show="$data.frequencyHasProvidedPct()" x-text="$data.frequencyRestRowPercent()"></td>
								<td x-show="$data.frequencyHasNormalizedIpm()" x-text="$data.frequencyRestRowIpm()"></td>
								<td x-show="$data.frequencyHasProvidedSubcorpusSize()"><span class="flexicorp-na">—</span></td>
								<td x-show="$data.frequencyHasRelativeSubcorpusIpm()"><span class="flexicorp-na">—</span></td>
							</tr>
						</tbody>
					</table>
					<table class="flexicorp-table" x-show="frequency.rows.length && frequencyVizMode === 'pivot'">
						<thead>
							<tr>
								<th x-text="$data.frequencyFieldsArray()[0]"></th>
								<template x-for="colName in $data.frequencyPivotColumns()" :key="'piv-col-' + colName">
									<th x-text="colName"></th>
								</template>
							</tr>
						</thead>
						<tbody>
							<template x-for="rowName in $data.frequencyPivotRows()" :key="'piv-row-' + rowName">
								<tr>
									<th x-text="rowName"></th>
									<template x-for="colName in $data.frequencyPivotColumns()" :key="'piv-cell-' + rowName + '-' + colName">
										<td :style="$data.frequencyPivotCellStyle(rowName, colName)" x-text="$data.frequencyPivotCellValue(rowName, colName)"></td>
									</template>
								</tr>
							</template>
						</tbody>
					</table>
					<div class="flexicorp-freq-chart-wrap" x-show="frequency.rows.length && frequencyVizMode !== 'table' && frequencyVizMode !== 'pivot' && !callHasErrors(frequency.response)">
						<canvas id="flexicorp-freq-chart-canvas" aria-label="Frequency chart"></canvas>
					</div>
					<p class="flexicorp-panel-note" x-show="!frequency.rows.length && !callHasIssues(frequency.response)">No rows returned.</p>
					<!-- Frequency pagination control intentionally hidden: backends usually return the complete set for the configured limit. -->
				</div>
			</div>
			</div>

			<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'coll'">
				<div class="flexicorp-stats-block">
					<div class="flexicorp-panel-title-row">
						<h3 class="flexicorp-subtitle">Collocations</h3>
						<button type="button" class="btn btn-sm btn-outline-secondary" x-show="hasRaw('collocation')" x-on:click="openResponse('collocation', 'Collocation response')">Raw</button>
					</div>
					<p class="flexicorp-panel-note">Calculate words that commonly co-occur with the search query in a left/right context window. For <strong>discontinuous</strong> or <strong>aligned</strong> queries (<code>with</code>, multiple named tokens), choose which named token to count from. Choose the attribute to group collocates by (for example lemma), set the window span, minimum frequency, and maximum rows, then choose one or more association measures. The <strong>first</strong> selected measure drives sorting and chart display.</p>
					<form action="" method="post" class="flexicorp-form" x-on:submit.prevent="submitCollocation($event)">
						<input type="hidden" name="action" value="__FLEXICORP_ACTION__">
						<input type="hidden" name="backend" :value="settings.backend">
						<input type="hidden" name="blacklab_url" :value="backendOverrides.blacklab_url || ''">
						<input type="hidden" name="blacklab_corpus" :value="backendOverrides.blacklab_corpus || ''">
						<input type="hidden" name="blacklab_user" :value="backendOverrides.blacklab_user || ''">
						<input type="hidden" name="blacklab_password" :value="backendOverrides.blacklab_password || ''">
						<input type="hidden" name="blacklab_field" :value="backendOverrides.blacklab_field || ''">
						<input type="hidden" name="docs_limit" :value="settings.docsLimit">
						<input type="hidden" name="kwic_limit" :value="settings.kwicLimit">
						<input type="hidden" name="window" :value="settings.kwicWindow">
						<input type="hidden" name="active_tab" value="frequency">
						<input type="hidden" name="run" value="coll">
						<div class="flexicorp-form-grid">
							<div class="flexicorp-form-row">
								<label for="fc-coll-anchor-token">Count on token</label>
								<select id="fc-coll-anchor-token" name="coll_anchor_token" class="flexicorp-form-control flexicorp-form-control-sm" x-model="collocation.anchorToken">
									<option value="">(unspecified — only safe for a single token span)</option>
									<template x-for="tok in collocationTokenAliasOptions()" :key="'coll-anch-' + tok">
										<option :value="tok" :selected="String(tok) === String(collocation.anchorToken)" x-text="tok"></option>
									</template>
								</select>
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-coll-field">Collocate by</label>
								<select id="fc-coll-field" name="coll_field" x-model="collocation.field">
									__FREQ_FIELD_OPTIONS__
								</select>
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-coll-left">Window left</label>
								<input id="fc-coll-left" type="number" min="0" max="500" name="coll_left" x-model.number="collocation.left">
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-coll-right">Window right</label>
								<input id="fc-coll-right" type="number" min="0" max="500" name="coll_right" x-model.number="collocation.right">
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-coll-min-freq">Min. frequency</label>
								<input id="fc-coll-min-freq" type="number" min="0" max="1000000" name="coll_min_freq" x-model.number="collocation.minFreq">
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-coll-max-items">Max. items</label>
								<input id="fc-coll-max-items" type="number" min="1" max="500" name="coll_max_items" x-model.number="collocation.maxItems">
							</div>
							<div class="flexicorp-form-row">
								<label for="fc-coll-stoplist">Stoplist (top N)</label>
								<input id="fc-coll-stoplist" type="number" min="0" max="1000000" name="coll_stoplist" x-model.number="collocation.stoplist">
							</div>
							<div class="flexicorp-form-row flexicorp-form-row--coll-measures">
								<span class="flexicorp-form-row__label">Association measures</span>
								<div class="flexicorp-coll-measure-checks" role="group" aria-label="Association measures">
									<template x-for="mid in collocationMeasureOrder()" :key="mid">
										<label class="flexicorp-measure-check">
											<input type="checkbox" :value="mid" x-model="collocation.measureKeys" x-on:change="onCollocationMeasureSelectionChanged()">
											<span x-text="mid"></span>
										</label>
									</template>
								</div>
							</div>
						</div>
						<p class="flexicorp-panel-note flexicorp-panel-note--tight">The <strong>primary</strong> metric for sorting and charts is the first in this order among those checked: logdice → mi → mi3 → tscore → ll → dice.</p>
						<div class="flexicorp-form-actions">
							<button type="button" class="btn btn-sm btn-primary" x-on:click="submitCollocationFromButton()" :disabled="isLoading('collocation') || isBusy() || (!statsSearchHasHits())" x-text="isLoading('collocation') ? 'Executing…' : 'Run collocations'"></button>
						</div>
					</form>
					<p class="flexicorp-panel-note" x-show="!((search.query || '').trim()) && !((search.value || '').trim())">Run a search query first to enable collocations.</p>
					<p class="flexicorp-panel-note" x-show="isLoading('collocation')">executing</p>
					<div class="flexicorp-call-errors" x-show="collocation.ran && callHasIssues(collocation.response)">
						<template x-for="msg in callMessages(collocation.response)" :key="msg">
							<p class="wrong" x-text="msg"></p>
						</template>
					</div>
					<div x-show="collocation.ran && !callHasErrors(collocation.response)">
						<div class="flexicorp-stats-freq-results-head" x-show="collocationRows().length">
							<div class="flexicorp-stats-freq-results-head__top">
								<h4 class="flexicorp-subtitle flexicorp-stats-freq-results-head__title">Collocations — results</h4>
								<div class="flexicorp-stats-freq-results-head__controls" x-show="collocationRows().length && !callHasErrors(collocation.response)">
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline">
										<span class="flexicorp-inline-label">View</span>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'table' }" :aria-pressed="collocationVizMode === 'table' ? 'true' : 'false'" x-on:click="setCollocationVizMode('table')">Table</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'bar' }" :aria-pressed="collocationVizMode === 'bar' ? 'true' : 'false'" x-on:click="setCollocationVizMode('bar')" title="Horizontal bar chart (Chart.js)">H-bar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'vbar' }" :aria-pressed="collocationVizMode === 'vbar' ? 'true' : 'false'" x-on:click="setCollocationVizMode('vbar')" title="Vertical bar chart (Chart.js)">V-bar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'line' }" :aria-pressed="collocationVizMode === 'line' ? 'true' : 'false'" x-on:click="setCollocationVizMode('line')" title="Line chart (Chart.js)">Line</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'polar' }" :aria-pressed="collocationVizMode === 'polar' ? 'true' : 'false'" x-on:click="setCollocationVizMode('polar')" title="Polar area chart (Chart.js)">Polar</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'pie' }" :aria-pressed="collocationVizMode === 'pie' ? 'true' : 'false'" x-on:click="setCollocationVizMode('pie')" title="Pie chart (Chart.js)">Pie</button>
										<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': collocationVizMode === 'doughnut' }" :aria-pressed="collocationVizMode === 'doughnut' ? 'true' : 'false'" x-on:click="setCollocationVizMode('doughnut')" title="Donut chart (Chart.js)">Donut</button>
									</div>
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="collocationVizMode !== 'table' && frequencyChartLibraryAvailable()">
										<span class="flexicorp-inline-label">Metric</span>
										<select class="flexicorp-form-control flexicorp-form-control-sm" x-model="collocationChartMetricKey" x-on:change="onCollocationChartMetricChanged()">
											<template x-for="opt in collocationChartMetricOptions()" :key="'coll-chart-metric-' + opt.key">
												<option :value="opt.key" :selected="String(opt.key) === String(collocationChartMetricKey)" x-text="opt.label"></option>
											</template>
										</select>
									</div>
									<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="collocationVizMode !== 'table' && frequencyChartLibraryAvailable()">
										<span class="flexicorp-inline-label">Export</span>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadCollocationChartPNG()" title="Download chart as PNG">PNG</button>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadCollocationChartJPEG()" title="Download chart as JPEG">JPEG</button>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadCollocationChartPDF()" title="Download chart as PDF (or print dialog if PDF library fails)">PDF</button>
										<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadCollocationChartPrintForPdf()" title="Open chart in a new window for printing / Save as PDF">Print…</button>
									</div>
								</div>
							</div>
							<p class="flexicorp-panel-note flexicorp-freq-viz-note" x-show="collocationRows().length && !frequencyChartLibraryAvailable() && collocationVizMode !== 'table'">Chart views need the Chart.js script (check network / CSP).</p>
							<p class="flexicorp-panel-note" x-text="$data.collocationMetaLine()"></p>
						</div>
						<p class="flexicorp-panel-note" x-show="collocationRows().length && collocationVizMode !== 'table' && !callHasErrors(collocation.response)">
							Charts use the selected metric (<strong>Obs</strong> or any returned association measure). Pie / polar / donut use non-negative values only (negative scores are clipped to 0 for slice size).
						</p>
						<table class="flexicorp-table" x-show="collocationRows().length && collocationVizMode === 'table'">
							<thead>
								<tr>
									<th>Word</th>
									<th>Obs</th>
									<th>Freq</th>
									<template x-for="mk in collocationMeasureKeys()" :key="mk">
										<th x-text="mk"></th>
									</template>
								</tr>
							</thead>
							<tbody>
								<template x-for="(row, idx) in collocationRows()" :key="idx">
									<tr>
										<td x-text="row.word != null ? row.word : ''"></td>
										<td x-text="row.obs != null ? row.obs : ''"></td>
										<td x-text="row.freq != null ? row.freq : ''"></td>
										<template x-for="mk in collocationMeasureKeys()" :key="mk + '-' + idx">
											<td x-text="row[mk] != null ? row[mk] : ''"></td>
										</template>
									</tr>
								</template>
								<tr x-show="collocationHasRestBucket()" class="flexicorp-row-rest">
									<td>Others</td>
									<td x-text="collocationRestObsCount()"></td>
									<td><span class="flexicorp-na">—</span></td>
									<template x-for="mk in collocationMeasureKeys()" :key="'coll-rest-' + mk">
										<td><span class="flexicorp-na">—</span></td>
									</template>
								</tr>
							</tbody>
						</table>
						<div class="flexicorp-freq-chart-wrap" x-show="collocationRows().length && collocationVizMode !== 'table' && !callHasErrors(collocation.response)">
							<canvas id="flexicorp-coll-chart-canvas" aria-label="Collocation chart"></canvas>
						</div>
						<p class="flexicorp-panel-note" x-show="!collocationRows().length && !callHasIssues(collocation.response)">No collocates returned.</p>
					</div>
				</div>
			</div>

			<div class="flexicorp-stats-subpanel" x-show="false && statsSubTab === 'dcoll'">
				<div class="flexicorp-stats-block flexicorp-stats-block--placeholder">
					<h3 class="flexicorp-subtitle">D-collocations</h3>
					<p class="flexicorp-panel-note">Dependency-based collocations around hits for the base query above (when the backend exposes this operation). Table / graph views will appear here when the operation is wired up.</p>
				</div>
			</div>

			<!-- "Other" subpanel: generic raw-table view for aggregation operations that
			     don't have a first-class panel yet (count, dist). The PHP
			     side fills `state.other` with { operation, columns, rows, total } from the
			     flexicorp result envelope. No charts / measure pickers — just the table. -->
			<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'other'">
				<div class="flexicorp-stats-block">
					<div class="flexicorp-stats-base-query" x-show="statsShouldShowAggregationCommand()" :class="statsHasMultipleAggregationClauses() ? 'flexicorp-stats-base-query--aa-multi' : ''" :title="statsHasMultipleAggregationClauses() ? statsMultiAaNotice() : null">
						<div class="flexicorp-stats-base-query__label">Aggregation command</div>
						<div class="flexicorp-stats-base-query__query flexicorp-query-highlight-code" x-html="statsAggregationClauseDisplayHtml()"></div>
					</div>
					<div class="flexicorp-panel-title-row">
						<h3 class="flexicorp-subtitle">
							<span x-text="other && other.operation ? (other.operation.charAt(0).toUpperCase() + other.operation.slice(1)) : 'Other aggregation'"></span>
							<span class="flexicorp-subtitle-tag" x-show="other && other.operation" x-text="'(' + (other.operation || '') + ')'"></span>
						</h3>
					</div>
					<p class="flexicorp-panel-note" x-show="isAdmin">
						Raw result table for an aggregation operation that does not yet have a dedicated panel.
						Routed here to avoid silently relabelling it as a frequency. We expect this to
						progressively shrink as count and dist gain first-class views.
					</p>
					<p class="flexicorp-panel-note" x-show="!(other && other.ran)">No aggregation result for the current query. Run a query that ends with <code>count</code> or <code>dist</code> to populate this view.</p>
					<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" x-show="other && other.ran && Array.isArray(other.rows) && other.rows.length > 0" style="margin-bottom:0.5rem;">
						<span class="flexicorp-inline-label">View</span>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': otherVizMode === 'table' }" :aria-pressed="otherVizMode === 'table' ? 'true' : 'false'" x-on:click="setOtherVizMode('table')">Table</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': otherVizMode === 'bar' }" :aria-pressed="otherVizMode === 'bar' ? 'true' : 'false'" x-on:click="setOtherVizMode('bar')">H-bar</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': otherVizMode === 'line' }" :aria-pressed="otherVizMode === 'line' ? 'true' : 'false'" x-on:click="setOtherVizMode('line')">Line</button>
					</div>
					<div class="flexicorp-form-row" x-show="other && other.ran && Array.isArray(other.rows) && other.rows.length > 0 && otherVizMode !== 'table'" style="display:grid;grid-template-columns:minmax(10rem,1fr) minmax(10rem,1fr);gap:0.6rem;max-width:40rem;margin-bottom:0.55rem;">
						<div>
							<label for="fc-other-xcol">X column</label>
							<select id="fc-other-xcol" class="form-control form-control-sm" x-model="otherChartXColumn" x-on:change="onOtherChartColumnsChanged()">
								<template x-for="col in otherColumnsList()" :key="'other-x-' + col">
									<option :value="col" :selected="String(col) === String(otherChartXColumn)" x-text="String(col)"></option>
								</template>
							</select>
						</div>
						<div>
							<label for="fc-other-ycol">Y column (numeric)</label>
							<select id="fc-other-ycol" class="form-control form-control-sm" x-model="otherChartYColumn" x-on:change="onOtherChartColumnsChanged()">
								<template x-for="col in otherNumericColumns().filter(c => c !== otherChartXColumn)" :key="'other-y-' + col">
									<option :value="col" :selected="String(col) === String(otherChartYColumn)" x-text="String(col)"></option>
								</template>
							</select>
						</div>
					</div>
					<div class="flexicorp-table-wrap" x-show="other && other.ran && Array.isArray(other.rows) && other.rows.length > 0">
						<table class="flexicorp-table flexicorp-table--freq" x-show="otherVizMode === 'table'">
							<thead>
								<tr>
									<template x-for="(col, ci) in (Array.isArray(other.columns) && other.columns.length ? other.columns : (other.rows[0] && typeof other.rows[0] === 'object' ? Object.keys(other.rows[0]) : []))" :key="'oc-' + ci + '-' + col">
										<th x-text="String(col)"></th>
									</template>
								</tr>
							</thead>
							<tbody>
								<template x-for="(row, ri) in other.rows" :key="'or-' + ri">
									<tr>
										<template x-for="(col, ci) in (Array.isArray(other.columns) && other.columns.length ? other.columns : (other.rows[0] && typeof other.rows[0] === 'object' ? Object.keys(other.rows[0]) : []))" :key="'oc-' + ri + '-' + ci + '-' + col">
											<td x-text="(() => { const v = Array.isArray(row) ? row[ci] : (row && typeof row === 'object' ? row[col] : row); if (v === null || v === undefined) return ''; if (typeof v === 'object') { try { return JSON.stringify(v); } catch (e) { return String(v); } } return String(v); })()"></td>
										</template>
									</tr>
								</template>
							</tbody>
						</table>
						<div class="flexicorp-freq-chart-wrap" x-show="otherVizMode !== 'table' && frequencyChartLibraryAvailable()">
							<canvas id="flexicorp-other-chart-canvas" aria-label="Other chart"></canvas>
						</div>
						<p class="flexicorp-panel-note" x-show="otherVizMode !== 'table' && !frequencyChartLibraryAvailable()">Chart view needs Chart.js.</p>
					</div>
					<p class="flexicorp-panel-note" x-show="other && other.ran && (!Array.isArray(other.rows) || other.rows.length === 0)">The operation ran but returned no rows.</p>
					<p class="flexicorp-panel-note" x-show="other && other.ran && other.total !== null && other.total !== undefined">Rows: <span x-text="String(other.total)"></span></p>
				</div>
			</div>

			<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'corpus'" x-effect="if (activeTab === 'frequency' && statsSubTab === 'corpus' && typeof ensureCorpusStatsLoaded === 'function') { ensureCorpusStatsLoaded(); }">
				<div class="flexicorp-stats-block">
					<div class="flexicorp-panel-title-row">
						<h3 class="flexicorp-subtitle">Corpus overview</h3>
					</div>
					<p class="flexicorp-panel-note flexicorp-corpus-overview__lead">
						General corpus metadata (not filtered by your search query).
					</p>
					<div class="flexicorp-call-errors" x-show="corpusStatsInfoError()">
						<template x-for="msg in corpusStatsInfoErrorMessages()" :key="msg">
							<div class="flexicorp-call-error" x-text="msg"></div>
						</template>
					</div>
					<div class="flexicorp-corpus-overview" x-show="corpusStatsHasResult()">
						<div class="flexicorp-corpus-overview__section" x-show="corpusStatsGeneralInfoRows().length">
							<h4 class="flexicorp-corpus-overview__h">General corpus information</h4>
							<table class="flexicorp-table">
								<tbody>
									<template x-for="(row, idx) in corpusStatsGeneralInfoRows()" :key="'gi-' + idx">
										<tr>
											<th x-text="row.label"></th>
											<td x-text="row.value"></td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
						<div class="flexicorp-corpus-overview__section" x-show="corpusStatsPattributeRows().length">
							<h4 class="flexicorp-corpus-overview__h">Token attributes</h4>
							<table class="flexicorp-table">
								<thead>
									<tr>
										<th>Raw name</th>
										<th>Display name</th>
										<th>Vocabulary</th>
										<th>Type</th>
									</tr>
								</thead>
								<tbody>
									<template x-for="(row, idx) in corpusStatsPattributeRows()" :key="'p-' + idx">
										<tr>
											<td><code x-text="row.raw"></code></td>
											<td x-text="row.display"></td>
											<td x-text="row.vocab"></td>
											<td x-text="row.type"></td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
						<div class="flexicorp-corpus-overview__section" x-show="corpusStatsRegionGroups().length">
							<h4 class="flexicorp-corpus-overview__h">Regions &amp; structural metadata</h4>
							<template x-for="(grp, idx) in corpusStatsRegionGroups()" :key="'rg-' + idx">
								<div style="margin:0.55rem 0 0.95rem 0;">
									<h5 style="margin:0 0 0.35rem 0;">
										<span x-text="grp.region"></span>
										<span x-show="grp.raw && grp.raw !== grp.region"> (<code x-text="grp.raw"></code>)</span>
									</h5>
									<table class="flexicorp-table" style="margin-bottom:0.4rem;">
										<tbody>
											<tr>
												<th>Region count</th>
												<td x-text="(grp.info && grp.info.count != null && grp.info.count !== '') ? corpusStatsValueType(grp.info.count) : '—'"></td>
												<th>Token count</th>
												<td x-text="(grp.info && grp.info.token_count != null && grp.info.token_count !== '') ? corpusStatsValueType(grp.info.token_count) : '—'"></td>
											</tr>
											<tr>
												<th>Region type</th>
												<td x-text="(grp.info && grp.info.type != null && grp.info.type !== '') ? corpusStatsValueType(grp.info.type) : '—'"></td>
												<th>Span properties</th>
												<td x-text="(grp.info && (grp.info.overlap != null || grp.info.discontinuous != null)) ? ('overlap=' + corpusStatsValueType(grp.info.overlap, '—') + '; discontinuous=' + corpusStatsValueType(grp.info.discontinuous, '—')) : '—'"></td>
											</tr>
										</tbody>
									</table>
									<table class="flexicorp-table" x-show="grp.attrs && grp.attrs.length">
										<thead>
											<tr>
												<th>Raw name</th>
												<th>Display name</th>
												<th>Vocabulary</th>
												<th>Type</th>
											</tr>
										</thead>
										<tbody>
											<template x-for="(a, aidx) in grp.attrs" :key="'ra-' + idx + '-' + aidx">
												<tr>
													<td><code x-text="a.raw"></code></td>
													<td x-text="a.display"></td>
													<td x-text="a.vocab"></td>
													<td x-text="a.type"></td>
												</tr>
											</template>
										</tbody>
									</table>
									<p class="flexicorp-panel-note" x-show="!(grp.attrs && grp.attrs.length)">No region attributes listed.</p>
								</div>
							</template>
						</div>
					</div>
					<p class="flexicorp-panel-note" x-show="!corpusStatsHasResult() && !corpusStatsInfoError()">
						Loading corpus metadata...
					</p>
				</div>
			</div>
			__STATS_MAPS_MODULE_PANEL__
			__STATS_CONTRAST_MODULE_PANEL__
			__STATS_DCOLL_MODULE_PANEL__
		</section>

HTML;
			return strtr(
				$html,
				array(
					'__FLEXICORP_ACTION__'         => $action,
					'__FREQ_FIELD_OPTIONS__'      => $fieldOptionsHtml,
					'__FREQ_FIELD_CHECKBOXES__'   => $freqFieldCheckHtml,
					'__FLEXICORP_STATS_MAPS_GEO_JSON__' => htmlspecialchars( $stats_geo_map_json, ENT_QUOTES, 'UTF-8' ),
					'__STATS_MAPS_MODULE_PANEL__' => $stats_maps_module_panel,
					'__STATS_CONTRAST_MODULE_PANEL__' => $stats_contrast_module_panel,
					'__STATS_DCOLL_MODULE_PANEL__' => $stats_dcoll_module_panel,
				)
			);
		}
	}
