(async function () {
    const fixture = document.getElementById('fixture');
    const results = [];
    const errors = [];
    window.addEventListener('error', e => errors.push(e.message));
    const $ = selector => fixture.querySelector(selector);
    const $$ = selector => Array.from(fixture.querySelectorAll(selector));
    function check(ok, message = 'assertion failed') { if (!ok) throw Error(message); }
    async function test(name, run) {
        try { await run(); results.push({name, status: 'PASS'}); }
        catch (e) { results.push({name, status: 'FAIL', error: e.message}); }
    }
    function change(input, value, event = 'change') {
        input.value = value;
        input.dispatchEvent(new Event(event, {bubbles: true}));
    }
    function mount(platform = 'Linux', support = false, department = 7) {
        fixture.innerHTML = SOURCE.base
            .replaceAll('{$releemPublicApiKey|escape}', 'synthetic-test-key')
            .replaceAll('{$WEB_ROOT|escape}', '/billing')
            .replaceAll('{$releemTicketDeptId|intval}', String(department));
        Function(SOURCE.wizard)();
        function card(label) { $$('.rlm-card').find(c => c.querySelector('.rlm-name').textContent === label).click(); }
        card(support ? 'Other Database' : 'MySQL');
        if (!support) { card('Self-managed'); card(platform); }
    }
    function script() { return $('.rlm-code code').textContent; }
    function copyButton() { return $$('.rlm-result button').find(b => b.textContent === 'Copy Installation Script'); }
    for (const platform of ['Linux', 'Windows']) {
        for (const value of ['9'.repeat(400), '9007199254740992', '9007199254740993']) {
            await test(platform + ' rejects unrepresentable memory ' + value.slice(0, 20), () => {
                mount(platform);
                change($('.rlm-form input'), value, 'input');
                check(copyButton().disabled && script() === '', 'unsafe memory produced executable command');
            });
        }
        await test(platform + ' preserves valid memory and recovers from invalid input', () => {
            mount(platform);
            for (const value of ['', '384', ' 4096 ', '000512', '9007199254740991']) {
                change($('.rlm-form input'), value, 'input');
                check(!copyButton().disabled && script().includes('RELEEM_DB_MEMORY_LIMIT=' + (Number(value) || 0)));
            }
            change($('.rlm-form input'), 'bad', 'input');
            check(copyButton().disabled);
            change($('.rlm-form input'), '512', 'input');
            check(!copyButton().disabled);
        });
    }
    for (const message of ['界'.repeat(1500), '😀'.repeat(750), 'кириллица '.repeat(150), 'x'.repeat(1600), "'".repeat(1500)]) {
        await test('bounded support URL ' + message.slice(0, 10), () => {
            mount('Linux', true);
            change($('textarea'), message, 'input');
            const link = $('.rlm-result a');
            check(link.href.length <= 2000, 'URL length ' + link.href.length);
            const sent = new URL(link.href).searchParams.get('message');
            check(sent.length > 0 && message.startsWith(sent), 'message is not a complete Unicode prefix');
            if (sent.length < message.length) check($('.rlm-support-limit').textContent.length > 0, 'silent truncation');
        });
    }
    await test('support Unicode preserves short messages and department fallback', () => {
        mount('Linux', true, 0);
        change($('textarea'), 'Need & help 😀 日本語', 'input');
        const url = new URL($('.rlm-result a').href);
        check(url.searchParams.get('message') === 'Need & help 😀 日本語');
        check(!url.searchParams.has('deptid') && !url.searchParams.has('step'));
        check($('.rlm-support-limit').textContent === '');
    });
    await test('support handles a lone surrogate without throwing', () => {
        mount('Linux', true);
        change($('textarea'), 'prefix\ud800', 'input');
        check(new URL($('.rlm-result a').href).searchParams.get('message') === 'prefix�');
    });
    await test('Windows disables a previously enabled optimization environment variable', () => {
        mount('Windows');
        const [workload, query] = $$('.rlm-form select');
        check(script().includes('$env:RELEEM_QUERY_OPTIMIZATION="true"'));
        change(query, 'disabled');
        check(script().includes('Remove-Item Env:RELEEM_QUERY_OPTIMIZATION -ErrorAction SilentlyContinue'));
        change(query, 'enabled');
        check(script().includes('$env:RELEEM_QUERY_OPTIMIZATION="true"'));
        change(workload, 'over30');
        check(script().includes('Remove-Item Env:RELEEM_QUERY_OPTIMIZATION -ErrorAction SilentlyContinue'));
    });
    await test('services table scrolls inside viewport with long cell values', () => {
        check(SOURCE.home.includes('<div class="rl-table-scroll">'), 'PHP renderer lacks scroll wrapper');
        fixture.innerHTML = '<div id="releem-app"><div data-rl-table><div class="rl-table-scroll"><table class="rl-table"><thead><tr>'
            + '<th>Long column title</th>'.repeat(8) + '</tr></thead><tbody><tr><td>' + 'a'.repeat(200)
            + '</td>' + '<td>Customer data</td>'.repeat(7) + '</tr></tbody></table></div></div></div>';
        const wrapper = $('.rl-table-scroll');
        check(wrapper.getBoundingClientRect().width <= innerWidth, 'wrapper exceeds viewport');
        check(wrapper.scrollWidth > wrapper.clientWidth, 'table should scroll locally');
        check(document.documentElement.scrollWidth <= innerWidth, 'table widens page');
        wrapper.scrollLeft = 100;
        check(wrapper.scrollLeft > 0, 'wrapper is not horizontally scrollable');
    });
    await test('no unexpected browser errors', () => check(errors.length === 0, JSON.stringify(errors)));
    const summary = {total: results.length, passed: results.filter(r => r.status === 'PASS').length,
        failed: results.filter(r => r.status === 'FAIL').length, results};
    document.getElementById('results').textContent = JSON.stringify(summary);
    document.title = 'DONE';
})();
