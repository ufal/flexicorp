<?php

	// define where the TEITOK common files can be found
	$ttroot = getenv('TT_ROOT') or "..";

	$debug  = 1;

	// define which time-zone to use (obligatory for date in PHP)
	date_default_timezone_set('UTC');
	
	// define which errors to report
	ini_set('display_errors', '1');
	error_reporting(E_ERROR|E_WARNING);

	ini_set('upload_max_filesize', '100M');
	ini_set('post_max_size', '100M');
	ini_set('memory_limit', '100M');

	// call the main php script and only use resources from here
	include ( "$ttroot/common/Sources/main.php" );

?>
