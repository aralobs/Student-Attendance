(function () {
    'use strict';
    const grade = document.getElementById('attendanceGrade');
    const section = document.getElementById('attendanceSection');
    const year = document.getElementById('attendanceYear');
    const data = document.getElementById('attendanceSectionData');
    if (!grade || !section || !data) return;
    const sections = JSON.parse(data.textContent);
    grade.addEventListener('change', function () {
        const previous = section.value;
        section.replaceChildren(new Option('All Sections', '0'));
        sections.filter(s => !grade.value || s.grade === grade.value).forEach(s => {
            section.add(new Option(s.grade + ' — ' + s.name, String(s.id)));
        });
        section.value = Array.from(section.options).some(o => o.value === previous) ? previous : '0';
    });
    if (year) year.addEventListener('change', function () {
        // Reload the authorized section list for this year, without a stale grade/section.
        grade.value = '';
        section.value = '0';
        year.form.requestSubmit();
    });
})();
