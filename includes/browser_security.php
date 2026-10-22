<?php
function sendBrowserSecurityHeaders(): void {
    if (headers_sent()) return;
    // External JavaScript is forbidden. Inline scripts/handlers remain permitted for
    // existing templates; this is supply-chain isolation, not a complete XSS policy.
    header("Content-Security-Policy: script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'; worker-src 'self' blob:");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
