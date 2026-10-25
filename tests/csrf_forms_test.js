(async () => {
    let checks = 0;
    const assert = (condition, message) => { if (!condition) throw new Error(message); checks++; };
    let submitted = [], allow = false, dialogs = 0;
    // Intercept submission so the test never contacts the application.
    HTMLFormElement.prototype.submit = function () { submitted.push({method: this.method, action: this.action, data: new FormData(this)}); };
    showConfirm = async () => { dialogs++; return allow; };
    const submit = async id => {
        const form = document.getElementById(id);
        const event = new SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: form.querySelector('button')});
        form.dispatchEvent(event);
        assert(event.defaultPrevented, 'Confirmation must stop default submission');
        await new Promise(resolve => setTimeout(resolve, 0));
    };
    try {
        for (const id of ['buttonAction', 'formAction']) {
            allow = false;
            const before = submitted.length;
            await submit(id);
            assert(submitted.length === before, 'Cancel submitted a mutation');
            allow = true;
            await submit(id);
            assert(submitted.length === before + 1, 'Confirm failed to submit exactly once');
            const request = submitted.at(-1);
            assert(request.method === 'post', 'Mutation used GET');
            assert(request.data.get('csrf_token') === 'synthetic-session-token', 'Token lost');
            assert(request.data.get('id') === (id === 'buttonAction' ? '123' : '456'), 'ID lost');
            assert(!request.action.includes('?'), 'Mutation ID remains in query string');
        }
        assert(dialogs === 4, 'Confirmation bypassed');
        const bulk = new FormData(document.getElementById('bulkForm'));
        assert(bulk.get('student_ids[]') === '123', 'External bulk checkbox lost');
        assert(bulk.get('csrf_token') === 'synthetic-session-token', 'Bulk token lost');
        assert(!bulk.has('id'), 'Archive ID leaked into bulk submission');
        assert(document.querySelectorAll('form').length === 3, 'Nested form broke DOM');
        let sent;
        window.fetch = async (url, options) => { sent = options; return {ok: true, json: async () => ({success: true})}; };
        await ajaxPost('/synthetic-endpoint', {attendance_id: 123});
        assert(sent.method === 'POST', 'AJAX method wrong');
        assert(new URLSearchParams(sent.body).get('csrf_token') === 'synthetic-session-token', 'AJAX token missing');
        document.getElementById('testResult').textContent = `PASS: ${checks} checks`;
    } catch (error) {
        document.getElementById('testResult').textContent = `FAIL: ${error.message}`;
    }
})();
