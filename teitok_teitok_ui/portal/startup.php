<?php
	// fqsportal: TEITOK startup hook (installed by install-portal.pl; it replaces only this file
	// while it is unchanged). TEITOK runs a project's Sources/startup.php after reading
	// Resources/settings.xml and before the shared settings fill in (common/Sources/settings.php):
	// the place to put the selected corpus's settings over the portal's own (fqsportal.php).
	// A project with a startup.php of its own calls the portal from there instead:
	//   include_once( __DIR__ . '/fqsportal.php' ); fqp_startup();
	include_once( __DIR__ . '/fqsportal.php' );
	fqp_startup();
?>
