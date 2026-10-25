<?php

define('WHMCS', true);

require_once __DIR__ . '/../modules/servers/releem/releem.php';

function check($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

// Endpoint host allowlist: the partner token only goes to releem.com hosts.
foreach (['api2.releem.com', 'API2.Releem.com.', 'api2.dev.releem.com:8443', 'releem.com'] as $host) {
    $built = releem_server_build_endpoint_from_server($host, '', 'on');
    check($built['error'] === '', 'Expected host to be allowed: ' . $host);
}
foreach (['127.0.0.1', '169.254.169.254', 'localhost', 'api2.releem.com.evil.com', 'evilreleem.com', '[::1]', '2130706433', 'user@api2.releem.com'] as $host) {
    $built = releem_server_build_endpoint_from_server($host, '', 'on');
    check($built['error'] !== '', 'Expected host to be refused: ' . $host);
}
foreach (['https://evil.com', 'https://127.0.0.1', 'https://api2.releem.com@evil.com', 'http://api2.releem.com'] as $url) {
    $refused = false;
    try {
        ReleemServerApi::normalizeEndpoint($url);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    check($refused, 'Expected endpoint to be refused: ' . $url);
}
check(ReleemServerApi::normalizeEndpoint('https://api2.releem.com/') === 'https://api2.releem.com', 'Expected trailing slash to be trimmed.');
check(ReleemServerApi::normalizeEndpoint('') === 'https://api2.releem.com', 'Expected the default endpoint for an empty value.');

// Log redaction: secrets, personal data and email addresses inside free text.
releem_server_register_secret('tok_abcdef123');
$redacted = releem_server_redact([
    'email' => 'a@b.com',
    'name' => 'Bob',
    'payload' => ['subscription_email' => 'a@b.com', 'text' => 'tok_abcdef123 mail bob@example.org'],
]);
check($redacted['email'] === '[REDACTED]' && $redacted['name'] === '[REDACTED]', 'Expected email and name to be redacted.');
check($redacted['payload']['subscription_email'] === '[REDACTED]', 'Expected subscription_email to be redacted.');
check(strpos($redacted['payload']['text'], 'bob@') === false && strpos($redacted['payload']['text'], 'tok_') === false, 'Expected inline email and secret to be scrubbed.');

// Strict decryption: an undecryptable database value is never used as the token.
if (!function_exists('decrypt')) {
    function decrypt($value)
    {
        return "\x01\x02garbage";
    }
}
check(releem_server_decrypt_secret('ciphertextABC==', true) === '', 'Expected an undecryptable stored value to be rejected.');
check(releem_server_decrypt_secret('plain.token-1') === 'plain.token-1', 'Expected a plain-text parameter to be kept.');

// Customer lookup: a full page without an exact match must not read as "customer does not exist".
function releem_test_lookup($response)
{
    ReleemServerApi::setTransport(function ($method, $url, $headers, $body) use ($response) {
        return ['status' => 200, 'body' => json_encode($response)];
    });

    return (new ReleemServerApi('abcdefghijk'))->getCustomerByEmail('me@x.com');
}
function releem_test_customers($count)
{
    $list = [];
    for ($i = 0; $i < $count; $i++) {
        $list[] = ['id' => 'c' . $i, 'email' => 'x' . $i . '@other.com'];
    }

    return $list;
}

check(releem_test_lookup(releem_test_customers(3)) === null, 'Expected a few non-matching customers to read as not found.');

$guarded = false;
try {
    releem_test_lookup(releem_test_customers(ReleemServerApi::LOOKUP_PAGE_GUARD));
} catch (Exception $e) {
    $guarded = true;
}
check($guarded, 'Expected a full page without an exact match to be refused.');

$full = releem_test_customers(ReleemServerApi::LOOKUP_PAGE_GUARD);
$full[4]['email'] = 'ME@x.com';
$found = releem_test_lookup($full);
check($found && $found['id'] === 'c4', 'Expected an exact match on a full page to be found.');

$duplicateMessage = '';
try {
    releem_test_lookup([['id' => 1, 'email' => 'me@x.com'], ['id' => 2, 'email' => 'me@x.com']]);
} catch (Exception $e) {
    $duplicateMessage = $e->getMessage();
}
check($duplicateMessage !== '' && strpos($duplicateMessage, '@') === false, 'Expected the duplicate error to be raised without the email address.');

ReleemServerApi::setTransport(null);

echo "ok\n";
