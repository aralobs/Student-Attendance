// Show the saved name format after leaving a field, without interrupting typing.
(() => {
    function normalize(input) {
        if (!input.matches('input[data-title-case]')) return;
        const controls = input.form?.elements;
        if (input.dataset.titleCase === 'teacher' && controls?.namedItem('role')?.value !== 'teacher') return;
        if (input.dataset.titleCase === 'section' && Number(controls?.namedItem('id')?.value) > 0) return;
        input.value = input.value.trim().toLowerCase().replace(
            /[\p{L}\p{M}]+(?:['’][\p{L}\p{M}]+)*/gu,
            word => word.charAt(0).toUpperCase() + word.slice(1)
        );
    }
    document.addEventListener('focusout', event => normalize(event.target));
    document.addEventListener('submit', event => {
        event.target.querySelectorAll('input[data-title-case]').forEach(normalize);
    }, true);
})();
