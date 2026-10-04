#!/usr/bin/env perl
# check-stack.pl - check a TEITOK + query stack installation the way TEITOK uses it:
# as the web user, with the PATH and environment FQS runs with.
#
#   sudo perl check-stack.pl [--shared DIR] [--web-user USER] [--prefix DIR] [--no-smoke] [--url URL]
#
# Every line is OK, WARN or FAIL (with what to do). Exit status 1 when anything FAILs.
# The smoke test encodes a three-sentence TEITOK document with flexencoder +
# pando-index (as the web user, in a temporary folder) and runs a dependency
# query on it.
use strict;
use warnings;
use File::Basename qw(dirname basename);
use File::Temp qw(tempdir);
use Getopt::Long;

my %o = ( prefix => '/usr/local' );
GetOptions( \%o, 'shared=s', 'web-user=s', 'prefix=s', 'no-smoke', 'url=s', 'help|h' ) or exit 2;
if ( $o{help} ) { open my $me, '<', $0; while (<$me>) { next if /^#!/; last unless /^#/; s/^# ?//; print; } exit 0; }
my $MAC = $^O eq 'darwin';
$ENV{PATH} = "$o{prefix}/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin";

my ( $nfail, $nwarn ) = ( 0, 0 );
sub ok   { printf "  OK    %s\n", shift; }
sub wrn  { my ( $m, $fix ) = @_; $nwarn++; printf "  WARN  %s\n", $m; print "        -> $fix\n" if $fix; }
sub bad  { my ( $m, $fix ) = @_; $nfail++; printf "  FAIL  %s\n", $m; print "        -> $fix\n" if $fix; }
sub head { print "\n$_[0]\n"; }
sub q_ { my $s = shift; return $s if $s =~ m{^[\w./:=+,@%-]+$}; $s =~ s/'/'\\''/g; return "'$s'"; }
sub cap { my $c = shift; $c .= ' 2>/dev/null' if $c !~ /2>/; my $o = `$c`; $o //= ''; chomp $o; return $o; }
sub read_file { my $f = shift; open my $fh, '<', $f or return ''; local $/; my $s = <$fh>; return $s // ''; }
sub as_u {    # shell command line run as the web user
	my ( $u, $cmd ) = @_;
	return $cmd if $u eq ( getpwuid($<) // '' );
	return "runuser -u " . q_($u) . " -- sh -c " . q_($cmd) if !$MAC && cap('command -v runuser') ne '';
	return "sudo -u " . q_($u) . " sh -c " . q_($cmd);
}

# where things are: the manifest written by install-stack.pl, else options, else detection
my $mf = read_file("$o{prefix}/share/teitok-stack/manifest.json");
my %m;
$m{$1} = $2 while $mf =~ /"(teitok_root|shared|web_user)"\s*:\s*"([^"]*)"/g;
my $shared = $o{shared} || $m{shared} || '';
if ( !$shared ) {
	my $det = cap( 'perl ' . q_( dirname($0) . '/install-stack.pl' ) . ' --detect' );
	$shared = $1 if $det =~ /^SHARED=(.+)$/m;
	$o{'web-user'} ||= $1 if $det =~ /^WEB_USER=(.+)$/m;
}
my $webu = $o{'web-user'} || $m{web_user} || '';
if ( !$shared || !$webu ) { print "cannot tell where TEITOK is: pass --shared DIR --web-user USER\n"; exit 2; }
my $envf = -f '/etc/fqs/fqs.env' ? '/etc/fqs/fqs.env' : ( -f '/usr/local/etc/fqs/fqs.env' ? '/usr/local/etc/fqs/fqs.env' : '' );
my $envs = $envf ? read_file($envf) : '';
my $fqs_path = ( $envs =~ /^\s*PATH=(.+)$/m ) ? $1 : "$o{prefix}/bin:/usr/local/bin:/usr/bin:/bin";
my $venv = ( $mf =~ /"venv"\s*:\s*"([^"]+)"/ ) ? $1 : "$shared/Resources/venv";
print "TEITOK stack check: shared project $shared, web user $webu\n";

# ── components on the web user's PATH ─────────────────────────────────────────
head('Programs (as the web user, with the PATH FQS uses)');
for my $b (qw(flexencoder pando-index pando flexicorp-pando fqs)) {
	my $p = cap( as_u( $webu, "PATH=$fqs_path command -v $b" ) );
	if ( !$p ) { ( $b eq 'flexicorp-pando' ? \&wrn : \&bad )->( "$b not found", "run install-stack.pl (component " . ( $b =~ /pando/ ? 'pando' : $b ) . ")" ); next; }
	my $v = $b eq 'flexencoder' ? '' : cap( "$p --version" );
	ok( "$b: $p" . ( $v ? "  ($v)" : '' ) );
}
my $fe = cap('flexencoder --help 2>&1');
if ( $fe =~ /--output-pando/ ) { ok('flexencoder accepts --output-pando') } elsif ($fe) { bad( 'flexencoder does not accept --output-pando (too old for flexicorp reindex)', 'install-stack.pl --only flexencoder' ); }
my $lib = "$o{prefix}/lib/libflexicorp_pando." . ( $MAC ? 'dylib' : 'so' );
-f $lib ? ok("libflexicorp_pando: $lib") : bad( "$lib missing (FQS falls back to the slow path)", 'install-stack.pl --only pando' );

# ── flexicorp in the venv ───────────────────────────────────────────────────
head("flexicorp (venv $venv)");
if ( !-x "$venv/bin/python" ) { bad( "no venv at $venv", 'install-stack.pl --only flexicorp' ); }
else {
	my $notmine = cap( 'find ' . q_($venv) . ' ! -user ' . q_($webu) . ' -print -quit' );
	$notmine ? wrn( "files in the venv not owned by $webu (e.g. $notmine): pip as $webu will fail", "chown -R $webu " . q_($venv) ) : ok("venv owned by $webu");
	my @left = glob("$venv/lib/python*/site-packages/~*");
	@left ? wrn( "pip leftovers in site-packages: @left", 'remove them (an interrupted pip run)' ) : ok('no pip leftovers');
	my $v = cap( as_u( $webu, q_("$venv/bin/python") . q( -c 'import flexicorp.cli,inspect,importlib.metadata as m; print(m.version("flexicorp"), "staging" if "reindex_staging" in inspect.getsource(flexicorp.cli) else "no-staging")') ) );
	if ( !$v ) { bad( "import flexicorp fails as $webu", 'install-stack.pl --only flexicorp' ); }
	elsif ( $v =~ /no-staging/ ) { bad( "flexicorp $v: too old (no staged reindex)", 'install-stack.pl --only flexicorp' ); }
	else { my $want = ( $mf =~ /"flexicorp"\s*:\s*\{[^}]*"version"\s*:\s*"([^"]+)"/ ) ? $1 : ''; ok( "flexicorp " . ( split ' ', $v )[0] . ( $want && index( $v, $want ) != 0 ? " (manifest says $want)" : '' ) ); }
}

# ── TEITOK pages ────────────────────────────────────────────────────────────
head('TEITOK pages (shared project)');
my $pm = read_file("$shared/Resources/flexicorp-teitok-ui.json");
if ( !$pm ) { bad( 'flexicorp pages not installed in the shared project', 'install-stack.pl --only pages' ); }
else {
	my ($pv) = $pm =~ /"version"\s*:\s*"([^"]*)"/; my ($pc) = $pm =~ /"commit"\s*:\s*"([^"]*)"/;
	ok( "pages $pv ($pc)" );
	for my $f (qw(Sources/flexicorp.php Sources/fqs.php Scripts/flexicorp.js)) { -f "$shared/$f" ? 1 : bad( "$shared/$f missing", 'install-stack.pl --only pages' ); }
	my @shadow = grep { -f $_ } map { ( "$_/Sources/flexicorp.php", "$_/Scripts/flexicorp.js" ) } grep { $_ ne $shared && -d $_ } glob( dirname($shared) . '/*' );
	@shadow ? wrn( "project copies win over the shared pages: @shadow", 'remove them unless that project needs its own version' ) : ok('no project copies hide the shared pages');
}

# ── FQS ────────────────────────────────────────────────────────────────────
head('FQS');
if ( !$envf ) { bad( 'no FQS environment file (/etc/fqs/fqs.env)', 'install-stack.pl --only fqs' ); }
else {
	my $n = () = $envs =~ /^\s*FQS_SECRET=/mg;
	$n == 1 ? ok("$envf: one FQS_SECRET") : $n == 0 ? bad( "$envf: no FQS_SECRET", 'install-stack.pl --only fqs' ) : wrn( "$envf: $n FQS_SECRET lines (the last one is used)", 'keep only one' );
	cap( as_u( $webu, "test -r " . q_($envf) . " && echo yes" ) ) eq 'yes' ? ok("$webu can read $envf (TEITOK signs FQS requests with it)") : bad( "$webu cannot read $envf", "chgrp the file to a group of $webu, mode 0640" );
	my ($py) = $envs =~ /^\s*PYTHON_BIN=(\S+)/m;
	$py && -x $py ? ok("PYTHON_BIN=$py") : bad( 'PYTHON_BIN not set or not executable', "set PYTHON_BIN=$venv/bin/python in $envf" );
}
my $h = cap('curl -sf -m 3 http://127.0.0.1:8787/health');
if ( !$h ) { bad( 'FQS does not answer on http://127.0.0.1:8787/health', ( -d '/run/systemd/system' ? 'systemctl status fqs; journalctl -u fqs' : "start it: $o{prefix}/sbin/teitok-fqs (log /var/log/fqs/fqs.log)" ) ); }
else {
	my ($v) = $h =~ /"version"\s*:\s*"([^"]+)"/;
	ok( "FQS answers" . ( $v ? " (version $v)" : '' ) );
	$h =~ /"pando"\s*:\s*\{\s*"available"\s*:\s*false/ ? wrn( 'FQS runs without libflexicorp_pando (slow cold path for Pando queries)', "check FLEXICORP_PANDO_LIB in $envf, restart FQS" ) : ok('FQS uses libflexicorp_pando (hot path)');
	my ($db) = $h =~ /"db_path"\s*:\s*"([^"]+)"/;
	if ( !$db ) { my $j = read_file('/etc/fqs/fqs.json') || read_file('/usr/local/etc/fqs/fqs.json'); ($db) = $j =~ /"db_path"\s*:\s*"([^"]+)"/; }
	$db = $1 if $envs =~ /^\s*FQS_DB_PATH=(\S+)/m;
	if ($db) { cap( as_u( $webu, "test -w " . q_($db) . " && test -w " . q_( dirname($db) ) . " && echo yes" ) ) eq 'yes' ? ok("$webu can write the catalog $db") : bad( "$webu cannot write the catalog $db (or its folder)", 'chown/chmod it for the FQS service user and the web user' ); }
}

