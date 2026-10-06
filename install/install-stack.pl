#!/usr/bin/env perl
# install-stack.pl - install or upgrade the TEITOK query stack:
#
#   teitok      TEITOK itself: its checkout is pulled, and the TEITOK tool it still needs
#               (tt-xpath) rebuilt when its sources changed
#   flexicorp   Python package in TEITOK's venv (multi-engine corpus access)
#   pages       flexicorp's TEITOK pages, installed into the shared project
#   flexencoder TEITOK XML -> CWB / Pando / xidx encoder
#   pando       pando, pando-index, pando-server, libflexicorp_pando, flexicorp-pando
#   fqs         FQS query / reindex service (systemd, launchd, or a start script)
#
# It works on an existing TEITOK installation: it finds where TEITOK lives
# (Apache .htaccess or vhost SetEnv, nginx fastcgi_param, php-fpm env[], the
# Scripts symlink, the usual places), which user runs PHP, and the shared
# project, and changes nothing in TEITOK's configuration except adding
# settings that are not there yet. Re-running it upgrades the stack: it pulls the
# checkouts (git pull --ff-only, as their owner), then rebuilds and installs only the
# components whose sources changed since the last install (clean builds, atomic
# installs); --force rebuilds them anyway. When the pull updated this installer
# itself, it restarts with the new version.
# install-teitok.pl runs it at the end of a fresh install and for --upgrade.
#
# Usage:
#   sudo perl install-stack.pl [options]
#
#   --detect              only print what was found (as KEY=VALUE lines) and exit
#   --check               only run the checks (check-stack.pl) and exit
#   -q, --yes             no questions: accept what was detected (an installation
#                         confirmed once is not asked about again)
#   --teitok-root DIR     the TEITOK checkout (TT_ROOT)          [detected]
#   --shared DIR          the TEITOK shared project (TT_SHARED)  [detected]
#   --web-user USER       the user PHP runs as                    [detected]
#   --git-folder DIR      where flexicorp and pando are checked out
#                         [the folder of the flexicorp checkout this script runs from;
#                          else the one the previous run used; else next to TEITOK]
#   --prefix DIR          binaries and libraries (default /usr/local)
#   --only LIST           comma-separated subset of: teitok,flexicorp,pages,flexencoder,pando,fqs
#   --skip LIST           components to leave out
#   --flexicorp-repo URL  (default https://github.com/ufal/flexicorp.git; a local path works)
#   --flexicorp-ref REF   branch / tag for a new clone (default: the repository's default)
#   --pando-repo URL      (default https://github.com/ufal/pando.git)
#   --pando-ref REF
#   --no-pull             use the existing checkouts as they are
#   --force [LIST]        rebuild and reinstall even when the sources did not change:
#                         every component, or the comma-separated ones in LIST
#   --cron                for unattended updates (cron, a systemd timer): no questions,
#                         no output and no log when nothing changed; a summary (which cron
#                         mails) when something was updated, a pull failed or a step failed.
#                         Exits quietly when another run is still busy.
#                         e.g. /etc/cron.d/teitok-stack:
#                           30 3 * * * root perl /path/to/flexicorp/install/install-stack.pl --cron
#
#   --auto-update [HH:MM] set up a nightly run with --cron (default 02:00): a systemd timer
#                         (teitok-stack-update.timer), else /etc/cron.d/teitok-stack, on
#                         macOS a launchd job. A first interactive run offers this itself.
#   --no-auto-update      remove that nightly run (and do not offer it again)
#
# Only one run at a time (a lock in PREFIX/share/teitok-stack). FQS is not restarted while
# it runs a reindex job: the restart is postponed (marked in PREFIX/share/teitok-stack) and
# done by the next run once the jobs have finished.
#   --no-deps             do not install system packages (compilers, RE2, Rust, ...)
#   --fqs-admin EMAIL     TEITOK user allowed into the FQS admin (only set when
#                         flexicorp/fqs_admin_users is not set yet)
#   --no-check            skip the checks at the end
#   --no-frontends        leave the files of other frontends (KonText, …) alone: by
#                         default the FQS service gets write access to the files its
#                         frontend modules edit (KonText's corplist.xml,
#                         pando_corpora.json, the Manatee registry/data/vert folders):
#                         a systemd ReadWritePaths drop-in, and an ACL (or group write)
#                         for the service user — owners and other permissions stay
#
# Log: /var/log/teitok-install/stack-<time>.log (macOS: /Library/Logs/teitok-install/).
use strict;
use warnings;
use File::Basename qw(dirname basename);
use File::Path qw(make_path remove_tree);
use File::Copy qw(copy);
use File::Spec;
use Cwd qw(abs_path getcwd);
use POSIX qw(strftime);
use Getopt::Long qw(:config no_ignore_case bundling);
use Digest::SHA qw(sha1_hex);

my $VERSION = '0.2.0';
my %o = (
	prefix         => '/usr/local',
	'flexicorp-repo' => 'https://github.com/ufal/flexicorp.git',
	'pando-repo'     => 'https://github.com/ufal/pando.git',
	'flexicorp-ref'  => '',
	'pando-ref'      => '',
	only => '', skip => '',
);
my @ORIG_ARGV = @ARGV;    # to restart with the same options when the pull updated this script
GetOptions( \%o, 'detect', 'check', 'q|yes', 'teitok-root=s', 'shared=s', 'web-user=s', 'git-folder=s',
	'prefix=s', 'only=s', 'skip=s', 'flexicorp-repo=s', 'flexicorp-ref=s', 'pando-repo=s', 'pando-ref=s',
	'no-pull', 'no-deps', 'fqs-admin=s', 'no-check', 'no-frontends', 'force:s', 'cron', 'auto-update:s', 'no-auto-update', 'help|h' ) or exit 2;
my $CRON = $o{cron} ? 1 : 0;
$o{q} = 1 if $CRON;
if ( $o{help} ) { usage(); exit 0; }

