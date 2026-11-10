#!/usr/bin/env perl
# publish-project.pl - move a finished TEITOK project from where it was built (a development
# TEITOK, e.g. teitok-dev) to where it is published (e.g. teitok-main), and point every absolute
# path in it at the new folder.
#
#   perl publish-project.pl FROM TO                  move FROM to TO (same disk), then rewrite
#   perl publish-project.pl --rewrite-only OLD DIR   DIR was copied (rsync) from OLD: only rewrite
#   perl publish-project.pl --dry-run …              show what would change, change nothing
#
# What is rewritten: the old project path (FROM, or OLD) at the start of a path, in every text
# file of the project outside xmlfiles/ and tmp/: the CWB registries' HOME and INFO (cqp/), a
# Manatee registry's PATH and VERTICAL, settings, and anything else that names it. pando and
# xidx indexes hold no absolute paths. xmlfiles/ are left alone: a path there is provenance (the
# converter's note of its source), not something read. Symlinks are reported when they point
# into the old folder or, relative, outside the project (they break with the move); they are
# not changed.
#
# The move is a rename, so FROM and TO must be on the same file system (for another disk: copy
# with rsync, then --rewrite-only). TO must not exist. Ownership and permissions stay as they
# are. Afterwards: register the project in the published FQS (fqs corpora import … --only ID).
use strict;
use warnings;
use Cwd qw(abs_path);
use File::Basename qw(dirname basename);
use File::Find;
use Getopt::Long qw(GetOptions);

my ( $rewrite_only, $dry, $help );
GetOptions( 'rewrite-only' => \$rewrite_only, 'dry-run|n' => \$dry, 'help|h' => \$help ) or exit 2;
if ( $help || @ARGV != 2 ) {
	open my $me, '<', $0 or exit 2;
	while (<$me>) { next if /^#!/; last unless /^#/; s/^# ?//; print; }
	exit( $help ? 0 : 2 );
}
my ( $from, $to ) = @ARGV;
s{/+$}{} for $from, $to;

if ($rewrite_only) {
	-d $to or die "$to: no such folder\n";
	$to = abs_path($to);
	$from =~ m{^/} or die "--rewrite-only: OLD must be an absolute path (the folder it was copied from)\n";
} else {
	-d $from or die "$from: no such folder\n";
	-f "$from/Resources/settings.xml" or die "$from: not a TEITOK project (no Resources/settings.xml)\n";
	-e $to and die "$to exists already: not overwritten\n";
	my $parent = dirname($to);
	-d $parent or die "$parent: no such folder\n";
	$from = abs_path($from);
	$to = abs_path($parent) . '/' . basename($to);
	( stat $from )[0] == ( stat $parent )[0]
	  or die "$from and $parent are on different file systems: copy with rsync, then\n"
	  . "  perl $0 --rewrite-only $from $to\n";
}
die "the old and the new path are the same\n" if $from eq $to;

if ( !$rewrite_only ) {
	print( ( $dry ? 'would move' : 'move' ), " $from -> $to\n" );
	if ( !$dry ) { rename $from, $to or die "cannot move $from to $to: $!\n"; }
}
my $root = $dry && !$rewrite_only ? $from : $to;

# the old path at the start of a path: followed by /, a quote, whitespace or the end
my $old = qr{\Q$from\E(?=[/"'\s]|$)}m;
my ( $files, $changed, @links ) = ( 0, 0 );
find(
	{
		no_chdir => 1,
		preprocess => sub { return grep { !( $File::Find::dir eq $root && ( $_ eq 'xmlfiles' || $_ eq 'tmp' ) ) } @_ },
		wanted => sub {
			my $f = $File::Find::name;
			if ( -l $f ) {
				my $t = readlink $f;
				my $abs = $t =~ m{^/} ? $t : dirname($f) . "/$t";
				push @links, "$f -> $t" if $t =~ $old || ( $t !~ m{^/} && index( abs_path_lexical($abs), "$root/" ) != 0 );
				return;
			}
			return unless -f $f && -s _ && -s _ < 64 * 1024 * 1024;
			open my $fh, '<:raw', $f or return;
			my $head = '';
			read $fh, $head, 8192;
			return if $head =~ /\0/;    # binary: an index file
			local $/;
			my $text = $head . ( <$fh> // '' );
			close $fh;
			$files++;
			my $n = () = $text =~ /$old/g;
			return unless $n;
			$changed++;
			my $rel = substr( $f, length($root) + 1 );
			print( ( $dry ? 'would rewrite' : 'rewrite' ), " $rel ($n)\n" );
			return if $dry;
			( my $new = $text ) =~ s/$old/$to/g;
			my @st = stat $f;
			my $tmp = "$f.publish-tmp";
			open my $out, '>:raw', $tmp or die "cannot write $tmp: $!\n";
			print $out $new;
			close $out or die "cannot write $tmp: $!\n";
			chmod $st[2] & 07777, $tmp;
			chown $st[4], $st[5], $tmp;    # as root: keep the owner; otherwise a no-op
			rename $tmp, $f or die "cannot replace $f: $!\n";
		},
	},
	$root
);
print "$changed of $files text file(s) " . ( $dry ? 'would be ' : '' ) . "rewritten ($from -> $to)\n";
print "symlinks that break with the move (not changed):\n", map { "  $_\n" } @links if @links;

# a path with . and .. resolved without touching the file system (the target may not exist)
sub abs_path_lexical {
	my @out;
	for ( split m{/}, shift ) {
		next if $_ eq '' || $_ eq '.';
		if ( $_ eq '..' ) { pop @out; next; }
		push @out, $_;
	}
	return '/' . join '/', @out;
}
