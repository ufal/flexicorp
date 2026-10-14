<?php
/**
 * Custom layers (custom_layers.php): storage and permissions.
 *
 * One file per user, in the user's own folder (as TEITOK's query manager): Users/<user>/custom_layers.xml.
 * It holds the user's attributes (each with its values), tasks (a query to classify, which attributes,
 * optionally a sample) and the labels as TEITOK stand-off spans per document — the format TEITOK reads
 * from Annotations/<type>_<docid>.xml, so an administrator's layer can later be moved into the main
 * corpus by splitting it per document. Custom layers are not indexed into the main corpus themselves.
 *
 *   <customLayers user="…" updated="…">
 *     <attribute key="feel_wsd" display="Sense of feel" shared="1">
 *       <desc>…</desc>
 *       <value key="touch" display="Touch">physical contact</value> …
 *       <prefill if='child [deprel="obj"]' then="…" else="…"/>
 *     </attribute>
 *     <attribute key="trans" owner="otheruser"/>          (another user's shared attribute, used here)
 *     <task id="feel" display="…" query='v:[lemma="feel"]' unit="v" attributes="feel_wsd trans" sample="200" seed="17"/>
 *     <doc id="xmlfiles/70.xml">
 *       <span corresp="#w-183" feel_wsd="touch" trans="TRANS" when="…"/>
 *     </doc>
 *   </customLayers>
 *
 * Only the user writes their own file. Another user's labels are read only for attributes shared by
 * their owner.
 */

