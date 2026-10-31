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

// The subscription ID is always the WHMCS service ID: gateway subscription IDs live in a different number space
// and could collide with an unrelated service.
$serviceWithGatewaySubscriptionId = (object) [
    'id' => 42,
    'subscriptionid' => '1001',
];

assertSameValue(
    42,
    releem_server_get_numeric_subscription_id($serviceWithGatewaySubscriptionId, []),
    'Expected the WHMCS service ID to be used even when a numeric gateway subscriptionid exists.'
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

assertSameValue(
    0,
    releem_server_get_numeric_subscription_id((object) ['id' => '1234567890'], []),
    'Expected IDs longer than 9 digits to be rejected.'
);

echo "ok\n";
