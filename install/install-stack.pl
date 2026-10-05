#!/usr/bin/env perl
# install-stack.pl - install or upgrade the TEITOK query stack:
#
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
# settings that are not there yet. Re-running it upgrades every component
# (git pull --ff-only of the checkouts, clean rebuilds, atomic installs).
# install-teitok.pl runs it at the end of a fresh install and for --upgrade.
#
# Usage:
#   sudo perl install-stack.pl [options]
#
#   --detect              only print what was found (as KEY=VALUE lines) and exit
#   --check               only run the checks (check-stack.pl) and exit
#   -q, --yes             no questions: accept what was detected
#   --teitok-root DIR     the TEITOK checkout (TT_ROOT)          [detected]
#   --shared DIR          the TEITOK shared project (TT_SHARED)  [detected]
#   --web-user USER       the user PHP runs as                    [detected]
#   --git-folder DIR      where flexicorp and pando are checked out
#                         [the folder of the flexicorp checkout this script runs from;
#                          else the one the previous run used; else next to TEITOK]
#   --prefix DIR          binaries and libraries (default /usr/local)
#   --only LIST           comma-separated subset of: flexicorp,pages,flexencoder,pando,fqs
#   --skip LIST           components to leave out
#   --flexicorp-repo URL  (default https://github.com/ufal/flexicorp.git; a local path works)
#   --flexicorp-ref REF   branch / tag for a new clone (default: the repository's default)
#   --pando-repo URL      (default https://github.com/ufal/pando.git)
#   --pando-ref REF
#   --no-pull             use the existing checkouts as they are
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

my $VERSION = '0.1.0';
my %o = (
	prefix         => '/usr/local',
	'flexicorp-repo' => 'https://github.com/ufal/flexicorp.git',
	'pando-repo'     => 'https://github.com/ufal/pando.git',
	'flexicorp-ref'  => '',
	'pando-ref'      => '',
	only => '', skip => '',
);
GetOptions( \%o, 'detect', 'check', 'q|yes', 'teitok-root=s', 'shared=s', 'web-user=s', 'git-folder=s',
	'prefix=s', 'only=s', 'skip=s', 'flexicorp-repo=s', 'flexicorp-ref=s', 'pando-repo=s', 'pando-ref=s',
	'no-pull', 'no-deps', 'fqs-admin=s', 'no-check', 'no-frontends', 'help|h' ) or exit 2;
if ( $o{help} ) { usage(); exit 0; }

my $OS   = $^O;                                   # linux, darwin, freebsd
my $MAC  = $OS eq 'darwin';
my $ME   = abs_path($0);
my $HERE = dirname($ME);                           # .../flexicorp/install
my $STAMP = strftime( '%Y%m%d-%H%M%S', localtime );

# ── logging ──────────────────────────────────────────────────────────────────
my $LOGDIR = $MAC ? '/Library/Logs/teitok-install' : '/var/log/teitok-install';
make_path($LOGDIR) unless -d $LOGDIR;
$LOGDIR = '/tmp' unless -w $LOGDIR;
my $LOG = "$LOGDIR/stack-$STAMP.log";
open( my $LOGFH, '>>', $LOG ) or die "cannot write $LOG: $!\n";
{ my $old = select($LOGFH); $| = 1; select($old); }
$| = 1;

