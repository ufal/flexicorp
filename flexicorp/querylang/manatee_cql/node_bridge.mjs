import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const payloadText = fs.readFileSync(0, 'utf8');
const payload = payloadText ? JSON.parse(payloadText) : {};
const parserModule = await import(pathToFileURL(path.join(__dirname, 'parser.mjs')).href);

const query = String(payload.query || '');
const startRule = String(payload.start_rule || 'Query');

try {
    const ast = parserModule.parse(query, { startRule });
    process.stdout.write(JSON.stringify({
        ok: true,
        start_rule: startRule,
        ast,
    }));
} catch (err) {
    const location = err && err.location ? err.location : {};
    const start = location.start || {};
    const end = location.end || {};
    process.stdout.write(JSON.stringify({
        ok: false,
        error: String(err && err.message ? err.message : err || 'Manatee CQL PEG bridge failed.'),
        error_type: 'syntax',
        error_line: start.line ?? null,
        error_column: start.column ?? null,
        error_offset: start.offset ?? null,
        error_end_line: end.line ?? null,
        error_end_column: end.column ?? null,
        error_end_offset: end.offset ?? null,
    }));
    process.exit(1);
}
