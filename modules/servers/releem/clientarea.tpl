<div style="text-align: left;">
{if $releemPublicApiKey}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Public API Key</strong></div>
        <div class="panel-body">
            <code>{$releemPublicApiKey|escape}</code>
        </div>
    </div>

    <style>
        .rlm-wiz { background: #fff; border: 1px solid #e3e6ea; border-radius: 18px; padding: 28px 32px 22px; margin: 0 0 20px 0; }
        .rlm-wiz h2.rlm-title { text-align: center; font-size: 30px; font-weight: 400; margin: 0 0 18px 0; }
        .rlm-wiz .rlm-divider { border: 0; border-top: 1px dashed #c9ced6; margin: 0 0 22px 0; }
        .rlm-wiz h3.rlm-step { text-align: center; font-size: 18px; font-weight: 700; margin: 0 0 18px 0; }
        .rlm-wiz .rlm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 20px; }
        .rlm-wiz .rlm-card { display: block; width: 100%; text-align: left; background: #fff; border: 1px solid #dfe3e8; border-radius: 18px; padding: 18px 20px; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
        .rlm-wiz .rlm-card:hover:not([disabled]) { border-color: #4a6cf7; box-shadow: 0 2px 10px rgba(74,108,247,.15); }
        .rlm-wiz .rlm-card[disabled] { opacity: .45; cursor: not-allowed; background: #f6f7f9; }
        .rlm-wiz .rlm-card .rlm-ico { display: block; height: 34px; margin-bottom: 8px; color: #222; }
        .rlm-wiz .rlm-card .rlm-ico svg { width: 34px; height: 34px; display: block; }
        .rlm-wiz .rlm-card .rlm-ico img { height: 34px; width: auto; max-width: 64px; display: block; }
        .rlm-wiz .rlm-card .rlm-name { display: block; font-size: 20px; font-weight: 700; color: #111; }
        .rlm-wiz .rlm-card .rlm-desc { display: block; font-size: 14px; color: #6b7280; margin-top: 4px; }
        .rlm-wiz .rlm-chips { text-align: center; margin-bottom: 16px; }
        .rlm-wiz .rlm-chip { display: inline-block; background: #eef1f6; border: 0; border-radius: 14px; padding: 3px 12px; margin: 0 3px 4px; font-size: 13px; cursor: pointer; }
        .rlm-wiz .rlm-chip:hover { background: #dfe5f0; }
        .rlm-wiz .rlm-foot { text-align: center; color: #6b7280; font-size: 14px; margin: 0 0 8px 0; }
        .rlm-wiz .rlm-result label { font-weight: 600; display: block; margin-bottom: 4px; }
        .rlm-wiz .rlm-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
        .rlm-wiz .rlm-help { font-size: 12px; color: #6b7280; margin-top: 3px; }
        .rlm-wiz .rlm-err { font-size: 12px; color: #c0392b; margin-top: 3px; }
        .rlm-wiz pre.rlm-code { background: #f5f5f5; border: 1px solid #ddd; padding: 12px; white-space: pre-wrap; word-break: break-all; margin-bottom: 10px; }
        .rlm-wiz .rlm-back { margin-top: 6px; text-align: center; }
        @media (max-width: 767px) { .rlm-wiz .rlm-grid, .rlm-wiz .rlm-form { grid-template-columns: 1fr; } .rlm-wiz { padding: 18px 14px; } }
    </style>

    <div class="rlm-wiz" id="releem-wizard" data-api-key="{$releemPublicApiKey|escape}" data-ticket-url="{$WEB_ROOT|escape}/submitticket.php" data-ticket-dept="{$releemTicketDeptId|intval}" data-manual-url="{$releemDocsLinuxManualUrl|escape}" data-logo-base="{$WEB_ROOT|escape}/modules/servers/releem/assets/logos/">
        <h2 class="rlm-title">Connect Database Server</h2>
        <hr class="rlm-divider">
        <div class="rlm-chips" id="rlm-chips"></div>
        <div id="rlm-body"></div>
        <p class="rlm-foot" id="rlm-foot-support">Need assistance? <a href="{$WEB_ROOT|escape}/submitticket.php">Open a support ticket</a> at any time.</p>
        <p class="rlm-foot" style="color:#111;">Prefer a manual setup? <a href="https://docs.releem.com/get-started/connect-your-database-server" target="_blank" rel="noopener">View the installation guides</a></p>
    </div>

    {literal}
    <script>
    (function () {
        var root = document.getElementById('releem-wizard');
        if (!root) { return; }
        var API_KEY = root.getAttribute('data-api-key') || '';
        var TICKET_URL = root.getAttribute('data-ticket-url') || 'submitticket.php';
        var TICKET_DEPT = parseInt(root.getAttribute('data-ticket-dept') || '0', 10) || 0;
        var MAX_MESSAGE = 1500; // keeps the pre-filled ticket link within URL length limits
        var MANUAL_URL = root.getAttribute('data-manual-url') || '';
        var LOGO_BASE = root.getAttribute('data-logo-base') || '';
        var LOGOS = { mysql: 'mysql.svg', postgresql: 'postgresql.svg', mariadb: 'mariadb.svg', linux: 'linux.svg', windows: 'windows.svg', docker: 'docker.svg', kubernetes: 'kubernetes.svg', rds: 'rds.svg', aurora: 'aurora.svg', gcp: 'gcp.svg', azure: 'azure.svg', cpanel: 'cpanel.svg' };
        var DOCS = 'https://docs.releem.com/installation/';
        var SUPPORT = { type: 'support' };

        var S = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
        var ICONS = {
            database: '<svg ' + S + '><ellipse cx="16" cy="8" rx="9" ry="4"/><path d="M7 8v8c0 2.2 4 4 9 4s9-1.8 9-4V8"/><path d="M7 16v8c0 2.2 4 4 9 4s9-1.8 9-4v-8"/></svg>',
            server: '<svg ' + S + '><rect x="4" y="5" width="24" height="9" rx="2"/><rect x="4" y="18" width="24" height="9" rx="2"/><path d="M9 9.5h.01M9 22.5h.01"/></svg>',
            cloud: '<svg ' + S + '><path d="M9 25a6 6 0 0 1-.6-11.97A8 8 0 0 1 23.8 11.5 6.5 6.5 0 0 1 23 25z"/></svg>',
            panel: '<svg ' + S + '><rect x="3" y="5" width="26" height="22" rx="2"/><path d="M3 11h26M11 11v16"/></svg>',
            cpanel: '<svg ' + S + '><rect x="3" y="5" width="26" height="22" rx="2"/><path d="M3 11h26"/><text x="16" y="23" text-anchor="middle" font-size="10" font-weight="700" font-family="Arial,sans-serif" fill="currentColor" stroke="none">cP</text></svg>',
            linux: '<svg ' + S + '><rect x="3" y="5" width="26" height="22" rx="3"/><path d="M8 13l5 4-5 4M15 22h8"/></svg>',
            windows: '<svg ' + S.replace('fill="none"', 'fill="currentColor"') + ' stroke="none"><path d="M4 6.5l10.5-1.4v10.2H4zM16 4.9L28 3.2v12.1H16zM4 16.7h10.5v10.2L4 25.5zM16 16.7h12v12.1L16 27.1z"/></svg>',
            cube: '<svg ' + S + '><path d="M16 3l11 6v14l-11 6-11-6V9z"/><path d="M5 9l11 6 11-6M16 15v14"/></svg>',
            cubes: '<svg ' + S + '><path d="M10 3l7 4v8l-7 4-7-4V7zM3 7l7 4 7-4M10 11v8"/><path d="M22 13l7 4v8l-7 4-7-4v-8zM15 17l7 4 7-4M22 21v8"/></svg>',
            question: '<svg ' + S + '><circle cx="16" cy="16" r="12"/><path d="M12 12.5a4 4 0 1 1 5.5 3.7c-1 .5-1.5 1.2-1.5 2.3M16 22.5h.01"/></svg>'
        };


        function link(path) { return { type: 'link', url: DOCS + path }; }
        function script(os, docs) { return { type: 'script', os: os, docs: docs ? DOCS + docs : '' }; }
        var OFF = null; // greyed-out card

        var DB = {
            mysql:      { name: 'MySQL',      desc: 'Choose OS and installation type next', icon: 'mysql' },
            postgresql: { name: 'PostgreSQL', desc: 'Choose OS and installation type next', icon: 'postgresql' },
            mariadb:    { name: 'MariaDB',    desc: 'Choose OS and installation type next', icon: 'mariadb' },
            other:      { name: 'Other Database', desc: 'Not listed / don\u2019t know',      icon: 'question', leaf: SUPPORT }
        };
        var ENV = {
            self:  { name: 'Self-managed',  desc: 'Your own server or virtual machine', icon: 'server' },
            cloud: { name: 'Cloud-managed', desc: 'Managed database service',           icon: 'cloud' },
            panel: { name: 'Control panel', desc: 'cPanel / WHM and others',            icon: 'cpanel' },
            other: { name: 'Other',         desc: 'Not listed / don\u2019t know',       icon: 'question' }
        };
        var PLAT = {
            linux:      { name: 'Linux',            icon: 'linux' },
            windows:    { name: 'Windows',          icon: 'windows' },
            docker:     { name: 'Docker',           icon: 'docker' },
            kubernetes: { name: 'Kubernetes',       icon: 'kubernetes' },
            rds:        { name: 'Amazon RDS',       icon: 'rds' },
            aurora:     { name: 'Amazon Aurora',    icon: 'aurora' },
            gcp:        { name: 'Google Cloud SQL', icon: 'gcp' },
            azure:      { name: 'Azure Database',   icon: 'azure' },
            whm:        { name: 'WHM/cPanel',       icon: 'cpanel' },
            other:      { name: 'Other',            icon: 'question' }
        };

        // db -> env -> platform -> leaf (OFF means not supported for this database type)
        var TREE = {
            mysql: {
                self:  { linux: script('linux'), docker: link('mysql/docker'), windows: script('windows'), kubernetes: link('mysql/kubernetes') },
                cloud: { rds: link('mysql/aws-rds'), aurora: link('mysql/aws-rds'), gcp: link('mysql/gcp-cloud-sql'), azure: link('mysql/azure-database-for-mysql') },
                panel: { whm: script('whm', 'mysql/whm-cpanel'), other: SUPPORT },
                other: { other: SUPPORT }
            },
            mariadb: {
                self:  { linux: script('linux'), docker: link('mariadb/docker'), windows: script('windows'), kubernetes: link('mysql/kubernetes') },
                cloud: { rds: link('mariadb/aws-rds'), other: SUPPORT },
                panel: { whm: script('whm', 'mariadb/whm-cpanel'), other: SUPPORT },
                other: { other: SUPPORT }
            },
            postgresql: {
                self:  { linux: script('linux'), docker: link('postgresql/docker'), windows: OFF, kubernetes: OFF },
                cloud: { rds: link('postgresql/aws-rds'), aurora: link('postgresql/aws-rds'), gcp: OFF, azure: OFF },
                panel: { whm: OFF, other: OFF },
                other: { other: SUPPORT }
            }
        };

        var state = { db: null, env: null, plat: null };
        var body = document.getElementById('rlm-body');
        var chips = document.getElementById('rlm-chips');

        function el(tag, attrs, children) {
            var n = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (k) {
                if (k === 'text') { n.textContent = attrs[k]; }
                else if (k === 'class') { n.className = attrs[k]; }
                else { n.setAttribute(k, attrs[k]); }
            });
            (children || []).forEach(function (c) { n.appendChild(c); });
            return n;
        }

        function card(meta, onClick, disabled) {
            var b = el('button', { type: 'button', 'class': 'rlm-card' }, [
                el('span', { 'class': 'rlm-ico', 'aria-hidden': 'true' }),
                el('span', { 'class': 'rlm-name', text: meta.name }),
                el('span', { 'class': 'rlm-desc', text: meta.desc || '' })
            ]);
            var ico = b.querySelector('.rlm-ico');
            if (LOGOS[meta.icon]) {
                var img = el('img', { src: LOGO_BASE + LOGOS[meta.icon], alt: '' });
                img.addEventListener('error', function () { ico.innerHTML = ICONS[meta.icon] || ICONS.database; });
                ico.appendChild(img);
            } else {
                ico.innerHTML = ICONS[meta.icon] || ICONS.database;
            }
            if (disabled) { b.disabled = true; b.title = 'Not available for this database type'; }
            else { b.addEventListener('click', onClick); }
            return b;
        }

        function envEnabled(db, env) {
            var plats = TREE[db][env];
            return Object.keys(plats).some(function (p) { return plats[p] !== OFF; });
        }

        function setState(db, env, plat) { state.db = db; state.env = env; state.plat = plat; render(); }

        function renderChips() {
            chips.innerHTML = '';
            [['db', DB], ['env', ENV], ['plat', PLAT]].forEach(function (pair, i) {
                var key = pair[0];
                if (!state[key]) { return; }
                var b = el('button', { type: 'button', 'class': 'rlm-chip', title: 'Go back to this step', text: pair[1][state[key]].name });
                b.addEventListener('click', function () {
                    if (i === 0) { setState(null, null, null); }
                    else if (i === 1) { setState(state.db, null, null); }
                    else { setState(state.db, state.env, null); }
                });
                chips.appendChild(b);
            });
        }

        function stepTitle(text) { return el('h3', { 'class': 'rlm-step', text: text }); }

        function render() {
            renderChips();
            body.innerHTML = '';
            var grid = el('div', { 'class': 'rlm-grid' });

            if (!state.db) {
                body.appendChild(stepTitle('What is your database type?'));
                ['mysql', 'postgresql', 'mariadb', 'other'].forEach(function (k) {
                    grid.appendChild(card(DB[k], function () { setState(k, null, null); }, false));
                });
                body.appendChild(grid);
                return;
            }
            if (DB[state.db].leaf) { renderSupport(); return; }

            if (!state.env) {
                body.appendChild(stepTitle('Where is your ' + DB[state.db].name + ' server running?'));
                Object.keys(TREE[state.db]).forEach(function (k) {
                    grid.appendChild(card(ENV[k], function () { setState(state.db, k, null); }, !envEnabled(state.db, k)));
                });
                body.appendChild(grid);
                return;
            }
            if (!state.plat) {
                body.appendChild(stepTitle('Choose your platform'));
                var plats = TREE[state.db][state.env];
                Object.keys(plats).forEach(function (k) {
                    var leaf = plats[k];
                    var meta = { name: PLAT[k].name, icon: PLAT[k].icon, desc: leaf && leaf.type === 'link' ? 'Opens the installation guide' : (leaf && leaf.type === 'support' ? 'Contact support' : (leaf ? 'Copy-paste install script' : 'Not available')) };
                    grid.appendChild(card(meta, function () { setState(state.db, state.env, k); }, leaf === OFF));
                });
                body.appendChild(grid);
                return;
            }

            var leaf = TREE[state.db][state.env][state.plat];
            if (leaf.type === 'script') { renderScript(leaf); }
            else if (leaf.type === 'link') { renderLink(leaf); }
            else { renderSupport(); }
        }

        function backButton() {
            var b = el('button', { type: 'button', 'class': 'btn btn-default btn-sm', text: 'Back' });
            b.addEventListener('click', function () {
                if (state.plat) { setState(state.db, state.env, null); }
                else if (state.env) { setState(state.db, null, null); }
                else { setState(null, null, null); }
            });
            return el('div', { 'class': 'rlm-back' }, [b]);
        }

        function renderLink(leaf) {
            var title = PLAT[state.plat].name + ' installation';
            body.appendChild(stepTitle(title));
            var wrap = el('div', { 'class': 'rlm-result', style: 'text-align:center;' }, [
                el('p', { text: 'This setup is covered by a step-by-step guide that includes the installation commands. Use your Public API Key shown above.' }),
                el('p', {}, [el('a', { 'class': 'btn btn-primary', href: leaf.url, target: '_blank', rel: 'noopener', text: 'Open the installation guide' })])
            ]);
            body.appendChild(wrap);
            body.appendChild(backButton());
        }

        function renderSupport() {
            body.appendChild(stepTitle('We will help you connect this server'));
            var ta = el('textarea', { 'class': 'form-control', rows: '5', maxlength: String(MAX_MESSAGE), placeholder: 'Tell us about your database (type, version, where it runs) and what you need.' });
            var send = el('a', { 'class': 'btn btn-primary disabled', href: '#', text: 'Send a message' });
            function sync() {
                var v = ta.value.trim();
                if (v === '') { send.className = 'btn btn-primary disabled'; send.href = '#'; return; }
                send.className = 'btn btn-primary';
                // step=2 only works together with a department; without one the client picks it on the first step.
                send.href = TICKET_URL + '?' + (TICKET_DEPT > 0 ? 'step=2&deptid=' + TICKET_DEPT + '&' : '')
                    + 'subject=' + encodeURIComponent('Releem: connect a database server')
                    + '&message=' + encodeURIComponent(v.substring(0, MAX_MESSAGE));
            }
            ta.addEventListener('input', sync);
            send.addEventListener('click', function (e) { if (/disabled/.test(send.className)) { e.preventDefault(); } });
            var wrap = el('div', { 'class': 'rlm-result' }, [
                el('label', { text: 'Message' }), ta,
                el('div', { style: 'margin-top:12px;' }, [send]),
                el('p', { 'class': 'rlm-help', style: 'margin-top:12px;' }, [
                    document.createTextNode('Or follow the '),
                    el('a', { href: MANUAL_URL, target: '_blank', rel: 'noopener', text: 'manual installation documentation' }),
                    document.createTextNode('.')
                ])
            ]);
            body.appendChild(wrap);
            body.appendChild(backButton());
        }

        // ---- script generation -------------------------------------------------
        function shQuote(v) { return /^[A-Za-z0-9._\-]+$/.test(v) ? '"' + v + '"' : "'" + v.replace(/'/g, "'\\''") + "'"; }
        function psQuote(v) { return /^[A-Za-z0-9._\-]+$/.test(v) ? '"' + v + '"' : "'" + v.replace(/'/g, "''") + "'"; }

        function parseMemory(raw) {
            raw = (raw || '').trim();
            if (raw === '') { return { value: 0, error: '' }; }
            if (!/^\d+$/.test(raw) || parseInt(raw, 10) < 384) { return { value: null, error: 'Enter a whole number of 384 MB or more, or leave empty for all server memory.' }; }
            return { value: parseInt(raw, 10), error: '' };
        }

        function buildScript(os, db, mem, queryOptimization) {
            if (os === 'whm') {
                return 'bash -c "$(curl -fsSL --proto \'=https\' --tlsv1.2 https://releem.s3.amazonaws.com/v2/whm/whm-install.sh)" --api-key=' + shQuote(API_KEY);
            }
            if (os === 'windows') {
                var ps = ['$env:RELEEM_DB_MEMORY_LIMIT=' + mem, '$env:RELEEM_API_KEY=' + psQuote(API_KEY), '$env:RELEEM_CRON_ENABLE=1'];
                if (queryOptimization) { ps.push('$env:RELEEM_QUERY_OPTIMIZATION="true"'); }
                ps.push('iwr -useb https://releem.s3.amazonaws.com/v2/install.ps1 | iex');
                return ps.join('; ');
            }
            var parts = [db === 'postgresql' ? 'RELEEM_PG_TYPE=1' : 'RELEEM_MYSQL_TYPE=1',
                         'RELEEM_DB_MEMORY_LIMIT=' + mem,
                         'RELEEM_API_KEY=' + shQuote(API_KEY),
                         'RELEEM_CRON_ENABLE=1'];
            if (queryOptimization) { parts.push('RELEEM_QUERY_OPTIMIZATION=true'); }
            parts.push('bash -c "$(curl -fsSL --proto \'=https\' --tlsv1.2 https://releem.s3.amazonaws.com/v2/install.sh)"');
            return parts.join(' ');
        }

        function field(label, control, help) {
            var kids = [el('label', { text: label }), control];
            if (help) { kids.push(el('div', { 'class': 'rlm-help', text: help })); }
            return el('div', {}, kids);
        }

        function renderScript(leaf) {
            body.appendChild(stepTitle(PLAT[state.plat].name + ' installation'));

            var mem = el('input', { type: 'text', 'class': 'form-control', placeholder: 'Default', inputmode: 'numeric' });
            var memErr = el('div', { 'class': 'rlm-err' });
            var workload = el('select', { 'class': 'form-control' }, [
                el('option', { value: 'default', text: 'Default' }),
                el('option', { value: 'over30', text: 'Over 30 databases' })
            ]);
            var query = el('select', { 'class': 'form-control' }, [
                el('option', { value: 'default', text: 'Default' }),
                el('option', { value: 'enabled', text: 'Enabled' }),
                el('option', { value: 'disabled', text: 'Disabled' })
            ]);
            var memField = field('Memory limit, MB', mem, 'Minimum 384 MB. Leave empty to use all server memory.');
            memField.appendChild(memErr);

            var code = el('code', { text: '' });
            var pre = el('pre', { 'class': 'rlm-code' }, [code]);
            var copy = el('button', { type: 'button', 'class': 'btn btn-primary', text: 'Copy Installation Script' });
            var note = el('span', { 'class': 'rlm-help', style: 'margin-left:10px;' });

            function update() {
                var m = leaf.os === 'whm' ? { value: 0, error: '' } : parseMemory(mem.value);
                memErr.textContent = m.error;
                if (workload.value === 'over30') { query.value = 'disabled'; query.disabled = true; }
                else { query.disabled = false; }
                var qo = query.value !== 'disabled';
                if (m.value === null) { code.textContent = ''; copy.disabled = true; return; }
                copy.disabled = false;
                code.textContent = buildScript(leaf.os, state.db, m.value, qo);
            }
            [mem, workload, query].forEach(function (c) { c.addEventListener('input', update); c.addEventListener('change', update); });

            copy.addEventListener('click', function () {
                var text = code.textContent;
                function done(ok) { note.textContent = ok ? 'Copied.' : 'Copy failed, select the command and copy it manually.'; setTimeout(function () { note.textContent = ''; }, 3000); }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
                } else {
                    var r = document.createRange(); r.selectNodeContents(code);
                    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
                    try { done(document.execCommand('copy')); } catch (e) { done(false); }
                }
            });

            var form = el('div', { 'class': 'rlm-form' }, [
                memField,
                field('Workload profile', workload, 'Choose "Over 30 databases" for servers hosting many databases; it turns Query & Schema recommendations off.'),
                field('Query & Schema recommendations', query, 'Needed for query and schema optimization recommendations. Can cause slowdowns on servers with more than 30 databases.')
            ]);

            var hint = leaf.os === 'windows'
                ? 'Run this command in PowerShell as Administrator on your database server.'
                : (leaf.os === 'whm' ? 'Run this command as root on your cPanel/WHM server. After installation open WHM > Plugins > Releem Database Advisor.' : 'Run this command as root on your database server.');
            var extra = [];
            if (leaf.os === 'whm') {
                extra.push(el('p', { 'class': 'rlm-help', text: 'The WHM installer sets the memory limit automatically (half of server RAM) and always enables Query & Schema recommendations, so no extra options are needed.' }));
            }
            if (leaf.docs) {
                extra.push(el('p', { 'class': 'rlm-help' }, [document.createTextNode('Need more detail? See the '), el('a', { href: leaf.docs, target: '_blank', rel: 'noopener', text: 'installation guide' }), document.createTextNode('.')]));
            }
            body.appendChild(el('div', { 'class': 'rlm-result' }, [
                leaf.os === 'whm' ? el('span') : form,
                el('p', { text: hint, style: 'margin-bottom:8px;' }),
                pre,
                el('div', {}, [copy, note])
            ].concat(extra)));
            body.appendChild(backButton());
            update();
        }

        render();
    })();
    </script>
    {/literal}

    <div class="panel panel-default">
        <div class="panel-heading" style="margin-top: 16px;"><strong>Documentation</strong></div>
        <div class="panel-body">
            <p style="margin: 0 0 12px 0;">Use these references for setup and troubleshooting:</p>
            <ul>
                <li><a href="{$releemDocsCpanelTroubleshootingUrl|escape}" target="_blank" rel="noopener">cPanel/WHM checks and troubleshooting</a></li>
                <li><a href="{$releemDocsMysqlPermissionsUrl|escape}" target="_blank" rel="noopener">MySQL permissions reference</a></li>
                <li><a href="{$releemDocsLinuxManualUrl|escape}" target="_blank" rel="noopener">Manual Linux installation</a></li>
            </ul>
        </div>
    </div>
{elseif !$releemServiceActive}
    <div class="alert alert-warning">
        This service is not active, so Releem installation details are not available. Please contact support if you need access.
    </div>
{else}
    <div class="alert alert-warning">
        Releem installation details are not available yet. Please wait until provisioning completes or contact support.
    </div>
{/if}
</div>
