(() => {
    'use strict';
    const all = document.getElementById('select-all-sections');
    const boxes = [...document.querySelectorAll('.section-copy-check')];
    const invalidatePreview = () => document.getElementById('section-copy-preview')?.setAttribute('hidden', '');
    const update = () => {
        if (!all) return;
        const count = boxes.filter(box => box.checked).length;
        all.checked = boxes.length > 0 && count === boxes.length;
        all.indeterminate = count > 0 && count < boxes.length;
    };
    all?.addEventListener('change', () => {
        boxes.forEach(box => { box.checked = all.checked; });
        update();
        invalidatePreview();
    });
    boxes.forEach(box => box.addEventListener('change', () => {
        update();
        invalidatePreview();
    }));
    update();
    // Reload the source list before previewing a different school-year pair.
    const source = document.getElementById('section-source-year');
    const target = document.getElementById('section-target-year');
    [source, target].forEach(select => select?.addEventListener('change', () => {
        document.getElementById('section-preparation-form')?.setAttribute('hidden', '');
        invalidatePreview();
        if (select === source) {
            // The server filters valid targets again when Load sections is pressed.
            for (const option of target.options) {
                option.hidden = false;
                option.disabled = false;
            }
            target.value = '';
        }
    }));
})();
