<?php
/**
 * Flexicorp TEITOK overlay: xidx helpers + search response / IPM helpers.
 * Loaded via require_once from flexicorp.php (same directory).
 */

	// --- xidx helpers for XML fragment enrichment (pando adapter path) ---
	if ( !function_exists('tt_flexicorp_xidx_read_lines') ) {
		function tt_flexicorp_xidx_read_lines($path) {
			if ( !is_file($path) ) return array();
			$lines = @file($path, FILE_IGNORE_NEW_LINES);
			return is_array($lines) ? $lines : array();
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_u32le') ) {
		function tt_flexicorp_xidx_u32le($raw) {
			if ( strlen((string)$raw) < 4 ) return null;
			$v = unpack('Vv', $raw);
			return ( is_array($v) && isset($v['v']) ) ? (int)$v['v'] : null;
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_u64le') ) {
		function tt_flexicorp_xidx_u64le($raw) {
			if ( strlen((string)$raw) < 8 ) return null;
			$v = unpack('Pq', $raw);
			return ( is_array($v) && isset($v['q']) ) ? (int)$v['q'] : null;
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_token_record_map') ) {
		function tt_flexicorp_xidx_token_record_map($tokensBin) {
			$map = array();
			if ( !is_file($tokensBin) ) return $map;
			$size = @filesize($tokensBin);
			if ( !is_int($size) || $size <= 0 ) return $map;
			// flexencoder_xidx writes fixed-size 32-byte token records.
			// Prefer 32 when it fits; some corpora have sizes divisible by both 32 and 40
			// (LCM 160), and picking 40 mis-decodes tok_id_idx and shifts highlights.
			$stride = ( $size % 32 === 0 ) ? 32 : ( ( $size % 40 === 0 ) ? 40 : 40 );
			$fh = @fopen($tokensBin, 'rb');
			if ( !$fh ) return $map;
			while ( !feof($fh) ) {
				$rec = (string)@fread($fh, $stride);
				if ( strlen($rec) !== $stride ) break;
				if ( $stride === 32 ) {
					$corpusPos = tt_flexicorp_xidx_u64le(substr($rec, 0, 8));
					$docIdx    = tt_flexicorp_xidx_u32le(substr($rec, 8, 4));
					$xmlStart  = tt_flexicorp_xidx_u64le(substr($rec, 12, 8));
					$xmlEnd    = tt_flexicorp_xidx_u64le(substr($rec, 20, 8));
					$tokIdRaw  = substr($rec, 28, 4);
				} else {
					$corpusPos = tt_flexicorp_xidx_u64le(substr($rec, 0, 8));
					$docIdx    = tt_flexicorp_xidx_u32le(substr($rec, 8, 4));
					$xmlStart  = tt_flexicorp_xidx_u64le(substr($rec, 16, 8));
					$xmlEnd    = tt_flexicorp_xidx_u64le(substr($rec, 24, 8));
					$tokIdRaw  = substr($rec, 32, 4);
				}
				if ( $corpusPos === null || $docIdx === null || $xmlStart === null || $xmlEnd === null ) continue;
				if ( $docIdx < 0 || $xmlEnd < $xmlStart ) continue;
				$row = array(
					'corpus_pos' => $corpusPos,
					'doc_idx' => $docIdx,
					'xml_start' => $xmlStart,
					'xml_end' => $xmlEnd,
				);
				if ( strlen($tokIdRaw) === 4 && $tokIdRaw !== "\xff\xff\xff\xff" ) {
					$tix = tt_flexicorp_xidx_u32le($tokIdRaw);
					if ( $tix !== null && $tix !== 4294967295 ) {
						$row['tok_id_idx'] = $tix;
					}
				}
				$map[(string)$corpusPos] = $row;
			}
			fclose($fh);
			return $map;
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_token_record_at') ) {
		function tt_flexicorp_xidx_token_record_at($tokensBin, $pos) {
			if ( !is_file($tokensBin) ) return null;
			$pos = (int)$pos;
			if ( $pos < 0 ) return null;
			$fh = @fopen($tokensBin, 'rb');
			if ( !$fh ) return null;
			foreach ( array(32, 40) as $stride ) {
				$off = $pos * $stride;
				if ( @fseek($fh, $off, SEEK_SET) !== 0 ) continue;
				$rec = (string)@fread($fh, $stride);
				if ( strlen($rec) !== $stride ) continue;
				if ( $stride === 32 ) {
					$corpusPos = tt_flexicorp_xidx_u64le(substr($rec, 0, 8));
					$docIdx    = tt_flexicorp_xidx_u32le(substr($rec, 8, 4));
					$xmlStart  = tt_flexicorp_xidx_u64le(substr($rec, 12, 8));
					$xmlEnd    = tt_flexicorp_xidx_u64le(substr($rec, 20, 8));
				} else {
					$corpusPos = tt_flexicorp_xidx_u64le(substr($rec, 0, 8));
					$docIdx    = tt_flexicorp_xidx_u32le(substr($rec, 8, 4));
					$xmlStart  = tt_flexicorp_xidx_u64le(substr($rec, 16, 8));
					$xmlEnd    = tt_flexicorp_xidx_u64le(substr($rec, 24, 8));
				}
				if ( $corpusPos === $pos && $docIdx !== null && $xmlStart !== null && $xmlEnd !== null && $xmlEnd >= $xmlStart ) {
					fclose($fh);
					return array('corpus_pos' => $corpusPos, 'doc_idx' => $docIdx, 'xml_start' => $xmlStart, 'xml_end' => $xmlEnd);
				}
			}
			fclose($fh);
			return null;
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_find_region_span') ) {
		function tt_flexicorp_xidx_find_region_span($regionsBin, $typeIdx, $docIdx, $corpusPos) {
			if ( !is_file($regionsBin) ) return null;
			$typeIdx = (int)$typeIdx; $docIdx = (int)$docIdx; $corpusPos = (int)$corpusPos;
			if ( $typeIdx < 0 || $docIdx < 0 || $corpusPos < 0 ) return null;
			$fh = @fopen($regionsBin, 'rb');
			if ( !$fh ) return null;
			$sz = @filesize($regionsBin);
			$stride = 40;
			if ( is_int($sz) && $sz > 0 ) {
				if ( $sz % 56 === 0 ) {
					$stride = 56;
				} elseif ( $sz % 40 === 0 ) {
					$stride = 40;
				}
			}
			while ( !feof($fh) ) {
				$rec = (string)@fread($fh, $stride);
				if ( strlen($rec) !== $stride ) break;
				$rtype = tt_flexicorp_xidx_u32le(substr($rec, 0, 4));
				$rdoc  = tt_flexicorp_xidx_u32le(substr($rec, 4, 4));
				$rstart = tt_flexicorp_xidx_u64le(substr($rec, 16, 8));
				$rend   = tt_flexicorp_xidx_u64le(substr($rec, 24, 8));
				if ( $rtype === null || $rdoc === null || $rstart === null || $rend === null ) continue;
				if ( $rtype !== $typeIdx || $rdoc !== $docIdx ) continue;
				if ( $corpusPos >= $rstart && $corpusPos <= $rend ) {
					fclose($fh);
					return array('start_pos' => $rstart, 'end_pos' => $rend);
				}
			}
			fclose($fh);
			return null;
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_tok_id_string_for_corpus_pos') ) {
		/**
		 * Resolve TEI xml:id for a corpus position from xidx/tokens.bin tok_id_idx + xidx/tok_ids.tbl.
		 * Used when Pando JSON omits token id (CWB "_" / empty) but the encoder stored the real id in xidx.
		 */
		function tt_flexicorp_xidx_tok_id_string_for_corpus_pos($projectRoot, $corpusPos) {
			$corpusPos = (int)$corpusPos;
			if ( $corpusPos < 0 ) return '';
			static $mapCache = array();
			static $linesCache = array();
			$root = rtrim((string)$projectRoot, '/');
			if ( !isset($mapCache[$root]) ) {
				$mapCache[$root] = tt_flexicorp_xidx_token_record_map($root . '/xidx/tokens.bin');
			}
			if ( !isset($linesCache[$root]) ) {
				$linesCache[$root] = tt_flexicorp_xidx_read_lines($root . '/xidx/tok_ids.tbl');
			}
			$map = $mapCache[$root];
			$lines = $linesCache[$root];
			if ( !is_array($map) || !isset($map[(string)$corpusPos]) ) return '';
			$rec = $map[(string)$corpusPos];
			if ( !is_array($rec) || !isset($rec['tok_id_idx']) ) return '';
			$idx = (int)$rec['tok_id_idx'];
			if ( $idx < 0 || !is_array($lines) || $idx >= count($lines) ) return '';
			$s = trim((string)$lines[$idx]);
			return ( $s !== '' && $s !== '_' ) ? $s : '';
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_read_xml_slice') ) {
		function tt_flexicorp_xidx_read_xml_slice($xmlPath, $xmlStart, $xmlEnd) {
			if ( !is_file($xmlPath) ) return null;
			$xmlStart = (int)$xmlStart; $xmlEnd = (int)$xmlEnd;
			if ( $xmlStart < 0 || $xmlEnd < $xmlStart ) return null;
			$len = $xmlEnd - $xmlStart; if ( $len <= 0 ) $len = 1;
			$fh = @fopen($xmlPath, 'rb');
			if ( !$fh ) return null;
			if ( @fseek($fh, $xmlStart, SEEK_SET) !== 0 ) { fclose($fh); return null; }
			$frag = (string)@fread($fh, $len);
			fclose($fh);
			return $frag !== '' ? $frag : null;
		}
	}
	if ( !function_exists('tt_flexicorp_xidx_doc_id_from_relpath') ) {
		function tt_flexicorp_xidx_doc_id_from_relpath($relPath) {
			$base = basename((string)$relPath);
			return ( substr($base, -4) === '.xml' ) ? substr($base, 0, -4) : $base;
		}
	}
	if ( !function_exists('tt_flexicorp_tok_ids_from_xml_fragment') ) {
		/**
		 * Token @id / xml:id values in document order (TEI). Used when Pando JSON omits id (e.g. CWB "_")
		 * but the fragment still carries ids for highlighting.
		 */
		function tt_flexicorp_tok_ids_from_xml_fragment( $xml ) {
			$xml = (string) $xml;
			if ( $xml === '' ) return array();
			$out = array();
			if ( ! preg_match_all( '/<(?:tok|dtok)\b[^>]*>/u', $xml, $tags ) ) {
				return array();
			}
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bxml:id\s*=\s*"([^"]*)"/u', $tag, $m ) ) {
					$out[] = $m[1];
				} elseif ( preg_match( '/\bid\s*=\s*"([^"]*)"/u', $tag, $m ) ) {
					$out[] = $m[1];
				}
			}
			return $out;
		}
	}
	if ( !function_exists('tt_flexicorp_enrich_hits_xidx') ) {
		function tt_flexicorp_enrich_hits_xidx(&$hits, $projectRoot, $scope, $fmt) {
			if ( !is_array($hits) || empty($hits) ) return;
			$xidxDir = rtrim((string)$projectRoot, '/') . '/xidx';
			$tokensBin = $xidxDir . '/tokens.bin';
			$regionsBin = $xidxDir . '/regions.bin';
			$docs = tt_flexicorp_xidx_read_lines($xidxDir . '/docs.tbl');
			$regionTypes = tt_flexicorp_xidx_read_lines($xidxDir . '/region_types.tbl');
			if ( !is_file($tokensBin) || !is_file($regionsBin) || empty($docs) || empty($regionTypes) ) return;
			$tokMap = tt_flexicorp_xidx_token_record_map($tokensBin);
			if ( empty($tokMap) ) return;

			$scope = trim((string)$scope);
			$typeIdx = -1;
			if ( $scope !== '' ) {
				$idx = array_search($scope, $regionTypes, true);
				if ( $idx !== false ) $typeIdx = (int)$idx;
			}
			if ( $typeIdx < 0 ) {
				$idx = array_search('s', $regionTypes, true);
				if ( $idx === false ) $idx = array_search('seg', $regionTypes, true);
				if ( $idx === false ) $idx = array_search('l', $regionTypes, true);
				if ( $idx === false ) $idx = array_search('lb', $regionTypes, true);
				if ( $idx !== false ) $typeIdx = (int)$idx;
			}

			foreach ( $hits as &$hit ) {
				if ( !is_array($hit) || !isset($hit['match_start']) ) continue;
				$pos = (int)$hit['match_start'];
				$tokRec = $tokMap[(string)$pos] ?? null;
				if ( !is_array($tokRec) && $pos > 0 ) $tokRec = $tokMap[(string)($pos - 1)] ?? null;
				if ( !is_array($tokRec) ) continue;
				$docIdx = (int)$tokRec['doc_idx'];
				if ( !isset($docs[$docIdx]) ) continue;
				$relPath = (string)$docs[$docIdx];
				$xmlPath = rtrim((string)$projectRoot, '/') . '/' . ltrim($relPath, '/');
				if ( !is_file($xmlPath) ) continue;

				$textId = tt_flexicorp_xidx_doc_id_from_relpath($relPath);
				if ( empty($hit['doc_id']) ) $hit['doc_id'] = $textId;
				if ( empty($hit['text_id']) ) $hit['text_id'] = $textId;

				$fragXml = null;
				if ( $typeIdx >= 0 ) {
					$span = tt_flexicorp_xidx_find_region_span($regionsBin, $typeIdx, $docIdx, $pos);
					if ( is_array($span) ) {
						$startTok = tt_flexicorp_xidx_token_record_at($tokensBin, (int)$span['start_pos']);
						$endTok = tt_flexicorp_xidx_token_record_at($tokensBin, (int)$span['end_pos']);
						if ( !is_array($startTok) ) $startTok = $tokMap[(string)((int)$span['start_pos'])] ?? null;
						if ( !is_array($endTok) ) $endTok = $tokMap[(string)((int)$span['end_pos'])] ?? null;
						if ( is_array($startTok) && is_array($endTok) ) {
							$fragXml = tt_flexicorp_xidx_read_xml_slice($xmlPath, (int)$startTok['xml_start'], (int)$endTok['xml_end']);
						}
					}
				}
				if ( !is_string($fragXml) || $fragXml === '' ) {
					$fragXml = tt_flexicorp_xidx_read_xml_slice($xmlPath, (int)$tokRec['xml_start'], (int)$tokRec['xml_end']);
				}
				if ( !is_string($fragXml) || $fragXml === '' ) continue;

				$hit['fragment'] = $fragXml;
				$hit['context_xml'] = $fragXml;
				$hit['context_data'] = $fragXml;
			}
			unset($hit);
		}
	}

	if ( !function_exists('tt_flexicorp_done') ) {
		function tt_flexicorp_done($call) {
			if ( !is_array($call) || empty($call['data']) || !is_array($call['data']) || empty($call['data']['done']) || !is_array($call['data']['done']) ) return array();
			return $call['data']['done'];
		}
	}

	if ( !function_exists('tt_flexicorp_error_call') ) {
		function tt_flexicorp_error_call($backend, $operation, $message) {
			$done = array(
				'backend' => (string)$backend,
				'operation' => (string)$operation,
				'result' => null,
				'warnings' => array(),
				'errors' => array((string)$message),
			);
			return array(
				'ok' => false,
				'command' => '',
				'raw' => '',
				'error' => (string)$message,
				'data' => array(
					'success' => false,
					'done' => $done,
				),
			);
		}
	}

	if ( !function_exists('tt_flexicorp_response_payload') ) {
		function tt_flexicorp_response_payload($call) {
			$done = tt_flexicorp_done($call);
			return array(
				'ok' => !empty($call['ok']),
				'error' => (string)($call['error'] ?? ''),
				'errors' => ( isset($done['errors']) && is_array($done['errors']) ) ? array_values($done['errors']) : array(),
				'warnings' => ( isset($done['warnings']) && is_array($done['warnings']) ) ? array_values($done['warnings']) : array(),
				'command' => (string)($call['command'] ?? ''),
				'raw' => (string)($call['raw'] ?? ''),
				'result' => $done['result'] ?? null,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_search_response_with_timing') ) {
		function tt_flexicorp_search_response_with_timing($call, $timeMs) {
			$payload = tt_flexicorp_response_payload($call);
			if ( $timeMs !== null && isset($payload['result']) && is_array($payload['result']) ) {
				$payload['result']['time_ms'] = (int) $timeMs;
			}
			return $payload;
		}
	}

	if ( !function_exists('tt_flexicorp_compact_response_payload') ) {
		function tt_flexicorp_compact_response_payload($payload) {
			if ( !is_array($payload) ) return array();
			$compact = $payload;
			unset($compact['raw']);
			unset($compact['command']);
			return $compact;
		}
	}

	if ( !function_exists('tt_flexicorp_compact_search_state_payload') ) {
		function tt_flexicorp_compact_search_state_payload($payload) {
			if ( !is_array($payload) ) return array();
			$compact = $payload;
			unset($compact['raw']);
			unset($compact['command']);
			if ( isset($compact['result']) && is_array($compact['result']) ) {
				$compactResult = $compact['result'];
				unset($compactResult['hits']);
				$compact['result'] = $compactResult;
			}
			return $compact;
		}
	}

	if ( !function_exists('tt_flexicorp_search_response_merged') ) {
		function tt_flexicorp_search_response_merged($call, $done, $timeMs) {
			$payload = tt_flexicorp_search_response_with_timing($call, $timeMs);
			if ( !is_array($payload['result']) ) return $payload;
			// Ensure total/returned are in result (backend may put them in done root or in result)
			$total = isset($payload['result']['total']) ? (int) $payload['result']['total'] : null;
			if ( $total === null && isset($done['result']['total']) ) $total = (int) $done['result']['total'];
			if ( $total === null && isset($done['total']) ) $total = (int) $done['total'];
			$returned = isset($payload['result']['returned']) ? (int) $payload['result']['returned'] : null;
			if ( $returned === null && isset($done['result']['returned']) ) $returned = (int) $done['result']['returned'];
			if ( $returned === null && isset($done['returned']) ) $returned = (int) $done['returned'];
			if ( $returned === null && isset($done['result']['hits']) && is_array($done['result']['hits']) ) {
				$returned = count($done['result']['hits']);
			}
			if ( $total !== null ) $payload['result']['total'] = $total;
			if ( $returned !== null ) $payload['result']['returned'] = $returned;
			return $payload;
		}
	}

	if ( !function_exists('tt_flexicorp_corpus_tokens_for_ipm') ) {
		/**
		 * Token count for IPM: xidx/tokens.bin (same stride rules as flexicorp xidx) or CWB word.corpus (4 bytes per token).
		 */
		function tt_flexicorp_corpus_tokens_for_ipm($projectRoot) {
			$root = rtrim( (string) $projectRoot, '/' );
			$tokensBins = array( $root . '/xidx/tokens.bin' );
			$cqpfolderRel = function_exists( 'getset' ) ? getset( 'cqp/cqpfolder', 'cqp' ) : 'cqp';
			if ( $cqpfolderRel !== '' ) {
				$cqpBase = ( $cqpfolderRel !== '' && $cqpfolderRel[0] === '/' ) ? $cqpfolderRel : $root . '/' . $cqpfolderRel;
				$tokensBins[] = $cqpBase . '/xidx/tokens.bin';
			}
			foreach ( $tokensBins as $p ) {
				if ( !is_file( $p ) || !is_readable( $p ) ) {
					continue;
				}
				$sz = @filesize( $p );
				if ( !is_int( $sz ) || $sz <= 0 ) {
					continue;
				}
				if ( $sz % 40 === 0 ) {
					$stride = 40;
				} else if ( $sz % 32 === 0 ) {
					$stride = 32;
				} else {
					continue;
				}
				$n = (int) ( $sz / $stride );
				if ( $n > 0 ) {
					return $n;
				}
			}
			$wordCorp = array();
			if ( $root !== '' ) {
				$wordCorp[] = $root . '/cqp/word.corpus';
			}
			if ( isset( $cqpBase ) && $cqpBase !== '' ) {
				$wordCorp[] = $cqpBase . '/word.corpus';
			}
			foreach ( $wordCorp as $p ) {
				if ( !is_file( $p ) || !is_readable( $p ) ) {
					continue;
				}
				$sz = @filesize( $p );
				if ( !is_int( $sz ) || $sz <= 0 ) {
					continue;
				}
				$n = (int) ( $sz / 4 );
				if ( $n > 0 ) {
					return $n;
				}
			}
			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_search_augment_ipm') ) {
		/**
		 * Add ipm and corpus_tokens when total match count is known and corpus size can be inferred (so AJAX search rows show IPM without a separate info call).
		 */
		function tt_flexicorp_search_augment_ipm($payload, $projectRoot, $kwicDone) {
			if ( !is_array( $payload ) || !isset( $payload['result'] ) || !is_array( $payload['result'] ) ) {
				return $payload;
			}
			$r = &$payload['result'];
			if ( isset( $r['ipm'] ) && is_numeric( $r['ipm'] ) ) {
				return $payload;
			}
			$total = null;
			if ( isset( $r['total'] ) && is_numeric( $r['total'] ) ) {
				$total = (int) $r['total'];
			}
			if ( $total === null && isset( $kwicDone['result']['total'] ) && is_numeric( $kwicDone['result']['total'] ) ) {
				$total = (int) $kwicDone['result']['total'];
			}
			if ( $total === null || $total < 0 ) {
				return $payload;
			}
			$ct = null;
			if ( isset( $r['corpus_tokens'] ) && is_numeric( $r['corpus_tokens'] ) ) {
				$ct = (int) $r['corpus_tokens'];
			}
			if ( $ct === null || $ct <= 0 ) {
				$ct = tt_flexicorp_corpus_tokens_for_ipm( $projectRoot );
			}
			if ( $ct === null || $ct <= 0 ) {
				return $payload;
			}
			$r['corpus_tokens'] = $ct;
			$r['ipm'] = ( $total / $ct ) * 1000000.0;
			return $payload;
		}
	}
