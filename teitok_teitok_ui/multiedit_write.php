<?php
/**
 * multiedit: writing the changes into a document byte-preserving: only the changed attributes in the start
 * tags of the tokens change; everything else in the file stays byte for byte as it was. (Not TTXML->save(),
 * which writes the XML as SimpleXML has it: the `tei_` names TEITOK gives HTML-like elements on loading, as
 * <tei_body>, stay in the file, and the formatting changes.)
 */

if ( ! function_exists( 'tt_me_tag_spans' ) ) {
	/**
	 * Where the start tags of the tok / dtok with the given ids are: id => array( start, length ). The parser
	 * reports the end of a start tag; its start is the last '<' before it ('<' cannot occur in an attribute
	 * value). Returns null when the document does not parse.
	 */
	function tt_me_tag_spans( $raw, array $ids ) {
		$want = array_flip( $ids );
		$spans = array();
		$p = xml_parser_create( 'UTF-8' );
		xml_parser_set_option( $p, XML_OPTION_CASE_FOLDING, 0 );
		xml_set_element_handler( $p, function ( $p, $name, $attrs ) use ( $raw, $want, &$spans ) {
			if ( ( $name !== 'tok' && $name !== 'dtok' ) || ! isset( $attrs['id'] ) || ! isset( $want[ $attrs['id'] ] ) ) return;
			$at = xml_get_current_byte_index( $p );           // the '>' of the start tag, or the '/' of '/>'
			$end = strpos( $raw, '>', $at );
			$start = strrpos( substr( $raw, 0, $at ), '<' );
			if ( $end === false || $start === false ) return;
			$spans[ $attrs['id'] ] = array( $start, $end + 1 - $start );
		}, function () {} );
		$ok = xml_parse( $p, $raw, true );
		if ( PHP_VERSION_ID < 80000 ) xml_parser_free( $p );   // does nothing since PHP 8, deprecated in 8.5
		return $ok ? $spans : null;
	}
}

if ( ! function_exists( 'tt_me_attr_escape' ) ) {
	function tt_me_attr_escape( $v, $quote ) {
		$v = str_replace( array( '&', '<', "\t", "\n", "\r" ), array( '&amp;', '&lt;', '&#9;', '&#10;', '&#13;' ), (string) $v );
		return $quote === "'" ? str_replace( "'", '&apos;', $v ) : str_replace( '"', '&quot;', $v );
	}
}

if ( ! function_exists( 'tt_me_set_attr' ) ) {
	/**
	 * A start tag with attribute NAME set to VALUE (null: removed): an existing one keeps its place and quotes;
	 * a new one goes after the last attribute, with double quotes.
	 */
	function tt_me_set_attr( $tag, $name, $value ) {
		$re = '/(\s+)(' . preg_quote( $name, '/' ) . ')(\s*=\s*)("[^"]*"|\'[^\']*\')/';
		if ( preg_match( $re, $tag, $m, PREG_OFFSET_CAPTURE ) ) {
			if ( $value === null ) return substr( $tag, 0, $m[0][1] ) . substr( $tag, $m[0][1] + strlen( $m[0][0] ) );
			$q = $m[4][0][0];
			$at = $m[4][1];
			return substr( $tag, 0, $at ) . $q . tt_me_attr_escape( $value, $q ) . $q . substr( $tag, $at + strlen( $m[4][0] ) );
		}
		if ( $value === null ) return $tag;
		$close = substr( $tag, -2 ) === '/>' ? 2 : 1;
		$body = substr( $tag, 0, strlen( $tag ) - $close );
		$trail = strlen( $body ) - strlen( rtrim( $body ) );   // whitespace before the end: stays before it
		return rtrim( $body ) . ' ' . $name . '="' . tt_me_attr_escape( $value, '"' ) . '"' . substr( $body, strlen( $body ) - $trail ) . substr( $tag, -$close );
	}
}

if ( ! function_exists( 'tt_me_patch' ) ) {
	/**
	 * The document text with the changes made: CHANGES id => array( attribute => value or null ). Returns
	 * array( text, '' ) or array( null, why ). Afterwards it parses again and every changed value reads back.
	 */
	function tt_me_patch( $raw, array $changes ) {
		$spans = tt_me_tag_spans( $raw, array_keys( $changes ) );
		if ( $spans === null ) return array( null, 'the document does not parse' );
		uasort( $spans, function ( $a, $b ) { return $b[0] - $a[0]; } );   // from the end: earlier offsets stay right
		foreach ( $spans as $id => $sp ) {
			$tag = substr( $raw, $sp[0], $sp[1] );
			foreach ( $changes[ $id ] as $attr => $val ) $tag = tt_me_set_attr( $tag, $attr, $val );
			$raw = substr( $raw, 0, $sp[0] ) . $tag . substr( $raw, $sp[0] + $sp[1] );
		}
		foreach ( array_keys( $changes ) as $id ) if ( ! isset( $spans[ $id ] ) ) return array( null, "token $id not found in the text" );
		// read back: every changed attribute as intended
		$check = array();
		$p = xml_parser_create( 'UTF-8' );
		xml_parser_set_option( $p, XML_OPTION_CASE_FOLDING, 0 );
		xml_set_element_handler( $p, function ( $p, $name, $attrs ) use ( $changes, &$check ) {
			if ( isset( $attrs['id'] ) && isset( $changes[ $attrs['id'] ] ) && ( $name === 'tok' || $name === 'dtok' ) ) $check[ $attrs['id'] ] = $attrs;
		}, function () {} );
		$ok = xml_parse( $p, $raw, true );
		if ( PHP_VERSION_ID < 80000 ) xml_parser_free( $p );   // does nothing since PHP 8, deprecated in 8.5
		if ( ! $ok ) return array( null, 'the changed document would not parse' );
		foreach ( $changes as $id => $attrs ) {
			foreach ( $attrs as $a => $v ) {
				$got = $check[ $id ][ $a ] ?? null;
				if ( $got !== $v ) return array( null, "$id/$a does not read back as intended" );
			}
		}
		return array( $raw, '' );
	}
}

if ( ! function_exists( 'tt_me_write_doc' ) ) {
	/**
	 * Write a changed document: first a once-a-day backup as TEITOK makes it (backups/NAME-YYYYMMDD.xml, when
	 * there is none yet for today), then the file itself, in place (its owner and permissions stay).
	 */
	function tt_me_write_doc( $path, $old, $new ) {
		if ( ! is_dir( 'backups' ) ) @mkdir( 'backups' );
		$bu = 'backups/' . preg_replace( '/\.xml$/', '-' . date( 'Ymd' ) . '.xml', basename( $path ) );
		if ( ! file_exists( $bu ) && @file_put_contents( $bu, $old ) === false ) return 'no backup could be made';
		$fh = @fopen( $path, 'c+' );
		if ( ! $fh || ! flock( $fh, LOCK_EX ) ) return 'the document cannot be written';
		if ( stream_get_contents( $fh ) !== $old ) { flock( $fh, LOCK_UN ); fclose( $fh ); return 'the document changed while the job ran'; }
		ftruncate( $fh, 0 );
		rewind( $fh );
		$ok = fwrite( $fh, $new ) === strlen( $new );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		return $ok ? '' : 'the document could not be written completely';
	}
}
?>
