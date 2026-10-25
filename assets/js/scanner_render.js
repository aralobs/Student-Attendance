// All server-provided values are rendered as text, never parsed as HTML.
function scannerNode(tag, className = '', text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = String(text ?? '');
    return node;
}

function scannerTypeClass(type) {
    return type === 'full_day' ? 'success' : type === 'partial' ? 'warning text-dark' :
        type === 'absent' ? 'danger' : 'secondary';
}

function renderScanResult(data) {
    document.getElementById('scanFeedback').replaceChildren();
    updateScanGuide(data);
    const styles = {
        am_in: ['#d1fae5', '#10b981', '☀️', 'Time-In (AM)'],
        am_out: ['#fef3c7', '#f59e0b', '🌤️', 'Time-Out (AM)'],
        pm_in: ['#dbeafe', '#3b82f6', '🌙', 'Time-In (PM)'],
        pm_out: ['#f3e8ff', '#8b5cf6', '🚪', 'Time-Out (PM)']
    };
    const style = Object.prototype.hasOwnProperty.call(styles, data.event) ? styles[data.event] : styles.am_in;
    const card = scannerNode('div');
    card.style.cssText = `background:${style[0]};border:2px solid ${style[1]};border-radius:10px;padding:1rem;animation:fadeIn .3s ease`;
    const row = scannerNode('div', 'd-flex align-items-start gap-3');
    const icon = scannerNode('div', '', style[2]);
    icon.style.fontSize = '2rem';
    const details = scannerNode('div', 'flex-grow-1');
    details.append(scannerNode('div', 'fw-800 fs-5', data.student),
        scannerNode('div', 'text-muted small', `${data.grade ?? ''} — ${data.section ?? ''}`));
    const badges = scannerNode('div', 'mt-1');
    const type = String(data.attendance_type || 'pending');
    badges.append(scannerNode('span', 'badge bg-dark', style[3]),
        scannerNode('span', 'badge bg-secondary ms-1', `${data.date ? data.date + ' ' : ''}${data.time ?? ''}`),
        scannerNode('span', `badge bg-${scannerTypeClass(type)} ms-1`, type.replace('_', ' ').toUpperCase()));
    details.append(badges);
    const grid = scannerNode('div', 'row g-1 mt-2 text-center small');
    for (const [label, time] of [
        ['Time-In', data.schedule_type === 'pm_only' ? data.pm_in : data.am_in],
        ['Time-Out', data.schedule_type === 'am_only' ? data.am_out : data.pm_out]
    ]) {
        const cell = scannerNode('div', 'col-6', label);
        cell.append(scannerNode('br'), scannerNode('strong', '', time || '-'));
        grid.append(cell);
    }
    details.append(grid);
    const remaining = Array.isArray(data.remaining) ? data.remaining : [];
    if (remaining.length) {
        const next = scannerNode('div', 'mt-2 small text-muted', 'Next: ');
        next.append(scannerNode('strong', '', remaining.join(' → ')));
        details.append(next);
    } else {
        details.append(scannerNode('div', 'mt-2 small text-success fw-bold', '✅ Required scans completed'));
    }
    details.append(scannerNode('div', 'mt-2 small', `SMS: ${data.sms_sent ? 'Sent' : '—'} | Email: ${data.email_sent ? 'Sent' : '—'}`));
    row.append(icon, details);
    card.append(row);
    document.getElementById('resultArea').replaceChildren(card);
}

function showResult(type, title, body) {
    const colors = {success: '#d1fae5', warning: '#fef3c7', danger: '#fee2e2', info: '#e0f2fe'};
    const color = Object.prototype.hasOwnProperty.call(colors, type) ? colors[type] : '#f1f5f9';
    const card = scannerNode('div');
    card.style.cssText = `background:${color};border-radius:10px;padding:1rem;animation:fadeIn .3s ease`;
    const message = scannerNode('div', '', body);
    message.style.whiteSpace = 'pre-line';
    card.append(scannerNode('h6', 'fw-800 mb-2', title), message);
    document.getElementById('scanFeedback').replaceChildren(card);
}

// The log API already encodes these identity fields. Decode only its known
// entities once, then assign textContent; no HTML parser is used.
function scannerLogText(value) {
    const entities = {'&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'", '&#39;': "'"};
    return String(value ?? '').replace(/&(?:amp|lt|gt|quot);|&#0?39;/g, entity => entities[entity]);
}

function scannerLogMessage(message, isError = false) {
    const row = scannerNode('tr');
    const cell = scannerNode('td', `text-center ${isError ? 'text-danger' : 'text-muted'} py-3`, message);
    cell.colSpan = 5;
    row.append(cell);
    document.getElementById('logBody').replaceChildren(row);
}

function renderScannerLog(data, highlightedId) {
    if (!data.length) {
        scannerLogMessage('No scans today');
        return;
    }
    const styles = {
        am_in: ['☀️ Time-In', '#d1fae5', '#065f46'],
        am_out: ['🌤️ Time-Out (AM)', '#fef3c7', '#92400e'],
        pm_in: ['🌙 Time-In (PM)', '#dbeafe', '#1e40af'],
        pm_out: ['🚪 Time-Out (PM)', '#f3e8ff', '#6b21a8']
    };
    const rows = data.map(record => {
        const row = scannerNode('tr', record.attendance_type === 'absent' ? 'table-danger' : record.attendance_type === 'pending' ? 'table-light' : '');
        if (highlightedId != null && record.student_id == highlightedId) {
            row.classList.add('table-warning');
            row.style.animation = 'pulse 1.2s ease 2';
        }
        const identity = scannerNode('td');
        const section = scannerNode('div', 'text-muted', `${scannerLogText(record.grade)} — ${scannerLogText(record.section)}`);
        section.style.fontSize = '0.7rem';
        identity.append(scannerNode('div', 'fw-600 small', scannerLogText(record.name)), section);
        row.append(identity);
        for (const [time, status] of [[record.time_in, record.arrival_status], [record.time_out, null]]) {
            const cell = scannerNode('td', 'text-center small');
            if (time) {
                cell.textContent = String(time);
                if (status === 'late') cell.append(scannerNode('span', 'badge bg-warning text-dark ms-1', 'Late'));
            } else {
                cell.append(scannerNode('span', status === 'absent' ? 'badge bg-danger' : 'text-muted', status === 'absent' ? 'Absent' : '—'));
            }
            row.append(cell);
        }
        const eventCell = scannerNode('td');
        if (Object.prototype.hasOwnProperty.call(styles, record.last_event)) {
            const style = styles[record.last_event];
            const badge = scannerNode('span', 'badge', style[0]);
            badge.style.cssText = `background:${style[1]};color:${style[2]};font-size:0.65rem`;
            eventCell.append(badge, scannerNode('div', 'text-muted small', record.last_event_time || ''));
        } else {
            eventCell.append(scannerNode('span', 'text-muted', '—'));
        }
        const typeCell = scannerNode('td');
        const label = record.attendance_type === 'remarked' ? 'Manual remark' : String(record.attendance_type || '').replace('_', ' ');
        typeCell.append(scannerNode('span', `badge bg-${scannerTypeClass(record.attendance_type)}`, label));
        row.append(eventCell, typeCell);
        return row;
    });
    document.getElementById('logBody').replaceChildren(...rows);
}
