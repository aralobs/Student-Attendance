/* Shared School Calendar editor. Server services enforce scope and confirmation. */
(() => {
    'use strict';
    const byId = id => document.getElementById(id);
    const modal = byId('calendarModal');
    if (!modal) return;
    const form = byId('calendarEntryForm');
    const kind = byId('entryKind');
    let previewToken = '';
    let lookupVersion = 0;
    let busy = false;
    let eventLoading = false;
    let eventLoadFailed = false;
    let controller;

    function clearPreview() {
        previewToken = '';
        byId('deliveryPreview').hidden = true;
        byId('deliveryPreview').textContent = '';
        byId('calendarEntrySubmit').textContent = kind.value === 'delivery' ? 'Preview Schedule' : 'Save Entry';
    }

    function updateFields() {
        const delivery = kind.value === 'delivery';
        const eventBlocked = eventLoading || eventLoadFailed;
        kind.disabled = eventBlocked;
        byId('calendarEntrySubmit').disabled = eventBlocked;
        if (byId('eventFields')) {
            byId('eventFields').hidden = delivery;
            byId('eventFields').querySelectorAll('input,select,textarea').forEach(el => { el.disabled = delivery || eventBlocked; });
        }
        byId('deliveryFields').hidden = !delivery;
        byId('deliveryFields').querySelectorAll('input,select,textarea').forEach(el => { el.disabled = !delivery; });
        const modular = delivery && byId('deliveryMode')?.value === 'modular';
        if (byId('deliveryDeadlineGroup')) byId('deliveryDeadlineGroup').hidden = !modular;
        if (byId('deliveryDeadline')) {
            byId('deliveryDeadline').disabled = !modular;
            byId('deliveryDeadline').required = modular;
        }
        clearPreview();
    }

    function message(text, error = true) {
        byId('modalMsg').className = text ? `alert alert-${error ? 'danger' : 'success'} py-2` : '';
        byId('modalMsg').textContent = text;
    }

    window.openAddModal = async function(date, requestedKind) {
        if (busy) return;
        const version = ++lookupVersion;
        eventLoading = false;
        eventLoadFailed = false;
        form.reset();
        kind.value = requestedKind || modal.dataset.defaultKind;
        if (kind.selectedOptions[0]?.disabled) kind.value = modal.dataset.defaultKind;
        if (!kind.selectedOptions[0] || kind.selectedOptions[0].disabled) return;
        message('');
        byId('modalTitle').textContent = date ? `Calendar Entry — ${date}` : 'Add Calendar Entry';
        if (byId('entryDate')) byId('entryDate').value = date || '';
        for (const id of ['deliveryFrom', 'deliveryTo']) {
            if (byId(id)) byId(id).value = date || modal.dataset.defaultDate;
        }
        updateFields();
        controller ||= bootstrap.Modal.getOrCreateInstance(modal);
        controller.show();
        if (!date || kind.value !== 'event') return;
        eventLoading = true;
        updateFields();
        try {
            const response = await fetch('calendar.php', {method: 'POST', body: new URLSearchParams({
                ajax: '1', action: 'get', date, csrf_token: byId('calendarCsrf').value
            })});
            const data = await response.json();
            if (version !== lookupVersion || kind.value !== 'event') return;
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load this entry.');
            if (data.entry) {
                byId('entryTitle').value = data.entry.title;
                byId('entryType').value = data.entry.type;
                byId('entryDescription').value = data.entry.description || '';
                byId('modalTitle').textContent = `Edit Entry — ${date}`;
            }
        } catch (error) {
            if (version === lookupVersion) {
                eventLoadFailed = true;
                message('Unable to load this entry. Please reopen it and try again.');
            }
        } finally {
            if (version === lookupVersion) {
                eventLoading = false;
                updateFields();
            }
        }
    };

    form.addEventListener('input', () => { ++lookupVersion; clearPreview(); });
    form.addEventListener('change', () => { ++lookupVersion; updateFields(); });
    modal.addEventListener('hidden.bs.modal', () => { ++lookupVersion; clearPreview(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || eventLoading || eventLoadFailed || !form.reportValidity()) return;
        const delivery = kind.value === 'delivery';
        const date = byId(delivery ? 'deliveryFrom' : 'entryDate').value;
        const params = new URLSearchParams({ajax: '1', action: delivery ? 'delivery' : 'add',
            csrf_token: byId(delivery ? 'entryCsrf' : 'calendarCsrf').value});
        if (delivery) {
            params.set('section_id', byId('deliverySection').value);
            params.set('from', date);
            params.set('to', byId('deliveryTo').value);
            params.set('mode', byId('deliveryMode').value);
            params.set('deadline_at', byId('deliveryMode').value === 'modular' ? byId('deliveryDeadline').value : '');
            params.set('confirm_token', previewToken);
        } else {
            params.set('date', date);
            params.set('title', byId('entryTitle').value.trim());
            params.set('type', byId('entryType').value);
            params.set('description', byId('entryDescription').value.trim());
        }
        busy = true;
        ++lookupVersion;
        message('');
        const controls = [...modal.querySelectorAll('input,select,textarea,button')];
        const enabled = controls.filter(el => !el.disabled);
        enabled.forEach(el => { el.disabled = true; });
        try {
            const response = await fetch('calendar.php', {method: 'POST', body: params});
            const data = await response.json();
            if (!response.ok || !data.success) {
                clearPreview();
                message(data.message || 'Unable to save this entry.');
            } else if (delivery && !data.saved) {
                previewToken = data.token;
                const preview = byId('deliveryPreview');
                preview.textContent = `Review ${data.dates.length} eligible dates, ${data.skipped.length} skipped closures/weekends, and ${data.existing.length} existing schedules to replace. Dates: ${data.dates.join(', ')}.` +
                    (data.skipped.length ? ` Skipped: ${data.skipped.join(', ')}.` : '');
                preview.hidden = false;
                byId('calendarEntrySubmit').textContent = 'Confirm Schedule';
            } else {
                const url = new URL(window.location.href);
                url.searchParams.set('month', Number(date.slice(5, 7)));
                url.searchParams.set('year', date.slice(0, 4));
                url.searchParams.delete('entry');
                window.location.assign(url.toString());
            }
        } catch (error) {
            clearPreview();
            message('Could not complete the request. Please try again.');
        } finally {
            busy = false;
            enabled.forEach(el => { el.disabled = false; });
        }
    });
    updateFields();
    if (new URL(window.location.href).searchParams.get('entry') === 'delivery') window.openAddModal(null, 'delivery');
})();
