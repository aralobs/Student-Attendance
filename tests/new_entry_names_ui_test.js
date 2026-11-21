const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const listeners = {};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/new_entry_names.js', 'utf8'), {
    document: { addEventListener: (name, callback) => { listeners[name] = callback; } }
});
function field(value, kind = '', controls = {}) {
    return { value, dataset: { titleCase: kind }, matches: () => true,
        form: { elements: { namedItem: name => controls[name] || null } } };
}
for (const [input, expected] of [['juan dela cruz', 'Juan Dela Cruz'], ['  MARÍA peÑA  ', 'María Peña'],
    ["ana-mARIE o'NEILL", "Ana-Marie O'neill"], ['', ''], ['section 1a', 'Section 1A']]) {
    const target = field(input);
    listeners.focusout({ target });
    assert.equal(target.value, expected);
}
const existing = field('Existing SECTION', 'section', { id: { value: '10' } });
listeners.focusout({ target: existing });
assert.equal(existing.value, 'Existing SECTION');
const admin = field('Custom ADMIN', 'teacher', { role: { value: 'admin' } });
listeners.focusout({ target: admin });
assert.equal(admin.value, 'Custom ADMIN');
const teacher = field('NEW TEACHER', 'teacher', { role: { value: 'teacher' } });
const form = { querySelectorAll: () => [teacher] };
listeners.submit({ target: form });
assert.equal(teacher.value, 'New Teacher');
console.log('New entry name UI checks passed (8 checks).');
