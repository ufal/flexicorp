#!/usr/bin/env perl
# Install the FQS portal (one TEITOK project that searches many corpora served by FQS; README.md
# in this folder) into ONE TEITOK project. Optional: flexicorp's normal install
# (install-teitok-ui.pl, into the shared project) never installs it.
#
#   fqsportal.php, corpora.php  -> PROJECT/Sources/
#   startup.php                 -> PROJECT/Sources/startup.php, the hook TEITOK runs for this
#                                  project only; see "An existing startup.php" below
#
# Never into the shared project (or another project whose Sources/startup.php TEITOK runs for
# every project): the hook would then turn every project into a portal.
#
# An existing startup.php: one this installer put there and nobody changed is updated; one it
# put there that was edited since is kept as startup.php.local-TIME and replaced, as other
# files; one that already calls fqp_startup() is left as it is; any other is left untouched,
# and the installer says which two lines to add to it (exit status 3: the portal files are
# installed, but the portal is not on until those lines are there).
#
# Re-running upgrades in place. A manifest (PROJECT/Resources/fqsportal-install.json) records
# what was installed, with checksums; a file changed locally since the last install (or there
# before the first one) is kept as FILE.local-YYYYmmdd-HHMMSS before it is replaced.
#
# Usage:
#   sudo perl install-portal.pl --project DIR [--user USER] [--dry-run]
#   sudo perl install-portal.pl --project DIR --remove [--dry-run]
#
#   --project DIR  the portal's TEITOK project (it must have Resources/settings.xml)
#   --shared DIR   the TEITOK shared project, refused as a target (default: TT_SHARED from the
#                  .htaccess next to DIR, else DIR/../shared, else TT_SHARED of the usual TEITOK places)
#   --user USER    owner of the installed files (default: owner of DIR)
#   --remove       remove the portal's files (only those unchanged since they were installed;
#                  settings.xml is left as it is)
#   --dry-run      show what would change, change nothing
#
# Exit status: 0 done; 1 error; 3 installed, but the project's own startup.php needs the hook.
use strict;
use warnings;
use File::Basename qw(dirname basename);
use File::Copy qw(copy);
use File::Path qw(make_path);
use Cwd qw(abs_path);
use Digest::SHA;
use POSIX qw(strftime);

# every error exits with 1 (die alone exits with whatever errno was)
$SIG{__DIE__} = sub { print STDERR $_[0]; exit 1; };

