(() => {
    const banner = document.getElementById('endOfDayAlert');
    if (!banner) return;
    const link = banner.querySelector('a');
    let busy = false;
    let timer;
    async function check() {
        clearTimeout(timer);
        if (busy) return;
        if (document.hidden) { timer = setTimeout(check, 30000); return; }
        busy = true;
        let delay = 30000;
        try {
            const response = await fetch(banner.dataset.endpoint, {credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (!response.ok) throw new Error('Review unavailable');
            const data = await response.json();
            banner.hidden = !data.count;
            link.textContent = `End of Day Alert: ${data.count} student${data.count === 1 ? '' : 's'} need attention. Review and add remarks.`;
            link.href = banner.dataset.review + '?date=' + encodeURIComponent(data.date);
            delay = Math.max(1000, Math.min(30000, Number(data.next_check_ms) || 30000));
        } catch (error) { /* Retry when the connection recovers. */ }
        finally { busy = false; timer = setTimeout(check, delay); }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    document.getElementById('endOfDayReviewForm')?.addEventListener('submit', event => {
        event.currentTarget.querySelector('button[type="submit"], button:not([type])').disabled = true;
    });
    check();
})();
