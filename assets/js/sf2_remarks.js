(() => {
    const status = document.getElementById('sf2RemarkStatus');
    if (!status) return;
    const retry = document.getElementById('sf2RemarkRetry');
    const states = [...document.querySelectorAll('.sf2-remark')].map(el => ({el, saved:el.value, version:Number(el.dataset.version || 0), pending:false, error:'', conflict:false, timer:null}));
    function grow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
    function showStatus() {
        const failed = states.find(s => s.error);
        const unsaved = states.some(s => s.pending || s.el.value !== s.saved);
        status.textContent = failed ? failed.error : (unsaved ? 'Saving remarks…' : 'Remarks saved to the server.');
        status.classList.toggle('text-danger', Boolean(failed));
        retry.hidden = !states.some(s => s.error && !s.conflict);
    }
    async function save(state) {
        clearTimeout(state.timer);
        if (state.pending || state.conflict || state.el.value === state.saved) return;
        const value = state.el.value;
        state.pending = true; state.error = ''; showStatus();
        let success = false;
        try {
            const response = await fetch(status.dataset.endpoint, {
                method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
                body:new URLSearchParams({csrf_token:document.querySelector('meta[name="csrf-token"]').content,
                    student_id:state.el.dataset.studentId,section_id:status.dataset.section,month:status.dataset.month,
                    year:status.dataset.year,version:String(state.version),remark:value})
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                state.conflict = response.status === 409;
                throw new Error(data.message || 'Remarks could not be saved. Please reload and sign in if needed.');
            }
            state.version = data.version; state.saved = value; success = true;
        } catch (error) {
            state.error = error.message || 'Remarks could not be saved. Check your connection and retry.';
        } finally {
            state.pending = false; showStatus();
            if (success && state.el.value !== state.saved) save(state);
        }
    }
    states.forEach(state => {
        grow(state.el);
        state.el.addEventListener('input', () => { grow(state.el); clearTimeout(state.timer); state.timer = setTimeout(() => save(state), 700); showStatus(); });
        state.el.addEventListener('blur', () => save(state));
    });
    retry.addEventListener('click', () => states.filter(s => !s.conflict).forEach(save));
    window.addEventListener('beforeunload', event => {
        if (states.some(s => s.pending || s.el.value !== s.saved)) { event.preventDefault(); event.returnValue = ''; }
    });
    document.getElementById('sf2FilterForm')?.addEventListener('submit', () => {
        document.getElementById('genSpin')?.classList.remove('d-none');
        document.getElementById('genBtn').disabled = true;
    });
})();
