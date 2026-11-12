<?php
/**
 * fc_examples.php — example queries from files, for the existing "Example queries" list.
 *
 * Written by the corpus creator in <project>/Resources/cql-example.txt (the format of
 * TEITOK's old clickcql page): a "# title" line, the query on the next line(s), a blank line
 * ends the example. A second file, cql-example.generated.txt (same format; written by
 * `nl2cql examples`), follows the hand-written ones. Neither file = no box at all.
 *
 * An example may carry the full link of a visualization or any saved state, as the Share
 * panel gives it: a line "## url: index.php?action=flexicorp&viz=…" after the title. That link
 * is used instead of the plain query link (only relative index.php?… links are accepted).
 * Other "## key: value" lines are ignored.
 *
 * (?query=…&autorun=1), so it needs no JavaScript.
 *
 *   tt_fc_examples_parse( $text )                    [ [title, query], … ]
 *   tt_fc_examples_html( $projectRoot, $actionName ) the box, or '' when there are none
 */

if ( ! function_exists( 'tt_fc_examples_html' ) ) {

	function tt_fc_examples_parse( $text ) {
		$out = array();
		$title = '';
		$lines = array();
		$url = '';
		$dialect = '';
		$note = '';
		$flush = function () use ( &$out, &$title, &$lines, &$url, &$dialect, &$note ) {
			$q = trim( implode( "\n", $lines ) );
			if ( $title !== '' && $q !== '' ) $out[] = array( $title, $q, $url, $dialect, $note );
			$title = '';
			$lines = array();
			$url = '';
			$dialect = '';
			$note = '';
		};
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( trim( $line ) === '' ) { $flush(); continue; }
			if ( substr( $line, 0, 2 ) === '##' ) {
				// only a relative link into this TEITOK is taken: never another host
				if ( preg_match( '/^##\s*url:\s*(index\.php\?\S+)\s*$/', $line, $m ) ) $url = $m[1];
				if ( preg_match( '/^##\s*dialect:\s*([\w.-]+)\s*$/', $line, $m ) ) $dialect = strtolower( $m[1] );
				if ( preg_match( '/^##\s*note:\s*(.+?)\s*$/', $line, $m ) ) $note = $m[1];
				continue;
			}
			if ( $line[0] === '#' ) { $flush(); $title = trim( substr( $line, 1 ) ); continue; }
			$lines[] = $line;
		}
		$flush();
		return $out;
	}

	function tt_fc_examples_read( $projectRoot ) {
		$all = array();
		$seen = array();
		foreach ( array( 'cql-example.txt', 'cql-example.generated.txt' ) as $name ) {
			$file = rtrim( (string) $projectRoot, '/' ) . '/Resources/' . $name;
			if ( ! is_file( $file ) || ! is_readable( $file ) ) continue;
			foreach ( tt_fc_examples_parse( (string) file_get_contents( $file ) ) as $ex ) {
				if ( isset( $seen[ $ex[1] ] ) ) continue;
				$seen[ $ex[1] ] = true;
				$all[] = $ex;
			}
		}
		return $all;
	}

	function tt_fc_examples_items( $projectRoot, $queryLanguage = '' ) {
		$ql = strtolower( trim( (string) $queryLanguage ) );
		$items = array();
		foreach ( tt_fc_examples_read( $projectRoot ) as $ex ) {
			if ( $ex[3] !== '' && $ql !== '' && $ex[3] !== $ql ) continue;
			$item = array( 'query' => $ex[1], 'label' => $ex[0] );
			if ( $ex[2] !== '' ) $item['url'] = $ex[2];
			if ( $ex[4] !== '' ) $item['note'] = $ex[4];
			$items[] = $item;
		}
		return $items;
	}
}
