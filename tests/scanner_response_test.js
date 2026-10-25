const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../attendance/scanner.php'), 'utf8');
const start = source.indexOf('async function processToken(token)');
const end = source.indexOf('async function refreshLog()', start);
assert.ok(start >= 0 && end > start);

async function run(response) {
    const messages = [];
    let count = 0;
    let rendered = 0;
    const context = vm.createContext({
        URLSearchParams, scannerContext: {csrf_token: 'synthetic'}, scanCount: 0,
        fetch: async (_url, options) => {
            count++;
            assert.equal(options.method, 'POST');
            assert.equal(new URLSearchParams(options.body).get('csrf_token'), 'synthetic');
            if (response instanceof Error) throw response;
            return {ok: false, status: 500, redirected: false, headers: {get: () => null}, ...response};
        },
        showResult: (...args) => messages.push(args), beep: () => {},
        document: {getElementById: () => ({})},
        renderScanResult: () => rendered++, rememberLastScan: () => {}, refreshLog: () => {},
    });
    vm.runInContext(source.slice(start, end), context);
    await context.processToken('synthetic-token');
    assert.equal(count, 1, 'Scan request must never be retried automatically');
    return {message: messages.at(-1), rendered};
}

(async () => {
    for (const body of ['', '<html>PRIVATE SERVER DIAGNOSTIC</html>', 'null', '[]', '{}']) {
        const result = await run({text: async () => body});
        assert.equal(result.message[1], 'Scan unavailable');
        assert.match(result.message[2], /HTTP 500/);
        assert.doesNotMatch(result.message[2], /PRIVATE SERVER DIAGNOSTIC/);
        assert.equal(result.rendered, 0);
    }
    const reference = 'a'.repeat(24);
    const empty = await run({text: async () => '', headers: {get: () => reference}});
    assert.match(empty.message[2], new RegExp(reference));
    const expired = await run({redirected: true, status: 200, text: async () => '<html>Sign in</html>'});
    assert.match(expired.message[2], /session may have expired/);
    const limited = await run({status: 429, text: async () => JSON.stringify({success: false, message: 'Slow down'})});
    assert.equal(limited.message[0], 'warning');
    assert.equal(limited.message[2], 'Slow down');
    const rejected = await run({ok: true, status: 200, text: async () => JSON.stringify({success: false, message: 'Unknown token'})});
    assert.equal(rejected.message[2], 'Unknown token');
    const success = await run({ok: true, status: 200, text: async () => JSON.stringify({success: true, student_id: 1})});
    assert.equal(success.rendered, 1);
    const network = await run(new Error('Offline'));
    assert.match(network.message[1], /Network Error/);
    console.log('Scanner response checks passed (empty/HTML responses, session expiry, quota, rejection, success and network failure).');
})().catch(error => { console.error(error); process.exitCode = 1; });
