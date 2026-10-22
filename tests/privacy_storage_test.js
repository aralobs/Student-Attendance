const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/privacy_storage.js'), 'utf8');
const data = new Map([
    ['sf2_remarks_10_10_2026', 'must-not-be-read'], ['sf2_remarks_20_9_2025', 'must-not-be-read'],
    ['theme', 'dark'], ['sf2_remarks_preference', 'keep'], ['other_feature', 'keep']
]);
const removed = [];
vm.runInNewContext(script, {localStorage:{
    get length(){return data.size;}, key(index){return [...data.keys()][index];},
    getItem(){throw Error('Legacy remarks must not be read or imported');},
    removeItem(key){removed.push(key); data.delete(key);}
}});
assert.deepEqual(removed.sort(), ['sf2_remarks_10_10_2026', 'sf2_remarks_20_9_2025']);
assert.equal(data.get('theme'), 'dark');
assert.equal(data.get('sf2_remarks_preference'), 'keep');
assert.equal(data.get('other_feature'), 'keep');
vm.runInNewContext(script, {get localStorage(){throw Error('Disabled storage');}});
assert(!fs.readFileSync(require('node:path').join(__dirname, '../assets/js/sf2_remarks.js'), 'utf8').includes('localStorage'));
console.log('Legacy browser remark cleanup passed (only SF2 keys removed, no old values read).');
