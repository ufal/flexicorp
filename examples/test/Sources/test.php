<?php

	$cmd = " /usr/local/bin/cqp -r cqp -c";

	$resp = shell_exec($cmd);
	print "$cmd: $resp";

	exit;

?>
