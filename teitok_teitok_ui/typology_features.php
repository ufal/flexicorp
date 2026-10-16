<?php
/**
 * typology.php's features: WALS-style properties of a language that can be counted from dependency
 * (UD) annotation, each as pando queries over the whole corpus, counted per language. Three kinds:
 *
 *   order      competing orders (named queries) → each order's share; the dominant one by Dryer's
 *              criterion (as WALS: at least twice the next), else "no dominant order"
 *   share      two or more alternatives (named queries) → their shares (e.g. subjects expressed or not)
 *   inventory  a feature's values on a part of speech (feats/Case on nouns) → the values in use (at
 *              least a small share of the tokens) and how many; also the share left unmarked
 *   bundles    the feature bundles (feats) on a part of speech → how many distinct bundles, and how
 *              many features a token carries on average (morphological richness)
 *
 * Queries name the language attribute {L}; dependency relations as in UD v2. Each feature says what
 * annotation it relies on: a value of 0 can mean "not annotated" (shown as such where the share left
 * unmarked is complete). See dev/lenses-plan.md.
 */

if ( ! function_exists( 'tt_typology_features' ) ) {
	/** id → feature (wals, title, group, kind, variants: [[name, query], …] | pos + feat, note). */
	function tt_typology_features() {
		$N = 'upos="NOUN|PROPN"';
		$S = 's:[deprel="nsubj" & ' . $N . '] < v:[upos="VERB"] > o:[deprel="obj" & ' . $N . ']';
		$fin = 'feats/VerbForm="Fin" & (deprel="root" | deprel="aux|cop" & parent [deprel="root"])';
		$pre = 'before [parent [deprel="root"] & deprel!="punct|cc|mark|discourse|aux|cop|dep|reparandum"]';
		$pair = function ( $head, $dep, $a, $b ) {   // [a = dependent first, b = head first]
			$base = "h:[$head] > d:[$dep]";
			return array( array( $a, "$base :: d < h" ), array( $b, "$base :: h < d" ) );
		};
		$f = array();
		$f['sov'] = array( 'wals' => '81A', 'title' => 'Order of subject, object and verb', 'group' => 'order', 'kind' => 'order',
			'variants' => array(
				array( 'SVO', "$S :: s < v & v < o" ), array( 'SOV', "$S :: s < o & o < v" ), array( 'VSO', "$S :: v < s & s < o" ),
				array( 'VOS', "$S :: v < o & o < s" ), array( 'OVS', "$S :: o < v & v < s" ), array( 'OSV', "$S :: o < s & s < v" ) ),
			'note' => 'Clauses with a nominal subject and a nominal object of the same verb.' );
		$f['sv'] = array( 'wals' => '82A', 'title' => 'Order of subject and verb', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="VERB"', 'deprel="nsubj" & ' . $N, 'SV', 'VS' ), 'note' => 'Nominal subjects.' );
		$f['ov'] = array( 'wals' => '83A', 'title' => 'Order of object and verb', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="VERB"', 'deprel="obj" & ' . $N, 'OV', 'VO' ), 'note' => 'Nominal objects.' );
		$f['adp'] = array( 'wals' => '85A', 'title' => 'Order of adposition and noun phrase', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( $N, 'deprel="case" & upos="ADP"', 'Prepositions', 'Postpositions' ),
			'note' => 'UD attaches adpositions to their noun (case).' );
		$f['gen'] = array( 'wals' => '86A', 'title' => 'Order of genitive and noun', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="NOUN"', $N . ' & (deprel="nmod:poss" | deprel="nmod" & feats/Case="Gen")', 'GenN', 'NGen' ),
			'note' => 'Possessive nominal modifiers (nmod:poss) and genitive nominal modifiers.' );
		$f['adj'] = array( 'wals' => '87A', 'title' => 'Order of adjective and noun', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="NOUN"', 'deprel="amod" & upos="ADJ"', 'AdjN', 'NAdj' ) );
		$f['dem'] = array( 'wals' => '88A', 'title' => 'Order of demonstrative and noun', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="NOUN"', 'deprel="det" & feats/PronType="Dem"', 'DemN', 'NDem' ),
			'note' => 'Needs PronType=Dem on demonstratives.' );
		$f['num'] = array( 'wals' => '89A', 'title' => 'Order of numeral and noun', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="NOUN"', 'deprel="nummod" & upos="NUM"', 'NumN', 'NNum' ) );
		$f['rel'] = array( 'wals' => '90A', 'title' => 'Order of relative clause and noun', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="NOUN"', 'deprel="acl:relcl|acl" & upos="VERB"', 'RelN', 'NRel' ),
			'note' => 'Clausal modifiers of nouns (acl, acl:relcl).' );
		$f['neg'] = array( 'wals' => '143A', 'title' => 'Order of negative morpheme and verb', 'group' => 'order', 'kind' => 'order',
			'variants' => $pair( 'upos="VERB"', 'feats/Polarity="Neg" & upos="PART|ADV"', 'NegV', 'VNeg' ),
			'note' => 'Negative particles and adverbs (Polarity=Neg) of a verb; negative affixes are not counted.' );
		$f['v2'] = array( 'wals' => '', 'title' => 'Position of the finite verb in main clauses (V2)', 'group' => 'order', 'kind' => 'share',
			'variants' => array(
				array( 'V1', "[$fin & count($pre) = 0]" ), array( 'V2', "[$fin & count($pre) = 1]" ), array( 'V3+', "[$fin & count($pre) >= 2]" ) ),
			'note' => 'Constituents (dependents of the main verb) before the finite verb or auxiliary. Needs VerbForm=Fin.' );
		$f['prodrop'] = array( 'wals' => '101A', 'title' => 'Expression of pronominal subjects', 'group' => 'clause', 'kind' => 'share',
			'variants' => array(
				array( 'subject expressed', '[upos="VERB" & deprel="root" & feats/VerbForm="Fin" & child [deprel="nsubj.*|expl.*"]]' ),
				array( 'no subject', '[upos="VERB" & deprel="root" & feats/VerbForm="Fin" & not child [deprel="nsubj.*|expl.*"]]' ) ),
			'note' => 'Finite main verbs with and without a subject. Needs VerbForm=Fin.' );
		$f['case'] = array( 'wals' => '49A', 'title' => 'Number of cases', 'group' => 'morphology', 'kind' => 'inventory',
			'pos' => 'NOUN', 'feat' => 'Case', 'note' => 'Case values on nouns (pronouns not counted).' );
		$f['gender'] = array( 'wals' => '30A', 'title' => 'Number of genders', 'group' => 'morphology', 'kind' => 'inventory',
			'pos' => 'NOUN', 'feat' => 'Gender', 'note' => 'Gender values on nouns; noun classes of Bantu languages are NounClass.' );
		$f['number'] = array( 'wals' => '33A', 'title' => 'Nominal number', 'group' => 'morphology', 'kind' => 'inventory',
			'pos' => 'NOUN', 'feat' => 'Number', 'note' => 'Number values on nouns (Sing, Plur, Dual, …).' );
		$f['tense'] = array( 'wals' => '66A', 'title' => 'Tense on verbs', 'group' => 'morphology', 'kind' => 'inventory',
			'pos' => 'VERB|AUX', 'feat' => 'Tense', 'note' => 'Tense values on verbs and auxiliaries (Past, Pres, Fut, …).' );
		$f['aspect'] = array( 'wals' => '65A', 'title' => 'Aspect on verbs', 'group' => 'morphology', 'kind' => 'inventory',
			'pos' => 'VERB|AUX', 'feat' => 'Aspect', 'note' => 'Aspect values (Perf, Imp, Prog, …).' );
		$f['vmorph'] = array( 'wals' => '22A', 'title' => 'Inflectional richness of the verb', 'group' => 'morphology', 'kind' => 'bundles',
			'pos' => 'VERB', 'note' => 'Distinct feature bundles on verbs and the features per verb token (morphological richness).' );
		$f['nmorph'] = array( 'wals' => '', 'title' => 'Inflectional richness of the noun', 'group' => 'morphology', 'kind' => 'bundles',
			'pos' => 'NOUN', 'note' => 'Distinct feature bundles on nouns and the features per noun token.' );
		return $f;
	}

	/** The pando program that counts a feature per language ($lang: the language attribute). */
	function tt_typology_program( array $feat, $lang ) {
		$set = 'set group_limit 0; set child_limit 0; ';
		switch ( $feat['kind'] ) {
			case 'order':
			case 'share':
				$names = array();
				$p = $set;
				foreach ( $feat['variants'] as $i => $v ) {
					$p .= "q$i = {$v[1]}; ";
					$names[] = "q$i";
				}
				return $p . 'freq ' . implode( ', ', $names ) . " by $lang;";
			case 'inventory':
				return $set . "[upos=\"{$feat['pos']}\"]; freq by feats/{$feat['feat']}, $lang";
			case 'bundles':
				return $set . "[upos=\"{$feat['pos']}\"]; freq by feats, $lang";
		}
		return '';
	}

	/** A variant's query for one language (for links to its hits): `within` before the `::` filter. */
	function tt_typology_variant_query( $query, $lang, $value ) {
		$within = ' within ' . $lang . '="' . addcslashes( $value, '"\\' ) . '"';
		$i = strpos( $query, ' :: ' );
		return $i === false ? $query . $within : substr( $query, 0, $i ) . $within . substr( $query, $i );
	}
}