my ( $project, $shared, $user, $dry, $remove ) = ( '', '', '', 0, 0 );
while ( @ARGV ) {
	my $a = shift @ARGV;
	if    ( $a eq '--project' ) { $project = shift @ARGV // ''; }
	elsif ( $a eq '--shared' )  { $shared = shift @ARGV // ''; }
	elsif ( $a eq '--user' )    { $user = shift @ARGV // ''; }
	elsif ( $a eq '--dry-run' ) { $dry = 1; }
	elsif ( $a eq '--remove' )  { $remove = 1; }
	elsif ( $a eq '-h' || $a eq '--help' ) {
		open my $me, '<', $0 or die; while ( <$me> ) { last if !/^#/; next if /^#!/; s/^# ?//; print; } exit 0;
	} else { die "install-portal.pl: unknown option $a (see --help)\n"; }
}
die "install-portal.pl: --project DIR is required (see --help)\n" if $project eq '';
$project =~ s{/+$}{};
die "install-portal.pl: $project is not a directory\n" unless -d $project;
die "install-portal.pl: $project is not a TEITOK project (no Resources/settings.xml)\n" unless -f "$project/Resources/settings.xml";
$project = abs_path($project);

# ── never the shared project ────────────────────────────────────────────────
# the TEITOK folder this project is in first (its .htaccess, else its shared/), then the usual places
if ( $shared eq '' ) {
	my @ht = ( dirname($project) . '/.htaccess' );
	push @ht, '/var/www/html/teitok/.htaccess', '/srv/www/htdocs/teitok/.htaccess',
	          '/Library/WebServer/Documents/teitok/.htaccess', '/var/www/localhost/htdocs/teitok/.htaccess'
		unless -r $ht[0] || -d dirname($project) . '/shared';
	for my $ht ( @ht ) {
		next unless -r $ht;
		open my $fh, '<', $ht or next;
		while ( <$fh> ) { if ( /^\s*SetEnv\s+TT_SHARED\s+(\S+)/ ) { $shared = $1; last; } }
		close $fh;
		last if $shared ne '';
	}
	$shared = dirname($project) . '/shared' if $shared eq '';
}
my $sharedabs = -d $shared ? abs_path($shared) : '';
die "install-portal.pl: $project is the shared project: TEITOK runs its Sources/startup.php for every project,\n"
  . "  which would turn every project into a portal. Install the portal into a project of its own.\n"
	if ( $sharedabs ne '' && $sharedabs eq $project ) || basename($project) eq 'shared';

my ( $uid, $gid ) = ( -1, -1 );
if ( $user ne '' ) {
	my @pw = getpwnam($user) or die "install-portal.pl: no such user $user\n";
	( $uid, $gid ) = @pw[ 2, 3 ];
} else {
	( $uid, $gid ) = ( stat $project )[ 4, 5 ];
	$user = getpwuid($uid) // $uid;
}

my $src = dirname( abs_path($0) );
my $repo = dirname( dirname($src) );
my @files = ( 'fqsportal.php', 'corpora.php' );
my $HOOK = 'Sources/startup.php';
my $MARK = 'fqsportal: TEITOK startup hook';
for ( @files, 'startup.php' ) { die "install-portal.pl: $src/$_ is missing\n" unless -f "$src/$_"; }

sub sha { my $p = shift; return '' unless -f $p; return Digest::SHA->new(256)->addfile($p, 'b')->hexdigest; }
sub slurp { my $p = shift; open my $fh, '<', $p or return ''; local $/; my $s = <$fh>; close $fh; return $s // ''; }

my $version = '';
if ( open my $pp, '<', "$repo/pyproject.toml" ) {
	while ( <$pp> ) { if ( /^version\s*=\s*"([^"]+)"/ ) { $version = $1; last; } }
	close $pp;
}
my $commit = '';
if ( -d "$repo/.git" ) {
	$commit = `git -C '$repo' rev-parse --short HEAD 2>/dev/null` // ''; chomp $commit;
	my $dirty = `git -C '$repo' status --porcelain -- '$src' 2>/dev/null` // '';
	$commit .= '-dirty' if $commit ne '' && $dirty ne '';
}

# previous manifest: { "rel": "sha", ... }
my $manifest = "$project/Resources/fqsportal-install.json";
my %old;
{
	my $j = slurp($manifest);
	if ( $j =~ /"files"\s*:\s*\{(.*?)\}/s ) {
		my $body = $1;
		while ( $body =~ /"([^"]+)"\s*:\s*"([0-9a-f]{64})"/g ) { $old{$1} = $2; }
	}
}
my $stamp = strftime( '%Y%m%d-%H%M%S', localtime );
my $what = $dry ? 'would ' : '';

# ── remove ──────────────────────────────────────────────────────────────────
if ( $remove ) {
	my @kept;
	for my $rel ( ( map { "Sources/$_" } @files ), $HOOK ) {
		my $to = "$project/$rel";
		next unless -e $to;
		if ( !exists $old{$rel} || $old{$rel} ne sha($to) ) { push @kept, $rel; next; }
		print "  ${what}remove $rel\n";
		unlink $to or die "cannot remove $to: $!\n" unless $dry;
	}
	unlink $manifest unless $dry;
	print "FQS portal ", ( $dry ? 'would be removed' : 'removed' ), " from $project\n";
	print "  left in place (changed since they were installed, or not installed by this script): ", join( ', ', @kept ), "\n" if @kept;
	print "  the <fqsportal> settings in Resources/settings.xml are left as they are\n";
	exit 0;
}

# ── install ─────────────────────────────────────────────────────────────────
my ( %new, @changed, @kept_local, $unchanged, $hook_note );
$unchanged = 0;
my $hook_status = 0;
sub put {
	my ( $from, $rel ) = @_;
	my $to = "$project/$rel";
	my $want = sha($from);
	$new{$rel} = $want;
	my $have = sha($to);
	if ( $have eq $want ) { $unchanged++; return; }
	if ( $have ne '' && ( !exists $old{$rel} || $old{$rel} ne $have ) ) {
		push @kept_local, "$rel -> $rel.local-$stamp";
		copy( $to, "$to.local-$stamp" ) or die "cannot back up $to: $!\n" unless $dry;
	}
	push @changed, ( $have eq '' ? "new      $rel" : "updated  $rel" );
	return if $dry;
	my $dir = dirname($to);
	unless ( -d $dir ) { make_path($dir) or die "cannot create $dir: $!\n"; chown $uid, $gid, $dir; }
	my $tmp = "$to.part-$$";
	copy( $from, $tmp ) or die "cannot write $tmp: $!\n";
	chmod 0644, $tmp;
	chown $uid, $gid, $tmp;
	rename( $tmp, $to ) or die "cannot replace $to: $!\n";
}
put( "$src/$_", "Sources/$_" ) for @files;

# the hook: ours (installed by us, or marked as ours) is installed or updated; another is not touched
{
	my $to = "$project/$HOOK";
	my $text = slurp($to);
	if ( !-e $to || ( exists $old{$HOOK} && $old{$HOOK} eq sha($to) ) || index( $text, $MARK ) >= 0 ) {
		put( "$src/startup.php", $HOOK );
	} elsif ( $text =~ /\bfqp_startup\s*\(/ ) {
		$hook_note = "Sources/startup.php is the project's own and already calls fqp_startup(): left as it is";
	} else {
		$hook_status = 3;
		$hook_note = "Sources/startup.php is the project's own: left untouched. The portal is not on until it has these\n"
		  . "  two lines (inside its <?php … ?>, before anything is printed):\n"
		  . "    include_once( __DIR__ . '/fqsportal.php' );\n    fqp_startup();\n"
		  . "  then re-run this installer to check";
	}
}
my @gone = grep { !exists $new{$_} && $_ ne $HOOK && -e "$project/$_" } sort keys %old;

unless ( $dry ) {
	my $tmp = "$manifest.part-$$";
	open my $out, '>', $tmp or die "cannot write $tmp: $!\n";
	print $out "{\n  \"component\": \"flexicorp-fqsportal\",\n  \"version\": \"$version\",\n",
	           "  \"commit\": \"$commit\",\n  \"source\": \"$src\",\n",
	           "  \"installed\": \"", strftime( '%Y-%m-%dT%H:%M:%S%z', localtime ), "\",\n  \"files\": {\n",
	           join( ",\n", map { "    \"$_\": \"$new{$_}\"" } sort keys %new ), "\n  }\n}\n";
	close $out;
	chmod 0644, $tmp; chown $uid, $gid, $tmp;
	rename( $tmp, $manifest ) or die "cannot replace $manifest: $!\n";
}

# ── what the project still needs ────────────────────────────────────────────
my $settings = slurp("$project/Resources/settings.xml");
my @todo;
push @todo, "add <fqsportal/> to Resources/settings.xml (options: README.md), e.g.\n"
  . "      <fqsportal sources=\"non-teitok\" cache_ttl=\"300\"/>" unless $settings =~ /<fqsportal\b/;
push @todo, "add the picker to the menu in Resources/settings.xml: <item key=\"corpora\" display=\"Corpora\"/>"
	unless $settings =~ /<item\b[^>]*\bkey\s*=\s*["']corpora["']/;
push @todo, "name the FQS in Resources/settings.xml: <flexicorp fqs_url=\"http://…:8787\"/> (else FQS_URL, else a local FQS)"
	unless $settings =~ /<flexicorp\b[^>]*\bfqs_url\s*=/ || ( $ENV{FQS_URL} // '' ) ne '';
my $sharedfc = $sharedabs ne '' && -f "$sharedabs/Sources/flexicorp.php";
push @todo, "install flexicorp's TEITOK pages into the shared project (install-stack.pl, or install-teitok-ui.pl): the portal searches with them"
	unless $sharedfc || -f "$project/Sources/flexicorp.php";

# ── report ──────────────────────────────────────────────────────────────────
printf "FQS portal %s %s%s into %s (owner %s)\n", ( $version || '?' ),
	( $commit ne '' ? "($commit) " : '' ), ( $dry ? 'would be installed' : 'installed' ), $project, $user;
print "  $_\n" for @changed;
printf "  %d file(s) already up to date\n", $unchanged if $unchanged;
print "  local changes kept as:\n", map { "    $_\n" } @kept_local if @kept_local;
print "  no longer shipped (left in place): ", join( ', ', @gone ), "\n" if @gone;
print "  $hook_note\n" if $hook_note;
print "  still to do:\n", map { "    - $_\n" } @todo if @todo;
exit $hook_status;
