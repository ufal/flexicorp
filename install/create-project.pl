#!/usr/bin/env perl
# create-project.pl - create a TEITOK project (not the shared one: real data belongs
# in a project of its own), empty or with a demo corpus.
#
#   sudo perl create-project.pl --name mycorpus [--title "My corpus"]
#   sudo perl create-project.pl --demo [--name ud-demo] [--sentences 500] [--conllu URL|FILE]
#   sudo perl create-project.pl --site [--title "Corpora at ..."]
#
#   --name NAME        folder name of the project (default: mycorpus, or ud-demo with --demo)
#   --title TITLE      title shown in TEITOK
#   --demo             fill it with a demo corpus: the first sentences (whole documents)
#                      of a UD treebank, converted to TEITOK XML (stand-off sentences,
#                      tokens with lemma, upos, xpos, feats, head, deprel) and indexed
#                      for Pando (and CWB when installed)
#   --conllu URL|FILE  the treebank (default: UD English EWT, test part, CC BY-SA 4.0)
#   --sentences N      about how many sentences (default 500; documents are kept whole)
#   --shared DIR  --web-user USER  --teitok-root DIR      [detected, as install-stack.pl does]
#   --no-index         do not index the demo corpus
#   --site             the public start page instead of a corpus: a non-corpus project
#                      (default name "site") that opens on the corpus list (action=fqs) and
#                      has an About page for the hosting institution, plus <teitok>/index.php
#                      forwarding to it (only when that file does not exist yet), so
#                      visitors land there and never in the shared project
#
# Like TEITOK's "Create new project" (shared admin): index.php from the TEITOK
# checkout, Resources/settings.xml from the shared project's defaultsettings.xml,
# a home page, and an entry in the shared project's corpus list.
use strict;
use warnings;
use File::Basename qw(dirname basename);
use File::Path qw(make_path);
use File::Copy qw(copy);
use File::Temp qw(tempdir);
use Getopt::Long;
use POSIX qw(strftime);

