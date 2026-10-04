#!/usr/bin/env perl
# install-teitok.pl - install TEITOK, or upgrade an existing installation, with
# its query stack (flexicorp, flexencoder, Pando, FQS).
#
#   sudo perl install-teitok.pl            fresh install, or upgrade when TEITOK is found
#   sudo perl install-teitok.pl -q         the same without questions (defaults everywhere)
#
# Options:
#   -q                    quiet: accept all defaults (for scripts, Docker)
#   -v                    verbose
#   --upgrade             upgrade the TEITOK installation found on this machine (no TEITOK
#                         base install; configuration is left as it is)
#   --fresh               install even when a TEITOK installation is found
#   --webserver NAME      apache or nginx (fresh install; default: the one installed, else asked)
#   --no-stack            only TEITOK itself (no flexicorp / Pando / FQS)
#   --no-cwb              do not build Corpus WorkBench
#   --demo / --no-demo    first project: a demo corpus (UD English EWT sample, indexed), or
#                         an empty project (asked; -q: demo). Real data does not belong in
#                         the shared project.
#   --project NAME        name of the first project (default ud-demo, or mycorpus when empty)
#   --git-folder DIR      where the git checkouts go (default /home/git, macOS /Users/Shared/git)
#   --shared-name NAME    name of the shared TEITOK project (default shared)
#   --admin-email EMAIL   --admin-password PW     the shared project's admin (asked otherwise)
#   --teitok-repo URL     (default https://gitlab.com/maartenes/TEITOK.git; a local path works)
#   --flexicorp-repo URL --flexicorp-ref REF --pando-repo URL --pando-ref REF
#                         passed on to install-stack.pl (flexicorp/install/install-stack.pl)
#   --check               only check the installation (check-stack.pl)
#
# On a machine where TEITOK is already installed (by this script or another way),
# install-teitok.pl hands over to install-stack.pl, which finds the installation
# (Apache or nginx, the shared project, the user PHP runs as) and adds or updates
# the query stack without changing TEITOK's configuration.
use strict;
use warnings;
use File::Basename qw(dirname basename);
use File::Path qw(make_path);
use File::Temp qw(tempdir);
use Getopt::Long qw(:config no_ignore_case bundling pass_through);
use POSIX qw(strftime);

my %o = (
	'teitok-repo'    => 'https://gitlab.com/maartenes/TEITOK.git',
	'flexicorp-repo' => 'https://github.com/ufal/flexicorp.git',
	'flexicorp-ref'  => '',
	'pando-repo'     => 'https://github.com/ufal/pando.git',
	'pando-ref'      => '',
	'shared-name'    => 'shared',
);
GetOptions( \%o, 'q', 'v', 'upgrade', 'fresh', 'webserver=s', 'no-stack', 'no-flexicorp', 'no-cwb', 'git-folder=s', 'demo!', 'project=s',
	'shared-name=s', 'admin-email=s', 'admin-password=s', 'teitok-repo=s', 'flexicorp-repo=s', 'flexicorp-ref=s',
	'pando-repo=s', 'pando-ref=s', 'check', 'help|h' ) or exit 2;
