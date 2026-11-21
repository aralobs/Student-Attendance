const assert = require('node:assert/strict');
const { pageWindow, visibleRows } = require('../assets/js/list_view.js');

assert.deepEqual(pageWindow(0, 99), {page:1, pages:1, start:0, end:0, total:0});
assert.deepEqual(pageWindow(31, 2), {page:2, pages:3, start:15, end:30, total:31});
assert.deepEqual(pageWindow(31, 999), {page:3, pages:3, start:30, end:31, total:31});
assert.equal(pageWindow(31, 'invalid').page, 1);
assert.equal(pageWindow(31, -1).page, 1);
// Group headings do not count as records and repeat at a page boundary.
const rows = [{group:true}, ...Array.from({length:16},()=>({})), {group:true}, {}];
assert.deepEqual([...visibleRows(rows, 2)].sort((a,b)=>a-b), [0, 16, 17, 18]);
assert.deepEqual([...visibleRows(rows, 1)].sort((a,b)=>a-b), Array.from({length:16},(_,i)=>i));
assert.deepEqual([...visibleRows([{text:'Ana'}, {text:'Ben'}, {text:'ANA Cruz'}], 1, 'ana')], [0, 2]);
// Empty placeholders are neither records nor pagination candidates.
assert.equal(visibleRows([{empty:true}], 1).size, 1);
console.log('List pagination checks passed.');
