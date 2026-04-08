<?php

define('WHMCS', true);

require_once __DIR__ . '/../modules/servers/releem/releem.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$serviceWithSubscriptionId = (object) [
    'id' => 42,
    'subscriptionid' => '1001',
];

assertSameValue(
    1001,
    releem_server_get_numeric_subscription_id($serviceWithSubscriptionId, []),
    'Expected explicit subscriptionid to be used when present.'
);

$serviceWithoutSubscriptionId = (object) [
    'id' => 42,
    'subscriptionid' => '',
];

assertSameValue(
    42,
    releem_server_get_numeric_subscription_id($serviceWithoutSubscriptionId, []),
    'Expected WHMCS service ID to be used when subscriptionid is empty.'
);

echo "ok\n";
