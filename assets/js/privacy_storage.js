// Legacy SF2 remarks were browser-only and had no account ownership.
// They are deliberately discarded; only authorized server storage is used now.
(() => {
    try {
        for (let index = localStorage.length - 1; index >= 0; index--) {
            const key = localStorage.key(index);
            if (/^sf2_remarks_\d+_\d+_\d+$/.test(key || '')) localStorage.removeItem(key);
        }
    } catch (_) { /* Storage may be disabled; never read or import old values. */ }
})();
