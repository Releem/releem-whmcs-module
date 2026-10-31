<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// WHMCS loads this file while the Releem Setup addon is active. The Releem server module has no server records,
// so WHMCS does not load hooks shipped inside modules/servers/releem.
if (defined('RELEEM_HOOKS_REGISTERED')) {
    return;
}
define('RELEEM_HOOKS_REGISTERED', true);

$releemServerModule = dirname(__DIR__, 2) . '/servers/releem/releem.php';
if (!is_file($releemServerModule)) {
    return;
}
require_once $releemServerModule;

// Keep Releem's subscription window (valid_to) in step with WHMCS renewals.
add_hook('InvoicePaid', 1, function (array $vars) {
    if (!empty($vars['invoiceid'])) {
        releem_server_resync_invoice((int) $vars['invoiceid']);
    }
});

// Safety net: re-sync any service whose WHMCS state differs from what was last sent to Releem.
add_hook('DailyCronJob', 1, function () {
    releem_server_reconcile_all();
});

// Server form (Configuration -> System Settings -> Servers): Releem only needs Hostname, Port, Secure and a token.
// WHMCS has no module setting for these labels, so the form is adjusted client-side while the type is "Releem":
// Username and Access Hash rows are hidden and "Password" is shown as "Partner API Token".
add_hook('AdminAreaFooterOutput', 1, function (array $vars) {
    if (!isset($vars['filename']) || $vars['filename'] !== 'configservers') {
        return '';
    }

    return <<<'HTML'
<script type="text/javascript">
(function () {
    var TYPE = 'releem';
    var LABEL = 'Partner API Token';
    var saved = {};
    var observer = null;

    function row(el) { return el ? el.closest('tr') : null; }

    function apply() {
        var select = document.getElementById('inputServerType');
        if (!select) { return; }
        var wanted = select.value === TYPE;

        var username = row(document.getElementById('inputUsername'));
        var password = document.getElementById('inputPassword');
        var passwordRow = row(password);
        var hashRow = row(document.getElementById('serverHash'));
        var hostname = document.getElementById('inputHostname');
        var label = passwordRow ? passwordRow.querySelector('td.fieldlabel') : null;

        // Our own edits must not retrigger the observer.
        if (observer) { observer.disconnect(); }

        if (wanted) {
            if (username) { username.style.display = 'none'; }
            if (hashRow) { hashRow.style.display = 'none'; }
            if (label && label.textContent.trim() !== LABEL) {
                if (saved.label === undefined) { saved.label = label.innerHTML; }
                label.textContent = LABEL;
            }
            if (password) { password.setAttribute('title', 'Releem partner API token'); }
            if (hostname && !hostname.getAttribute('placeholder')) { hostname.setAttribute('placeholder', 'api2.releem.com'); }
        } else {
            if (username) { username.style.display = ''; }
            if (hashRow) { hashRow.style.display = ''; }
            if (label && saved.label !== undefined) { label.innerHTML = saved.label; saved.label = undefined; }
            if (password) { password.removeAttribute('title'); }
            if (hostname && hostname.getAttribute('placeholder') === 'api2.releem.com') { hostname.removeAttribute('placeholder'); }
        }

        watch();
    }

    function watch() {
        var select = document.getElementById('inputServerType');
        var table = select ? select.closest('table') : null;
        if (window.MutationObserver && table) {
            if (!observer) { observer = new MutationObserver(apply); }
            observer.observe(table, {childList: true, subtree: true});
        }
    }

    function init() {
        var select = document.getElementById('inputServerType');
        if (!select) { return; }
        select.addEventListener('change', apply);
        apply();
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
HTML;
});
