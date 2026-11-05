<?php
	// index.php?action=corpora — the portal's corpus picker (fqsportal.php): the corpora this
	// portal serves from FQS, the ones the caller may query; a link selects one for the search.
	// Admins: ?refresh=1 rereads the catalogue and rebuilds the cached settings fragments.

	include_once( __DIR__ . '/fqsportal.php' );

	$fqp_h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };

	// the role as fqs_query.php signs it
	$fqp_admin = isset( $user ) && is_array( $user ) && ( $user['permissions'] ?? '' ) === 'admin';
	$fqp_role = $fqp_admin ? 'admin' : ( ! empty( $username ) ? 'user' : 'visitor' );

	if ( ! fqp_enabled() ) {
		$maintext .= "<h1>Corpora</h1><p class=wrong>This project is not set up as a portal: add &lt;fqsportal/&gt; to its settings.xml.</p>";
		return;
	}

	$fqp_fresh = $fqp_admin && ! empty( $_GET['refresh'] );
	if ( $fqp_fresh && ( $fqp_dir = fqp_cache_dir() ) !== '' ) {
		foreach ( glob( $fqp_dir . '/*.json' ) as $f ) @unlink( $f );
	}
	$fqp_cat = fqp_catalogue( $fqp_fresh, $fqp_err );
	$fqp_sel = fqp_session();
	$fqp_param = fqp_conf( 'param', 'corpus' );

	$maintext .= "<h1>Corpora</h1>";
	if ( ! empty( $GLOBALS['fqsportal']['error'] ) ) {
		$maintext .= "<p class=wrong>" . $fqp_h( $GLOBALS['fqsportal']['error'] )
			. ( $fqp_admin && ! empty( $GLOBALS['fqsportal']['detail'] ) ? ' (' . $fqp_h( $GLOBALS['fqsportal']['detail'] ) . ')' : '' ) . "</p>";
	}
	// the reason names the FQS address: only for admins
	if ( $fqp_err !== '' ) $maintext .= "<p class=wrong>" . ( $fqp_admin ? $fqp_h( $fqp_err ) : 'The list of corpora cannot be read right now. Please try again later.' ) . "</p>";

	$fqp_rows = array();
	foreach ( $fqp_cat as $fqp_id => $fqp_row ) {
		if ( fqp_role_may( $fqp_row, $fqp_role ) ) $fqp_rows[ $fqp_id ] = $fqp_row;
	}
	uasort( $fqp_rows, function ( $a, $b ) {
		return strcasecmp( ( $a['family_label'] ?? '' ) . "\t" . ( $a['label'] ?? $a['id'] ), ( $b['family_label'] ?? '' ) . "\t" . ( $b['label'] ?? $b['id'] ) );
	} );

	if ( ! $fqp_rows ) {
		$maintext .= "<p>No corpora available.</p>";
		return;
	}

	$maintext .= "<p><input id=fqpfilter type=search placeholder='Filter: name, language, label …' style='width: 24em;' oninput='fqpFilter(this.value)'> "
		. count( $fqp_rows ) . " corpora"
		. ( $fqp_admin ? " · <a href='index.php?action=corpora&refresh=1'>reread from FQS</a>" : '' ) . "</p>";
	$maintext .= "<table id=fqptable class=rollovertable><tr><th>Corpus<th>Languages<th>Size<th>Engine<th>Description" . ( $fqp_admin ? "<th>Attributes from" : '' ) . "</tr>";
	$fqp_family = null;
	foreach ( $fqp_rows as $fqp_id => $fqp_row ) {
		$s = isset( $fqp_row['settings'] ) && is_array( $fqp_row['settings'] ) ? $fqp_row['settings'] : array();
		$fam = (string) ( $fqp_row['family_label'] ?? '' );
		if ( $fam !== '' && $fam !== $fqp_family ) {
			$maintext .= "<tr class=fqpfam><th colspan=" . ( $fqp_admin ? 6 : 5 ) . " style='text-align: left;'>" . $fqp_h( $fam ) . "</th></tr>";
		}
		$fqp_family = $fam;
		$langs = implode( ', ', array_map( 'strval', (array) ( $s['languages'] ?? array() ) ) );
		$size = isset( $fqp_row['corpus_size'] ) && $fqp_row['corpus_size'] ? number_format( (int) $fqp_row['corpus_size'] ) : '';
		$desc = (string) ( $s['description'] ?? '' );
		if ( mb_strlen( $desc ) > 200 ) $desc = mb_substr( $desc, 0, 200 ) . '…';
		$labels = implode( ' ', array_map( 'strval', (array) ( $fqp_row['labels'] ?? array() ) ) );
		$link = 'index.php?action=flexicorp&' . rawurlencode( $fqp_param ) . '=' . rawurlencode( $fqp_id );
		$mark = $fqp_id === $fqp_sel ? ' style="font-weight: bold;"' : '';
		$maintext .= "<tr class=fqprow data-search='" . $fqp_h( strtolower( $fqp_id . ' ' . ( $fqp_row['label'] ?? '' ) . ' ' . $langs . ' ' . $labels . ' ' . $fam ) ) . "'>"
			. "<td$mark><a href='" . $fqp_h( $link ) . "'>" . $fqp_h( $fqp_row['label'] ?? $fqp_id ) . "</a>"
			. ( ( $fqp_row['label'] ?? '' ) !== $fqp_id ? " <span style='opacity: 0.6;'>" . $fqp_h( $fqp_id ) . "</span>" : '' )
			. "<td>" . $fqp_h( $langs )
			. "<td style='text-align: right;'>" . $fqp_h( $size )
			. "<td>" . $fqp_h( fqp_engine( $fqp_row ) )
			. "<td>" . $fqp_h( $desc );
		if ( $fqp_admin ) {
			$dir = fqp_cache_dir();
			$cf = $dir !== '' ? $dir . '/' . preg_replace( '/[^A-Za-z0-9_.-]/', '_', $fqp_id ) . '.json' : '';
			$c = $cf !== '' && is_file( $cf ) ? json_decode( (string) file_get_contents( $cf ), true ) : null;
			$maintext .= "<td>" . ( is_array( $c ) ? $fqp_h( $c['source'] ) . ( $c['note'] !== '' ? " <span title='" . $fqp_h( $c['note'] ) . "'>ⓘ</span>" : '' ) : '<i>not yet built</i>' );
		}
		$maintext .= "</tr>";
	}
	$maintext .= "</table>
	<script>
	function fqpFilter(q) {
		q = q.toLowerCase().trim();
		document.querySelectorAll('#fqptable tr.fqprow').forEach(function (tr) {
			tr.style.display = q === '' || tr.dataset.search.indexOf(q) >= 0 ? '' : 'none';
		});
		document.querySelectorAll('#fqptable tr.fqpfam').forEach(function (tr) { tr.style.display = q === '' ? '' : 'none'; });
	}
	</script>";
?>
