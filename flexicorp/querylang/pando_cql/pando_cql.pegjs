/*
 * Provisional PEG (PegJS / Peggy) grammar for Pando / flexicorp-pando CQL.
 *
 * Intended for syntax highlighting, validation experiments, and eventual
 * alignment with the C++ lexer+parser (dev/lexer.cpp, dev/parser.cpp).
 * This is NOT guaranteed to accept exactly the same strings as the C++
 * implementation yet — tighten rules as the language stabilises.
 *
 * Compile (example):
 *   npx peggy --format es pando_cql.pegjs -o pando_cql_parser.js
 */

{
  // PegJS passes `text`, `location`, etc. into action blocks when needed.
}

Start
  = _ stmts:StatementList? _ { return stmts ? { type: "Program", body: stmts } : { type: "Program", body: [] }; }

StatementList
  = head:Statement tail:(_ ";" _ stmt:Statement { return stmt; })* { return [head].concat(tail); }

Statement
  = CommandStatement
  / NamedAssign
  / TokenQuery

/* ---- Commands (count, group, show …) — tail captured loosely for provisional highlighting ---- */

CommandStatement
  = kw:CommandKeyword tail:CommandTail? { return { type: "command", kw, tail: tail || "" }; }

CommandKeyword
  = $(("count" / "group" / "sort" / "freq" / "coll" / "dcoll" / "cat" / "size" / "raw" / "show" / "tabulate" / "drop") !IdentCont)

CommandTail
  = chars:(!";" .)* { return chars.map(function (c) { return c[1]; }).join(""); }

NamedAssign
  = name:Identifier _ "=" _ tq:TokenQuery { return { type: "named", name, query: tq }; }

/* ---- Token query: chain + optional with + :: global filters ---- */

TokenQuery
  = a:TokenQueryCore
    w:(_ "with" _ b:TokenQueryCore { return b; })?
    g:(_ "::" _ gf:GlobalFilterChain { return gf; })?
    { return { type: "tokenQuery", core: a, withQuery: w || null, globalFilters: g || null }; }

TokenQueryCore
  = first:TokenExpr
    chain:(_ rel:ExplicitRelation? _ tok:TokenExpr { return { rel: rel || "seq", tok: tok }; })*
    within:(_ wc:WithinOrContaining { return wc; })*
    {
      return {
        type: "tokenQueryCore",
        tokens: [first].concat(chain.map(function (c) { return c.tok; })),
        relations: chain.map(function (c) { return c.rel; }),
        within: within
      };
    }

ExplicitRelation
  = ">>" { return ">>"; }
  / "<<" { return "<<"; }
  / "!>" { return "!>"; }
  / "!<" { return "!<"; }
  / ">" { return ">"; }
  / "<" { return "<"; }

TokenExpr
  = label:(i:Identifier _ ":" _ { return i; })?
    core:(RegionStart / RegionEnd / BracketToken / StringToken)
    rep:RepetitionSuffix?
    { return { type: "tokenExpr", label: label || null, core: core, rep: rep || null }; }

RegionStart
  = "<" !"/" inner:RegionStartInner ">" { return { type: "regionStart", inner: inner }; }

/* Everything until closing > (lexer: raw between angle brackets). */
RegionStartInner
  = chars:(!">" .)* { return chars.map(function (c) { return c[1]; }).join(""); }

RegionEnd
  = "</" name:Identifier ">" { return { type: "regionEnd", name: name }; }

BracketToken
  = "[" cond:OrCondition? "]" { return { type: "bracket", cond: cond || null }; }

StringToken
  = s:StringLiteral { return { type: "stringToken", value: s }; }

RepetitionSuffix
  = "+" { return "+"; }
  / "*" { return "*"; }
  / "?" { return "?"; }
  / "{" min:Number "," max:Number? "}" { return { min: min, max: max }; }
  / "{" n:Number "}" { return { min: n, max: n }; }

/* ---- Conditions inside [ ... ] ---- */

OrCondition
  = head:AndCondition tail:(_ "|" _ node:AndCondition { return node; })*
    { return tail.length ? { type: "or", head: head, tail: tail } : head; }

AndCondition
  = head:PrimaryCondition tail:(_ "&" _ node:PrimaryCondition { return node; })*
    { return tail.length ? { type: "and", head: head, tail: tail } : head; }

