(function () {
    'use strict';
    document.querySelectorAll('select[data-section-filter]').forEach(function (section) {
        const grade = section.form.querySelector('select[name="grade"]');
        if (!grade) return;
        const options = Array.from(section.options);
        const placeholder = options.find(option => !option.dataset.grade);
        function updateSections() {
            const previous = section.value;
            const matching = options.filter(option => !option.dataset.grade || !grade.value || option.dataset.grade === grade.value);
            matching.forEach(option => { option.hidden = false; option.disabled = false; });
            section.replaceChildren(...matching);
            section.value = matching.some(option => option.value === previous) ? previous : placeholder.value;
        }
        grade.addEventListener('change', updateSections);
        updateSections();
    });
})();
