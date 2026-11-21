/* One dated delivery entry; the server previews and rechecks its inherited schedule. */
window.deleteDeliverySchedule = async function(button) {
    if (button.disabled) return;
    button.disabled = true;
    try {
        const params = new URLSearchParams({ajax:'1', action:'delivery_delete', id:button.dataset.deliveryId,
            csrf_token:document.querySelector('meta[name="csrf-token"]')?.content || ''});
        async function request() {
            let response, data;
            try {
                response = await fetch('calendar.php', {method:'POST', body:params});
                data = await response.json();
            } catch (error) {
                throw new Error('Could not complete the request. Reload the calendar before trying again.');
            }
            if (!response.ok || !data?.success) throw new Error(typeof data?.message === 'string' ? data.message : 'Unable to delete this delivery entry.');
            return data;
        }
        const preview = await request();
        if (preview.deleted || typeof preview.token !== 'string') throw new Error('Reload the schedule before deleting it.');
        if (!await showConfirm(`Delete ${preview.label}?\n\n${preview.fallback_message}`, {
            title:'Delete delivery entry', confirmLabel:'Delete', tone:'danger'
        })) return;
        params.set('confirm_token',preview.token);
        const result = await request();
        if (!result.deleted) {
            await showMessage('The schedule changed. Please review it and choose Delete again.', {title:'Schedule changed'});
            return;
        }
        window.location.reload();
    } catch (error) {
        await showMessage(error instanceof Error ? error.message : 'Could not complete the request. Reload the calendar before trying again.', {title:'Delete failed'});
    } finally {
        button.disabled = false;
    }
};
