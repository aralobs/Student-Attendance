// Open scanner_xss_test.html in a browser; only synthetic data is used.
(async () => {
    let checks = 0;
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
        checks++;
    };
    const payload = '<img src=x onerror="window.scannerXssExecuted=true"><svg onload="window.scannerXssExecuted=true"></svg>';
    const assertSafe = root => {
        assert(!root.querySelector('img,svg,script,iframe'), 'Injected element was parsed');
        assert(!root.querySelector('[onerror],[onload],[onclick]'), 'Injected handler was parsed');
    };
    try {
        const result = document.getElementById('resultArea');
        const feedback = document.getElementById('scanFeedback');
        const log = document.getElementById('logBody');
        const base = {student: 'Student & Parent', grade: 'Grade 1', section: 'Section A', event: 'am_in', time: '08:00', date: '2026-10-10', attendance_type: 'full_day', am_in: '08:00', am_out: '12:00', pm_in: '13:00', pm_out: '16:00', remaining: ['Time-Out'], sms_sent: true};
        for (const field of ['student', 'grade', 'section', 'time', 'date', 'attendance_type', 'am_in', 'am_out', 'pm_in', 'pm_out', 'remaining']) {
            const data = {...base, [field]: field === 'remaining' ? [payload] : payload};
            if (field === 'pm_in') data.schedule_type = 'pm_only';
            if (field === 'am_out') data.schedule_type = 'am_only';
            renderScanResult(data);
            assertSafe(result);
            assert(result.textContent.includes(field === 'attendance_type' ? payload.toUpperCase() : payload), `Missing literal ${field}`);
        }
        renderScanResult({...base, event: 'constructor', remaining: []});
        assert(result.textContent.includes('Required scans completed'), 'Unknown event fallback/completed state failed');
        renderScanResult(base);
        assert(result.textContent.includes('Student & Parent'), 'Normal name altered');
        assert(result.textContent.includes('SMS: Sent'), 'Notification status missing');
        showResult('danger', payload, payload + '\nSecond line');
        assertSafe(feedback);
        assert(feedback.textContent.includes(payload + '\nSecond line'), 'Error text/newline missing');
        showResult('constructor', 'Fallback', 'Safe');
        assert(feedback.textContent.includes('Fallback'), 'Unknown message type failed');
        const encoded = payload.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
        const record = {student_id: 1, name: 'Student', grade: 'Grade 1', section: 'A', time_in: '08:00', time_out: '16:00', arrival_status: 'late', attendance_type: 'full_day', last_event: 'pm_out', last_event_time: '16:00'};
        for (const field of ['name', 'grade', 'section', 'time_in', 'time_out', 'last_event_time', 'attendance_type']) {
            renderScannerLog([{...record, [field]: ['name', 'grade', 'section'].includes(field) ? encoded : payload}], 1);
            assertSafe(log);
            assert(log.textContent.includes(payload), `Missing literal log ${field}`);
            assert(log.querySelector('tr').classList.contains('table-warning'), 'Highlight lost');
        }
        renderScannerLog([{...record, name: 'A &amp; B &lt; C &#039; D &amp;lt;'}], null);
        assert(log.textContent.includes("A & B < C ' D &lt;"), 'Identity entities must decode once');
        renderScannerLog([{...record, last_event: 'constructor', time_in: null, arrival_status: 'absent'}], null);
        assert(log.textContent.includes('Absent'), 'Absent state lost');
        renderScannerLog([], null);
        assert(log.textContent === 'No scans today', 'Empty state lost');
        scannerLogMessage('Failed to load log: ' + payload, true);
        assertSafe(log);
        assert(log.textContent.includes(payload), 'Error payload not displayed literally');
        await new Promise(resolve => setTimeout(resolve, 100));
        assert(!window.scannerXssExecuted, 'Injected script executed');
        document.getElementById('testResult').textContent = `PASS: ${checks} checks`;
    } catch (error) {
        document.getElementById('testResult').textContent = `FAIL: ${error.message}`;
    }
})();