my $OS   = $^O;                                   # linux, darwin, freebsd
my $MAC  = $OS eq 'darwin';
my $ME   = abs_path($0);
my $HERE = dirname($ME);                           # .../flexicorp/install
my $STAMP = strftime( '%Y%m%d-%H%M%S', localtime );
my $ME_SHA = do { open my $fh, '<', $ME; local $/; my $c = <$fh> // ''; sha1_hex($c) };   # to notice a pull that updates this script

# ── logging ──────────────────────────────────────────────────────────────────
my $LOGDIR = $MAC ? '/Library/Logs/teitok-install' : '/var/log/teitok-install';
make_path($LOGDIR) unless -d $LOGDIR;
$LOGDIR = '/tmp' unless -w $LOGDIR;
my $LOG = "$LOGDIR/stack-$STAMP.log";
open( my $LOGFH, '>>', $LOG ) or die "cannot write $LOG: $!\n";
{ my $old = select($LOGFH); $| = 1; select($old); }
$| = 1;

sub logline { print $LOGFH @_; }
# --cron: everything goes to the log only; the summary at the end decides what is printed
sub say_ { my $m = join( '', @_ ); print $m unless $CRON; logline($m); }
my @WARNINGS;
sub step { my $m = shift; say_("\n== $m\n"); }
sub warn_ { push @WARNINGS, join( '', @_ ); say_( "  warning: ", @_, "\n" ); }
sub fail {
	my $m = shift;
	my $out = "\n!!!! $m\n     full log: $LOG\n";
	print $out;    # also with --cron: this is what cron mails
	logline($out);
	exit 1;
}
sub q_ { my $s = shift; return $s if $s =~ m{^[\w./:=+,@%-]+$}; $s =~ s/'/'\\''/g; return "'$s'"; }
sub cmdline { return join( ' ', map { q_($_) } @_ ); }

# run a command (list), output to the log; on failure show the tail and die (unless $soft)
sub run {
	my ( $desc, $cmd, %opt ) = @_;
	my $line = ref $cmd ? cmdline(@$cmd) : $cmd;
	my $env = '';
	if ( $opt{env} ) { $env = join( ' ', map { "$_=" . q_( $opt{env}{$_} ) } sort keys %{ $opt{env} } ) . ' '; }
	my $cd = $opt{cwd} ? 'cd ' . q_( $opt{cwd} ) . ' && ' : '';
	say_("  $desc ...\n") if $desc;
	logline("\$ $cd$env$line\n");
	my $rc = system( "$cd${env}$line >>" . q_($LOG) . " 2>&1" );
	if ( $rc != 0 ) {
		return 0 if $opt{soft};
		my $tail = `tail -25 $LOG`;
		print "\n----- last lines of the log -----\n$tail---------------------------------\n";
		fail("$desc failed (exit " . ( $rc >> 8 ) . ")");
	}
	return 1;
}
sub capture { my $c = shift; $c .= ' 2>/dev/null' if $c !~ /2>/; my $out = `$c`; $out = '' unless defined $out; chomp $out; return $out; }
sub have { my $c = shift; return capture("command -v $c") ne ''; }

sub ask {
	my ( $q, $default ) = @_;
	return $default if $o{q} || !-t STDIN;
	print "$q [$default] ";
	my $a = <STDIN>; $a = '' unless defined $a; chomp $a;
	return $a eq '' ? $default : $a;
}
sub yes { my ( $q, $def ) = @_; my $a = ask( $q, $def ? 'y' : 'n' ); return $a =~ /^y/i; }

# The files FQS's frontend modules edit (KonText's corplist.xml and pando_corpora.json,
# the Manatee registry, data and vert folders; `fqs frontends paths` lists them) must be
# writable for the FQS service: its systemd unit has ProtectSystem=strict, so their
# folders go in a ReadWritePaths drop-in, and the service user gets write access by an ACL (owner,
# group and mode stay as KonText has them), else — when the file's group is root — by
# group write for the service group. Folders get a default ACL too, so that what FQS
# creates there stays writable for it.
sub frontend_access {
	my ( $fqs, $user, $group ) = @_;
	return unless -x $fqs;
	my $json = capture( q_($fqs) . ' frontends paths' );
	return if $json eq '';
	require JSON::PP;
	my $d = eval { JSON::PP::decode_json($json) } or return;
	my @paths = @{ $d->{paths} || [] };
	return unless @paths;
	say_("  frontends: write access for $user to the files FQS edits for them\n");
	my $acl = have('setfacl');
	my @rw;
	for my $p (@paths) {
		my $path = $p->{path};
		if ( $p->{dir} && !-d $path ) {
			# the data / vert folders next to an existing Manatee registry folder
			if ( -d dirname($path) ) { run( "create $path", [ 'install', '-d', '-m', '0755', $path ] ); }
			else { next; }
		}
		next unless -e $path;
		# the folder, not the file: a bind-mounted file would keep pointing at the old
		# one when KonText's deployment replaces it. What may be written there is
		# still decided by the permissions below (write access to the file only).
		my $rwp = $p->{dir} ? $path : dirname($path);
		push @rw, $rwp unless grep { $_ eq $rwp } @rw;
		my $ok = system( as_user( $user, 'test', '-w', $path ) ) == 0;
		if ( !$ok ) {
			if ($acl) {
				my $perm = $p->{dir} ? 'rwx' : 'rw';
				run( "ACL $user:$perm on $path", [ 'setfacl', '-m', "u:$user:$perm", $path ], soft => 1 )
					or warn_("setfacl failed on $path");
				run( '', [ 'setfacl', '-d', '-m', "u:$user:rwx", $path ], soft => 1 ) if $p->{dir};
			} elsif ( ( stat($path) )[5] == 0 ) {
				run( "group $group, group write on $path", [ 'chgrp', $group, $path ] );
				run( '', [ 'chmod', $p->{dir} ? 'g+rwxs' : 'g+rw', $path ] );
			} else {
				warn_("$user cannot write $path (and setfacl is not installed): give it write access, e.g. apt install acl && setfacl -m u:$user:rw $path");
			}
		}
		say_("    $path\n");
	}
	if ( -d '/run/systemd/system' && @rw ) {
		my $dir = '/etc/systemd/system/fqs.service.d';
		make_path($dir);
		open my $fh, '>', "$dir/frontends.conf" or return warn_("cannot write $dir/frontends.conf: $!");
		print $fh "# written by install-stack.pl: files and folders FQS's frontend modules edit\n"
			. "# (`fqs frontends paths`); '-' = ignore when missing\n[Service]\n";
		print $fh "ReadWritePaths=-$_\n" for @rw;
		close $fh;
		say_("    systemd: $dir/frontends.conf (ReadWritePaths)\n");
	}
}

# run as another user: runuser (Linux, works with nologin shells) or sudo. With that
# user's HOME (runuser keeps root's otherwise), so git finds their ssh keys, known_hosts and
# credential helper; an ssh agent passed through sudo (--preserve-env=SSH_AUTH_SOCK) too.
sub as_user {
	my ( $user, @cmd ) = @_;
	my $cur = getpwuid($<);
	return @cmd if !defined $user || $user eq '' || $user eq $cur;
	my @pw = getpwnam($user);
	my @env = ( 'env', ( @pw && $pw[7] ? ( "HOME=$pw[7]" ) : () ), "USER=$user", "LOGNAME=$user",
		( $ENV{SSH_AUTH_SOCK} ? ( "SSH_AUTH_SOCK=$ENV{SSH_AUTH_SOCK}" ) : () ) );
	return ( 'runuser', '-u', $user, '--', @env, @cmd ) if !$MAC && have('runuser');
	return ( 'sudo', '-H', '-u', $user, @env, @cmd );
}

sub usage { open my $me, '<', $0 or return; while (<$me>) { next if /^#!/; last unless /^#/; s/^# ?//; print; } }

my @PATHS_EXTRA = ( '/usr/local/bin', '/usr/sbin', '/sbin', '/opt/homebrew/bin', '/opt/rust/cargo/bin' );
$ENV{PATH} = join( ':', grep { -d $_ } ( split( /:/, $ENV{PATH} || '/usr/bin:/bin' ), @PATHS_EXTRA ) );

if ( $< != 0 && !$o{detect} && !$o{check} ) {
	fail("run as root (sudo perl $0 ...): it installs into $o{prefix} and runs builds as other users");
}

# one run at a time: two runs would remove each other's build folders (a lock held until the
# process ends; a restart with a newer version of this script takes it over)
my $LOCKFH;
if ( !$o{detect} && !$o{check} ) {
	use Fcntl qw(:flock);
	my $ld = "$o{prefix}/share/teitok-stack";
	make_path($ld) unless -d $ld;
	open( $LOCKFH, '>>', "$ld/install.lock" ) or fail("cannot open $ld/install.lock: $!");
	if ( !flock( $LOCKFH, LOCK_EX | LOCK_NB ) ) {
		if ($CRON) { close $LOGFH; unlink $LOG; exit 0; }    # the previous run is still busy: next time
		fail("another install-stack.pl is running (lock: $ld/install.lock)");
	}
}

# ── platform ────────────────────────────────────────────────────────────────
sub detect_pkg {
	return 'brew'   if $MAC;
	return 'apt'    if have('apt-get');
	return 'dnf'    if have('dnf');
	return 'yum'    if have('yum');
	return 'zypper' if have('zypper');
	return 'apk'    if have('apk');
	return 'pacman' if have('pacman');
	return 'pkg'    if have('pkg') && $OS eq 'freebsd';
	return '';
}
my $PKG  = detect_pkg();
my $INIT = $MAC ? 'launchd' : ( -d '/run/systemd/system' ? 'systemd' : 'none' );
my $SUDO_USER = $ENV{SUDO_USER} || '';

# ── detection of an existing TEITOK ─────────────────────────────────────────
my @WEBROOTS = ( '/var/www/html', '/srv/www/htdocs', '/var/www/localhost/htdocs', '/srv/http',
	'/usr/local/www/apache24/data', '/Library/WebServer/Documents', '/opt/homebrew/var/www',
	'/usr/local/var/www', '/var/www' );

sub read_file { my $f = shift; open my $fh, '<', $f or return ''; local $/; my $s = <$fh>; close $fh; return $s // ''; }

sub detect_teitok {
	my %d = ( how => [] );
	my %env;    # TT_ROOT / TT_SHARED / SMARTY_DIR => [value, source]
	my $note = sub { my ( $k, $v, $src ) = @_; return if !defined $v || $v eq '' || $env{$k}; $v =~ s/^["']|["']$//g; $env{$k} = [ $v, $src ]; };

	# 1. Apache: <webroot>/teitok/.htaccess, then SetEnv in the server config
	for my $w (@WEBROOTS) {
		my $ht = "$w/teitok/.htaccess";
		next unless -r $ht;
		my $s = read_file($ht);
		while ( $s =~ /^\s*SetEnv\s+(TT_ROOT|TT_SHARED|SMARTY_DIR)\s+(\S+)/mg ) { $note->( $1, $2, $ht ); }
		$d{webroot} //= $w;
		$d{apache_htaccess} = $ht;
	}
	my @apconf = grep { -d $_ } ( '/etc/apache2', '/etc/httpd', '/usr/local/etc/apache24', '/opt/homebrew/etc/httpd', '/usr/local/etc/httpd' );
	if (@apconf) {
		my $g = capture( 'grep -rhE "^[[:space:]]*SetEnv[[:space:]]+TT_(ROOT|SHARED)" ' . join( ' ', map { q_($_) } @apconf ) );
		while ( $g =~ /SetEnv\s+(TT_ROOT|TT_SHARED|SMARTY_DIR)\s+(\S+)/g ) { $note->( $1, $2, 'apache config' ); }
	}
	# 2. nginx: fastcgi_param in the full configuration
	if ( have('nginx') ) {
		my $n = capture('nginx -T 2>&1');
		while ( $n =~ /fastcgi_param\s+(TT_ROOT|TT_SHARED|SMARTY_DIR)\s+([^;\s]+)\s*;/g ) { $note->( $1, $2, 'nginx config' ); }
		$d{nginx_teitok} = 1 if $n =~ /TT_ROOT|TT_SHARED|\/teitok/;
	}
	# 3. php-fpm pools: env[TT_ROOT] = ...
	for my $pool ( glob('/etc/php/*/fpm/pool.d/*.conf'), glob('/etc/php-fpm.d/*.conf'), glob('/etc/php*/php-fpm.d/*.conf'),
		glob('/usr/local/etc/php-fpm.d/*.conf'), glob('/opt/homebrew/etc/php/*/php-fpm.d/*.conf') ) {
		my $s = read_file($pool);
		while ( $s =~ /^\s*env\[(TT_ROOT|TT_SHARED|SMARTY_DIR)\]\s*=\s*(\S+)/mg ) { $note->( $1, $2, $pool ); }
	}

	# shared project
	if ( $o{shared} ) { $d{shared} = $o{shared}; push @{ $d{how} }, "shared: --shared"; }
	elsif ( $env{TT_SHARED} ) { $d{shared} = $env{TT_SHARED}[0]; push @{ $d{how} }, "shared: TT_SHARED in $env{TT_SHARED}[1]"; }
	if ( !$d{shared} ) {
		for my $w (@WEBROOTS) {
			next unless -d "$w/teitok";
			if ( -f "$w/teitok/shared/Resources/settings.xml" ) { $d{shared} = "$w/teitok/shared"; push @{ $d{how} }, 'shared: <webroot>/teitok/shared'; last; }
			for my $p ( sort glob("$w/teitok/*") ) {
				next unless -f "$p/Resources/userlist.xml" && -f "$p/Resources/settings.xml";
				if ( read_file("$p/Resources/userlist.xml") =~ /projects="all"/ ) { $d{shared} = $p; push @{ $d{how} }, "shared: $p (admin for all projects)"; last; }
			}
			last if $d{shared};
		}
	}
	if ( $d{shared} ) {
		$d{shared} =~ s{/+$}{};
		$d{teitok_dir} = dirname( $d{shared} );
		$d{webroot} = dirname( $d{teitok_dir} ) if !$d{webroot} || index( $d{teitok_dir}, $d{webroot} ) != 0;
	}

	# TEITOK checkout
	my @ttc;
	push @ttc, [ $o{'teitok-root'}, '--teitok-root' ] if $o{'teitok-root'};
	push @ttc, [ $env{TT_ROOT}[0], "TT_ROOT in $env{TT_ROOT}[1]" ] if $env{TT_ROOT};
	if ( $d{teitok_dir} && -l "$d{teitok_dir}/Scripts" ) {
		my $t = readlink("$d{teitok_dir}/Scripts"); $t = abs_path("$d{teitok_dir}/$t") if $t !~ m{^/};
		push @ttc, [ dirname($t), "the $d{teitok_dir}/Scripts symlink" ] if $t;
	}
	if ( $d{shared} && -f "$d{shared}/index.php" && read_file("$d{shared}/index.php") =~ /\$ttroot\s*=\s*["']([^"']+)["']/ ) {
		push @ttc, [ $1, "$d{shared}/index.php" ];
	}
	push @ttc, map { [ $_, 'a usual place' ] } grep { -f "$_/common/Sources/main.php" }
		( '/home/git/TEITOK', glob('/home/*/git/TEITOK'), glob('/home/*/Git/TEITOK'), glob('/home/*/TEITOK'),
		  glob('/opt/*/TEITOK'), glob('/srv/*/TEITOK'), glob('/usr/local/*/TEITOK'), glob('/Users/*/git/TEITOK'), glob('/Users/*/Git/TEITOK') );
	for my $c (@ttc) {
		my ( $p, $src ) = @$c;
		$p =~ s{/+$}{};
		next unless -f "$p/common/Sources/main.php";
		$d{teitok_root} = $p; push @{ $d{how} }, "TEITOK checkout: $src"; last;
	}

	# web user: owner of the shared project, else the web server's user
	if ( $o{'web-user'} ) { $d{web_user} = $o{'web-user'}; push @{ $d{how} }, 'web user: --web-user'; }
	elsif ( $d{shared} && -d "$d{shared}/Resources" ) {
		my $uid = ( stat("$d{shared}/Resources") )[4];
		my $u = getpwuid($uid);
		if ( $u && $u ne 'root' ) { $d{web_user} = $u; push @{ $d{how} }, "web user: owner of $d{shared}/Resources"; }
	}
	if ( !$d{web_user} ) {
		for my $u ( 'www-data', 'apache', 'nginx', 'wwwrun', 'http', 'www', '_www' ) {
			if ( defined getpwnam($u) ) { $d{web_user} = $u; push @{ $d{how} }, "web user: $u exists on this system"; last; }
		}
	}
	if ( $d{web_user} ) { my @pw = getpwnam( $d{web_user} ); $d{web_group} = getgrgid( $pw[3] ) // $d{web_user} if @pw; }

	# web server serving TEITOK
	$d{webserver} = $d{nginx_teitok} ? 'nginx' : ( $d{apache_htaccess} ? 'apache' : ( have('nginx') ? 'nginx' : ( have('apache2ctl') || have('apachectl') || have('httpd') ? 'apache' : '' ) ) );

	# venv
	if ( $d{shared} ) {
		my $set = read_file("$d{shared}/Resources/settings.xml");
		if ( $set =~ /<defaults\b[^>]*>.*?<base\b[^>]*\bvenv="([^"]+)"/s ) { $d{venv} = $1; push @{ $d{how} }, 'venv: defaults/base/venv'; }
		$d{venv} //= "$d{shared}/Resources/venv";
	}
	return \%d;
}

# ── main ────────────────────────────────────────────────────────────────────
my $D = detect_teitok();
if ( $o{detect} ) {
	for my $k (qw(teitok_root shared teitok_dir webroot web_user web_group webserver venv)) { print uc($k), '=', ( $D->{$k} // '' ), "\n"; }
	print "HOW=$_\n" for @{ $D->{how} };
	print "OS=$OS\nPKG=$PKG\nINIT=$INIT\n";
	exit( $D->{shared} && $D->{teitok_root} ? 0 : 3 );
}
if ( $o{check} ) { exec( 'perl', "$HERE/check-stack.pl", ( $D->{shared} ? ( '--shared', $D->{shared} ) : () ), ( $D->{web_user} ? ( '--web-user', $D->{web_user} ) : () ), '--prefix', $o{prefix} ); }

say_("TEITOK stack installer $VERSION  (log: $LOG)\n");
step('Existing TEITOK installation');
say_("  $_\n") for map { "found $_" } @{ $D->{how} };
fail("no TEITOK checkout found (common/Sources/main.php); pass --teitok-root DIR") unless $D->{teitok_root};
fail("no TEITOK shared project found; pass --shared DIR") unless $D->{shared} && -d $D->{shared};
fail("no web user found; pass --web-user USER") unless $D->{web_user};
say_ sprintf "  %-16s %s\n" x 6, 'TEITOK checkout', $D->{teitok_root}, 'shared project', $D->{shared}, 'web user', "$D->{web_user} (group $D->{web_group})",
	'web server', ( $D->{webserver} || '?' ), 'venv', $D->{venv}, 'system', "$OS, $PKG, init: $INIT";
logline("teitok_root=$D->{teitok_root} shared=$D->{shared} web_user=$D->{web_user} webserver=" . ( $D->{webserver} // '' ) . "\n");
# asked once: an installation confirmed before (or installed into by a previous run) is not
# asked about again
my $PREFIX = $o{prefix};
my $MANIFEST = "$PREFIX/share/teitok-stack/manifest.json";
my $CONFIRMED = "$PREFIX/share/teitok-stack/confirmed";
my $THIS_INSTALL = "teitok_root=$D->{teitok_root}\nshared=$D->{shared}\nweb_user=$D->{web_user}\n";
{
	my $mf = read_file($MANIFEST);
	my %m = map { ( $_ => ( $mf =~ /"$_"\s*:\s*"([^"]*)"/ ? $1 : '' ) ) } qw(teitok_root shared web_user);
	my $known = read_file($CONFIRMED) eq $THIS_INSTALL
		|| ( $m{teitok_root} eq $D->{teitok_root} && $m{shared} eq $D->{shared} && $m{web_user} eq $D->{web_user} );
	if ( $known || $ENV{TEITOK_STACK_CONFIRMED} ) {
		say_("  (the installation the previous run used: not asking again)\n");
	} elsif ( !$o{q} && -t STDIN && !yes( "Is this the installation to add the query stack to?", 1 ) ) {
		say_("Stopped: re-run with --teitok-root / --shared / --web-user to point at the right one.\n");
		exit 0;
	}
	make_path( dirname($CONFIRMED) );
	if ( open my $cf, '>', $CONFIRMED ) { print $cf $THIS_INSTALL; close $cf; }
}

my %want = map { $_ => 1 } qw(teitok flexicorp pages flexencoder pando fqs);
if ( $o{only} ) { %want = map { $_ => 1 } split /[,\s]+/, $o{only}; }
delete $want{$_} for split /[,\s]+/, $o{skip};

my $PREV_GIT = ( read_file($MANIFEST) =~ /"git_folder"\s*:\s*"([^"]+)"/ ) ? $1 : '';
my ( $GIT, $GIT_WHY ) = git_folder();

# where flexicorp and pando are checked out: what was asked for; else the checkout
# this installer runs from (unless that is a temporary copy, as install-teitok.pl
# --upgrade makes); else the folder the previous run used; else next to TEITOK
sub git_folder {
	return ( abs_path( $o{'git-folder'} ) || $o{'git-folder'}, '--git-folder' ) if $o{'git-folder'};
	my $repo = dirname($HERE);    # .../flexicorp
	if ( -d "$repo/.git" && !$ENV{TEITOK_STACK_TEMP_COPY} ) {
		my $tmp = abs_path( File::Spec->tmpdir() ) || '/tmp';
		my $shallow = capture( cmdline( 'git', '-C', $repo, 'rev-parse', '--is-shallow-repository' ) ) eq 'true';
		my $in_tmp = grep { $_ && index( "$repo/", "$_/" ) == 0 } ( $tmp, '/tmp', '/var/tmp', '/private/tmp', '/private/var/folders' );
		return ( dirname($repo), 'the checkout this installer runs from' ) unless $shallow || $in_tmp;
	}
	return ( $PREV_GIT, "the previous run ($MANIFEST)" ) if $PREV_GIT && -d $PREV_GIT;
	return ( dirname( $D->{teitok_root} ), 'next to the TEITOK checkout' );
}
my $WEBU = $D->{web_user};
my $WEBG = $D->{web_group};
my $BUILD = ( $MAC ? '/var/tmp' : '/var/tmp' ) . "/teitok-stack-build";
my $CACHE = $MAC ? '/Library/Caches/teitok-stack' : '/var/cache/teitok-stack';
make_path( $BUILD, $CACHE );
my %manifest;

# ── system packages ─────────────────────────────────────────────────────────
sub pkg_install {
	my @pk = @_;
	return 1 unless @pk;
	if ( $PKG eq 'apt' ) { return run( "apt-get install " . join( ' ', @pk ), "DEBIAN_FRONTEND=noninteractive apt-get install -y -q " . join( ' ', @pk ), soft => 1 ); }
	if ( $PKG eq 'dnf' || $PKG eq 'yum' ) { return run( "$PKG install " . join( ' ', @pk ), "$PKG install -y " . join( ' ', @pk ), soft => 1 ); }
	if ( $PKG eq 'zypper' ) { return run( "zypper install " . join( ' ', @pk ), "zypper --non-interactive install " . join( ' ', @pk ), soft => 1 ); }
	if ( $PKG eq 'apk' ) { return run( "apk add " . join( ' ', @pk ), "apk add --no-cache " . join( ' ', @pk ), soft => 1 ); }
	if ( $PKG eq 'pacman' ) { return run( "pacman -S " . join( ' ', @pk ), "pacman -S --noconfirm --needed " . join( ' ', @pk ), soft => 1 ); }
	if ( $PKG eq 'brew' ) {
		my $bu = $SUDO_USER || capture("stat -f %Su /dev/console");
		fail("Homebrew needs a normal user: run this with sudo from your own account") if !$bu || $bu eq 'root';
		return run( "brew install " . join( ' ', @pk ), [ 'sudo', '-u', $bu, '-H', 'brew', 'install', @pk ], soft => 1 );
	}
	warn_("no supported package manager: install yourself: @pk");
	return 0;
}

sub python_ok {    # a python3 >= 3.10, as a path
	for my $p ( 'python3', 'python3.13', 'python3.12', 'python3.11', 'python3.10', '/opt/homebrew/bin/python3', '/usr/local/bin/python3' ) {
		my $path = capture("command -v $p") or next;
		my $v = capture("$path -c 'import sys; print(\"%d %d\" % sys.version_info[:2])'");
		my ( $maj, $min ) = split ' ', $v;
		return $path if $maj && $maj == 3 && $min >= 10;
	}
	return '';
}

sub rust_ok {
	for my $c ( 'cargo', '/opt/rust/cargo/bin/cargo', ( $ENV{HOME} ? "$ENV{HOME}/.cargo/bin/cargo" : () ) ) {
		my $path = capture("command -v $c") || ( -x $c ? $c : '' );
		next unless $path;
		my $rv = capture( q_( dirname($path) . '/rustc' ) . ' --version' );
		if ( $rv =~ /rustc (\d+)\.(\d+)/ && ( $1 > 1 || $2 >= 85 ) ) { return $path; }
	}
	return '';
}

# build tools already there (an upgrade): no package manager run
sub build_tools_ok {
	my $re2 = capture('pkg-config --exists re2 && echo y') eq 'y' || grep { -f "$_/re2/re2.h" } ( '/usr/include', '/usr/local/include', '/opt/homebrew/include' );
	return have('git') && have('make') && ( have('cmake') || have('cmake3') ) && ( have('c++') || have('g++') || have('clang++') )
		&& python_ok() ne '' && $re2 && ( !$want{fqs} || rust_ok() ne '' );
}
if ( !$o{'no-deps'} && -f $MANIFEST && build_tools_ok() ) {
	step('System packages for building');
	say_("  compilers, cmake, RE2, Python and Rust are there: no package installs\n");
} elsif ( !$o{'no-deps'} ) {
	step('System packages for building');
	my %pk = (
		apt    => [qw(git g++ make cmake pkg-config libre2-dev python3 python3-venv python3-dev curl ca-certificates sudo)],
		dnf    => [qw(git gcc-c++ make cmake pkgconf-pkg-config re2-devel python3 python3-devel curl sudo)],
		yum    => [qw(git gcc-c++ make cmake3 pkgconfig re2-devel python3 python3-devel curl sudo)],
		zypper => [qw(git gcc-c++ make cmake pkg-config re2-devel python3 python3-devel curl sudo)],
		apk    => [qw(git g++ make cmake pkgconf re2-dev python3 py3-pip curl sudo bash linux-headers)],
		pacman => [qw(git base-devel cmake re2 python curl sudo)],
		brew   => [qw(git cmake re2 pkg-config python@3.12)],
	);
	if ( $PKG eq 'dnf' ) {
		# RE2 on RHEL-likes lives in EPEL / CRB
		if ( -f '/etc/redhat-release' && read_file('/etc/redhat-release') !~ /Fedora/ ) {
			run( 'enable EPEL and CRB (RE2)', 'dnf install -y epel-release && (dnf config-manager --set-enabled crb || dnf config-manager --set-enabled powertools)', soft => 1 );
		}
	}
	pkg_install( @{ $pk{$PKG} || [] } ) if $pk{$PKG};
	if ( !python_ok() ) {
		say_("  python3 is older than 3.10: installing a newer one\n");
		pkg_install( $PKG eq 'apt' ? qw(python3.11 python3.11-venv) : $PKG =~ /dnf|yum/ ? qw(python3.11 python3.11-devel) : qw(python3) );
	}
	if ( $want{fqs} && !rust_ok() ) {
		say_("  Rust >= 1.85 not found: installing it with rustup into /opt/rust\n");
		make_path('/opt/rust');
		run( 'rustup (stable, minimal)', "curl -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable --no-modify-path",
			env => { RUSTUP_HOME => '/opt/rust/rustup', CARGO_HOME => '/opt/rust/cargo' } );
	}
}
my $PY = python_ok() or fail('no python3 >= 3.10 found (flexicorp needs it)');
for my $t (qw(git make cmake)) { fail("$t not found; install it or drop --no-deps") if !have($t) && ( $t ne 'cmake' || $want{pando} ); }

# ── sources ─────────────────────────────────────────────────────────────────
sub owner_of { my $p = shift; my $u = getpwuid( ( stat($p) )[4] ); return $u // 'root'; }

sub git_in { my ( $dir, @a ) = @_; return capture( cmdline( 'git', '-C', $dir, @a ) ); }

# git pull --ff-only of an existing checkout, as its owner; says what it brought or why not
my %PULLED;    # checkout => "before -> after", for the summary
sub pull_checkout {
	my ( $name, $dir ) = @_;
	my $own = owner_of($dir);
	my $before = git_in( $dir, 'rev-parse', '--short', 'HEAD' );
	if ( $o{'no-pull'} ) { say_("  $name: using $dir as it is (--no-pull)\n"); return; }
	my $dirty = git_in( $dir, 'status', '--porcelain', '--untracked-files=no' );
	my $upstream = git_in( $dir, 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}' );
	if ( $dirty ne '' ) {
		warn_("$dir has uncommitted changes: not pulling (commit or stash them to get updates); using it as it is");
		return;
	}
	if ( $upstream eq '' ) {
		warn_("$dir: the branch " . git_in( $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ) . " tracks no remote branch: not pulling");
		return;
	}
	say_("  $name: git pull --ff-only from $upstream (as $own) ...\n");
	my $cmd = cmdline( as_user( $own, 'git', '-C', $dir, 'pull', '--ff-only', '-q' ) );
	logline("\$ $cmd\n");
	my $out = `$cmd 2>&1`;
	my $rc = $? >> 8;
	logline( $out // '' );
	my $after = git_in( $dir, 'rev-parse', '--short', 'HEAD' );
	if ($rc) {
		my ($why) = grep { /\S/ } reverse split /\n/, ( $out // '' );
		warn_( "git pull in $dir failed" . ( $why ? ": $why" : '' ) . "; using $before as it is" );
		my $url = git_in( $dir, 'remote', 'get-url', 'origin' );
		if ( $url =~ m{^(git@|ssh://)} ) {
			say_("    (an ssh remote: the pull runs as $own with $own\'s ~/.ssh; for a key with a passphrase\n"
				. "     pass your agent along: sudo --preserve-env=SSH_AUTH_SOCK perl $0 ...)\n");
		}
	} elsif ( $after ne $before ) {
		$PULLED{$name} = "$before -> $after";
		my $n = git_in( $dir, 'rev-list', '--count', "$before..$after" );
		say_("  $name: $n new commit" . ( $n == 1 ? '' : 's' ) . ": $before -> $after\n");
		say_("    $_\n") for grep { $_ ne '' } split /\n/, git_in( $dir, 'log', '--oneline', '--no-decorate', '-n', '8', "$before..$after" );
	} else {
		say_("  $name: up to date\n");
	}
}

sub checkout {
	my ( $name, $repo, $ref ) = @_;
	my $dir = "$GIT/$name";
	if ( -d "$dir/.git" ) {
		pull_checkout( $name, $dir );
	} elsif ( -d $dir ) {
		warn_("$dir exists but is not a git checkout: building it as it is");
	} else {
		make_path($GIT) unless -d $GIT;
		my $own = owner_of($GIT);
		my @c = ( 'git', 'clone', '-q', ( $ref ne '' ? ( '-b', $ref ) : () ), $repo, $dir );
		run( "clone $repo into $dir (as $own)", [ as_user( $own, @c ) ] );
	}
	my $commit = git_in( $dir, 'rev-parse', '--short', 'HEAD' );
	my $branch = git_in( $dir, 'rev-parse', '--abbrev-ref', 'HEAD' );
	my $dirty = git_in( $dir, 'status', '--porcelain', '--untracked-files=no' ) ne '' ? '-dirty' : '';
	say_( "  $name: $dir  ($branch $commit$dirty)\n" );
	return ( $dir, "$commit$dirty" );
}

step('Sources');
say_("  git folder: $GIT  ($GIT_WHY)\n");
if ( $PREV_GIT && $PREV_GIT ne $GIT ) {
	warn_( "the previous run built from $PREV_GIT, this one builds from $GIT: from now on the installed binaries come from $GIT"
		  . ( -d "$PREV_GIT/pando" || -d "$PREV_GIT/flexicorp" ? " (the checkouts in $PREV_GIT are no longer used; remove them or pass --git-folder $PREV_GIT)" : '' ) );
}
# `git -C` as root in someone else's checkout: tell git that is fine
system( 'git config --global --get-all safe.directory 2>/dev/null | grep -qx "\*" || git config --global --add safe.directory "*"' );
# TEITOK itself: pulled like the others (PHP: a pull is all it takes; its tools are rebuilt below)
my $TT = $D->{teitok_root};
$TT =~ s{/+$}{};
my $TT_COMMIT = '';
if ( $want{teitok} ) {
	if ( -d "$TT/.git" ) {
		pull_checkout( 'TEITOK', $TT );
		$TT_COMMIT = git_in( $TT, 'rev-parse', '--short', 'HEAD' ) . ( git_in( $TT, 'status', '--porcelain', '--untracked-files=no' ) ne '' ? '-dirty' : '' );
		say_( "  TEITOK: $TT  (" . git_in( $TT, 'rev-parse', '--abbrev-ref', 'HEAD' ) . " $TT_COMMIT)\n" );
	} else {
		warn_("$TT is not a git checkout: TEITOK is left as it is");
	}
}

# this installer as the flexicorp checkout has it before the pull (to notice a pull that updates it)
my $INSTALLER_IN_GIT = "$GIT/flexicorp/install/install-stack.pl";
my $INSTALLER_BEFORE = -f $INSTALLER_IN_GIT ? sha1_hex( read_file($INSTALLER_IN_GIT) ) : '';
my ( $FLEXI, $FLEXI_COMMIT ) = checkout( 'flexicorp', $o{'flexicorp-repo'}, $o{'flexicorp-ref'} );
my ( $PANDO, $PANDO_COMMIT ) = ( '', '' );
( $PANDO, $PANDO_COMMIT ) = checkout( 'pando', $o{'pando-repo'}, $o{'pando-ref'} ) if $want{pando} || $want{fqs};
my $FLEXI_VERSION = ( read_file("$FLEXI/pyproject.toml") =~ /^version\s*=\s*"([^"]+)"/m ) ? $1 : '?';

# the pull brought a new version of this installer: run that one instead (once). Only a
# change made by this pull counts: a checkout that merely differs from the copy running
# (an older checkout, a newer copy elsewhere) is not a reason to switch.
{
	my $new = $INSTALLER_IN_GIT;
	if ( !$o{'no-pull'} && !$ENV{TEITOK_STACK_REEXEC} && $INSTALLER_BEFORE ne '' && -f $new
		&& sha1_hex( read_file($new) ) ne $INSTALLER_BEFORE && sha1_hex( read_file($new) ) ne $ME_SHA ) {
		say_("\n  the pull updated this installer: restarting with the new version ($new)\n");
		$ENV{TEITOK_STACK_REEXEC} = 1;
		$ENV{TEITOK_STACK_CONFIRMED} = 1;
		$ENV{TEITOK_STACK_PULLED} = join( ';', map { "$_=$PULLED{$_}" } sort keys %PULLED );
		close $LOCKFH if $LOCKFH;    # the new run takes the lock
		close $LOGFH;
		my @args = grep { $_ ne '--no-pull' } @ORIG_ARGV;
		exec( $^X, $new, @args, '--no-pull', ( $o{'git-folder'} ? () : ( '--git-folder', $GIT ) ) ) or fail("cannot run $new: $!");
	}
}

# ── what changed since the last install ─────────────────────────────────────────
# Each component is rebuilt only when its sources differ from the ones it was last built
# from (a fingerprint of the git trees plus any uncommitted changes, kept in the manifest),
# or when what it installs is missing; --force rebuilds anyway.
my %OLD;
{
	my $s = read_file($MANIFEST);
	while ( $s =~ /"(\w+)"\s*:\s*\{([^{}]*)\}/g ) {
		my ( $c, $body ) = ( $1, $2 );
		my %h;
		$h{$1} = $2 while $body =~ /"(\w+)"\s*:\s*"([^"]*)"/g;
		$OLD{$c} = \%h;
	}
}
# fingerprint of some paths of a checkout: their committed trees, the uncommitted diff, and
# untracked files; '' when it cannot be told (then the component is always rebuilt)
sub source_print {
	my ( $repo, @paths ) = @_;
	return '' unless $repo && -d "$repo/.git";
	my @parts;
	for my $p (@paths) {
		my $t = git_in( $repo, 'rev-parse', $p eq '' ? 'HEAD^{tree}' : "HEAD:$p" );
		return '' if $t eq '';
		push @parts, "$p=$t";
	}
	my @scope = ( '--', map { $_ eq '' ? '.' : $_ } @paths );
	push @parts, sha1_hex( scalar `@{[ cmdline( 'git', '-C', $repo, 'diff', 'HEAD', '--binary', @scope ) ]} 2>/dev/null` // '' );
	for my $u ( sort split /\n/, git_in( $repo, 'ls-files', '--others', '--exclude-standard', @scope ) ) {
		push @parts, "$u:" . sha1_hex( read_file("$repo/$u") );
	}
	return sha1_hex( join( "\n", @parts ) );
}
sub prints { my @p = @_; return ( grep { $_ eq '' } @p ) ? '' : sha1_hex( join( '+', "install-stack $VERSION", $PREFIX, @p ) ); }
my %SRC = (
	teitok      => prints( source_print( $TT, 'src' ) ),
	pando       => prints( source_print( $PANDO, '' ), source_print( $FLEXI, 'flexicorp_pando' ) ),
	flexencoder => prints( source_print( $FLEXI, 'flexencoder' ) ),
	flexicorp   => prints( source_print( $FLEXI, 'flexicorp', 'pyproject.toml' ), $D->{venv} ),
	pages       => prints( source_print( $FLEXI, 'teitok_teitok_ui' ), $D->{shared} ),
	fqs         => prints( source_print( $FLEXI, 'fqs' ) ),
);
my %FORCE;
if ( defined $o{force} ) {
	%FORCE = $o{force} eq '' ? map { $_ => 1 } qw(teitok flexicorp pages flexencoder pando fqs) : map { $_ => 1 } split /[,\s]+/, $o{force};
}
my ( %BUILT, @UNCHANGED );
# true (and says so) when a component can be left as it is
sub unchanged {
	my ( $c, $present ) = @_;
	return 0 if $FORCE{$c} || !$present || $SRC{$c} eq '';
	my $old = $OLD{$c} || {};
	return 0 unless ( $old->{source} // '' ) eq $SRC{$c};
	step( ( $c eq 'teitok' ? 'TEITOK tools' : $c ) . ': unchanged' );
	say_( "  same sources as its install of " . ( $old->{installed} // '?' ) . ( $old->{commit} ? " ($old->{commit})" : '' ) . ": skipped (--force $c rebuilds it)\n" );
	push @UNCHANGED, $c eq 'teitok' ? 'TEITOK tools' : $c;
	return 1;
}
my $LIBFP = $MAC ? "$PREFIX/lib/libflexicorp_pando.dylib" : "$PREFIX/lib/libflexicorp_pando.so";

sub fresh_dir { my $d = "$BUILD/" . shift; remove_tree($d) if -e $d; make_path($d); return $d; }
sub copy_tree {    # a clean copy of a source tree (no .git, build outputs, objects)
	my ( $from, $to ) = @_;
	make_path($to);
	run( '', "tar -C " . q_($from) . " --exclude=.git --exclude=target --exclude=build --exclude='build-*' --exclude='*.o' --exclude='*.d' --exclude='*.egg-info' --exclude=__pycache__ -cf - . | tar -C " . q_($to) . " -xf -" );
}
sub install_file {    # atomic: copy next to the target, then rename over it
	my ( $from, $to, $mode ) = @_;
	make_path( dirname($to) );
	my $tmp = "$to.new-$$";
	copy( $from, $tmp ) or fail("cannot write $tmp: $!");
	chmod( $mode // 0755, $tmp );
	rename( $tmp, $to ) or fail("cannot replace $to: $!");
}

# ── pando + libflexicorp_pando (one CMake build) ───────────────────────────────
# ── TEITOK's own tools (as install-teitok.pl builds them) ──────────────────────
# the one TEITOK's pages still need with flexicorp: tt-xpath (tualign, XPath search, the API).
# Not tt-cwb-encode / tt-cwb-xidx (flexencoder replaces them; kept only by installations without
# flexicorp), tt-cqp (phased out: too slow), neotag (flexipipe's flexitag) or xpathquery (unused)
my @TT_TOOLS = qw(tt-xpath);
if ( $want{teitok} && -d "$TT/src" && !unchanged( 'teitok', !grep { !-x "$PREFIX/bin/$_" } @TT_TOOLS ) ) {
	step('TEITOK tools');
	my $b = fresh_dir('teitok-src');
	copy_tree( "$TT/src", $b );
	my $cxx = have('g++') ? 'g++' : 'c++';
	my $n = 0;
	for my $t (@TT_TOOLS) {
		next unless -f "$b/$t.cpp";
		if ( run( "build $t", [ $cxx, '-std=c++11', '-O2', '-o', $t, "$t.cpp", 'pugixml.cpp', 'functions-c11.cpp' ], cwd => $b, soft => 1 ) ) {
			install_file( "$b/$t", "$PREFIX/bin/$t", 0755 );
			$n++;
		} else {
			warn_("building $t failed (see the log): the installed one is kept");
		}
	}
	say_( "  installed: $n of " . scalar(@TT_TOOLS) . ' ' . ( @TT_TOOLS == 1 ? 'tool' : 'tools' ) . " into $PREFIX/bin\n" );
	$manifest{teitok} = { commit => $TT_COMMIT, checkout => $TT_COMMIT, source => $SRC{teitok} } if $n == @TT_TOOLS;
	$BUILT{teitok} = 1 if $n;
	remove_tree($b);
} elsif ( $want{teitok} && $TT_COMMIT ne '' && !$BUILT{teitok} ) {
	# tools unchanged, but the checkout (PHP) may be newer: recorded as "checkout" ("commit" is the
	# one the tools were built from)
	$manifest{teitok} = { %{ $OLD{teitok} || {} }, checkout => $TT_COMMIT };
}

if ( $want{pando} && !unchanged( 'pando', -x "$PREFIX/bin/pando-index" && -e $LIBFP ) ) {
	step('Pando and libflexicorp_pando');
	my $b = fresh_dir('pando');
	my @args = ( "-DPANDO_DIR=$PANDO", '-DCMAKE_BUILD_TYPE=Release', '-DPANDO_USE_RE2=ON', '-DBUILD_TESTING=OFF' );
	if ($MAC) { my $bp = capture('brew --prefix') || '/opt/homebrew'; push @args, "-DCMAKE_PREFIX_PATH=$bp"; }
	my $cmake = have('cmake3') && !have('cmake') ? 'cmake3' : 'cmake';
	run( 'configure (cmake, RE2 required)', [ $cmake, '-S', "$FLEXI/flexicorp_pando", '-B', $b, @args ] );
	my $jobs = capture('nproc') || capture('sysctl -n hw.ncpu') || 2;
	run( "build (make -j$jobs; a few minutes)", [ $cmake, '--build', $b, '-j', $jobs, '--target', qw(flexicorp_pando flexicorp-pando pando pando-index pando-check pando-server) ] );
	# install into a staging prefix, then move each file into place (atomic per file)
	my $stage = fresh_dir('pando-stage');
	run( 'install (staging)', [ $cmake, '--install', $b, '--prefix', $stage ] );
	for my $f ( split /\n/, capture( 'cd ' . q_($stage) . ' && find . -type f -o -type l' ) ) {
		$f =~ s{^\./}{};
		next if $f =~ m{^lib/.*\.a$} || $f =~ m{^include/};    # static dialect libraries, headers: not needed at run time
		install_file( "$stage/$f", "$PREFIX/$f", ( $f =~ m{^bin/} ? 0755 : 0644 ) );
	}
	run( 'ldconfig', 'ldconfig', soft => 1 ) if !$MAC && have('ldconfig');
	my $v = capture( q_("$PREFIX/bin/pando-index") . ' --version' );
	say_("  installed: $v\n");
	$manifest{pando} = { version => $v, commit => $PANDO_COMMIT, source => $SRC{pando} };
	$BUILT{pando} = 1;
	remove_tree( $b, $stage );
}

# ── flexencoder ────────────────────────────────────────────────────────────
if ( $want{flexencoder} && !unchanged( 'flexencoder', -x "$PREFIX/bin/flexencoder" ) ) {
	step('flexencoder');
	my $b = fresh_dir('flexencoder');
	copy_tree( "$FLEXI/flexencoder", $b );    # never reuses objects from the checkout
	my $jobs = capture('nproc') || capture('sysctl -n hw.ncpu') || 2;
	run( 'build (make)', [ 'make', '-s', '-j', $jobs, '-f', 'Makefile.flexencoder', 'BINDIR=.' ], cwd => $b );
	install_file( "$b/flexencoder", "$PREFIX/bin/flexencoder", 0755 );
	my $usage = capture( q_("$PREFIX/bin/flexencoder") . ' --help 2>&1' );
	fail('the installed flexencoder does not accept --output-pando') if $usage !~ /--output-pando/;
	say_("  installed: $PREFIX/bin/flexencoder\n");
	$manifest{flexencoder} = { commit => $FLEXI_COMMIT, source => $SRC{flexencoder} };
	$BUILT{flexencoder} = 1;
	remove_tree($b);
}

# ── flexicorp (Python, in TEITOK's venv, as the web user) ────────────────────
if ( $want{flexicorp} && !unchanged( 'flexicorp', -x "$D->{venv}/bin/python"
		&& capture( cmdline( "$D->{venv}/bin/python", '-c', 'import flexicorp; print("ok")' ) ) eq 'ok' ) ) {
	step('flexicorp (Python package in the TEITOK venv)');
	my $venv = $D->{venv};
	if ( !-x "$venv/bin/python" ) {
		make_path( dirname($venv) );
		run( "create venv $venv (as $WEBU)", [ as_user( $WEBU, $PY, '-m', 'venv', $venv ) ] );
	} else {
		# a venv that was ever touched with sudo pip has root-owned files: give it back
		my $bad = capture( 'find ' . q_($venv) . ' ! -user ' . q_($WEBU) . ' -print -quit' );
		if ( $bad ne '' ) { run( "give $venv back to $WEBU", [ 'chown', '-R', "$WEBU:$WEBG", $venv ] ); }
		for my $left ( glob("$venv/lib/python*/site-packages/~*") ) { remove_tree($left); say_("  removed pip leftover $left\n"); }
	}
	my $vpy = capture( q_("$venv/bin/python") . ' -c "import sys; print(sys.version_info[1])"' );
	fail("the venv $venv has Python 3.$vpy; flexicorp needs 3.10 or newer (move it aside and re-run to recreate it)") if $vpy ne '' && $vpy < 10;
	my $src = fresh_dir('flexicorp-src');
	copy_tree( $FLEXI, $src );
	remove_tree( map { "$src/$_" } qw(git tmp dev tests examples data Old) );
	my $whl = fresh_dir('flexicorp-wheel');
	run( 'build the wheel', [ "$venv/bin/python", '-m', 'pip', 'wheel', '--no-deps', '--no-cache-dir', '-q', '-w', $whl, $src ] );
	my ($wf) = glob("$whl/flexicorp-*.whl");
	fail('no wheel was built') unless $wf;
	run( '', [ 'chmod', '-R', 'a+rX', $whl ] );
	run( "install it (as $WEBU)", [ as_user( $WEBU, "$venv/bin/python", '-m', 'pip', 'install', '--no-deps', '--force-reinstall', '--no-cache-dir', '-q', $wf ) ],
		env => { HOME => ( getpwnam($WEBU) )[7] || '/tmp' } );
	my $chk = capture( cmdline( as_user( $WEBU, "$venv/bin/python", '-c', 'import flexicorp.cli,inspect; print("ok" if "reindex_staging" in inspect.getsource(flexicorp.cli) else "old")' ) ) );
	fail("flexicorp does not import in $venv as $WEBU") if $chk eq '';
	say_("  installed: flexicorp $FLEXI_VERSION ($FLEXI_COMMIT) into $venv\n");
	$manifest{flexicorp} = { version => $FLEXI_VERSION, commit => $FLEXI_COMMIT, venv => $venv, source => $SRC{flexicorp} };
	$BUILT{flexicorp} = 1;
	remove_tree( $src, $whl );
}

# ── TEITOK pages (shared project) ───────────────────────────────────────────────
if ( $want{pages} && !unchanged( 'pages', -f "$D->{shared}/Sources/flexicorp.php" ) ) {
	step('flexicorp pages for TEITOK (shared project)');
	my $ui = "$FLEXI/teitok_teitok_ui/install-teitok-ui.pl";
	fail("$ui not found") unless -f $ui;
	my $out = `perl $ui --shared @{[q_($D->{shared})]} --user @{[q_($WEBU)]} 2>&1`;
	say_( map { "  $_\n" } split /\n/, $out );
	fail('installing the TEITOK pages failed') if $?;
	$manifest{pages} = { version => $FLEXI_VERSION, commit => $FLEXI_COMMIT, source => $SRC{pages} };
	$BUILT{pages} = 1;
}

# ── FQS ────────────────────────────────────────────────────────────────────
sub env_set {    # set KEY=value in an env file only when the key is not set yet
	my ( $file, $key, $val ) = @_;
	my $s = read_file($file);
	return 0 if $s =~ /^\s*\Q$key\E=/m;
	open my $fh, '>>', $file or return 0;
	print $fh "\n" if $s ne '' && $s !~ /\n$/;
	print $fh "$key=$val\n";
	close $fh;
	return 1;
}
sub env_dedupe_secret {    # several FQS_SECRET= lines: keep the last (the one systemd and sh use)
	my $file = shift;
	my @l = split /\n/, read_file($file), -1;
	my @idx = grep { $l[$_] =~ /^\s*FQS_SECRET=/ } 0 .. $#l;
	return 0 if @idx < 2;
	my %drop = map { $_ => 1 } @idx[ 0 .. $#idx - 1 ];
	open my $fh, '>', "$file.new-$$" or return 0;
	print $fh join( "\n", map { $l[$_] } grep { !$drop{$_} } 0 .. $#l );
	close $fh;
	chmod 0640, "$file.new-$$";
	my ( $u, $g ) = ( stat($file) )[ 4, 5 ];
	chown $u, $g, "$file.new-$$";
	rename "$file.new-$$", $file;
	return scalar(@idx) - 1;
}

# ── restarting FQS without cancelling reindex jobs ────────────────────────────────
my $RESTART_PENDING = "$PREFIX/share/teitok-stack/fqs-restart-pending";
my ( $RESTARTED, $POSTPONED ) = ( 0, '' );
sub fqs_running {
	return $INIT eq 'systemd' ? capture('systemctl is-active fqs') eq 'active'
		: $MAC ? capture('launchctl print system/org.teitok.fqs') ne ''
		: capture('pgrep -x fqs') ne '';
}
# reindex jobs FQS is running now (a restart would cancel them; queued ones survive it)
sub fqs_jobs_running {
	my $j = capture('curl -sf -m 5 "http://127.0.0.1:8787/reindex/jobs?status=running&limit=100"');
	my $n = () = $j =~ /"job_id"/g;
	return $n;
}
sub fqs_wait_health {
	for ( 1 .. 30 ) { return 1 if capture('curl -sf -m 2 http://127.0.0.1:8787/health') ne ''; sleep 1; }
	return 0;
}
# restart a running FQS now, or postpone it while reindex jobs run (the next run does it)
sub fqs_restart {
	my $why = shift;
	return 0 unless fqs_running();
	if ( my $n = fqs_jobs_running() ) {
		my $later = !$o{q} && -t STDIN ? !yes( "FQS is running $n reindex job(s); restart now anyway (they are cancelled)?", 0 ) : 1;
		if ($later) {
			$POSTPONED = $why;
			if ( open my $fh, '>', $RESTART_PENDING ) { print $fh "$why\n"; close $fh; }
			warn_("FQS runs $n reindex job(s): its restart ($why) is postponed; the next run of this installer (or a restart by hand) does it");
			return 0;
		}
	}
	step("Restart FQS ($why)");
	my $done = 1;
	if ( $INIT eq 'systemd' ) { run( 'systemctl restart fqs', 'systemctl daemon-reload && systemctl restart fqs', soft => 1 ); }
	elsif ($MAC) { run( 'launchctl kickstart -k system/org.teitok.fqs', 'launchctl kickstart -k system/org.teitok.fqs', soft => 1 ); }
	elsif ( -x "$PREFIX/sbin/teitok-fqs" ) { run( "$PREFIX/sbin/teitok-fqs restart", [ "$PREFIX/sbin/teitok-fqs", 'restart' ], soft => 1 ); }
	else { warn_('FQS runs, but not through a start script this installer knows: restart it yourself'); $done = 0; }
	if ($done) {
		fqs_wait_health() ? say_("  FQS is up again\n") : warn_("FQS did not answer on 127.0.0.1:8787 within 30 s (see /var/log/fqs/)");
		unlink $RESTART_PENDING;
		$RESTARTED = 1;
	}
	return $done;
}

if ( $want{fqs} && !unchanged( 'fqs', -x "$PREFIX/bin/fqs" ) ) {
	step('FQS (query / reindex service)');
	my $cargo = rust_ok() or fail('Rust >= 1.85 (cargo) not found; drop --no-deps or install rustup');
	my $src = fresh_dir('fqs-src');
	copy_tree( "$FLEXI/fqs", $src );
	my %renv = ( CARGO_TARGET_DIR => "$CACHE/fqs-target", PATH => dirname($cargo) . ":$ENV{PATH}" );
	if ( $cargo =~ m{^/opt/rust/} ) { $renv{RUSTUP_HOME} = '/opt/rust/rustup'; $renv{CARGO_HOME} = '/opt/rust/cargo'; }
	# --locked when the checkout has a Cargo.lock (reproducible dependency versions)
	( -f "$src/Cargo.lock" && run( 'build (cargo build --release --locked; several minutes the first time)', [ $cargo, 'build', '--release', '--locked', '-q' ], cwd => $src, env => \%renv, soft => 1 ) )
		or run( 'build (cargo build --release; several minutes the first time)', [ $cargo, 'build', '--release', '-q' ], cwd => $src, env => \%renv );
	make_path("$src/target/release");
	copy( "$CACHE/fqs-target/release/fqs", "$src/target/release/fqs" ) or fail("no fqs binary built: $!");
	chmod 0755, "$src/target/release/fqs";
	my $was_running = $INIT eq 'systemd' ? capture('systemctl is-active fqs') eq 'active' : capture('pgrep -x fqs') ne '';
	my $envf = '/etc/fqs/fqs.env';
	if ( !$MAC ) {
		my @a = ( 'bash', "$src/install.sh", '--skip-build', '--user', $WEBU, '--group', $WEBG, '--web-user', $WEBU,
			'--teitok-venv', $D->{venv}, '--scan-root', $D->{teitok_dir}, '--prefix', $PREFIX );
		push @a, '--no-systemd' if $INIT ne 'systemd';
		push @a, '--no-start';
		run( "install (fqs/install.sh, service user $WEBU)", \@a );
	} else {
		# macOS: same layout as install.sh, launchd instead of systemd
		make_path( "$PREFIX/share/fqs/admin", '/usr/local/etc/fqs', '/usr/local/var/fqs', '/usr/local/var/log/fqs' );
		install_file( "$src/target/release/fqs", "$PREFIX/bin/fqs", 0755 );
		install_file( "$src/admin/$_", "$PREFIX/share/fqs/admin/$_", 0644 ) for qw(index.html admin.css app.js);
		$envf = '/usr/local/etc/fqs/fqs.env';
		if ( !-f $envf ) { copy( "$src/deploy/fqs.env.example", $envf ); }
		if ( read_file($envf) !~ /^\s*FQS_SECRET=\S/m ) {
			my $sec = capture('openssl rand -hex 32');
			my $s = read_file($envf); $s =~ s/^\s*FQS_SECRET=.*$/FQS_SECRET=$sec/m or $s .= "\nFQS_SECRET=$sec\n";
			open my $fh, '>', $envf; print $fh $s; close $fh;
		}
		if ( !-f '/usr/local/etc/fqs/fqs.json' ) {
			open my $fh, '>', '/usr/local/etc/fqs/fqs.json';
			print $fh qq({\n  "db_path": "/usr/local/var/fqs/fqs.db",\n  "scan_roots": [ "$D->{teitok_dir}" ]\n}\n);
			close $fh;
		}
		run( '', [ 'chown', '-R', "$WEBU:$WEBG", '/usr/local/var/fqs', '/usr/local/var/log/fqs' ] );
		chmod 0640, $envf; chown 0, scalar( getgrnam($WEBG) ), $envf;
	}
	# settings the service needs, only when not set already
	env_set( $envf, 'PYTHON_BIN', "$D->{venv}/bin/python" ) and say_("  $envf: PYTHON_BIN added\n");
	my $lib = $MAC ? "$PREFIX/lib/libflexicorp_pando.dylib" : "$PREFIX/lib/libflexicorp_pando.so";
	env_set( $envf, 'FLEXICORP_PANDO_LIB', $lib ) and say_("  $envf: FLEXICORP_PANDO_LIB added\n") if -f $lib;
	env_set( $envf, 'PATH', "$PREFIX/bin:/usr/local/bin:/usr/bin:/bin" ) and say_("  $envf: PATH added (flexencoder, pando-index)\n");
	my $n = env_dedupe_secret($envf);
	warn_("$envf had $n extra FQS_SECRET line(s); kept the last one (the one in use)") if $n;
	frontend_access( "$PREFIX/bin/fqs", $WEBU, $WEBG ) unless $o{'no-frontends'};

	# start / restart
	my $start = "$PREFIX/sbin/teitok-fqs";
	if ( $INIT eq 'systemd' ) {
		# an FQS started by hand (not by systemd) holds the port: stop it first
		if ( !$was_running && capture('pgrep -x fqs') ne '' ) {
			if ( $o{q} || !-t STDIN || yes( 'An FQS process not managed by systemd is running; stop it so fqs.service can start?', 1 ) ) {
				run( 'stop the FQS started outside systemd', 'pkill -x fqs; sleep 2', soft => 1 );
			}
		}
		if ($was_running) {
			run( 'systemctl daemon-reload, enable fqs', 'systemctl daemon-reload && systemctl enable -q fqs' );
			fqs_restart('new FQS');
		} else {
			run( 'start fqs.service', 'systemctl daemon-reload && systemctl enable -q fqs && systemctl restart fqs' );
		}
	} elsif ($MAC) {
		my $plist = '/Library/LaunchDaemons/org.teitok.fqs.plist';
		open my $fh, '>', $plist or fail("cannot write $plist");
		print $fh <<"PLIST";
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>org.teitok.fqs</string>
  <key>UserName</key><string>$WEBU</string>
  <key>ProgramArguments</key><array>
    <string>/bin/sh</string><string>-c</string>
    <string>set -a; . $envf; set +a; exec $PREFIX/bin/fqs serve --host 127.0.0.1 --port 8787 --enable-admin-http --admin-bind 127.0.0.1:8790 --admin-dir $PREFIX/share/fqs/admin --activity-log /usr/local/var/log/fqs/activity.jsonl</string>
  </array>
  <key>WorkingDirectory</key><string>/usr/local/var/fqs</string>
  <key>EnvironmentVariables</key><dict><key>FQS_DB_PATH</key><string>/usr/local/var/fqs/fqs.db</string></dict>
  <key>RunAtLoad</key><true/><key>KeepAlive</key><true/>
  <key>StandardErrorPath</key><string>/usr/local/var/log/fqs/fqs.log</string>
</dict></plist>
PLIST
		close $fh;
		if ( $was_running && fqs_jobs_running() ) { fqs_restart('new FQS'); }    # postponed while jobs run
		else { run( 'reload launchd job org.teitok.fqs', "launchctl bootout system/org.teitok.fqs 2>/dev/null; launchctl bootstrap system $plist" ); $RESTARTED = 1; }
	} else {
		# no init system (containers): a start script; the container entrypoint (or you) runs it
		make_path( dirname($start) );
		open my $fh, '>', $start or fail("cannot write $start");
		print $fh <<"SH";
#!/bin/sh
# Start / restart FQS without systemd (written by install-stack.pl).
#   $start [start|stop|restart]
cmd=\${1:-restart}
if [ "\$cmd" != start ]; then pkill -x fqs 2>/dev/null; sleep 1; fi
[ "\$cmd" = stop ] && exit 0
mkdir -p /var/log/fqs && chown $WEBU:$WEBG /var/log/fqs
cd /var/lib/fqs || exit 1
exec_as() { if command -v runuser >/dev/null 2>&1; then runuser -u $WEBU -- "\$@"; else su -s /bin/sh $WEBU -c "\$*"; fi; }
set -a; . $envf; set +a
umask 0002
exec_as $PREFIX/bin/fqs serve --host 127.0.0.1 --port 8787 --enable-admin-http --admin-bind 127.0.0.1:8790 \\
	--admin-dir $PREFIX/share/fqs/admin --activity-log /var/log/fqs/activity.jsonl >>/var/log/fqs/fqs.log 2>&1 &
echo "fqs started (log /var/log/fqs/fqs.log)"
SH
		close $fh;
		chmod 0755, $start;
		if ($was_running) { fqs_restart('new FQS'); }
		else { run( 'start FQS (no systemd: ' . $start . ')', [$start] ); }
	}
	if ( !$POSTPONED ) {
		fqs_wait_health() ? say_("  FQS is up: http://127.0.0.1:8787/health\n") : warn_("FQS did not answer on 127.0.0.1:8787 within 30 s (see /var/log/fqs/)");
		unlink $RESTART_PENDING;
	}
	my $fv = capture( q_("$PREFIX/bin/fqs") . ' --version' );
	$manifest{fqs} = { version => $fv, commit => $FLEXI_COMMIT, init => $INIT, source => $SRC{fqs} };
	$BUILT{fqs} = 1;
	remove_tree($src);
}

# FQS loads libflexicorp_pando: a new one only counts after a restart
if ( $BUILT{pando} && !$BUILT{fqs} && -x "$PREFIX/bin/fqs" ) {
	fqs_restart('new libflexicorp_pando');
}
# a restart an earlier run postponed (reindex jobs were running then)
if ( -f $RESTART_PENDING && !$RESTARTED && !$POSTPONED ) {
	my $why = read_file($RESTART_PENDING);
	chomp $why;
	if ( !fqs_running() ) { unlink $RESTART_PENDING; }    # it loads the new files when it starts
	else { fqs_restart( $why || 'new files' ); }
}

# ── TEITOK settings: only add what is missing ─────────────────────────────────
if ( $o{'fqs-admin'} ) {
	step('TEITOK settings');
	my $sf = "$D->{shared}/Resources/settings.xml";
	if ( eval { require XML::LibXML; 1 } && -f $sf ) {
		my $doc = XML::LibXML->load_xml( location => $sf );
		my ($root) = $doc->documentElement;
		my ($fx) = $root->findnodes('./flexicorp');
		if ( !$fx ) { $fx = $doc->createElement('flexicorp'); $root->appendChild($fx); $root->appendText("\n"); }
		if ( ( $fx->getAttribute('fqs_admin_users') // '' ) eq '' ) {
			$fx->setAttribute( 'fqs_admin_users', $o{'fqs-admin'} );
			copy( $sf, "$sf.before-stack-$STAMP" );
			my $tmp = "$sf.new-$$";
			$doc->toFile($tmp);
			my ( $u, $g ) = ( stat($sf) )[ 4, 5 ];
			chown $u, $g, $tmp; chmod 0664, $tmp;
			rename $tmp, $sf;
			say_("  flexicorp/fqs_admin_users = $o{'fqs-admin'} (backup: settings.xml.before-stack-$STAMP)\n");
		} else { say_("  flexicorp/fqs_admin_users already set: left as is\n"); }
	} else { warn_("XML::LibXML not available: set flexicorp/fqs_admin_users in $sf yourself"); }
}

# ── manifest ──────────────────────────────────────────────────────────────
{
	my $mf = $MANIFEST;
	make_path( dirname($mf) );
	my %old;
	my $s = read_file($mf);
	while ( $s =~ /"(\w+)"\s*:\s*\{([^{}]*)\}/g ) { $old{$1} = $2; }
	open my $fh, '>', "$mf.new-$$" or fail("cannot write $mf");
	print $fh "{\n  \"installer\": \"install-stack.pl $VERSION\",\n  \"updated\": \"" . strftime( '%Y-%m-%dT%H:%M:%S%z', localtime ) . "\",\n";
	print $fh "  \"teitok_root\": \"$D->{teitok_root}\",\n  \"shared\": \"$D->{shared}\",\n  \"web_user\": \"$WEBU\",\n  \"git_folder\": \"$GIT\",\n  \"components\": {\n";
	my @parts;
	for my $c (qw(teitok flexicorp pages flexencoder pando fqs)) {
		if ( $manifest{$c} ) {
			my $m = $manifest{$c};
			my %e = ( %$m, installed => $m->{installed} // $STAMP );
			push @parts, "    \"$c\": {" . join( ', ', map { my $v = $e{$_} // ''; $v =~ s/"/'/g; "\"$_\": \"$v\"" } sort keys %e ) . "}";
		} elsif ( $old{$c} ) { push @parts, "    \"$c\": {$old{$c}}"; }
	}
	print $fh join( ",\n", @parts ), "\n  }\n}\n";
	close $fh;
	rename "$mf.new-$$", $mf;
}

# a restart of this script after the pull updated it: the pulls the first part did
if ( my $p = $ENV{TEITOK_STACK_PULLED} ) {
	for ( split /;/, $p ) { my ( $n, $v ) = split /=/, $_, 2; $PULLED{$n} //= $v if $n; }
}
# ── automatic updates: a nightly run with --cron ─────────────────────────────────
# Offered once on an interactive run (the answer is kept); --auto-update / --no-auto-update
# set or remove it without asking.
my $AUTO_FILE = "$PREFIX/share/teitok-stack/auto-update";
my $AUTO_UNIT = '/etc/systemd/system/teitok-stack-update';
my $AUTO_CRON = '/etc/cron.d/teitok-stack';
my $AUTO_PLIST = '/Library/LaunchDaemons/org.teitok.stack-update.plist';
my $AUTO_NOTE = '';
sub auto_update_remove {
	if ( -f "$AUTO_UNIT.timer" ) {
		run( 'remove teitok-stack-update.timer', 'systemctl disable --now teitok-stack-update.timer', soft => 1 );
		unlink "$AUTO_UNIT.timer", "$AUTO_UNIT.service";
		run( '', 'systemctl daemon-reload', soft => 1 );
	}
	unlink $AUTO_CRON if -f $AUTO_CRON;
	if ( -f $AUTO_PLIST ) { run( '', "launchctl bootout system/org.teitok.stack-update", soft => 1 ); unlink $AUTO_PLIST; }
}
sub auto_update_install {
	my $at = shift;
	my ( $hh, $mm ) = $at =~ /^(\d{1,2}):(\d{2})$/ ? ( $1 + 0, $2 + 0 ) : ( 2, 0 );
	$hh = 2 if $hh > 23; $mm = 0 if $mm > 59;
	my $hm = sprintf( '%02d:%02d', $hh, $mm );
	# the installer in the flexicorp checkout (a copy elsewhere may be temporary), with the
	# folders of this run
	my $inst = "$GIT/flexicorp/install/install-stack.pl";
	$inst = $ME unless -f $inst;
	my @args = ( '--cron', '--git-folder', $GIT, '--prefix', $PREFIX );
	push @args, '--teitok-root', $D->{teitok_root}, '--shared', $D->{shared} if $o{'teitok-root'} || $o{shared};
	my $cmd = join( ' ', map { q_($_) } ( '/usr/bin/env', 'perl', $inst, @args ) );
	auto_update_remove();
	my $how;
	if ( $INIT eq 'systemd' ) {
		open my $sv, '>', "$AUTO_UNIT.service" or fail("cannot write $AUTO_UNIT.service");
		print $sv "[Unit]\nDescription=TEITOK stack update (install-stack.pl --cron)\nAfter=network-online.target\nWants=network-online.target\n\n"
			. "[Service]\nType=oneshot\nExecStart=$cmd\n";
		close $sv;
		open my $tm, '>', "$AUTO_UNIT.timer" or fail("cannot write $AUTO_UNIT.timer");
		print $tm "[Unit]\nDescription=Nightly TEITOK stack update\n\n[Timer]\nOnCalendar=*-*-* $hm:00\nRandomizedDelaySec=10min\nPersistent=true\n\n"
			. "[Install]\nWantedBy=timers.target\n";
		close $tm;
		run( 'enable teitok-stack-update.timer', 'systemctl daemon-reload && systemctl enable --now teitok-stack-update.timer' );
		$how = "systemd timer teitok-stack-update.timer (output: journalctl -u teitok-stack-update)";
	} elsif ($MAC) {
		open my $fh, '>', $AUTO_PLIST or fail("cannot write $AUTO_PLIST");
		my $pargs = join( '', map { "<string>$_</string>" } ( '/usr/bin/perl', $inst, @args ) );
		print $fh qq(<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">\n)
			. qq(<plist version="1.0"><dict><key>Label</key><string>org.teitok.stack-update</string>)
			. qq(<key>ProgramArguments</key><array>$pargs</array>)
			. qq(<key>StartCalendarInterval</key><dict><key>Hour</key><integer>$hh</integer><key>Minute</key><integer>$mm</integer></dict>)
			. qq(<key>StandardOutPath</key><string>/Library/Logs/teitok-install/auto-update.log</string></dict></plist>\n);
		close $fh;
		run( 'load launchd job org.teitok.stack-update', "launchctl bootstrap system $AUTO_PLIST" );
		$how = "launchd job org.teitok.stack-update";
	} elsif ( -d '/etc/cron.d' ) {
		open my $fh, '>', $AUTO_CRON or fail("cannot write $AUTO_CRON");
		print $fh "# Nightly TEITOK stack update (written by install-stack.pl; remove with --no-auto-update)\n"
			. "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n$mm $hh * * * root $cmd\n";
		close $fh;
		chmod 0644, $AUTO_CRON;
		$how = "$AUTO_CRON (cron mails what it reports to root)";
		warn_("no cron daemon seems to run here: $AUTO_CRON only works once one does") if capture('pgrep -x cron || pgrep -x crond') eq '';
	} else {
		warn_("no systemd, launchd or /etc/cron.d here: add a nightly '$cmd' to your scheduler yourself");
		return;
	}
	if ( open my $af, '>', $AUTO_FILE ) { print $af "$hm\n"; close $af; }
	$AUTO_NOTE = "nightly update at $hm: $how";
	say_("  $AUTO_NOTE\n");
}
if ( !$CRON ) {
	if ( $o{'no-auto-update'} ) {
		step('Automatic updates');
		auto_update_remove();
		if ( open my $af, '>', $AUTO_FILE ) { print $af "no\n"; close $af; }
		say_("  no nightly update (removed if there was one)\n");
	} elsif ( defined $o{'auto-update'} ) {
		step('Automatic updates');
		auto_update_install( $o{'auto-update'} );
	} elsif ( !-f $AUTO_FILE && !$o{q} && -t STDIN ) {
		step('Automatic updates');
		say_("  A nightly run of this installer (with --cron) pulls TEITOK, flexicorp and pando and\n"
			. "  rebuilds what changed; it reports only when something happened.\n");
		if ( yes( 'Set up automatic nightly updates?', 1 ) ) {
			my $at = ask( 'At what time (HH:MM)?', '02:00' );
			auto_update_install($at);
		} else {
			if ( open my $af, '>', $AUTO_FILE ) { print $af "no\n"; close $af; }
			say_("  no automatic updates (not asked again; --auto-update sets them up later)\n");
		}
	}
}

step('Done');
my @built = grep { $BUILT{$_} } qw(teitok flexicorp pages flexencoder pando fqs);
say_( "  installed: " . ( @built ? join( ', ', @built ) : 'nothing to rebuild' ) . "\n" );
say_( "  unchanged: " . join( ', ', @UNCHANGED ) . "\n" ) if @UNCHANGED;
say_( "  updated by the pull: " . join( ', ', map { "$_ ($PULLED{$_})" } sort keys %PULLED )
	. ( $PULLED{TEITOK} ? "  (TEITOK's pages are PHP: in use right away)" : '' ) . "\n" ) if %PULLED;
say_( "  FQS restart postponed (reindex jobs running): $POSTPONED\n" ) if $POSTPONED;
say_("  automatic updates: $AUTO_NOTE\n") if $AUTO_NOTE;
say_("  log: $LOG\n");
if ($CRON) {
	# unattended: silent when there is nothing to report, else a short summary (cron mails it)
	if ( !@built && !%PULLED && !@WARNINGS && !$RESTARTED && !$POSTPONED ) {
		close $LOGFH;
		unlink $LOG;
		exit 0;
	}
	print "TEITOK stack update on " . ( capture('hostname') || 'this server' ) . " ($STAMP)\n";
	print "  updated by the pull: " . join( ', ', map { "$_ ($PULLED{$_})" } sort keys %PULLED ) . "\n" if %PULLED;
	print "  rebuilt and installed: " . ( @built ? join( ', ', @built ) : 'nothing' ) . "\n";
	print "  FQS restarted\n" if $RESTARTED;
	print "  FQS restart postponed (reindex jobs running): $POSTPONED\n" if $POSTPONED;
	print "  warning: $_\n" for @WARNINGS;
	print "  log: $LOG\n";
}
if ( !@built && !$o{'no-check'} ) {
	say_("  nothing changed: checks skipped (--check runs them)\n");
} elsif ( !$o{'no-check'} && -f "$HERE/check-stack.pl" ) {
	step('Checks');
	system( 'perl', "$HERE/check-stack.pl", '--shared', $D->{shared}, '--web-user', $WEBU, '--prefix', $PREFIX );
	exit( $? >> 8 );
}
exit 0;
