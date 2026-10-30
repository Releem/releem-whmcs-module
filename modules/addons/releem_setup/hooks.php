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
    // Original state belongs to the actual element, including rows replaced by WHMCS AJAX.
    var saved = new WeakMap();
    var observer = null;

    function row(el) { return el ? el.closest('tr') : null; }

    function read(el, key) {
        if (key === 'display') { return el.style.display; }
        if (key === 'html') { return el.innerHTML; }
        return el.getAttribute(key);
    }

    function write(el, key, value) {
        if (key === 'display') { el.style.display = value; }
        else if (key === 'html') { el.innerHTML = value; }
        else if (value === null) { el.removeAttribute(key); }
        else { el.setAttribute(key, value); }
    }

    function adjust(el, key, value) {
        if (!el) { return; }
        var original = saved.get(el) || {};
        if (!Object.prototype.hasOwnProperty.call(original, key)) { original[key] = read(el, key); }
        saved.set(el, original);
        if (read(el, key) !== value) { write(el, key, value); }
    }

    function restore(el) {
        if (!el || !saved.has(el)) { return; }
        var original = saved.get(el);
        Object.keys(original).forEach(function (key) { write(el, key, original[key]); });
        saved.delete(el);
    }

    function watch() {
        // The table and type selector can both be replaced; the document body remains stable.
        if (observer && document.body) { observer.observe(document.body, {childList: true, subtree: true}); }
    }

    function apply() {
        if (observer) { observer.disconnect(); }
        var select = document.getElementById('inputServerType');
        if (!select) { watch(); return; }
        var username = row(document.getElementById('inputUsername'));
        var password = document.getElementById('inputPassword');
        var passwordRow = row(password);
        var hashRow = row(document.getElementById('serverHash'));
        var hostname = document.getElementById('inputHostname');
        var label = passwordRow ? passwordRow.querySelector('td.fieldlabel') : null;

        if (select.value === TYPE) {
            adjust(username, 'display', 'none');
            adjust(hashRow, 'display', 'none');
            adjust(label, 'html', LABEL);
            adjust(password, 'title', 'Releem partner API token');
            if (hostname && !hostname.getAttribute('placeholder')) { adjust(hostname, 'placeholder', 'api2.releem.com'); }
        } else {
            [username, hashRow, label, password, hostname].forEach(restore);
        }
        watch();
    }

    function init() {
        document.addEventListener('change', function (event) {
            if (event.target && event.target.id === 'inputServerType') { apply(); }
        });
        if (window.MutationObserver) { observer = new MutationObserver(apply); }
        apply();
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
HTML;
});
