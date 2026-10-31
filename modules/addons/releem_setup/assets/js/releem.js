/* Releem Setup dashboard: client-side table (search, sort, page length, paging) and the report pie chart. */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); }
    }

    function initTable(root) {
        var table = root.querySelector('table.rl-table');
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.rows).filter(function (r) { return !r.classList.contains('rl-empty-row'); });
        var emptyRow = tbody.querySelector('.rl-empty-row');
        var search = root.querySelector('[data-rl-search]');
        var lengthSel = root.querySelector('[data-rl-length]');
        var info = root.querySelector('[data-rl-info]');
        var buttons = root.querySelector('[data-rl-pages]');
        var state = { page: 1, length: parseInt(lengthSel ? lengthSel.value : '25', 10), query: '', sortCol: parseInt(root.getAttribute('data-sort-col') || '0', 10), sortDir: root.getAttribute('data-sort-dir') || 'desc' };
        var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);

        function cellValue(row, col) {
            var cell = row.cells[col];
            return cell ? (cell.getAttribute('data-sort') !== null ? cell.getAttribute('data-sort') : cell.textContent).trim() : '';
        }

        function render() {
            var q = state.query.toLowerCase();
            var visible = rows.filter(function (r) { return q === '' || r.textContent.toLowerCase().indexOf(q) !== -1; });

            visible.sort(function (a, b) {
                var x = cellValue(a, state.sortCol), y = cellValue(b, state.sortCol);
                var nx = parseFloat(x), ny = parseFloat(y);
                var r = (!isNaN(nx) && !isNaN(ny) && /^-?[\d.]+$/.test(x) && /^-?[\d.]+$/.test(y)) ? nx - ny : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
                return state.sortDir === 'asc' ? r : -r;
            });

            var total = visible.length;
            var perPage = state.length > 0 ? state.length : (total || 1);
            var pages = Math.max(1, Math.ceil(total / perPage));
            if (state.page > pages) { state.page = pages; }
            var start = (state.page - 1) * perPage;

            rows.forEach(function (r) { r.style.display = 'none'; });
            visible.slice(start, start + perPage).forEach(function (r) { r.style.display = ''; tbody.appendChild(r); });
            if (emptyRow) { tbody.appendChild(emptyRow); emptyRow.style.display = total === 0 ? '' : 'none'; }

            headers.forEach(function (h, i) {
                h.classList.remove('rl-sort-asc', 'rl-sort-desc');
                if (i === state.sortCol) { h.classList.add(state.sortDir === 'asc' ? 'rl-sort-asc' : 'rl-sort-desc'); }
            });

            if (info) {
                info.textContent = total === 0 ? 'No entries' : 'Showing ' + (start + 1) + ' to ' + Math.min(start + perPage, total) + ' of ' + total + ' entries';
            }

            if (buttons) {
                buttons.innerHTML = '';
                var add = function (label, page, disabled, current) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'rl-btn' + (current ? ' is-current' : '');
                    b.textContent = label;
                    if (disabled) { b.disabled = true; } else { b.addEventListener('click', function () { state.page = page; render(); }); }
                    buttons.appendChild(b);
                };
                add('Previous', state.page - 1, state.page <= 1, false);
                var from = Math.max(1, state.page - 2), to = Math.min(pages, state.page + 2);
                for (var p = from; p <= to; p++) { add(String(p), p, false, p === state.page); }
                add('Next', state.page + 1, state.page >= pages, false);
            }
        }

        headers.forEach(function (h, i) {
            if (!h.classList.contains('rl-sortable')) { return; }
            h.addEventListener('click', function () {
                state.sortDir = (state.sortCol === i && state.sortDir === 'asc') ? 'desc' : 'asc';
                state.sortCol = i;
                state.page = 1;
                render();
            });
        });
        if (search) { search.addEventListener('input', function () { state.query = search.value; state.page = 1; render(); }); }
        if (lengthSel) { lengthSel.addEventListener('change', function () { state.length = parseInt(lengthSel.value, 10); state.page = 1; render(); }); }
        render();
    }

    function initChart(canvas) {
        if (typeof Chart === 'undefined') { return; }
        var data;
        try { data = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
        new Chart(canvas.getContext('2d'), {
            type: 'pie',
            data: { labels: data.labels, datasets: [{ data: data.values, backgroundColor: data.colors, borderColor: '#fff', borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }

    ready(function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-rl-table]'), initTable);
        Array.prototype.forEach.call(document.querySelectorAll('canvas[data-chart]'), initChart);
    });
})();