my %o = (
	sentences => 500,
	conllu    => 'https://raw.githubusercontent.com/UniversalDependencies/UD_English-EWT/master/en_ewt-ud-test.conllu',
);
GetOptions( \%o, 'name=s', 'title=s', 'demo', 'conllu=s', 'sentences=i', 'shared=s', 'web-user=s', 'teitok-root=s', 'no-index', 'site', 'help|h' ) or exit 2;
use Encode qw(decode_utf8);
for my $k (qw(title name)) { $o{$k} = decode_utf8( $o{$k} ) if defined $o{$k}; }    # files are written as UTF-8
if ( $o{help} ) { open my $me, '<', $0; while (<$me>) { next if /^#!/; last unless /^#/; s/^# ?//; print; } exit 0; }
my $HERE = dirname( Cwd::abs_path($0) );
use Cwd ();

sub q_ { my $s = shift; return $s if $s =~ m{^[\w./:=+,@%-]+$}; $s =~ s/'/'\\''/g; return "'$s'"; }
sub cap { my $c = shift; $c .= ' 2>/dev/null' if $c !~ /2>/; my $r = `$c`; $r //= ''; chomp $r; return $r; }
sub read_file { my $f = shift; open my $fh, '<', $f or return ''; local $/; my $s = <$fh>; return $s // ''; }
sub write_file { my ( $f, $s ) = @_; open my $fh, '>:utf8', $f or die "cannot write $f: $!\n"; print $fh $s; close $fh; }
sub xe { my $s = shift // ''; $s =~ s/&/&amp;/g; $s =~ s/</&lt;/g; $s =~ s/>/&gt;/g; $s =~ s/"/&quot;/g; return $s; }
sub as_u { my ( $u, $c ) = @_; return -x '/usr/sbin/runuser' || -x '/sbin/runuser' ? "runuser -u " . q_($u) . " -- sh -c " . q_($c) : "sudo -u " . q_($u) . " -H sh -c " . q_($c); }

# where TEITOK is: the same detection as install-stack.pl
if ( !$o{shared} || !$o{'web-user'} || !$o{'teitok-root'} ) {
	my $det = cap( 'perl ' . q_("$HERE/install-stack.pl") . ' --detect' );
	$o{shared}        ||= $1 if $det =~ /^SHARED=(.+)$/m;
	$o{'web-user'}    ||= $1 if $det =~ /^WEB_USER=(.+)$/m;
	$o{'teitok-root'} ||= $1 if $det =~ /^TEITOK_ROOT=(.+)$/m;
}
for my $k ( 'shared', 'web-user', 'teitok-root' ) { die "create-project.pl: cannot tell --$k (pass it)\n" unless $o{$k}; }
my $shared = $o{shared}; $shared =~ s{/+$}{};
my $root   = dirname($shared);
my $webu   = $o{'web-user'};
my $tt     = $o{'teitok-root'};
my $name   = $o{name} || ( $o{site} ? 'site' : $o{demo} ? 'ud-demo' : 'mycorpus' );
die "create-project.pl: project name '$name': use letters, digits, - and _\n" unless $name =~ /^[A-Za-z0-9][A-Za-z0-9_-]*$/;
my $dir = "$root/$name";
die "create-project.pl: $dir exists - refusing to overwrite it\n" if -e $dir;
my $title = $o{title} || ( $o{site} ? 'TEITOK corpora' : $o{demo} ? 'UD demo corpus' : $name eq 'mycorpus' ? 'My corpus' : $name );
site_project() if $o{site};

# the treebank first: nothing is created when it cannot be had
my $DEMO_FILE = $o{conllu};
my $LANG = ( $o{demo} && basename( $o{conllu} ) =~ /^([a-z]{2,3})_[a-z0-9]+-ud-/ ) ? $1 : '';
my $DEMO_TMP = tempdir( 'teitok-demo-XXXXXX', TMPDIR => 1, CLEANUP => 1 );
if ( $o{demo} ) {
	if ( $DEMO_FILE =~ m{^https?://} ) {
		print "Downloading $DEMO_FILE\n";
		system( 'curl', '-fsSL', '-m', '120', '-o', "$DEMO_TMP/source.conllu", $DEMO_FILE ) == 0 or die "create-project.pl: download failed: $DEMO_FILE\n";
		$DEMO_FILE = "$DEMO_TMP/source.conllu";
	}
	die "create-project.pl: cannot read $DEMO_FILE\n" unless -r $DEMO_FILE;
}

# ── the project folder ───────────────────────────────────────────────────────
make_path( "$dir/Resources", "$dir/Pages", "$dir/xmlfiles", "$dir/tmp" );
copy( "$tt/projects/default-shared/index.php", "$dir/index.php" ) or die "cannot copy index.php from $tt/projects/default-shared: $!\n";

my $base = read_file("$shared/Resources/defaultsettings.xml") || read_file("$tt/projects/default-shared/Resources/defaultsettings.xml");
die "create-project.pl: no defaultsettings.xml in the shared project or TEITOK\n" unless $base;
my $corp = 'TT-' . uc($name); $corp =~ s/[^A-Za-z0-9_-]//g;
my $set = $base;
$set =~ s{(<base\b[^>]*\bfoldername=")[^"]*}{$1$name};
$set =~ s{(<title\b[^>]*\bdisplay=")[^"]*}{$1 . xe($title)}e;
$set =~ s{(<cqp\b[^>]*\bcorpus=")[^"]*}{$1$corp};

my $ud_cqp = <<'X';
	<!-- UD annotation (demo corpus): searchable token attributes, sentences as stand-off <s sameAs> -->
	<cqp corpus="__CORP__" searchfolder="xmlfiles">
		<pattributes>
			<item key="form" display="Form"/>
			<item key="lemma" display="Lemma"/>
			<item key="upos" display="Universal POS"/>
			<item key="xpos" display="Treebank POS"/>
			<item key="feats" display="Morphological features" type="udfeats"/>
			<item key="deprel" display="Dependency relation"/>
			<item key="head" display="Dependency head" type="ref" nosearch="1"/>
		</pattributes>
		<sattributes>
			<item key="text" level="text" display="Document Search">
				<item key="id" display="Document"/>
			</item>
			<item key="s" level="s" display="Sentence" toklist="sameAs">
				<item key="id"/>
			</item>
		</sattributes>
	</cqp>
X
my $ud_xmlfile = <<'X';
	<xmlfile defaultform="form" defaultview="interpret" xpath="//text" paged="0">
		<pattributes>
			<forms>
				<item key="form" display="Written form" color="#990000" admin="1" noshow="1"/>
			</forms>
			<tags>
				<item key="lemma" display="Lemma"/>
				<item key="upos" display="UD POS tag"/>
				<item key="xpos" display="Treebank POS"/>
				<item key="feats" display="Morphosyntax" type="udfeats"/>
				<item key="deprel" display="Dependency relation"/>
				<item key="head" display="Dependency head" type="ref"/>
			</tags>
		</pattributes>
	</xmlfile>
X
if ( $o{demo} ) {
	( my $c = $ud_cqp ) =~ s/__CORP__/$corp/;
	$set =~ s{\s*<!--[^>]*CQP corpus[^>]*-->\s*<cqp\b.*?</cqp>}{\n$c}s or $set =~ s{<cqp\b.*?</cqp>}{$c}s;
	$set =~ s{<xmlfile\b.*?</xmlfile>}{$ud_xmlfile}s;
	# a search menu entry for flexicorp, Pando as its default engine
	$set =~ s{(<item key="cqp"[^>]*/>)}{$1\n\t\t\t<item key="flexicorp" display="Search (flexicorp)"/>};
	$set =~ s{</ttsettings>}{\t<flexicorp backend="pando"/>\n</ttsettings>} unless $set =~ /<flexicorp\b/;
}
write_file( "$dir/Resources/settings.xml", $set );

# ── demo corpus: CoNLL-U -> TEITOK XML ──────────────────────────────────────
my ( $ndocs, $nsent, $ntok ) = ( 0, 0, 0 );
my $source = '';
if ( $o{demo} ) {
	my $file = $DEMO_FILE;
	open my $in, '<:utf8', $file or die "cannot read $file: $!\n";
	$source = $o{conllu};
	my ( @docs, $doc, @sent, %meta );
	my $flush_sent = sub {
		return unless @sent;
		$doc //= { id => 'doc1', sents => [] };
		push @{ $doc->{sents} }, { meta => {%meta}, toks => [@sent] };
		@sent = (); %meta = ();
	};
	my $flush_doc = sub { $flush_sent->(); push @docs, $doc if $doc && @{ $doc->{sents} }; $doc = undef; };
	my $count = 0;
	while ( my $l = <$in> ) {
		chomp $l;
		if ( $l =~ /^#\s*newdoc(?:\s+id\s*=\s*(.*))?/ ) {
			$flush_doc->();
			last if $count >= $o{sentences};
			$doc = { id => ( $1 // 'doc' . ( @docs + 1 ) ), sents => [] };
			next;
		}
		if ( $l =~ /^#\s*(sent_id|text)\s*=\s*(.*)/ ) { $meta{$1} = $2; next; }
		next if $l =~ /^#/;
		if ( $l eq '' ) { if (@sent) { $flush_sent->(); $count++; } next; }
		my @f = split /\t/, $l;
		next if @f < 10 || $f[0] !~ /^\d+$/;    # multiword ranges (1-2) and empty nodes (1.1): syntactic words only
		push @sent, \@f;
	}
	$flush_doc->();
	close $in;
	die "create-project.pl: no sentences read from $source\n" unless @docs;

	for my $d (@docs) {
		my $id = $d->{id}; $id =~ s/[^A-Za-z0-9_.-]+/_/g;
		my $x = qq(<?xml version="1.0" encoding="UTF-8"?>\n<TEI>\n<teiHeader><fileDesc><titleStmt><title>) . xe( $d->{id} ) .
			qq(</title></titleStmt><sourceDesc><p>From ) . xe($source) . qq( (Universal Dependencies; see the treebank's licence). Converted by create-project.pl.</p></sourceDesc></fileDesc></teiHeader>\n<text id=") . xe($id) . qq(">\n);
		my $w = 0; my $s = 0;
		for my $snt ( @{ $d->{sents} } ) {
			$s++;
			my $off = $w;                         # sentence word n -> w-(off+n)
			my @ids = map { 'w-' . ( $off + $_->[0] ) } @{ $snt->{toks} };
			my $sid = $snt->{meta}{sent_id} // "s-$s";
			$x .= '<s id="s-' . $s . '" sameAs="' . join( ' ', map { "#$_" } @ids ) . '" n="' . xe($sid) . '"/>';
			for my $t ( @{ $snt->{toks} } ) {
				my ( $n, $form, $lemma, $upos, $xpos, $feats, $head, $deprel, undef, $misc ) = @$t;
				my $a = 'id="w-' . ( $off + $n ) . '" ord="' . $n . '"';
				$a .= ' lemma="' . xe($lemma) . '"' if $lemma ne '_';
				$a .= ' upos="' . xe($upos) . '"'   if $upos ne '_';
				$a .= ' xpos="' . xe($xpos) . '"'   if $xpos ne '_';
				$a .= ' feats="' . xe($feats) . '"' if $feats ne '_';
				$a .= ' head="w-' . ( $off + $head ) . '" ohead="' . $head . '"' if $head =~ /^\d+$/ && $head > 0;
				$a .= ' deprel="' . xe($deprel) . '"' if $deprel ne '_';
				$x .= "<tok $a>" . xe($form) . '</tok>';
				$x .= ' ' unless $misc =~ /(^|\|)SpaceAfter=No(\||$)/;
				$ntok++;
			}
			$x .= "\n";
			$w += @{ $snt->{toks} };
			$nsent++;
		}
		$x .= "</text>\n</TEI>\n";
		write_file( "$dir/xmlfiles/$id.xml", $x );
		$ndocs++;
	}
	print "Demo corpus: $ndocs documents, $nsent sentences, $ntok tokens from $source\n";
}

# ── home page, corpus list, ownership ───────────────────────────────────────
my $home = "<h1>" . xe($title) . "</h1>\n\n";
$home .= $o{demo}
	? "<p>A demo corpus: $nsent sentences in $ndocs documents from a <a href='https://universaldependencies.org'>Universal Dependencies</a> treebank (" . xe($source) . "; see its licence), with lemmas, parts of speech, morphological features and dependency relations.</p>\n<p>Try for instance, in Search (flexicorp) with Pando: <code>[upos=\"VERB\"] &gt; [deprel=\"nsubj\"]</code> (verbs with their subject).</p>\n"
	: "<p>New TEITOK project, created by the installer. Add your XML files, then index the corpus.</p>\n";
write_file( "$dir/Pages/home.html", $home );
my $cl = "$shared/Resources/corplist.xml";
my $cls = read_file($cl) || "<corplist>\n</corplist>\n";
if ( $cls !~ /\bid="\Q$dir\E"/ ) {
	my $e = "\t<corpus id=\"$dir\"><name>" . xe($name) . "</name><url>../" . xe($name) . "/index.php</url><fullname>" . xe($title) . "</fullname></corpus>\n";
	$cls =~ s{</corplist>}{$e</corplist>} or $cls .= $e;
	write_file( $cl, $cls );
	system( 'chown', $webu, $cl );
}
system( 'chown', '-R', $webu, $dir );
print "Created project $name: $dir\n";

# ── index the demo corpus ───────────────────────────────────────────────────
if ( $o{demo} && !$o{'no-index'} ) {
	my $venv = "$shared/Resources/venv/bin/python";
	if ( -x $venv && cap( as_u( $webu, q_($venv) . ' -c "import flexicorp"' ) . ' && echo ok' ) eq 'ok' ) {
		my $backs = cap('command -v cwb-encode') ? 'pando,cqp' : 'pando';
		print "Indexing for $backs (flexicorp, as $webu) ...\n";
		my $cmd = 'cd ' . q_($dir) . ' && PATH=/usr/local/bin:/usr/bin:/bin ' . q_($venv) . ' -m flexicorp reindex --api --backend pando --folder ' . q_($dir) .
			" --teitok yes --staging --reindex-backends $backs --options reindex_job_id=install-" . strftime( '%Y%m%d%H%M%S', localtime );
		my $out = cap( as_u( $webu, $cmd ) . ' 2>&1' );
		if ( -f "$dir/pando/corpus.info" ) {
			my $n = cap( "pando " . q_("$dir/pando") . " '[upos=\"VERB\"] > [deprel=\"nsubj\"]' --json --total --limit 1" );
			my ($tot) = $n =~ /"total"\s*:\s*(\d+)/;
			print "Indexed: Pando index in $dir/pando" . ( defined $tot ? " ($tot verbs with a subject)" : '' ) . "\n";
			fqs_register();
		} else {
			my @l = grep { /error|fail/i } split /\n/, $out;
			print "!!!! indexing failed" . ( @l ? ": $l[-1]" : '' ) . " - index it later from TEITOK (flexicorp page)\n";
		}
	} else {
		print "flexicorp is not installed in the venv: index the corpus later from TEITOK\n";
	}
}
my $url = 'http://127.0.0.1' . ( $root =~ m{/teitok$} ? '/teitok' : '' ) . "/$name/index.php";
print "Open $url (log in with the shared admin)\n";
exit 0;

# ── the site project (public start page) ────────────────────────────────────
sub site_project {
	make_path( "$dir/Resources", "$dir/Pages" );
	copy( "$tt/projects/default-shared/index.php", "$dir/index.php" ) or die "cannot copy index.php from $tt/projects/default-shared: $!\n";
	my $t = xe($title);
	write_file( "$dir/Resources/settings.xml", <<"X" );
<?xml version="1.0"?>
<ttsettings>
	<!-- The public start page of this TEITOK server (written by create-project.pl, option site).
	     Not a corpus: corpora are projects of their own, server-wide settings and admin
	     tools are in the shared project, which visitors are not sent to. -->
	<menu>
		<itemlist>
			<item key="fqs" display="Corpora"/>
			<item key="about" display="About"/>
		</itemlist>
	</menu>
	<defaults home="fqs">
		<title display="$t"/>
		<base foldername="$name"/>
	</defaults>
</ttsettings>
X
	write_file( "$dir/Pages/about.html", "<h1>About</h1>\n\n<p>These corpora are hosted by <i>(your institution)</i>.</p>\n<p><i>(Edit this page: $dir/Pages/about.html, or log in and use the page editor.)</i></p>\n" );
	system( 'chown', '-R', $webu, $dir );
	print "Created the site project: $dir (start page: the corpus list; About: Pages/about.html)\n";
	my $fw = "$root/index.php";
	my $want = "<?php\n\t// Visitors of the TEITOK root land in the site project (written by create-project.pl --site);\n\t// the shared project is for server-wide settings and administration only.\n\tchdir(__DIR__ . \"/$name\");\n\tinclude(__DIR__ . \"/$name/index.php\");\n?>\n";
	if ( !-e $fw ) {
		write_file( $fw, $want );
		system( 'chown', $webu, $fw );
		print "Wrote $fw: the TEITOK root now opens the site project\n";
	} elsif ( read_file($fw) ne $want ) {
		print "Left $fw as it is (it exists already); to open the site project from the TEITOK root, make it:\n$want";
	}
	print "Open http://127.0.0.1" . ( $root =~ m{/teitok$} ? '/teitok/' : "/$name/" ) . "\n";
	exit 0;
}

# ── register an indexed project in FQS (what fqs.php's "Register this corpus" does) ──
sub fqs_register {
	my $fqs = cap('command -v fqs') || ( -x '/usr/local/bin/fqs' ? '/usr/local/bin/fqs' : '' );
	return print "(FQS not installed: the corpus is not registered in the FQS catalogue)\n" unless $fqs;
	( my $id = $name ) =~ s/[^A-Za-z0-9_-]+/_/g;
	my @b = grep { -d "$dir/$_" } qw(pando cqp);
	my $j = sub { my $v = shift; $v =~ s/\\/\\\\/g; $v =~ s/"/\\"/g; return "\"$v\""; };
	my $url = ( $root =~ m{/teitok$} ? '/teitok' : '' ) . "/$name/index.php";
	my $payload = '{' . join( ',',
		'"id":' . $j->($id), '"label":' . $j->($title), '"project_root":' . $j->($dir), '"project_url":' . $j->($url),
		'"preferred_backend":"auto"', '"environment":"live"', '"visibility":"published"', '"listing_visibility":"public"',
		'"source_kind":"teitok"', '"supports_xml":' . ( -f "$dir/xidx/xidx.rng" || -d "$dir/cqp" ? 'true' : 'false' ),
		'"interface_preference":"teitok"', '"http_policy_mode":"public_query"', '"http_allowed_operations":["query","catalog"]',
		'"interfaces":["query"]', '"labels":["demo"' . ( $LANG ? ',"lang:' . $LANG . '"' : '' ) . ']', '"is_current":true',
		( ( read_file("$dir/pando/corpus.info") =~ /^size=(\d+)/m ) ? ( '"corpus_size":' . $1 ) : () ),
		'"settings":{"teitok_project_root":' . $j->($dir) . ( @b == 1 ? ',"query_backend":' . $j->( $b[0] ) : '' ) .
			',"available_backends":[' . join( ',', map { $j->($_) } @b ) . ']' . ( $LANG ? ',"languages":[' . $j->($LANG) . ']' : '' ) . '}' ) . '}';
	my $tmp = "$DEMO_TMP/fqs-register.json";
	write_file( $tmp, $payload );
	chmod 0644, $tmp; chmod 0755, $DEMO_TMP;
	my $out = cap( as_u( $webu, q_($fqs) . ' corpora upsert-json --json-file ' . q_($tmp) ) . ' 2>&1' );
	print( $? == 0 ? "Registered in FQS as \"$id\" (listed on the corpus page)\n" : "!!!! registering in FQS failed: " . ( ( split /\n/, $out )[-1] // '' ) . "\n" );
}

