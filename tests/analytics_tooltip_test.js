const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

// Execute the actual script emitted by the copied-page workflow, capturing Chart.js options.
const html = fs.readFileSync(path.join(__dirname, '../.qa/analytics-tooltip.html'), 'utf8');
const script = [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
    .map(match => match[1]).find(source => source.includes('var MONTHLY ='));
assert.ok(script, 'Run panel_workflow_test.php first to render Analytics');
assert.ok(!html.includes('get_today_log.php'), 'Analytics does not poll the duplicated live log');
assert.ok(!html.includes('todayLogBody'), 'Live log table remains on Dashboard');

function charts(source) {
    const captured = [];
    function Chart(context, config) { captured.push(config); }
    Chart.defaults = {font: {}};
    const canvases = new Map(['monthlyDoughnut', 'monthlyChart', 'monthlyGradeChart']
        .map(id => [id, {dataset: {}, getContext: () => ({id})}]));
    vm.runInNewContext(source, {
        Chart, document: {getElementById: id => canvases.get(id) || null},
        window: {}, console: {log() {}},
        setInterval() { throw new Error('Charts should initialize without polling when Chart.js is ready'); }
    });
    assert.equal(captured.length, 3, 'All existing monthly charts initialize');
    return captured.find(chart => chart.type === 'doughnut');
}
const doughnut = charts(script);
const label = doughnut.options.plugins.tooltip.callbacks.label;
const dataset = {data: [5, 1, 4]};
const absent = label({label: 'Absent', parsed: 4, dataset});
assert.equal(absent.length, 2, 'Only one reason line is appended');
assert.equal(absent[0], 'Absent: 4 (40.0%)');
assert.match(absent[1], /^Top reason: (Sickness|Family reasons|Transportation|Weather|Personal reasons|Other recorded reason|Not recorded)$/);
assert.equal(label({label: 'Full Day', parsed: 5, dataset}), 'Full Day: 5 (50.0%)');
assert.equal(label({label: 'Partial', parsed: 1, dataset}), 'Partial: 1 (10.0%)');
const missingReason = charts(script.replace(/"topAbsenceReason":(?:"[^"]*"|null)/, '"topAbsenceReason":null'));
const fallback = missingReason.options.plugins.tooltip.callbacks.label({label: 'Absent', parsed: 0, dataset: {data: [0, 0, 0]}});
assert.equal(fallback[1], 'Top reason: Not recorded');
assert.equal(fallback[0], 'Absent: 0 (0%)');
console.log('Analytics tooltip passed: actual rendered callbacks, one reason, percentage, no-reason fallback, and no live-log polling.');
