<?php

define('WHMCS', true);
require_once __DIR__ . '/../modules/servers/releem/releem.php';

$failures = [];
$checks = 0;
function safety_check($ok, $name)
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $name;
    }
}
function safety_api($body, $status = 200, $key = 'qa_partner_token_12345')
{
    ReleemServerApi::setTransport(function () use ($body, $status) {
        return ['status' => $status, 'body' => $body];
    });
    return new ReleemServerApi($key);
}
function safety_throws(callable $call)
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

foreach (['', " \n\t", '{}', '{ "0": {"id":"c1","email":"qa@example.invalid"}}', "{ \n }"] as $body) {
    safety_check(safety_throws(function () use ($body) {
        safety_api($body)->getCustomerByEmail('qa@example.invalid');
    }) !== null, 'Malformed/absent lookup must not prove absence: ' . json_encode($body));
}
safety_check(safety_api('[]')->getCustomerByEmail('qa@example.invalid') === null, 'A genuine empty list permits creation');
foreach (['{"id":"c1","email":"QA@example.invalid"}', '[{"id":"c1","email":"QA@example.invalid"}]'] as $body) {
    safety_check(safety_api($body)->getCustomerByEmail('qa@example.invalid')['id'] === 'c1', 'Exact list/single-object match remains supported');
}
safety_check(safety_api('[{"id":"c2","email":"other@example.invalid"}]')->getCustomerByEmail('qa@example.invalid') === null, 'Short nonmatching list remains supported');
safety_check(safety_api('', 204)->updateCustomer('c1', []) === [], 'Empty successful PATCH remains supported');
safety_check(safety_api('[]')->testConnection(), 'Valid empty-list credential test succeeds');
safety_check(safety_throws(function () { safety_api('{}')->testConnection(); }) !== null, 'Invalid credential-test body is rejected');

foreach (['qa_partner_token_12345', '00000000-1111-2222-3333-444444444444'] as $secret) {
    $prefix = '{"message":"' . str_repeat('x', 499 - strlen($secret) - 12);
    $body = $prefix . $secret . '"}';
    $e = safety_throws(function () use ($body, $secret) { safety_api($body, 500, $secret)->updateCustomer('c1', []); });
    safety_check($e && strpos($e->getMessage(), substr($secret, 0, -1)) === false, 'Known secret is scrubbed before a boundary cuts it');
    safety_check($e && strlen($e->getMessage()) <= 530, 'Sanitized API diagnostics remain bounded');
}
foreach (['{"token":"first\\"REST-SECRET"}', '{"to\\u006ben":"REST-SECRET"}', '{"nested":{"api_key":"REST-SECRET"}}', '{"token":"first\\"REST-SECRET'] as $body) {
    $e = safety_throws(function () use ($body) { safety_api($body, 500)->createCustomer([]); });
    safety_check($e && strpos($e->getMessage(), 'REST-SECRET') === false, 'Escaped, nested and unterminated sensitive JSON values are scrubbed');
}
$e = safety_throws(function () { safety_api('{"message":"bad abc"}', 401, 'abc')->testConnection(); });
safety_check($e && strpos($e->getMessage(), 'abc') === false && strpos($e->getMessage(), 'HTTP 401') !== false, 'Short allowed partner keys are masked and HTTP status preserved');
$e = safety_throws(function () { safety_api('{"message":"Permission denied"}', 403)->testConnection(); });
safety_check($e && strpos($e->getMessage(), 'Permission denied') !== false, 'Useful safe API error reason is preserved');

foreach (['{"to\\u006ben":"REST-SECRET', '{"message":"qa_partn\\u0065r_token_12345'] as $body) {
    $e = safety_throws(function () use ($body) { safety_api($body, 500)->createCustomer([]); });
    safety_check($e && strpos($e->getMessage(), 'REST-SECRET') === false && strpos($e->getMessage(), 'qa_partn') === false,
        'Malformed encoded error bodies cannot disclose reconstructable secrets');
}
foreach (['{"id":[],"email":[]}', '{"id":"","email":""}', '[{"id":{},"email":"qa@example.invalid"}]'] as $body) {
    safety_check(safety_throws(function () use ($body) { safety_api($body)->getCustomerByEmail('qa@example.invalid'); }) !== null,
        'Invalid customer fields cannot prove absence');
}
ReleemServerApi::setTransport(null);
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'ok (' . $checks . " response-safety checks)\n";