PrimaryCondition
  = "(" _ inner:OrCondition _ ")" { return inner; }
  / StructuralCondition
  / AttrCondition

StructuralCondition
  = neg:("not" __)?
    kw:$("child" / "parent" / "sibling" / "descendant" / "ancestor") !IdentCont
    lbl:(_ name:Identifier _ ":" _ { return name; })?
    _ "[" inner:OrCondition? "]"
    { return { type: "structural", neg: !!neg, kw: kw, label: lbl || null, inner: inner || null }; }

AttrCondition
  = path:AttrPath _ op:CompareOp _ val:Value flags:(_ "%" f:[cd] { return f; })*
    { return { type: "attr", path: path, op: op, value: val, flags: flags }; }

AttrPath
  = head:Identifier tail:(_ "." _ seg:Identifier { return seg; })*
    { return tail.length ? head + "." + tail.join(".") : head; }

CompareOp
  = "!=" / "<=" / ">=" / "=" / "<" / ">"

Value
  = RegexLiteral
  / StringLiteral
  / Number
  / Identifier

/* ---- within / containing ---- */

WithinOrContaining
  = "within" !IdentCont _ body:WithinBody
    { return { type: "within", body: body }; }
  / "not" _ "within" !IdentCont _ body:WithinBody
    { return { type: "notWithin", body: body }; }
  / "containing" !IdentCont _ body:ContainingBody
    { return { type: "containing", body: body }; }
  / "not" _ "containing" !IdentCont _ body:ContainingBody
    { return { type: "notContaining", body: body }; }

WithinBody
  = name:Identifier
    sh:(_ op:CompareOp _ v:Value { return { op: op, value: v }; })?
    hv:(_ "having" _ "[" h:OrCondition? "]" { return h; })?
    { return { region: name, shorthand: sh || null, having: hv || null }; }

ContainingBody
  = "subtree" !IdentCont _ "[" inner:OrCondition? "]"
    { return { subtree: true, inner: inner || null }; }
  / name:Identifier
    { return { region: name }; }

/* ---- Global filters after :: ---- */

GlobalFilterChain
  = head:GlobalFilter tail:(_ "&" _ g:GlobalFilter { return g; })*
    { return [head].concat(tail); }

GlobalFilter
  = "match" !IdentCont _ "." _ path:AttrPath _ op:CompareOp _ val:Value
    { return { type: "matchFilter", path: path, op: op, value: val }; }
  / n1:Identifier _ ("<" / ">") _ n2:Identifier
    { return { type: "positionOrder", left: n1, right: n2 }; }
  / AnchoredRegionFilter
  / AlignmentFilter

/* :: anchor.attr op "value" | :: anchor.attr op 123 */
AnchoredRegionFilter
  = anchor:Identifier _ "." _ path:AttrPath _ op:CompareOp _ val:(StringLiteral / Number)
    { return { type: "anchoredRegion", anchor: anchor, path: path, op: op, value: val }; }

/* :: a.attr1 op b.attr2 */
AlignmentFilter
  = n1:Identifier _ "." _ p1:AttrPath _ op:CompareOp _ n2:Identifier _ "." _ p2:AttrPath
    { return { type: "alignment", left: { name: n1, path: p1 }, op: op, right: { name: n2, path: p2 } }; }

/* ---- Lexical (mirror dev/lexer.cpp roughly) ---- */

Identifier
  = $(IdentStart IdentPart*)

IdentStart
  = [a-zA-Z_]

IdentPart
  = [a-zA-Z0-9_-]

IdentCont
  = [a-zA-Z0-9_-]

StringLiteral
  = '"' chars:CharDouble* '"' { return chars.join(""); }

CharDouble
  = "\\" ch:. { return ch; }
  / !["\\] ch:. { return ch; }

RegexLiteral
  = "/" chars:CharSlash* "/" { return "/" + chars.join("") + "/"; }

CharSlash
  = "\\" ch:. { return "\\" + ch; }
  / !["/"\\] ch:. { return ch; }

Number
  = $( "-"? [0-9]+ )

_ "whitespace"
  = [ \t\n\r]*

__ "mandatory_ws"
  = [ \t\n\r]+
