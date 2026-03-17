import { createRequire } from 'module';
import { pathToFileURL } from 'url';

const require = createRequire(import.meta.url);

function readStdin() {
  return new Promise((resolve, reject) => {
    const chunks = [];
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => chunks.push(chunk));
    process.stdin.on('end', () => resolve(chunks.join('')));
    process.stdin.on('error', reject);
  });
}

function normalizeAst(ast) {
  const astType = ast && typeof ast === 'object' ? String(ast.type || '') : '';
  if (astType === 'query_sequence' && Array.isArray(ast.queries) && ast.queries.length === 1) {
    return {
      rootAst: ast,
      effectiveAst: ast.queries[0],
      root_ast_type: astType,
      ast_type: String((ast.queries[0] && ast.queries[0].type) || ''),
      statement_count: 1,
      wrapped_sequence: true,
    };
  }
  return {
    rootAst: ast,
    effectiveAst: ast,
    root_ast_type: astType || null,
    ast_type: astType || null,
    statement_count: astType === 'query_sequence' && Array.isArray(ast.queries) ? ast.queries.length : 1,
    wrapped_sequence: false,
  };
}

function classifyAst(ast) {
  const normalized = normalizeAst(ast);
  const astType = normalized.ast_type || '';
  const statementCount = normalized.statement_count;
  const statefulTypes = new Set([
    'query_sequence',
    'named_query',
    'cat_query',
    'raw_query',
    'size_query',
    'show_named_query',
    'group_query',
    'freq_query',
    'sort_query',
    'tabulate_query',
    'coll_query',
    'dcoll_query',
    'count_query',
    'global_filter_query',
  ]);
  return {
    root_ast_type: normalized.root_ast_type,
    ast_type: astType || null,
    statement_count: statementCount,
    wrapped_sequence: normalized.wrapped_sequence,
    requires_state: statementCount > 1 || statefulTypes.has(astType),
    effectiveAst: normalized.effectiveAst,
  };
}

function stripTrailingSettings(sql) {
  if (!sql || typeof sql !== 'string') return sql;
  return sql.replace(/\nSETTINGS[\s\S]*$/i, '').trim();
}

function translatePlainQuery(translator, queryAst, limit, offset) {
  const expr = queryAst && queryAst.expr ? queryAst.expr : null;
  if (!expr) {
    throw new Error('ClickCQL query AST is missing expr');
  }
  const filters = typeof translator.normalizeFilters === 'function'
    ? translator.normalizeFilters(queryAst.filters || [])
    : (queryAst.filters || []);
  let sql = translator.translateExpr(expr, filters, limit, offset);
  if (queryAst.within) {
    const withinScope = queryAst.within.scope;
    if (withinScope === 's' || withinScope === 'sentence') {
      sql = sql.replace(/FROM toks t1/, 'FROM toks t1\nJOIN sentences s ON t1.sentence_id = s.sentence_id');
    } else if (withinScope === 'text' || withinScope === 'doc' || withinScope === 'document') {
      sql = sql.replace(/FROM toks t1/, 'FROM toks t1\nJOIN docs d ON t1.doc_id = d.doc_id');
    } else {
      sql = sql.replace(/FROM toks t1/, `FROM toks t1\nJOIN regions r ON t1.doc_id = r.doc_id AND t1.doc_pos BETWEEN r.start_pos AND r.end_pos AND r.region_type = '${withinScope}'`);
    }
  }
  return sql;
}

async function main() {
  const raw = await readStdin();
  const req = raw ? JSON.parse(raw) : {};
  const query = typeof req.query === 'string' ? req.query : '';
  const assetsDir = String(req.assets_dir || '');
  const parserPath = `${assetsDir}/Scripts/cql-parser-umd.js`;
  const translatorUrl = pathToFileURL(`${assetsDir}/Scripts/cql2sql-peg-optimized.js`).href;

  globalThis.window = globalThis;
  globalThis.debug = !!req.debug;
  window.debug = !!req.debug;

  const parser = require(parserPath);
  window.parser = parser;
  window.PEG = parser;
  if (req.session_id) {
    window.cqlSessionId = String(req.session_id);
  }

  const translatorModule = await import(translatorUrl);
  const { OptimizedSQLTranslator, cqlToCountQuery } = translatorModule;

  const translator = new OptimizedSQLTranslator();
  window.cqlTranslator = translator;

  if (req.corpus_config && typeof req.corpus_config === 'object') {
    translator.corpusConfig = req.corpus_config;
  }

  const ast = parser.parse(query);
  const meta = classifyAst(ast);
  const payload = {
    ok: true,
    root_ast_type: meta.root_ast_type,
    ast_type: meta.ast_type,
    statement_count: meta.statement_count,
    wrapped_sequence: meta.wrapped_sequence,
    requires_state: meta.requires_state,
  };

  if (req.action === 'parse') {
    process.stdout.write(JSON.stringify(payload));
    return;
  }

  if (req.action === 'translate') {
    const limit = req.limit === null || req.limit === undefined ? null : Number(req.limit);
    const offset = req.offset === null || req.offset === undefined ? null : Number(req.offset);
    if (payload.ast_type === 'query') {
      payload.sql = translatePlainQuery(translator, meta.effectiveAst, limit, offset);
    } else {
      payload.sql = translator.translate(meta.effectiveAst, limit, offset, query);
    }
    if (!payload.requires_state && payload.ast_type === 'query') {
      payload.count_sql = `SELECT count() FROM (${stripTrailingSettings(translatePlainQuery(translator, meta.effectiveAst, null, null))}) AS _q`;
    }
    process.stdout.write(JSON.stringify(payload));
    return;
  }

  throw new Error(`Unsupported bridge action: ${String(req.action || '')}`);
}

main().catch((error) => {
  const errorType = error && error.name === 'SyntaxError' ? 'syntax' : 'runtime';
  const location = error && error.location && typeof error.location === 'object' ? error.location : null;
  const start = location && location.start && typeof location.start === 'object' ? location.start : null;
  const end = location && location.end && typeof location.end === 'object' ? location.end : null;
  process.stdout.write(
    JSON.stringify({
      ok: false,
      error_type: errorType,
      error: error && error.message ? String(error.message) : String(error),
      error_location: location,
      error_line: start && Number.isFinite(start.line) ? Number(start.line) : null,
      error_column: start && Number.isFinite(start.column) ? Number(start.column) : null,
      error_offset: start && Number.isFinite(start.offset) ? Number(start.offset) : null,
      error_end_line: end && Number.isFinite(end.line) ? Number(end.line) : null,
      error_end_column: end && Number.isFinite(end.column) ? Number(end.column) : null,
      error_end_offset: end && Number.isFinite(end.offset) ? Number(end.offset) : null,
    })
  );
  process.exit(1);
});