sub logline { print $LOGFH @_; }
sub say_ { my $m = join( '', @_ ); print $m; logline($m); }
sub step { my $m = shift; say_("\n== $m\n"); }
sub warn_ { say_( "  warning: ", @_, "\n" ); }
sub fail {
	my $m = shift;
	say_("\n!!!! $m\n     full log: $LOG\n");
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

# run as another user: runuser (Linux, works with nologin shells) or sudo
sub as_user {
	my ( $user, @cmd ) = @_;
	my $cur = getpwuid($<);
	return @cmd if !defined $user || $user eq '' || $user eq $cur;
	return ( 'runuser', '-u', $user, '--', @cmd ) if !$MAC && have('runuser');
	return ( 'sudo', '-H', '-u', $user, @cmd );
}

sub usage { open my $me, '<', $0 or return; while (<$me>) { next if /^#!/; last unless /^#/; s/^# ?//; print; } }

my @PATHS_EXTRA = ( '/usr/local/bin', '/usr/sbin', '/sbin', '/opt/homebrew/bin', '/opt/rust/cargo/bin' );
$ENV{PATH} = join( ':', grep { -d $_ } ( split( /:/, $ENV{PATH} || '/usr/bin:/bin' ), @PATHS_EXTRA ) );

if ( $< != 0 && !$o{detect} && !$o{check} ) {
	fail("run as root (sudo perl $0 ...): it installs into $o{prefix} and runs builds as other users");
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
printf "  %-16s %s\n" x 6, 'TEITOK checkout', $D->{teitok_root}, 'shared project', $D->{shared}, 'web user', "$D->{web_user} (group $D->{web_group})",
	'web server', ( $D->{webserver} || '?' ), 'venv', $D->{venv}, 'system', "$OS, $PKG, init: $INIT";
logline("teitok_root=$D->{teitok_root} shared=$D->{shared} web_user=$D->{web_user} webserver=" . ( $D->{webserver} // '' ) . "\n");
if ( !$o{q} && -t STDIN && !yes( "Is this the installation to add the query stack to?", 1 ) ) {
	say_("Stopped: re-run with --teitok-root / --shared / --web-user to point at the right one.\n");
	exit 0;
}

my %want = map { $_ => 1 } qw(flexicorp pages flexencoder pando fqs);
if ( $o{only} ) { %want = map { $_ => 1 } split /[,\s]+/, $o{only}; }
delete $want{$_} for split /[,\s]+/, $o{skip};

my $PREFIX = $o{prefix};
my $MANIFEST = "$PREFIX/share/teitok-stack/manifest.json";
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

if ( !$o{'no-deps'} ) {
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

sub checkout {
	my ( $name, $repo, $ref ) = @_;
	my $dir = "$GIT/$name";
	if ( -d "$dir/.git" ) {
		my $own = owner_of($dir);
		if ( $o{'no-pull'} ) { say_("  $dir: using as is (--no-pull)\n"); }
		else {
			my $dirty = capture( cmdline( as_user( $own, 'git', '-C', $dir, 'status', '--porcelain', '--untracked-files=no' ) ) );
			if ( $dirty ne '' ) { warn_("$dir has local changes: not pulling, building it as it is"); }
			elsif ( !run( "update $dir (git pull --ff-only, as $own)", [ as_user( $own, 'git', '-C', $dir, 'pull', '--ff-only', '-q' ) ], soft => 1 ) ) {
				warn_("git pull in $dir failed (see log): building the checkout as it is");
			}
		}
	} elsif ( -d $dir ) {
		warn_("$dir exists but is not a git checkout: building it as it is");
	} else {
		make_path($GIT) unless -d $GIT;
		my $own = owner_of($GIT);
		my @c = ( 'git', 'clone', '-q', ( $ref ne '' ? ( '-b', $ref ) : () ), $repo, $dir );
		run( "clone $repo into $dir (as $own)", [ as_user( $own, @c ) ] );
	}
	my $commit = capture( cmdline( 'git', '-C', $dir, 'rev-parse', '--short', 'HEAD' ) );
	my $branch = capture( cmdline( 'git', '-C', $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ) );
	my $dirty = capture( cmdline( 'git', '-C', $dir, 'status', '--porcelain', '--untracked-files=no' ) ) ne '' ? '-dirty' : '';
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
my ( $FLEXI, $FLEXI_COMMIT ) = checkout( 'flexicorp', $o{'flexicorp-repo'}, $o{'flexicorp-ref'} );
my ( $PANDO, $PANDO_COMMIT ) = ( '', '' );
( $PANDO, $PANDO_COMMIT ) = checkout( 'pando', $o{'pando-repo'}, $o{'pando-ref'} ) if $want{pando} || $want{fqs};
my $FLEXI_VERSION = ( read_file("$FLEXI/pyproject.toml") =~ /^version\s*=\s*"([^"]+)"/m ) ? $1 : '?';

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
if ( $want{pando} ) {
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
	$manifest{pando} = { version => $v, commit => $PANDO_COMMIT };
	remove_tree( $b, $stage );
}

# ── flexencoder ────────────────────────────────────────────────────────────
if ( $want{flexencoder} ) {
	step('flexencoder');
	my $b = fresh_dir('flexencoder');
	copy_tree( "$FLEXI/flexencoder", $b );    # never reuses objects from the checkout
	my $jobs = capture('nproc') || capture('sysctl -n hw.ncpu') || 2;
	run( 'build (make)', [ 'make', '-s', '-j', $jobs, '-f', 'Makefile.flexencoder', 'BINDIR=.' ], cwd => $b );
	install_file( "$b/flexencoder", "$PREFIX/bin/flexencoder", 0755 );
	my $usage = capture( q_("$PREFIX/bin/flexencoder") . ' --help 2>&1' );
	fail('the installed flexencoder does not accept --output-pando') if $usage !~ /--output-pando/;
	say_("  installed: $PREFIX/bin/flexencoder\n");
	$manifest{flexencoder} = { commit => $FLEXI_COMMIT };
	remove_tree($b);
}

# ── flexicorp (Python, in TEITOK's venv, as the web user) ────────────────────
if ( $want{flexicorp} ) {
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
	$manifest{flexicorp} = { version => $FLEXI_VERSION, commit => $FLEXI_COMMIT, venv => $venv };
	remove_tree( $src, $whl );
}

# ── TEITOK pages (shared project) ───────────────────────────────────────────────
if ( $want{pages} ) {
	step('flexicorp pages for TEITOK (shared project)');
	my $ui = "$FLEXI/teitok_teitok_ui/install-teitok-ui.pl";
	fail("$ui not found") unless -f $ui;
	my $out = `perl $ui --shared @{[q_($D->{shared})]} --user @{[q_($WEBU)]} 2>&1`;
	logline($out);
	print map { "  $_\n" } split /\n/, $out;
	fail('installing the TEITOK pages failed') if $?;
	$manifest{pages} = { version => $FLEXI_VERSION, commit => $FLEXI_COMMIT };
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

if ( $want{fqs} ) {
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
		run( ( $was_running ? 'restart' : 'start' ) . ' fqs.service', 'systemctl daemon-reload && systemctl enable -q fqs && systemctl restart fqs' );
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
		run( 'reload launchd job org.teitok.fqs', "launchctl bootout system/org.teitok.fqs 2>/dev/null; launchctl bootstrap system $plist" );
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
		run( 'start FQS (no systemd: ' . $start . ')', [$start] );
	}
	# wait for /health
	my $ok = 0;
	for ( 1 .. 30 ) { if ( capture('curl -sf -m 2 http://127.0.0.1:8787/health') ne '' ) { $ok = 1; last; } sleep 1; }
	$ok ? say_("  FQS is up: http://127.0.0.1:8787/health\n") : warn_("FQS did not answer on 127.0.0.1:8787 within 30 s (see /var/log/fqs/)");
	my $fv = capture( q_("$PREFIX/bin/fqs") . ' --version' );
	$manifest{fqs} = { version => $fv, commit => $FLEXI_COMMIT, init => $INIT };
	remove_tree($src);
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
	for my $c (qw(flexicorp pages flexencoder pando fqs)) {
		if ( $manifest{$c} ) {
			my $m = $manifest{$c};
			push @parts, "    \"$c\": {" . join( ', ', map { my $v = $m->{$_} // ''; $v =~ s/"/'/g; "\"$_\": \"$v\"" } sort keys %$m ) . ", \"installed\": \"$STAMP\"}";
		} elsif ( $old{$c} ) { push @parts, "    \"$c\": {$old{$c}}"; }
	}
	print $fh join( ",\n", @parts ), "\n  }\n}\n";
	close $fh;
	rename "$mf.new-$$", $mf;
}

step('Done');
say_("  components: " . join( ', ', grep { $manifest{$_} } qw(flexicorp pages flexencoder pando fqs) ) . "\n  log: $LOG\n");
if ( !$o{'no-check'} && -f "$HERE/check-stack.pl" ) {
	step('Checks');
	system( 'perl', "$HERE/check-stack.pl", '--shared', $D->{shared}, '--web-user', $WEBU, '--prefix', $PREFIX );
	exit( $? >> 8 );
}
exit 0;
