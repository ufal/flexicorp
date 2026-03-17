import { createRequire } from 'module';

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

function classifyAst(ast) {
  const astType = ast && typeof ast === 'object' ? String(ast.type || '') : '';
  const hasOutputFilters = !!(ast && typeof ast === 'object' && ast.outputFilters);
  return {
    ast_type: astType || null,
    has_output_filters: hasOutputFilters,
    result_type: hasOutputFilters ? 'table' : 'hits',
  };
}

async function main() {
  const raw = await readStdin();
  const req = raw ? JSON.parse(raw) : {};
  const query = typeof req.query === 'string' ? req.query : '';
  const assetsDir = String(req.assets_dir || '');
  const parserPath = `${assetsDir}/Scripts/pmltq-parser-umd.js`;
  const translatorPath = `${assetsDir}/Scripts/pmltq2sql-optimized.js`;

  globalThis.window = globalThis;
  globalThis.debug = !!req.debug;
  window.debug = !!req.debug;

  const parser = require(parserPath);
  window.pmltqParser = parser;
  const { pmltqToSqlOptimized, PMLTQSQLTranslator } = require(translatorPath);

  const ast = parser.parse(query);
  const meta = classifyAst(ast);
  const payload = {
    ok: true,
    ...meta,
  };

  if (req.action === 'parse') {
    process.stdout.write(JSON.stringify(payload));
    return;
  }

  if (req.action === 'translate') {
    const translator = new PMLTQSQLTranslator();
    if (req.corpus_config && typeof req.corpus_config === 'object') {
      translator.corpusConfig = req.corpus_config;
    }
    const result = pmltqToSqlOptimized(ast, [], translator.corpusConfig);
    payload.sql = result && typeof result === 'object' ? (result.sql || '') : String(result || '');
    payload.sql_statements = result && typeof result === 'object' && Array.isArray(result.sqlStatements)
      ? result.sqlStatements
      : [payload.sql];
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
