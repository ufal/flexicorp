<?php
/**
 * multiedit's background worker (command line only; started by multiedit.php):
 *
 *   php multiedit_worker.php --job FILE --project DIR --ttroot DIR [--shared DIR]
 *
 * Applies a queued job document by document. The document is read as TEITOK reads it, for the checks; the
 * changes are written byte-preserving (multiedit_write.php: only the changed attributes change), after a
 * once-a-day backup in backups/ as TEITOK makes. Per change it checks that the token is still there, that its form
 * and the old value are still what the page showed (else skipped: someone may have changed it since), and
 * for a head that it is in the document and makes no cycle. It records per change what it did and the progress
 * after each document. One worker at a time per project (a lock); a second waits its turn.
 */

if ( PHP_SAPI !== 'cli' ) return;   // installed next to the pages: nothing to do as a page

$opt = getopt( '', array( 'job:', 'project:', 'ttroot:', 'shared::' ) );
foreach ( array( 'job', 'project', 'ttroot' ) as $k ) {
	if ( empty( $opt[ $k ] ) ) { fwrite( STDERR, "multiedit_worker: --$k is needed\n" ); exit( 2 ); }
}
$jobfile = $opt['job'];
if ( ! chdir( $opt['project'] ) ) { fwrite( STDERR, "multiedit_worker: no project {$opt['project']}\n" ); exit( 2 ); }

// TEITOK as its pages have it: functions, the settings (with the shared project's)
$ttroot = $opt['ttroot'];
$sharedfolder = isset( $opt['shared'] ) ? (string) $opt['shared'] : '';
$xmlfolder = 'xmlfiles';
$username = '';
error_reporting( E_ERROR );
ob_start();
require "$ttroot/common/Sources/functions.php";
require "$ttroot/common/Sources/settings.php";
ob_end_clean();
require_once __DIR__ . '/multiedit_jobs.php';
require_once __DIR__ . '/multiedit_xml.php';
require_once __DIR__ . '/multiedit_write.php';

$lock = fopen( tt_me_dir( 'jobs' ) . '/worker.lock', 'c' );
if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { fwrite( STDERR, "multiedit_worker: no lock\n" ); exit( 1 ); }

$job = tt_me_read_json( $jobfile );
if ( ! $job || $job['status'] !== 'queued' ) { fwrite( STDERR, "multiedit_worker: no queued job in $jobfile\n" ); exit( 1 ); }
$save = function () use ( &$job, $jobfile ) { tt_me_write_json( $jobfile, $job ); };

$bydoc = array();
foreach ( $job['changes'] as $i => $c ) $bydoc[ $c['doc'] ][] = $i;
$job['status'] = 'running';
$job['started'] = date( 'Y-m-d H:i:s' );
$job['results'] = array();
$job['progress'] = array( 'documents' => count( $bydoc ), 'done' => 0 );
$save();

try {
	foreach ( $bydoc as $doc => $idx ) {
		$res = tt_me_apply_doc( $doc, $idx, $job['changes'] );
		foreach ( $res as $r ) $job['results'][] = $r;
		$job['progress']['done']++;
		$save();
	}
	$job['status'] = 'done';
} catch ( Throwable $e ) {
	$job['status'] = 'failed';
	$job['error'] = $e->getMessage();
}
$job['finished'] = date( 'Y-m-d H:i:s' );
$save();
// an undo: the job it undid says so
if ( ! empty( $job['undo_of'] ) && $job['status'] === 'done' && ( $orig = tt_me_job_load( $job['undo_of'] ) ) ) {
	$orig['undone_by'] = $job['id'];
	tt_me_job_save( $orig );
}
flock( $lock, LOCK_UN );

/**
 * The changes (indexes into CHANGES) of one document: results array( i, result, why ) with result applied,
 * same (already so), skipped.
 */
function tt_me_apply_doc( $doc, array $idx, array $changes ) {
	$out = array();
	$skip = function ( $why ) use ( $idx ) {
		return array_map( function ( $i ) use ( $why ) { return array( 'i' => $i, 'result' => 'skipped', 'why' => $why ); }, $idx );
	};
	$path = tt_me_doc_path( $doc );
	if ( $path === '' ) return $skip( 'the document is not there' );
	if ( ! is_writable( $path ) ) return $skip( 'the document cannot be written (file permissions)' );
	$raw = (string) file_get_contents( $path );
	$xml = tt_me_load( $path );
	if ( ! $xml ) return $skip( 'the document cannot be read' );
	list( , , $byid ) = tt_me_doc_index( $xml );
	$patch = array();
	$pending = array();
	foreach ( $idx as $i ) {
		$c = $changes[ $i ];
		$el = $byid[ $c['id'] ] ?? null;
		if ( ! $el ) { $out[] = array( 'i' => $i, 'result' => 'skipped', 'why' => 'the token is not in the document' ); continue; }
		if ( $c['form'] !== '' && tt_me_form( $el ) !== $c['form'] ) {
			$out[] = array( 'i' => $i, 'result' => 'skipped', 'why' => 'the token is now "' . tt_me_form( $el ) . '"' ); continue;
		}
		$now = (string) $el[ $c['field'] ];
		if ( $now === $c['new'] ) { $out[] = array( 'i' => $i, 'result' => 'same' ); continue; }
		if ( $now !== $c['old'] ) {
			$out[] = array( 'i' => $i, 'result' => 'skipped', 'why' => "{$c['field']} is now \"$now\", not \"{$c['old']}\": changed since" ); continue;
		}
		if ( $c['field'] === 'head' && $c['new'] !== '' ) {
			if ( ! isset( $byid[ $c['new'] ] ) ) { $out[] = array( 'i' => $i, 'result' => 'skipped', 'why' => 'the new head is not in the document' ); continue; }
			if ( tt_me_cycle( $c['id'], $c['new'], $byid ) ) { $out[] = array( 'i' => $i, 'result' => 'skipped', 'why' => 'it would make a cycle' ); continue; }
		}
		$patch[ $c['id'] ][ $c['field'] ] = $c['new'] === '' ? null : $c['new'];
		$el[ $c['field'] ] = $c['new'];   // later changes of this job (a head after a head) see it
		$pending[] = $i;
	}
	if ( $pending ) {
		list( $new, $why ) = tt_me_patch( $raw, $patch );
		if ( $new !== null ) $why = tt_me_write_doc( $path, $raw, $new );
		foreach ( $pending as $i ) $out[] = $why === '' ? array( 'i' => $i, 'result' => 'applied' ) : array( 'i' => $i, 'result' => 'skipped', 'why' => $why );
	}
	usort( $out, function ( $a, $b ) { return $a['i'] - $b['i']; } );
	return $out;
}
?>
