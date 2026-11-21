/* Shared Students-style list presentation. All rows remain available for print/export. */
(function () {
    'use strict';
    const PER_PAGE = 15;
    function pageWindow(total, requested, size = PER_PAGE) {
        const pages = Math.max(1, Math.ceil(total / size));
        const page = Math.min(pages, Math.max(1, Math.floor(Number(requested)) || 1));
        return {page, pages, start:total ? (page - 1) * size : 0, end:Math.min(page * size, total), total};
    }
    function visibleRows(rows, requested, query = '') {
        const matches = row => !query || (row.text || '').toLocaleLowerCase().includes(query.toLocaleLowerCase());
        const state = pageWindow(rows.filter(row => !row.group && !row.empty && matches(row)).length, requested);
        const visible = new Set();
        let record = 0, group = null;
        rows.forEach((row, index) => {
            if (row.group) { group = index; return; }
            if (row.empty) { visible.add(index); return; }
            if (!matches(row)) return;
            if (record >= state.start && record < state.end) {
                visible.add(index);
                if (group !== null) visible.add(group);
            }
            record++;
        });
        return visible;
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {pageWindow, visibleRows};
    if (typeof document === 'undefined') return;
    function node(tag, classes, text) {
        const el = document.createElement(tag);
        el.className = classes;
        if (text !== undefined) el.textContent = text;
        return el;
    }
    function enhance(table) {
        if (table.dataset.listReady) return;
        table.dataset.listReady = '1';
        const native = table.dataset.listNative === '1';
        const title = table.dataset.listTitle || 'List';
        const wrap = table.closest('.table-responsive, .sf2-table-wrap, .sf4-table-wrap') || table.parentElement;
        let card = wrap.closest('.card');
        if (!card) {
            card = node('div', 'card');
            wrap.before(card);
            card.append(wrap);
        }
        card.classList.add('list-card');
        table.classList.add('list-table');
        if (!table.classList.contains('sf2-table') && !table.classList.contains('sf4-table')) {
            table.classList.remove('table-sm', 'table-bordered', 'table-striped');
            table.classList.add('table', 'table-hover', 'mb-0');
            table.style.removeProperty('font-size');
        }
        let header = Array.from(card.children).find(child=>child.classList.contains('card-header'));
        if (!header) {
            header = node('div', 'card-header');
            card.prepend(header);
            header.append(node('i', 'bi bi-table me-1'), document.createTextNode(' ' + title + ' '));
        }
        let badge = header.querySelector('.badge');
        if (!badge) { badge = node('span', 'badge bg-primary ms-1'); header.append(badge); }
        if (native) {
            badge.textContent = table.dataset.listTotal || '0';
            return;
        }
        const key = table.dataset.listKey || 'page';
        let requested = new URL(window.location.href).searchParams.get(key) || 1;
        const searchKey = 'list_search';
        let query = new URL(window.location.href).searchParams.get(searchKey) || '';
        const toolbar = node('div', 'list-search p-3 border-bottom');
        const label = node('label', 'form-label small', 'Search ' + title.toLowerCase());
        const input = node('input', 'form-control');
        input.type = 'search'; input.value = query;
        input.id = 'list-search-' + Array.from(document.querySelectorAll('table[data-list-title]')).indexOf(table);
        label.htmlFor = input.id;
        input.placeholder = table.dataset.listSearch || 'Search this list…';
        toolbar.append(label, input);
        wrap.before(toolbar);
        const empty = node('div', 'list-empty text-center text-muted py-4', 'No matching records found.');
        empty.hidden = true; wrap.after(empty);
        const footer = node('div', 'card-footer list-pagination d-flex justify-content-between align-items-center py-2');
        const summary = node('small', 'text-muted');
        summary.setAttribute('aria-live', 'polite');
        const nav = node('nav', '');
        nav.setAttribute('aria-label', title + ' pages');
        footer.append(summary, nav);
        // Keep the footer next to this table, outside its horizontal scroll area.
        wrap.after(footer);
        function render() {
            const body = table.tBodies[0];
            if (!body) return;
            const rows = Array.from(body.rows);
            const descriptors = rows.map(row => ({
                group: row.hasAttribute('data-list-group'),
                text: row.textContent,
                empty: row.cells.length === 1 && row.cells[0].colSpan > 1 && !row.hasAttribute('data-list-group')
            }));
            const state = pageWindow(descriptors.filter(row=>!row.group && !row.empty && row.text.toLocaleLowerCase().includes(query.toLocaleLowerCase())).length, requested);
            requested = state.page;
            const visible = visibleRows(descriptors, requested, query);
            rows.forEach((row, index)=>row.classList.toggle('list-page-hidden', !visible.has(index)));
            badge.textContent = String(state.total);
            empty.hidden = !query || state.total > 0;
            footer.hidden = state.pages <= 1;
            summary.textContent = `Showing ${state.total ? state.start + 1 : 0}–${state.end} of ${state.total}`;
            const list = node('ul', 'pagination pagination-sm mb-0');
            function link(label, page, disabled = false, active = false) {
                const item = node('li', 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : ''));
                const a = node('a', 'page-link', String(label));
                const url = new URL(window.location.href);
                url.searchParams.set(key, String(page));
                a.href = url.href;
                if (active) a.setAttribute('aria-current', 'page');
                if (disabled) { a.setAttribute('aria-disabled', 'true'); a.tabIndex = -1; }
                a.addEventListener('click', event => {
                    event.preventDefault();
                    if (disabled) return;
                    requested = page;
                    window.history.replaceState(null, '', url.href);
                    render();
                });
                item.append(a); list.append(item);
            }
            link('«', Math.max(1, state.page - 1), state.page === 1);
            const start = Math.max(1, state.page - 2), end = Math.min(state.pages, state.page + 2);
            if (start > 1) { link(1, 1); if (start > 2) link('…', state.page, true); }
            for (let page = start; page <= end; page++) link(page, page, false, page === state.page);
            if (end < state.pages) { if (end < state.pages - 1) link('…', state.page, true); link(state.pages, state.pages); }
            link('»', Math.min(state.pages, state.page + 1), state.page === state.pages);
            nav.replaceChildren(list);
        }
        input.addEventListener('input', () => {
            query = input.value.trim(); requested = 1;
            const url = new URL(window.location.href);
            if (query) url.searchParams.set(searchKey, query); else url.searchParams.delete(searchKey);
            url.searchParams.delete(key);
            window.history.replaceState(null, '', url.href);
            render();
        });
        // Some lists sit inside a bulk attendance form. Enter must only search.
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') event.preventDefault();
        });
        render();
        // Scanner refreshes replace body rows. Observe children only to avoid reacting to our classes.
        if (table.dataset.listLive === '1') {
            new MutationObserver(render).observe(table.tBodies[0], {childList:true});
        }
        window.addEventListener('popstate', () => {
            requested = new URL(window.location.href).searchParams.get(key) || 1;
            query = new URL(window.location.href).searchParams.get(searchKey) || '';
            input.value = query;
            render();
        });
    }
    const init = () => document.querySelectorAll('table[data-list-title]').forEach(enhance);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
