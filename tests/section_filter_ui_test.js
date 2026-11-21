const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const option = (value, grade) => ({ value, dataset: grade ? { grade } : {}, hidden: false, disabled: false });
function setup(initialGrade, selected, withGrade = true) {
    const grade = { value: initialGrade, addEventListener: (_, fn) => { grade.change = fn; } };
    const section = {
        value: selected, options: [option('', ''), option('11', 'Grade 3'), option('16', 'Grade 6')],
        form: { querySelector: () => withGrade ? grade : null },
        replaceChildren(...options) { this.options = options; }
    };
    vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/section_filters.js', 'utf8'), {
        document: { querySelectorAll: () => [section] }
    });
    return { grade, section };
}
let { grade, section } = setup('Grade 3', '16');
assert.deepEqual(section.options.map(o => o.value), ['', '11']);
assert.equal(section.value, '', 'Incompatible selection clears');
section.value = '11';
grade.change();
assert.equal(section.value, '11', 'Compatible selection stays');
grade.value = 'Grade 6'; grade.change();
assert.deepEqual(section.options.map(o => o.value), ['', '16']);
assert.equal(section.value, '');
grade.value = ''; grade.change();
assert.deepEqual(section.options.map(o => o.value), ['', '11', '16']);
grade.value = 'Kinder'; grade.change();
assert.deepEqual(section.options.map(o => o.value), ['']);
({ section } = setup('', '16', false));
assert.equal(section.value, '16');
assert.equal(section.options.length, 3, 'Section-only filters keep all sections');
console.log('Section filter UI passed (9 checks).');