# ── web server ──────────────────────────────────────────────────────────────
head('Web');
my $name = basename($shared);
my $url = $o{url} || "http://127.0.0.1/teitok/$name/index.php?action=flexicorp";
my $code = cap( 'curl -s -o /dev/null -w "%{http_code}" -m 90 ' . q_($url) );    # the first flexicorp page load probes every engine
$code =~ /^(200|30\d)$/ ? ok("$url -> HTTP $code") : bad( "$url -> HTTP " . ( $code || 'no answer' ), 'is the web server running, and serving TEITOK at /teitok/?' );

# ── smoke test ─────────────────────────────────────────────────────────────
if ( !$o{'no-smoke'} ) {
	head('Smoke test (flexencoder -> pando-index -> dependency query, as the web user)');
	my $t = tempdir( 'teitok-smoke-XXXXXX', TMPDIR => 1, CLEANUP => 1 );
	mkdir "$t/xmlfiles";
	open my $s, '>', "$t/settings.xml"; print $s <<'X'; close $s;
<?xml version="1.0" encoding="UTF-8"?>
<ttsettings><cqp><pattributes><item key="form"/><item key="lemma"/><item key="upos"/><item key="head"/><item key="deprel"/></pattributes>
<sattributes><item key="text" level="text"/><item key="s" level="s" toklist="sameAs"><item key="id"/></item></sattributes></cqp></ttsettings>
X
	open my $x, '>', "$t/xmlfiles/smoke.xml"; print $x <<'X'; close $x;
<?xml version="1.0" encoding="UTF-8"?>
<TEI><text id="smoke"><s id="s-1" sameAs="#w-1 #w-2 #w-3"/><tok id="w-1" lemma="I" upos="PRON" head="w-2" deprel="nsubj">I</tok> <tok id="w-2" lemma="live" upos="VERB" deprel="root">live</tok> <tok id="w-3" lemma="here" upos="ADV" head="w-2" deprel="advmod">here</tok>
<s id="s-2" sameAs="#w-4 #w-5"/><tok id="w-4" lemma="they" upos="PRON" head="w-5" deprel="nsubj">They</tok> <tok id="w-5" lemma="leave" upos="VERB" deprel="root">left</tok>
<s id="s-3" sameAs="#w-6 #w-7"/><tok id="w-6" lemma="home" upos="NOUN" deprel="root">Home</tok> <tok id="w-7" lemma="." upos="PUNCT" head="w-6" deprel="punct">.</tok></text></TEI>
X
	system( 'chown', '-R', $webu, $t ) if $< == 0;
	my $enc = cap( as_u( $webu, "cd " . q_($t) . " && PATH=$fqs_path flexencoder --project-root . --settings settings.xml --searchfolder xmlfiles --output-pando pando --output-xidx xidx 2>&1; echo EXIT=\$?" ) );
	my ($ec) = $enc =~ /EXIT=(\d+)/;
	if ( !defined $ec || $ec != 0 || !-f "$t/pando/corpus.info" ) {
		my @l = grep { !/^Wrote|^Removed/ } split /\n/, $enc;
		bad( 'encoding the sample failed: ' . join( ' | ', @l[ -3 .. -1 ] ), 'see the lines above; flexencoder and pando-index must be the installed versions' );
	} else {
		ok('flexencoder + pando-index built an index (staged into a temporary folder)');
		my $res = cap( as_u( $webu, "PATH=$fqs_path pando " . q_("$t/pando") . " " . q_('[upos="VERB"] > [deprel="nsubj"]') . " --json --total --limit 1" ) );
		my ($tot) = $res =~ /"total"\s*:\s*(\d+)/;
		( defined $tot && $tot == 2 ) ? ok('dependency query [upos="VERB"] > [deprel="nsubj"]: 2 hits, as expected') : bad( 'dependency query gave ' . ( $tot // 'no answer' ) . ' hits, expected 2', 'sentence regions or heads are not reaching pando-index' );
	}
}

print "\n", ( $nfail ? "FAILED: $nfail problem(s)" : 'All checks passed' ), ( $nwarn ? ", $nwarn warning(s)" : '' ), "\n";
exit( $nfail ? 1 : 0 );