if ( ! function_exists( 'tt_cl_path' ) ) {
	/** Span attributes that are not custom attributes. */
	function tt_cl_reserved() {
		return array( 'corresp', 'when', 'id', 'type', 'xml', 'doc', 'gid' );   // gid: the overlay's group column
	}

	/**
	 * An attribute or task key: lowercase letters a–z only. In queries an attribute follows the one
	 * hyphen: `my-feelwsd` (own labels), `maarten-feelwsd` (published).
	 */
	function tt_cl_valid_key( $k ) {
		return is_string( $k ) && preg_match( '/^[a-z]{1,24}$/', $k ) && ! in_array( $k, tt_cl_reserved(), true );
	}

	/** A key of an older file (feel_wsd) as keys are now (feelwsd). */
	function tt_cl_compact_key( $k ) {
		return preg_replace( '/[^a-z]/', '', strtolower( (string) $k ) );
	}

	function tt_cl_valid_folder( $f ) {
		return is_string( $f ) && preg_match( '/^[a-z0-9]{1,128}$/', $f );
	}

	/** A key suggested by a name: its first word in lowercase letters a–z (Sense of feel → sense). */
	function tt_cl_slug( $name ) {
		$words = preg_split( '/[^a-z]+/', strtolower( trim( (string) $name ) ), -1, PREG_SPLIT_NO_EMPTY );
		return substr( $words ? $words[0] : '', 0, 24 );
	}

	function tt_cl_path( $root, $folder ) {
		return tt_cl_valid_folder( $folder ) ? rtrim( (string) $root, '/' ) . "/Users/$folder/custom_layers.xml" : '';
	}

	function tt_cl_empty() {
		return array( 'attributes' => array(), 'tasks' => array(), 'docs' => array(), 'updated' => '', 'public' => '' );
	}

	/** A user's file as an array (empty when missing). docs: doc id → corresp → [attr → value, 'when' → …]. */
	function tt_cl_load( $root, $folder ) {
		$path = tt_cl_path( $root, $folder );
		$data = tt_cl_empty();
		if ( $path === '' || ! is_file( $path ) ) return $data;
		$xml = @simplexml_load_file( $path );
		if ( ! $xml ) return $data;
		$a = function ( $n, $k, $d = '' ) { return isset( $n[ $k ] ) ? (string) $n[ $k ] : $d; };
		$data['updated'] = $a( $xml, 'updated' );
		$data['public'] = $a( $xml, 'public' );   // published under this name (custom_layers_publish.php)
		foreach ( $xml->attribute ?? array() as $at ) {
			$def = array( 'key' => $a( $at, 'key' ), 'display' => $a( $at, 'display' ), 'owner' => $a( $at, 'owner' ),
				'shared' => $a( $at, 'shared' ) === '1', 'description' => isset( $at->desc ) ? (string) $at->desc : '',
				'values' => array(), 'prefill' => null );
			foreach ( $at->value ?? array() as $v ) {
				$def['values'][] = array( 'key' => $a( $v, 'key' ), 'display' => $a( $v, 'display' ), 'description' => (string) $v );
			}
			if ( isset( $at->prefill ) ) {
				$def['prefill'] = array( 'if' => $a( $at->prefill, 'if' ), 'then' => $a( $at->prefill, 'then' ), 'else' => $a( $at->prefill, 'else' ) );
			}
			$def['key'] = tt_cl_compact_key( $def['key'] );   // older files: feel_wsd
			if ( tt_cl_valid_key( $def['key'] ) ) $data['attributes'][ $def['key'] ] = $def;
		}
		foreach ( $xml->task ?? array() as $t ) {
			$id = tt_cl_compact_key( $a( $t, 'id' ) );
			if ( ! tt_cl_valid_key( $id ) ) continue;
			$data['tasks'][ $id ] = array( 'id' => $id, 'owner' => $a( $t, 'owner' ), 'shared' => $a( $t, 'shared' ) === '1',
				'display' => $a( $t, 'display', $id ), 'query' => $a( $t, 'query' ), 'unit' => $a( $t, 'unit' ),
				'attributes' => array_values( array_filter( array_map( 'tt_cl_compact_key', explode( ' ', $a( $t, 'attributes' ) ) ) ) ),
				'sample' => (int) $a( $t, 'sample', '0' ), 'seed' => (int) $a( $t, 'seed', '0' ) );
		}
		foreach ( $xml->doc ?? array() as $d ) {
			$doc = $a( $d, 'id' );
			foreach ( $d->span ?? array() as $sp ) {
				$vals = array();
				foreach ( $sp->attributes() as $k => $v ) {
					$k = (string) $k;
					$vals[ $k === 'corresp' || $k === 'when' ? $k : tt_cl_compact_key( $k ) ] = (string) $v;
				}
				$corresp = $vals['corresp'] ?? '';
				unset( $vals['corresp'] );
				if ( $doc !== '' && $corresp !== '' ) $data['docs'][ $doc ][ $corresp ] = $vals;
			}
		}
		return $data;
	}

	function tt_cl_to_xml( $user, array $data ) {
		$d = new DOMDocument( '1.0', 'UTF-8' );
		$d->formatOutput = true;
		$r = $d->appendChild( $d->createElement( 'customLayers' ) );
		$r->setAttribute( 'user', (string) $user );
		$r->setAttribute( 'updated', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		if ( ( $data['public'] ?? '' ) !== '' ) $r->setAttribute( 'public', (string) $data['public'] );
		foreach ( $data['attributes'] as $at ) {
			$e = $r->appendChild( $d->createElement( 'attribute' ) );
			$e->setAttribute( 'key', $at['key'] );
			if ( $at['owner'] !== '' ) { $e->setAttribute( 'owner', $at['owner'] ); continue; }
			$e->setAttribute( 'display', $at['display'] );
			if ( ! empty( $at['shared'] ) ) $e->setAttribute( 'shared', '1' );
			if ( $at['description'] !== '' ) $e->appendChild( $d->createElement( 'desc' ) )->appendChild( $d->createTextNode( $at['description'] ) );
			foreach ( $at['values'] as $v ) {
				$ve = $e->appendChild( $d->createElement( 'value' ) );
				$ve->setAttribute( 'key', $v['key'] );
				$ve->setAttribute( 'display', $v['display'] );
				if ( $v['description'] !== '' ) $ve->appendChild( $d->createTextNode( $v['description'] ) );
			}
			if ( ! empty( $at['prefill']['if'] ) ) {
				$pe = $e->appendChild( $d->createElement( 'prefill' ) );
				foreach ( array( 'if', 'then', 'else' ) as $k ) $pe->setAttribute( $k, (string) ( $at['prefill'][ $k ] ?? '' ) );
			}
		}
		foreach ( $data['tasks'] as $t ) {
			$e = $r->appendChild( $d->createElement( 'task' ) );
			$e->setAttribute( 'id', (string) $t['id'] );
			if ( ( $t['owner'] ?? '' ) !== '' ) { $e->setAttribute( 'owner', $t['owner'] ); continue; }   // a joined task
			if ( ! empty( $t['shared'] ) ) $e->setAttribute( 'shared', '1' );
			foreach ( array( 'display', 'query', 'unit' ) as $k ) $e->setAttribute( $k, (string) $t[ $k ] );
			$e->setAttribute( 'attributes', implode( ' ', $t['attributes'] ) );
			if ( $t['sample'] > 0 ) { $e->setAttribute( 'sample', (string) $t['sample'] ); $e->setAttribute( 'seed', (string) $t['seed'] ); }
		}
		foreach ( $data['docs'] as $doc => $spans ) {
			if ( ! $spans ) continue;
			$de = $r->appendChild( $d->createElement( 'doc' ) );
			$de->setAttribute( 'id', (string) $doc );
			foreach ( $spans as $corresp => $vals ) {
				$se = $de->appendChild( $d->createElement( 'span' ) );
				$se->setAttribute( 'corresp', (string) $corresp );
				foreach ( $vals as $k => $v ) $se->setAttribute( (string) $k, (string) $v );
			}
		}
		return $d->saveXML();
	}

	/**
	 * Change the user's file under an exclusive lock: $fn gets the data and returns the new data, or a
	 * string (an error: nothing written). Written to a temporary file, then renamed.
	 */
	function tt_cl_update( $root, $user, callable $fn ) {
		$folder = tt_cl_user_folder( $user );
		$path = tt_cl_path( $root, $folder );
		if ( $path === '' ) return 'log in to use custom layers';
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) ) return 'cannot create your folder under Users/';
		$lock = @fopen( $dir . '/.custom_layers.lock', 'c' );
		if ( ! $lock ) return 'cannot lock your custom layers';
		flock( $lock, LOCK_EX );
		try {
			$new = $fn( tt_cl_load( $root, $folder ) );
			if ( is_string( $new ) ) return $new;
			$tmp = $path . '.tmp' . getmypid();
			if ( @file_put_contents( $tmp, tt_cl_to_xml( $user, $new ) ) === false || ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
				return 'cannot write your custom layers';
			}
			tt_cl_all( $root, true );   // the files read so far in this request are out of date
			return $new;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	function tt_cl_user_folder( $user ) {
		return function_exists( 'tt_fc_env_user_folder' ) ? tt_fc_env_user_folder( $user ) : preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $user ) );
	}

	/** Every user's file (folder → data), read once per request. */
	function tt_cl_all( $root, $reset = false ) {
		static $cache = array();
		$k = (string) $root;
		if ( $reset ) { unset( $cache[ $k ] ); return array(); }
		if ( ! isset( $cache[ $k ] ) ) {
			$cache[ $k ] = array();
			foreach ( glob( rtrim( $k, '/' ) . '/Users/*/custom_layers.xml' ) ?: array() as $f ) {
				$folder = basename( dirname( $f ) );
				if ( tt_cl_valid_folder( $folder ) ) $cache[ $k ][ $folder ] = tt_cl_load( $root, $folder );
			}
		}
		return $cache[ $k ];
	}

	/** Shared attributes of other users: [ {owner, key, display, description, values, prefill} ]. */
	function tt_cl_shared_attributes( $root, $exceptFolder ) {
		$out = array();
		foreach ( tt_cl_all( $root ) as $folder => $data ) {
			if ( $folder === $exceptFolder ) continue;
			foreach ( $data['attributes'] as $at ) {
				if ( $at['owner'] === '' && ! empty( $at['shared'] ) ) $out[] = array_merge( $at, array( 'owner' => $folder ) );
			}
		}
		return $out;
	}

	/** The user's attributes with references resolved to their owner's definition (missing / unshared: dropped). */
	function tt_cl_resolved_attributes( $root, array $data ) {
		$all = null;
		$out = array();
		foreach ( $data['attributes'] as $key => $at ) {
			if ( $at['owner'] === '' ) { $out[ $key ] = $at; continue; }
			if ( $all === null ) $all = tt_cl_all( $root );
			$def = $all[ $at['owner'] ]['attributes'][ $key ] ?? null;
			if ( $def && $def['owner'] === '' && ! empty( $def['shared'] ) ) $out[ $key ] = array_merge( $def, array( 'owner' => $at['owner'] ) );
		}
		return $out;
	}

	function tt_cl_clean_values( $values ) {
		$out = array();
		$seen = array();
		foreach ( is_array( $values ) ? $values : array() as $v ) {
			$display = mb_substr( trim( (string) ( $v['display'] ?? '' ) ), 0, 80 );
			$key = trim( (string) ( $v['key'] ?? '' ) );
			if ( $key === '' ) $key = $display;
			$key = mb_substr( preg_replace( '/[\s"<>&]+/', '_', $key ), 0, 40 );
			if ( $key === '' || isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = 1;
			$out[] = array( 'key' => $key, 'display' => $display !== '' ? $display : $key,
				'description' => mb_substr( trim( (string) ( $v['description'] ?? '' ) ), 0, 1000 ) );
			if ( count( $out ) >= 20 ) break;
		}
		return $out;
	}

	/** Create or change one of the user's own attributes. */
	function tt_cl_save_attribute( $root, $user, array $in ) {
		$key = trim( (string) ( $in['key'] ?? '' ) );
		if ( $key === '' ) $key = tt_cl_slug( $in['display'] ?? '' );
		if ( ! tt_cl_valid_key( $key ) ) return 'the name in queries is lowercase letters a-z only (no digits, spaces, hyphens or underscores)';
		$values = tt_cl_clean_values( $in['values'] ?? array() );
		if ( ! $values ) return 'an attribute needs at least one value';
		$codes = array_column( $values, 'key' );
		$pre = null;
		if ( trim( (string) ( $in['prefill']['if'] ?? '' ) ) !== '' ) {
			$pre = array( 'if' => mb_substr( trim( (string) $in['prefill']['if'] ), 0, 1000 ),
				'then' => (string) ( $in['prefill']['then'] ?? '' ), 'else' => (string) ( $in['prefill']['else'] ?? '' ) );
			foreach ( array( 'then', 'else' ) as $k ) {
				if ( $pre[ $k ] !== '' && ! in_array( $pre[ $k ], $codes, true ) ) return "the pre-fill value '{$pre[$k]}' is not one of the values";
			}
		}
		$create = ! empty( $in['create'] );
		return tt_cl_update( $root, $user, function ( $data ) use ( $key, $in, $values, $pre, $create ) {
			$cur = $data['attributes'][ $key ] ?? null;
			if ( $cur && $create ) return 'you have an attribute with this name already';
			if ( $cur && $cur['owner'] !== '' ) return "'$key' is another user's attribute here";
			$data['attributes'][ $key ] = array( 'key' => $key, 'owner' => '',
				'display' => mb_substr( trim( (string) ( $in['display'] ?? '' ) ), 0, 120 ) ?: $key,
				'description' => mb_substr( trim( (string) ( $in['description'] ?? '' ) ), 0, 4000 ),
				'shared' => ! empty( $in['shared'] ), 'values' => $values, 'prefill' => $pre );
			return $data;
		} );
	}

	/** Use another user's shared attribute in your own tasks (your labels stay in your own file). */
	function tt_cl_add_reference( $root, $user, $owner, $key ) {
		if ( ! tt_cl_valid_folder( $owner ) || ! tt_cl_valid_key( $key ) ) return 'which attribute?';
		$def = tt_cl_load( $root, $owner )['attributes'][ $key ] ?? null;
		if ( ! $def || $def['owner'] !== '' || empty( $def['shared'] ) ) return 'this attribute is not shared';
		return tt_cl_update( $root, $user, function ( $data ) use ( $owner, $key ) {
			if ( isset( $data['attributes'][ $key ] ) ) return "you have an attribute '$key' already";
			$data['attributes'][ $key ] = array( 'key' => $key, 'owner' => $owner, 'display' => '', 'description' => '', 'shared' => false, 'values' => array(), 'prefill' => null );
			return $data;
		} );
	}

	/** Create or change a task: a query, the attributes to classify its lines with, optionally a sample. */
	function tt_cl_save_task( $root, $user, array $in ) {
		$id = trim( (string) ( $in['id'] ?? '' ) );
		if ( $id === '' ) $id = tt_cl_slug( $in['display'] ?? '' );
		if ( ! tt_cl_valid_key( $id ) ) return 'give the task a name (lowercase letters a-z)';
		$query = mb_substr( trim( (string) ( $in['query'] ?? '' ) ), 0, 4000 );
		if ( $query === '' ) return 'a task needs a query';
		$attrs = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $in['attributes'] ?? array() ) ), 'tt_cl_valid_key' ) ) );
		if ( ! $attrs ) return 'choose at least one attribute';
		$unit = preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string) ( $in['unit'] ?? '' ) ) ? (string) $in['unit'] : '';
		$size = max( 0, min( 5000, (int) ( $in['sample'] ?? 0 ) ) );
		$create = ! empty( $in['create'] );
		$shared = ! empty( $in['shared'] );
		return tt_cl_update( $root, $user, function ( $data ) use ( $id, $in, $query, $attrs, $unit, $size, $create, $shared ) {
			if ( isset( $data['tasks'][ $id ] ) && $create ) return 'you have a task with this name already';
			if ( ( $data['tasks'][ $id ]['owner'] ?? '' ) !== '' ) return "'$id' is another user's task here";
			foreach ( $attrs as $a ) {
				if ( ! isset( $data['attributes'][ $a ] ) ) return "no attribute '$a'";
				$at = $data['attributes'][ $a ];
				// others who join a shared task label its attributes: each must be shared (or someone else's shared one)
				if ( $shared && $at['owner'] === '' && empty( $at['shared'] ) ) return "share the attribute '{$at['display']}' to share this task";
			}
			$old = $data['tasks'][ $id ] ?? null;
			$seed = $old && $old['seed'] ? $old['seed'] : random_int( 1, 999999999 );
			$data['tasks'][ $id ] = array( 'id' => $id, 'owner' => '', 'shared' => $shared,
				'display' => mb_substr( trim( (string) ( $in['display'] ?? '' ) ), 0, 120 ) ?: $id,
				'query' => $query, 'unit' => $unit, 'attributes' => $attrs, 'sample' => $size, 'seed' => $size ? $seed : 0 );
			return $data;
		} );
	}

	/** Shared tasks of other users: [ {owner, id, display, query, unit, attributes, sample} ]. */
	function tt_cl_shared_tasks( $root, $exceptFolder ) {
		$out = array();
		foreach ( tt_cl_all( $root ) as $folder => $data ) {
			if ( $folder === $exceptFolder ) continue;
			foreach ( $data['tasks'] as $t ) {
				if ( $t['owner'] === '' && ! empty( $t['shared'] ) ) $out[] = array_merge( $t, array( 'owner' => $folder ) );
			}
		}
		return $out;
	}

	/**
	 * The user's tasks with joined ones resolved to their owner's current definition (same query, unit
	 * and sample seed: everyone gets the same lines); a task no longer shared is dropped.
	 */
	function tt_cl_resolved_tasks( $root, array $data ) {
		$out = array();
		foreach ( $data['tasks'] as $id => $t ) {
			if ( $t['owner'] === '' ) { $out[ $id ] = $t; continue; }
			$def = tt_cl_all( $root )[ $t['owner'] ]['tasks'][ $id ] ?? null;
			if ( $def && $def['owner'] === '' && ! empty( $def['shared'] ) ) $out[ $id ] = array_merge( $def, array( 'owner' => $t['owner'] ) );
		}
		return $out;
	}

	/**
	 * Join another user's shared task: a reference to it, and references to its attributes (to their
	 * owners), so the values can be stored in the user's own file.
	 */
	function tt_cl_join_task( $root, $user, $owner, $id ) {
		if ( ! tt_cl_valid_folder( $owner ) || ! tt_cl_valid_key( $id ) ) return 'which task?';
		$me = tt_cl_user_folder( $user );
		if ( $owner === $me ) return 'this is your own task';
		$odata = tt_cl_load( $root, $owner );
		$t = $odata['tasks'][ $id ] ?? null;
		if ( ! $t || $t['owner'] !== '' || empty( $t['shared'] ) ) return 'this task is not shared';
		$need = array();   // attribute key → its owner
		foreach ( $t['attributes'] as $k ) {
			$at = $odata['attributes'][ $k ] ?? null;
			if ( ! $at ) return "the task's attribute '$k' is missing";
			$need[ $k ] = $at['owner'] !== '' ? $at['owner'] : $owner;
		}
		return tt_cl_update( $root, $user, function ( $data ) use ( $owner, $id, $need, $me ) {
			if ( isset( $data['tasks'][ $id ] ) ) return "you have a task '$id' already";
			foreach ( $need as $k => $ko ) {
				$cur = $data['attributes'][ $k ] ?? null;
				if ( $ko === $me ) { if ( ! $cur || $cur['owner'] !== '' ) return "your attribute '$k' is missing"; continue; }
				if ( $cur && $cur['owner'] !== $ko ) return "you have another attribute '$k' already";
				if ( ! $cur ) $data['attributes'][ $k ] = array( 'key' => $k, 'owner' => $ko, 'display' => '', 'description' => '', 'shared' => false, 'values' => array(), 'prefill' => null );
			}
			$data['tasks'][ $id ] = array( 'id' => $id, 'owner' => $owner, 'shared' => false, 'display' => '', 'query' => '', 'unit' => '', 'attributes' => array(), 'sample' => 0, 'seed' => 0 );
			return $data;
		} );
	}

	/** Set the user's values on one item (doc + token ids); a value '' removes it, an empty span goes. */
	function tt_cl_set_values( $root, $user, array $in ) {
		$doc = trim( (string) ( $in['doc'] ?? '' ) );
		$ids = array_values( array_filter( preg_split( '/[\s#]+/', (string) ( $in['tokens'] ?? '' ) ) ) );
		if ( $doc === '' || ! $ids || strlen( $doc ) > 400 || count( $ids ) > 200 ) return 'which item?';
		foreach ( $ids as $t ) if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/', $t ) ) return 'which item?';
		$corresp = '#' . implode( ' #', $ids );
		$values = is_array( $in['values'] ?? null ) ? $in['values'] : array();
		return tt_cl_update( $root, $user, function ( $data ) use ( $root, $doc, $corresp, $values ) {
			$attrs = tt_cl_resolved_attributes( $root, $data );
			$span = $data['docs'][ $doc ][ $corresp ] ?? array();
			foreach ( $values as $k => $v ) {
				$k = (string) $k;
				$v = (string) $v;
				if ( ! isset( $attrs[ $k ] ) ) return "no attribute '$k'";
				if ( $v === '' ) { unset( $span[ $k ] ); continue; }
				if ( ! in_array( $v, array_column( $attrs[ $k ]['values'], 'key' ), true ) ) return "'$v' is not a value of $k";
				$span[ $k ] = $v;
			}
			unset( $span['when'] );
			if ( $span ) {
				$span['when'] = gmdate( 'Y-m-d\TH:i:s\Z' );
				$data['docs'][ $doc ][ $corresp ] = $span;
			} else {
				unset( $data['docs'][ $doc ][ $corresp ] );
				if ( empty( $data['docs'][ $doc ] ) ) unset( $data['docs'][ $doc ] );
			}
			return $data;
		} );
	}

	/**
	 * Other users' values for the given attributes (key → owner folder), only where the owner shares
	 * them: doc → corresp → [ {user, attr, value} ].
	 */
	function tt_cl_others_values( $root, $meFolder, array $attrOwners ) {
		$out = array();
		foreach ( tt_cl_all( $root ) as $folder => $data ) {
			if ( $folder === $meFolder ) continue;
			$theirs = array();   // their key → owner
			foreach ( $data['attributes'] as $k => $at ) $theirs[ $k ] = $at['owner'] !== '' ? $at['owner'] : $folder;
			$use = array();
			foreach ( $attrOwners as $k => $owner ) {
				if ( ( $theirs[ $k ] ?? null ) !== $owner ) continue;
				$def = $owner === $folder ? $data['attributes'][ $k ] : ( tt_cl_all( $root )[ $owner ]['attributes'][ $k ] ?? null );
				if ( $def && ! empty( $def['shared'] ) ) $use[] = $k;
			}
			if ( ! $use ) continue;
			foreach ( $data['docs'] as $doc => $spans ) {
				foreach ( $spans as $corresp => $vals ) {
					foreach ( $use as $k ) {
						if ( isset( $vals[ $k ] ) ) $out[ $doc ][ $corresp ][] = array( 'user' => $folder, 'attr' => $k, 'value' => $vals[ $k ] );
					}
				}
			}
		}
		return $out;
	}

	/** CSV of the user's values for a task's attributes: doc, tokens, one column per attribute, time. */
	function tt_cl_csv( array $data, array $task ) {
		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, array_merge( array( 'doc', 'tokens' ), $task['attributes'], array( 'time' ) ), ',', '"', '' );
		foreach ( $data['docs'] as $doc => $spans ) {
			foreach ( $spans as $corresp => $vals ) {
				$row = array( $doc, trim( str_replace( '#', '', $corresp ) ) );
				$any = false;
				foreach ( $task['attributes'] as $k ) { $row[] = $vals[ $k ] ?? ''; $any = $any || isset( $vals[ $k ] ); }
				if ( ! $any ) continue;
				$row[] = $vals['when'] ?? '';
				fputcsv( $fh, $row, ',', '"', '' );
			}
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}
}