if ( $o{help} ) { open my $me, '<', $0; while (<$me>) { next if /^#!/; last unless /^#/; s/^# ?//; print; } exit 0; }
$o{'no-stack'} = 1 if $o{'no-flexicorp'};
my $quiet = $o{q};
my $MAC   = $^O eq 'darwin';
my $user  = getpwuid($<);
my $curuser = $ENV{SUDO_USER} || '';
if ( !$curuser ) { $curuser = `who 2>/dev/null` // ''; $curuser =~ s/\s.*//s; }
$ENV{PATH} = join( ':', grep { -d } ( split( /:/, $ENV{PATH} || '/usr/bin:/bin' ), '/usr/sbin', '/sbin', '/usr/local/bin', '/opt/homebrew/bin' ) );
$ENV{DEBIAN_FRONTEND} = 'noninteractive' if $quiet;

my $LOGDIR = $MAC ? '/Library/Logs/teitok-install' : '/var/log/teitok-install';
make_path($LOGDIR) if $user eq 'root';
my $LOG = ( -w $LOGDIR ? $LOGDIR : '/tmp' ) . '/teitok-' . strftime( '%Y%m%d-%H%M%S', localtime ) . '.log';

print "You are not running as root - most likely the script will not be allowed to perform certain tasks\n" if $user ne 'root';

sub q_ { my $s = shift; return $s if $s =~ m{^[\w./:=+,@%-]+$}; $s =~ s/'/'\\''/g; return "'$s'"; }
sub sh { my $c = shift; open my $l, '>>', $LOG; print $l "\$ $c\n"; close $l; return system("$c >>" . q_($LOG) . " 2>&1") == 0; }
sub cap { my $c = shift; $c .= ' 2>/dev/null' if $c !~ /2>/; my $o = `$c`; $o //= ''; chomp $o; return $o; }
sub have { return cap( 'command -v ' . shift ) ne ''; }
sub read_file { my $f = shift; open my $fh, '<', $f or return ''; local $/; my $s = <$fh>; return $s // ''; }
sub write_file { my ( $f, $s ) = @_; open my $fh, '>', $f or die "cannot write $f: $!\n"; print $fh $s; close $fh; }
sub confirm {    # yes/no, default yes; without $noexit a "no" stops the script
	my ( $text, $noexit ) = @_;
	return 1 if $quiet;
	print "$text [y] ";
	my $r = <STDIN> // ''; chomp $r;
	my $yes = ( $r eq '' || $r =~ /^y(es)?$/i ) ? 1 : 0;
	if ( !$noexit && !$yes ) { print "Please perform this action externally first and re-run this script\n"; exit; }
	return $yes;
}
sub askuser {
	my ( $message, $default, $force ) = @_;
	return $default if $quiet;
	my $dt = $force ? '' : "($default) ";
	print "$message $dt> ";
	my $answer = <STDIN> // ''; chomp $answer;
	if ( $answer eq '' ) { return $force ? askuser( $message, $default, $force ) : $default; }
	return $answer;
}

# ── what kind of system ─────────────────────────────────────────────────────
my %S = ( homes => '/home', webroot => '/var/www/html', apacheuser => 'www-data', apachehttpd => '/etc/apache2/apache2.conf',
	apacherestart => 'apachectl -k restart', apachemore => '', pkg => '', install => '' );
my ( $system, @pkg_base, @pkg_apache, @pkg_nginx );
if ( -e '/etc/debian_version' || -e '/etc/lsb-release' ) {
	$system = 'debian/ubuntu';
	$S{pkg} = 'apt'; $S{install} = 'apt-get -y -q install';
	@pkg_base   = qw(apt-utils git g++ make libxml-libxml-perl libhtml-parser-perl sudo subversion curl ca-certificates php php-xml php-mbstring apache2-utils);
	@pkg_apache = qw(apache2 libapache2-mod-php);
	@pkg_nginx  = qw(nginx php-fpm);
	$S{apacherestart} = 'apachectl restart';
} elsif ( -e '/etc/fedora-release' || -e '/etc/redhat-release' || -e '/etc/centos-release' ) {
	$system = -e '/etc/fedora-release' ? 'fedora' : 'redhat';
	$S{pkg} = have('dnf') ? 'dnf' : 'yum'; $S{install} = "$S{pkg} install -y";
	@pkg_base   = qw(git gcc-c++ make perl-XML-LibXML perl-HTML-Parser perl-Time-HiRes sudo subversion curl php php-xml php-mbstring php-cli httpd-tools procps-ng);
	@pkg_apache = qw(httpd php);
	@pkg_nginx  = qw(nginx php-fpm);
	$S{apacheuser} = 'apache'; $S{apachehttpd} = '/etc/httpd/conf/httpd.conf'; $S{apacherestart} = 'apachectl restart';
	$S{apachemore} = 'systemctl enable httpd 2>/dev/null; firewall-cmd --permanent --add-service=http 2>/dev/null; firewall-cmd --reload 2>/dev/null';
} elsif ( -e '/etc/SUSE-brand' ) {
	$system = 'suse';
	$S{pkg} = 'zypper'; $S{install} = 'zypper --non-interactive install';
	@pkg_base   = qw(git gcc-c++ make perl-XML-LibXML perl-HTML-Parser sudo subversion curl php8 php8-xmlreader php8-dom php8-mbstring apache2-utils);
	@pkg_apache = qw(apache2 apache2-mod_php8);
	@pkg_nginx  = qw(nginx php8-fpm);
	$S{apacheuser} = 'wwwrun'; $S{apachehttpd} = '/etc/apache2/httpd.conf'; $S{webroot} = '/srv/www/htdocs';
	$S{apacherestart} = 'apachectl restart'; $S{apachemore} = 'a2enmod mod_access_compat env rewrite php8';
} elsif ( -e '/etc/alpine-release' ) {
	$system = 'alpine';
	$S{pkg} = 'apk'; $S{install} = 'apk add -q';
	@pkg_base   = qw(git g++ make perl perl-xml-libxml perl-html-parser sudo subversion curl bash php php-xml php-simplexml php-dom php-mbstring php-session apache2-utils);
	@pkg_apache = qw(apache2 php-apache2);
	@pkg_nginx  = qw(nginx php-fpm);
	$S{apacheuser} = 'apache'; $S{apachehttpd} = '/etc/apache2/httpd.conf'; $S{webroot} = '/var/www/localhost/htdocs';
	$S{apacherestart} = 'httpd -k restart || httpd';
} elsif ( -e '/etc/arch-release' ) {
	$system = 'arch';
	$S{pkg} = 'pacman'; $S{install} = 'pacman --noconfirm --needed -S';
	@pkg_base   = qw(git gcc make perl-xml-libxml perl-html-parser sudo subversion curl php);
	@pkg_apache = qw(apache php-apache);
	@pkg_nginx  = qw(nginx php-fpm);
	$S{apacheuser} = 'http'; $S{apachehttpd} = '/etc/httpd/conf/httpd.conf'; $S{webroot} = '/srv/http'; $S{apacherestart} = 'apachectl restart';
} elsif ( -e '/usr/bin/sw_vers' ) {
	$system = 'macosx';
	$S{homes} = '/Users'; $S{apacheuser} = '_www'; $S{webroot} = '/Library/WebServer/Documents';
	$S{pkg} = 'brew';
	@pkg_base   = qw(git subversion php);
	@pkg_apache = qw(httpd);
	@pkg_nginx  = qw(nginx);
	$S{apachehttpd} = -e '/opt/homebrew/etc/httpd/httpd.conf' ? '/opt/homebrew/etc/httpd/httpd.conf' : '/etc/apache2/httpd.conf';
	$S{apacherestart} = '/usr/sbin/apachectl restart';
	if ( !$curuser || $curuser eq 'root' ) { print "Run this with sudo from your own account (Homebrew does not run as root)\n"; exit 1; }
	if ( system("sudo -u $curuser -H brew --version >/dev/null 2>&1") != 0 ) {
		print "Homebrew cannot be installed within this script - please run the following from the command line and re-run this script:\n";
		print '  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"', "\n";
		exit 1;
	}
	$S{install} = "sudo -u $curuser -H brew install";
} else {
	$system = 'unknown';
	print "You are running an as of yet unsupported linux version\n";
	confirm('Do you have the following packages currently running on your system: Apache2 or nginx with PHP, Perl XML::LibXML, SVN, Git');
}
my $git = $o{'git-folder'} || ( $MAC ? '/Users/Shared/git' : '/home/git' );

if ( $o{check} ) { my $cs = find_stack_script('check-stack.pl'); exec( 'perl', $cs ) if $cs; print "check-stack.pl not found (is flexicorp installed?)\n"; exit 2; }

# ── an existing installation? ───────────────────────────────────────────────
sub existing_teitok {
	for my $w ( $S{webroot}, '/var/www/html', '/srv/www/htdocs', '/var/www/localhost/htdocs', '/srv/http', '/Library/WebServer/Documents' ) {
		return "$w/teitok" if -d "$w/teitok" && ( -f "$w/teitok/.htaccess" || glob("$w/teitok/*/Resources/settings.xml") );
	}
	if ( have('nginx') ) { my $n = cap('nginx -T 2>&1'); return 'nginx: TT_ROOT' if $n =~ /fastcgi_param\s+TT_ROOT/; }
	for my $p ( glob('/etc/php/*/fpm/pool.d/*.conf'), glob('/etc/php-fpm.d/*.conf') ) { return "php-fpm: $p" if read_file($p) =~ /env\[TT_ROOT\]/; }
	return '';
}
my $found = existing_teitok();
my $mode = $o{upgrade} ? 'upgrade' : $o{fresh} ? 'fresh' : '';
if ( !$mode ) {
	if ($found) {
		print "An existing TEITOK installation was found ($found).\n";
		$mode = confirm( 'Do you want to upgrade it (add or update flexicorp, Pando, FQS; TEITOK settings are kept)?', 1 ) ? 'upgrade' : 'fresh';
	} else { $mode = 'fresh'; }
}

sub pkg_install {
	my @p = @_;
	return unless @p && $S{install};
	print "  installing: @p\n";
	if ( $o{v} ) { system("$S{install} @p"); }
	else { sh("$S{install} @p") or do { for my $x (@p) { sh("$S{install} $x") or print "  (could not install $x)\n"; } }; }
}
sub pkg_refresh {
	print "Updating the package lists\n";
	sh('apt-get update -q') if $S{pkg} eq 'apt';
	sh('apk update -q') if $S{pkg} eq 'apk';
	sh('pacman -Sy --noconfirm') if $S{pkg} eq 'pacman';
	sh('zypper --non-interactive refresh') if $S{pkg} eq 'zypper';
}
sub find_stack_script {
	my $name = shift;
	for my $d ( "$git/flexicorp/install", dirname( $0 eq '-' ? '.' : $0 ) ) { return "$d/$name" if -f "$d/$name"; }
	return '';
}
sub stack_args {
	my @a;
	for my $k (qw(flexicorp-repo flexicorp-ref pando-repo pando-ref)) { push @a, "--$k", $o{$k} if $o{$k} ne ''; }
	push @a, '-q' if $quiet;
	return @a;
}

# ── upgrade: hand over to install-stack.pl ──────────────────────────────────────
if ( $mode eq 'upgrade' ) {
	print "Upgrading the TEITOK installation found on this machine\n";
	pkg_refresh();
	pkg_install(qw(git curl)) if !have('git') || !have('curl');
	# a copy of flexicorp just for its installer; install-stack.pl then puts flexicorp
	# and pando next to the TEITOK checkout it finds and builds from there
	my $tmp = tempdir( 'teitok-stack-XXXXXX', TMPDIR => 1, CLEANUP => 1 );
	my @ref = $o{'flexicorp-ref'} ne '' ? ( '-b', $o{'flexicorp-ref'} ) : ();
	sh( join( ' ', 'git', 'clone', '-q', '--depth', '1', @ref, q_( $o{'flexicorp-repo'} ), q_("$tmp/flexicorp") ) )
		or do { print "Could not get flexicorp from $o{'flexicorp-repo'} (see $LOG)\n"; exit 1; };
	my @a = ( 'perl', "$tmp/flexicorp/install/install-stack.pl", stack_args(), @ARGV );
	push @a, '--git-folder', $o{'git-folder'} if $o{'git-folder'};
	push @a, '--fqs-admin', $o{'admin-email'} if $o{'admin-email'};
	system(@a);
	exit( $? >> 8 );
}

# ── fresh install ──────────────────────────────────────────────────────────
print "Setting up TEITOK for $system\n";

# web server: Apache (as always) or nginx with php-fpm
my $ws = lc( $o{webserver} // '' );
if ( $ws eq '' ) {
	my $hasng = have('nginx');
	my $hasap = have('apache2ctl') || have('apachectl') || have('httpd');
	if ( $hasng && !$hasap ) { $ws = 'nginx'; print "nginx is installed (no Apache): TEITOK will be served by nginx with php-fpm\n"; }
	elsif ( $hasap && !$hasng ) { $ws = 'apache'; }
	else { $ws = lc askuser( 'Which web server should serve TEITOK: apache or nginx?', 'apache' ); }
}
$ws = 'apache' unless $ws eq 'nginx';
if ( $MAC && $ws eq 'nginx' ) { print "On macOS this installer sets up Apache only; using Apache\n"; $ws = 'apache'; }

if ( $ws eq 'apache' ) {
	my $tmp = cap('sestatus');
	if ( $tmp =~ /enforcing/ && confirm( 'TEITOK is not currently compatible with SELinux - we can turn it off until reboot (change yourself to modify permanently), do you want to do so?', 1 ) ) { sh('setenforce 0'); }
}
my @pkg = ( @pkg_base, $ws eq 'nginx' ? @pkg_nginx : @pkg_apache );
print "TEITOK depends on the following packages which can be handled automatically: @pkg\n";
if ( confirm( 'Do you want to install those automatically when needed?', 1 ) ) {
	pkg_refresh();
	pkg_install(@pkg);
} else {
	print "We will continue assuming you have all the above components correctly installed; if not, this installer will fail and you should install TEITOK manually\n";
}
$git = askuser( 'Where do you want to save your Git files?', $git ) unless $o{'git-folder'};
my $webroot = $S{webroot};
my $webuser = $S{apacheuser};
if ( $ws eq 'nginx' ) { my ($u) = php_fpm_pool(); $webuser = $u if $u; }

# TEITOK checkout
make_path($git) unless -d $git;
if ( !-d $git ) { print "Creating the folder $git failed; please create it and restart the script and select an existing folder for your Git file\n"; exit; }
my $gituser;
if ( !-d "$git/TEITOK" ) {
	$gituser = askuser( 'TEITOK does not seem to be cloned; as which user do you want to clone it?', $webuser );
	sh("chown -R $gituser " . q_($git));
	sh( "cd " . q_($git) . " && sudo -u $gituser -H git clone -q " . q_( $o{'teitok-repo'} ) . " TEITOK" ) or do { print "Cloning TEITOK from $o{'teitok-repo'} failed (see $LOG)\n"; exit 1; };
}
$gituser //= ( getpwuid( ( stat("$git/TEITOK") )[4] ) )[0] || $webuser;

# Smarty
my $smartyroot = -d "$git/smarty" ? "$git/smarty/libs/" : '';
if ( !$smartyroot ) { my $t = cap('locate Smarty.class.php 2>/dev/null | head -1'); ( $smartyroot = $t ) =~ s/Smarty\.class\.php.*// if $t; }
if ( !$smartyroot ) {
	sh( "cd " . q_($git) . " && sudo -u $gituser -H git clone -q https://github.com/smarty-php/smarty.git" );
	$smartyroot = "$git/smarty/libs/";
}

my $sharedfldr = askuser( "What do you want the 'shared' TEITOK project to be called?", $o{'shared-name'} );
my $tt = "$webroot/teitok";
if ( !-d $tt ) {
	make_path($tt);
	if ( !-d $tt ) { print "Creating the folder $tt failed\n"; exit 1; }
	write_file( "$tt/.htaccess", "DirectoryIndex index.php\nRewriteEngine On\nRewriteCond %{SCRIPT_FILENAME} !-f\nRewriteCond %{SCRIPT_FILENAME} !-d\nRewriteRule ^(.*?)/(.*)\$ \$1/index.php/\$2\n\n\nSetEnv SMARTY_DIR $smartyroot\nSetEnv TT_SHARED $tt/$sharedfldr/\nSetEnv TT_ROOT $git/TEITOK/\n" );
	symlink( "$git/TEITOK/Scripts", "$tt/Scripts" );    # the Javascript files
}

if ( $ws eq 'apache' ) { setup_apache(); } else { setup_nginx(); }

if ( !-e '/usr/local/bin/tt-cwb-encode' ) {
	print "Installing C++ modules of TEITOK\n";
	for my $p (qw(tt-cwb-encode tt-cwb-xidx tt-cqp)) {
		sh( "cd " . q_("$git/TEITOK/src") . " && g++ -std=c++11 -o /usr/local/bin/$p $p.cpp pugixml.cpp functions-c11.cpp" ) or print "  (building $p failed, see $LOG)\n";
	}
}

# Corpus WorkBench
if ( !$o{'no-cwb'} && !have('cqp') ) {
	if ( confirm( 'Corpus WorkBench does not seem to be installed - do you want to install it?', 1 ) ) {
		print "Attempting to install CWB via SVN (this takes a while)\n";
		my $cw = tempdir( 'cwb-XXXXXX', TMPDIR => 1, CLEANUP => 1 );
		if ( $system eq 'debian/ubuntu' ) { pkg_install(qw(autoconf bison flex gcc make pkg-config libc6-dev libncurses-dev libpcre3-dev libglib2.0-dev libreadline-dev)); }
		elsif ( $S{pkg} =~ /dnf|yum/ ) { pkg_install(qw(autoconf bison flex gcc make pkgconf ncurses-devel pcre-devel glib2-devel readline-devel)); }
		if ( sh("cd $cw && svn export -q http://svn.code.sf.net/p/cwb/code/cwb/trunk cwb") ) {
			my $script = $MAC ? 'install-mac-osx' : 'install-linux';
			sh("cd $cw/cwb && CWB_LIVE_DANGEROUSLY=1 ./install-scripts/$script --quiet") or print "  (building CWB failed, see $LOG)\n";
			sh('cp /usr/local/cwb-*/bin/* /usr/local/bin/') if !-e '/usr/local/bin/cqp';
		} else { print "  (could not download CWB, see $LOG)\n"; }
	}
}

# Make TEITOK root writable to allow creation of new projects
my $writable = 0;
if ( confirm( "Do you want to allow the web server to write to the TEITOK root? (less secure, more flexible)", 1 ) ) {
	sh("chown -R $webuser " . q_($tt));
	$writable = 1;
}

# the shared project
if ( !-d "$tt/$sharedfldr" ) {
	sh( "cp -R " . q_("$git/TEITOK/projects/default-shared") . ' ' . q_("$tt/$sharedfldr") );
	sh( "chown -R $webuser " . q_("$tt/$sharedfldr") );
}
# the check page
if ( !-d "$tt/check" && -d "$git/TEITOK/projects/check" ) { sh( "cp -R " . q_("$git/TEITOK/projects/check") . ' ' . q_("$tt/check") ); sh( "chown -R $webuser " . q_("$tt/check") ); }

# Install shared password (only on a first install: re-running must not reset the users)
my $shareduser = $o{'admin-email'} // '';
my $ul = "$tt/$sharedfldr/Resources/userlist.xml";
if ( !-e $ul ) {
	$shareduser = $o{'admin-email'} // askuser( 'Provide a user email (for your shared project folder)', 'teitokadmin@localhost', 1 );
	my $sharedpwd = $o{'admin-password'} // askuser( 'Provide a password (for your shared project folder)', 'changethis', 1 );
	my $hp = have('htpasswd') ? 'htpasswd' : ( have('htpasswd2') ? 'htpasswd2' : '' );
	my $sharedcrypt = '';
	if ($hp) { ( $sharedcrypt = cap( "$hp -bnBC 10 '' " . q_($sharedpwd) ) ) =~ s/^:(.*?)\s*$/$1/; }
	else { $sharedcrypt = cap( 'php -r ' . q_( 'echo password_hash($argv[1], PASSWORD_BCRYPT);' ) . ' ' . q_($sharedpwd) ); }
	write_file( $ul, "<userlist>\n\t<user password=\"$sharedcrypt\" enc=\"1\" email=\"$shareduser\" permissions=\"admin\" projects=\"all\">Shared Admin</user>\n</userlist>" );
	sh( "chown $webuser " . q_($ul) );
} else {
	print "Keeping the existing users of the shared project ($ul)\n";
}

# services without an init system (containers): one script starts everything
write_services_script($ws) if !$MAC && !-d '/run/systemd/system';

# ── the query stack ──────────────────────────────────────────────────────────
# flexicorp holds the installer scripts (stack, projects, checks): clone it in any case
my $fx = "$git/flexicorp";
if ( !-d $fx ) {
	my @ref = $o{'flexicorp-ref'} ne '' ? ( '-b', $o{'flexicorp-ref'} ) : ();
	sh( "cd " . q_($git) . " && sudo -u $gituser -H git clone -q @ref " . q_( $o{'flexicorp-repo'} ) . " flexicorp" )
		or print "!!!! Cloning flexicorp failed (is $git writable for $gituser?); see $LOG\n";
}
if ( !$o{'no-stack'} && confirm( 'Do you want to install the query stack (flexicorp, Pando, FQS: multi-engine search, dependency queries)?', 1 ) ) {
	my $st = "$fx/install/install-stack.pl";
	if ( -f $st ) {
		my @a = ( 'perl', $st, '--teitok-root', "$git/TEITOK", '--shared', "$tt/$sharedfldr", '--web-user', $webuser, '--git-folder', $git, stack_args() );
		push @a, '--fqs-admin', $shareduser if $shareduser;
		push @a, @ARGV;    # options meant for install-stack.pl (e.g. --no-check)
		system(@a);
		print "!!!! The query stack was not installed completely - see above; re-run: sudo perl $st\n" if $?;
	} else { print "!!!! $st not found - the query stack was not installed\n"; }
}

# A first project, so real data does not end up in the shared project: a demo corpus
# (a sample of a UD treebank, indexed) or an empty project
my $project = '';
my $cp = "$fx/install/create-project.pl";
if ( -f $cp ) {
	my $demo = $o{demo} // confirm( 'Do you want a demo corpus as your first project (about 500 sentences of the UD English EWT treebank, CC BY-SA 4.0, with dependency annotation)?', 1 );
	$project = $o{project} || ( $demo ? 'ud-demo' : askuser( 'Your corpus goes in a project of its own (not in the shared project). Name of your first, empty project?', 'mycorpus' ) );
	if ( -d "$tt/$project" ) { print "Project $tt/$project exists already: left as it is\n"; }
	else {
		my @a = ( 'perl', $cp, '--name', $project, '--shared', "$tt/$sharedfldr", '--web-user', $webuser, '--teitok-root', "$git/TEITOK", ( $demo ? '--demo' : () ) );
		if ( system(@a) != 0 ) {
			if ( $demo && !-d "$tt/$project" ) {    # e.g. no network for the treebank: an empty project instead
				print "The demo corpus could not be made; creating an empty project instead (add the demo later: sudo perl $cp --demo)\n";
				$project = 'mycorpus';
				system( 'perl', $cp, '--name', $project, '--shared', "$tt/$sharedfldr", '--web-user', $webuser, '--teitok-root', "$git/TEITOK" ) == 0 or $project = '';
			} else { print "!!!! Creating the project $project failed - re-run: sudo perl $cp " . ( $demo ? '--demo' : "--name $project" ) . "\n"; $project = ''; }
		}
	}
} else { print "(no flexicorp installer scripts: create your first project in TEITOK's shared admin, not in the shared project itself)\n"; }

# Finish and send user to online install environment
my $url = $project ? "http://127.0.0.1/teitok/$project/index.php?action=login" : "http://127.0.0.1/teitok/$sharedfldr/index.php?action=login";
if ($writable) {
	if ( $curuser && $ENV{DISPLAY} && have('firefox') ) {
		print "Opening the login page as $curuser - please finish the installation in the interface\n";
		system("sudo -u $curuser firefox '$url' >/dev/null 2>&1 &");
	} else { print "Open $url in a browser" . ( $project ? " (log in as " . ( $shareduser || "the shared admin" ) . ")\n" : " and create your first TEITOK project within the interface\n" ); }
} else {
	print "Open http://127.0.0.1/teitok/check/index.php in a browser and check that your installation is complete\n";
}
print "(installation log: $LOG)\n";
exit 0;

# ── web server set-up ──────────────────────────────────────────────────────
sub start_service {    # systemd, sysv/openrc, or the daemon itself (containers)
	my ( $name, @direct ) = @_;
	return 1 if -d '/run/systemd/system' && sh("systemctl enable --now $name && systemctl restart $name");
	return 1 if have('service') && sh("service $name restart");
	return 1 if have('rc-service') && sh("rc-service $name restart");
	for my $d (@direct) { return 1 if sh($d); }
	return 0;
}

sub setup_apache {
	sh($S{apachemore}) if $S{apachemore};
	sh('a2enmod env rewrite') if have('a2enmod');
	if ( !-e $S{apachehttpd} ) {
		print "\n\n!!!! Apache2 configuration file $S{apachehttpd} not found - you will need to add something like the following yourself to the httpd.conf or apache2.conf in order to make allow TEITOK to change files:\n";
		print "\t<Directory $tt/>\n\t\tOptions Indexes FollowSymLinks\n\t\tAllowOverride All\n\t</Directory>\n\n";
	} else {
		my $c = read_file( $S{apachehttpd} );
		my $c0 = $c;
		$c .= "\n# Settings for TEITOK\n<Directory $tt/>\n\tOptions FollowSymLinks\n\tAllowOverride All\n</Directory>\n" if $c !~ /TEITOK/;
		$c =~ s/^#(LoadModule\s+(env_module|rewrite_module|php\S*_module))/$1/mg;
		if ( $c ne $c0 ) { write_file( "$S{apachehttpd}.before-teitok", $c0 ) unless -e "$S{apachehttpd}.before-teitok"; write_file( $S{apachehttpd}, $c ); }
	}
	# RHEL / Fedora run PHP for Apache through php-fpm (conf.d/php.conf): it must run too
	if ( apache_uses_fpm() ) { start_service( 'php-fpm', 'mkdir -p /run/php-fpm && php-fpm -D', 'mkdir -p /run/php-fpm && /usr/sbin/php-fpm -D' ) or print "!!!! php-fpm could not be started\n"; }
	start_service( $system eq 'debian/ubuntu' || $system eq 'suse' ? 'apache2' : 'httpd', $S{apacherestart}, 'apachectl start', 'httpd' )
		or print "Apache does not seem to be running - please correct manually\n";
}

sub apache_uses_fpm {
	for my $c ( glob('/etc/httpd/conf.d/*.conf'), glob('/etc/apache2/conf-enabled/*.conf') ) { return 1 if read_file($c) =~ /proxy:unix:.*php-fpm|proxy:fcgi:.*9000/; }
	return 0;
}

sub php_fpm_pool {    # (user, listen) of the default php-fpm pool
	for my $p ( glob('/etc/php/*/fpm/pool.d/www.conf'), '/etc/php-fpm.d/www.conf', glob('/etc/php*/php-fpm.d/www.conf'), '/etc/php8/php-fpm.d/www.conf' ) {
		next unless -f $p;
		my $s = read_file($p);
		my ($u) = $s =~ /^\s*user\s*=\s*(\S+)/m;
		my ($l) = $s =~ /^\s*listen\s*=\s*(\S+)/m;
		return ( $u, $l, $p );
	}
	return ();
}

sub setup_nginx {
	my ( $u, $listen, $pool ) = php_fpm_pool();
	print "!!!! php-fpm pool configuration not found - is php-fpm installed?\n" unless $pool;
	$listen //= '127.0.0.1:9000';
	my $pass = $listen =~ m{^/} ? "unix:$listen" : $listen;
	my $loc = <<"NGX";
# TEITOK (written by install-teitok.pl): the rewrite from teitok/.htaccess, and
# the environment TEITOK reads (TT_ROOT, TT_SHARED, SMARTY_DIR) for PHP.
location ^~ /teitok/ {
	root $webroot;
	index index.php;
	try_files \$uri \$uri/ \@teitok;
	location ~ [^/]\\.php(/|\$) {
		root $webroot;
		fastcgi_split_path_info ^(.+?\\.php)(/.*)\$;
		if (!-f \$document_root\$fastcgi_script_name) { return 404; }
		set \$tt_path_info \$fastcgi_path_info;
		include fastcgi_params;
		fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
		fastcgi_param PATH_INFO \$tt_path_info;
		fastcgi_param TT_ROOT $git/TEITOK/;
		fastcgi_param TT_SHARED $tt/$sharedfldr/;
		fastcgi_param SMARTY_DIR $smartyroot;
		fastcgi_read_timeout 300;
		fastcgi_pass $pass;
	}
}
location \@teitok {
	rewrite ^/teitok/([^/]+)/(.*)\$ /teitok/\$1/index.php/\$2 last;
}
NGX
	my $snip;
	if ( -d '/etc/nginx/default.d' ) {    # RHEL / Fedora: included inside the default server
		$snip = '/etc/nginx/default.d/teitok.conf';
		write_file( $snip, $loc );
	} else {
		make_path('/etc/nginx/snippets');
		$snip = '/etc/nginx/snippets/teitok.conf';
		write_file( $snip, $loc );
		my ($site) = grep { -f $_ } ( '/etc/nginx/sites-available/default', '/etc/nginx/http.d/default.conf', '/etc/nginx/conf.d/default.conf' );
		if ( !$site ) {
			$site = -d '/etc/nginx/conf.d' ? '/etc/nginx/conf.d/teitok-server.conf' : '/etc/nginx/http.d/teitok-server.conf';
			write_file( $site, "server {\n\tlisten 80 default_server;\n\tserver_name _;\n\troot $webroot;\n\tinclude $snip;\n}\n" );
		} else {
			my $s = read_file($site);
			if ( $s !~ /\Q$snip\E/ ) {
				write_file( "$site.before-teitok", $s ) unless -e "$site.before-teitok";
				$s =~ s/(server\s*\{)/$1\n\tinclude $snip;/ or $s .= "\nserver {\n\tlisten 80;\n\tinclude $snip;\n}\n";
				write_file( $site, $s );
			}
		}
		print "  nginx: $snip, included from $site\n";
	}
	my $t = cap('nginx -t 2>&1');
	if ( $t =~ /\[::\]:\d+ failed \(97/ ) {    # no IPv6 (common in containers): drop the [::] listens
		for my $f ( glob('/etc/nginx/sites-enabled/*'), glob('/etc/nginx/conf.d/*.conf'), glob('/etc/nginx/http.d/*.conf'), '/etc/nginx/nginx.conf' ) {
			my $c = read_file($f);
			next unless $c =~ /^\s*listen\s+\[::\]/m;
			$c =~ s/^(\s*)(listen\s+\[::\])/$1# no IPv6 here (install-teitok.pl): $2/mg;
			write_file( ( -l $f ? readlink($f) =~ m{^/} ? readlink($f) : dirname($f) . '/' . readlink($f) : $f ), $c );
		}
		print "  nginx: IPv6 is not available here, disabled the [::] listen lines\n";
	}
	if ( !sh('nginx -t') ) { print "!!!! nginx -t reports an error in the configuration (see $LOG)\n"; }
	my ($fpm) = map { basename($_) } ( glob('/usr/sbin/php-fpm*'), glob('/usr/sbin/php*-fpm*'), glob('/usr/sbin/php-fpm[0-9]*') );
	my $fpmsvc = ( glob('/etc/init.d/php*-fpm') )[0];
	$fpmsvc = $fpmsvc ? basename($fpmsvc) : 'php-fpm';
	start_service( $fpmsvc, 'mkdir -p /run/php /run/php-fpm && ' . ( $fpm ? "/usr/sbin/$fpm -D" : 'php-fpm -D' ) ) or print "!!!! php-fpm could not be started\n";
	start_service( 'nginx', 'nginx -s reload', 'nginx' ) or print "!!!! nginx could not be started\n";
}

sub write_services_script {    # containers: start the web server, php-fpm and FQS
	my $ws = shift;
	my $f = '/usr/local/sbin/teitok-services';
	my ($fpm) = map { "/usr/sbin/" . basename($_) } ( glob('/usr/sbin/php-fpm*'), glob('/usr/sbin/php*-fpm*') );
	my $web = $ws eq 'nginx'
		? ( $fpm ? "$fpm -D\n" : '' ) . "nginx\n"
		: ( apache_uses_fpm() && $fpm ? "$fpm -D\n" : '' ) . ( $system eq 'debian/ubuntu' ? "apachectl start\n" : "httpd -k start 2>/dev/null || apachectl start\n" );
	write_file( $f, "#!/bin/sh\n# Start TEITOK's services without an init system (written by install-teitok.pl)\nmkdir -p /run/php /run/php-fpm\n$web" .
		"[ -x /usr/local/sbin/teitok-fqs ] && /usr/local/sbin/teitok-fqs start\nexit 0\n" );
	chmod 0755, $f;
	print "  without systemd: start the services with $f\n";
}
