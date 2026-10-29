<?php

namespace WHMCS\Database {
    // In-memory rows for the small Capsule query surface used by these regressions.
    class Capsule
    {
        public static function table($table)
        {
            return new \ReleemRegressionQuery(explode(' ', $table)[0]);
        }
    }
}

namespace {
    define('WHMCS', true);

    class ReleemRegressionQuery
    {
        private $table;
        private $filters = [];
        private $withMeta = false;

        public function __construct($table) { $this->table = $table; }
        public function join($table, ...$args)
        {
            if (strpos($table, 'mod_releem_service_meta') === 0) { $this->withMeta = true; }
            return $this;
        }
        public function where($key, $operator, $value = null)
        {
            if (func_num_args() === 2) { $value = $operator; $operator = '='; }
            $this->filters[] = [preg_replace('/^.*\./', '', $key), $operator, $value];
            return $this;
        }
        public function whereNotNull($key) { return $this->where($key, '!=', null); }
        public function value($key) { $row = $this->first(); return $row ? ($row->$key ?? null) : null; }
        public function orderBy(...$args) { return $this; }
        public function select(...$args) { return $this; }
        public function first()
        {
            $rows = $GLOBALS['regressionRows'][$this->table] ?? [];
            usort($rows, function ($a, $b) { return ($a['id'] ?? 0) <=> ($b['id'] ?? 0); });
            foreach ($rows as $row) {
                if ($this->withMeta) {
                    if (!isset($GLOBALS['regressionMeta'][$row['id']])) { continue; }
                    $row = array_merge($row, $GLOBALS['regressionMeta'][$row['id']]);
                }
                foreach ($this->filters as [$key, $operator, $value]) {
                    $equal = ($row[$key] ?? null) == $value;
                    if (($operator === '!=' && $equal) || ($operator === '=' && !$equal) || ($operator === '>' && ($row[$key] ?? 0) <= $value)) { continue 2; }
                }
                return (object) $row;
            }
            return null;
        }
    }

    function decrypt($value)
    {
        if ($value === 'encrypted-valid-token') { return 'plain.token-1'; }
        if ($value === 'encrypted-throws') { throw new \Exception('Cannot decrypt'); }
        return "\x01\x02garbage";
    }
    function releem_server_ensure_meta_table() { return true; }
    function releem_server_get_service_meta($id) { return $GLOBALS['regressionMeta'][$id] ?? []; }
    function releem_server_set_service_meta($id, array $values)
    {
        if (!empty($GLOBALS['failAdoptionWrite']) && !empty($values['adopted'])) { return false; }
        $GLOBALS['regressionMeta'][$id] = array_merge($GLOBALS['regressionMeta'][$id] ?? [], $values);
        return true;
    }
    function releem_server_get_server_count_option_id($params, $productId = 0) { return 0; }
    function releem_server_get_selected_server_count($params, $id, $optionId) { return 1; }
    function releem_server_subscription_id_in_use($subscriptionId, $id) { return false; }
    function releem_server_get_customer_id($id, $productId) { return $GLOBALS['regressionMeta'][$id]['customer_id'] ?? ''; }
    function releem_server_sync_public_api_key($id, $productId, $response, $adopted, $client) {}
    function releem_server_get_invoice_id($params, $serviceId = 0) { return 0; }

    require __DIR__ . '/../modules/servers/releem/releem.php';

    $failures = [];
    function regressionCheck($condition, $message)
    {
        if (!$condition) { $GLOBALS['failures'][] = $message; }
    }
    function lifecycleFixture($id, $otherId, $otherProvisioned)
    {
        $base = ['userid'=>7, 'packageid'=>0, 'server'=>1, 'servertype'=>'releem', 'domainstatus'=>'Active',
            'regdate'=>'2026-10-01', 'nextduedate'=>'2026-11-01', 'amount'=>0];
        $GLOBALS['regressionRows'] = [
            'tblhosting'=>[array_merge($base, ['id'=>$id]), array_merge($base, ['id'=>$otherId])],
            'tblclients'=>[['id'=>7, 'email'=>'test@example.invalid', 'firstname'=>'Test', 'lastname'=>'User']],
            'tblservers'=>[['id'=>1, 'password'=>'encrypted-valid-token', 'hostname'=>'api2.releem.com', 'secure'=>'on']],
        ];
        $GLOBALS['regressionMeta'] = [$id=>['customer_id'=>'customer-test', 'subscription_id'=>$id, 'status'=>'active']];
        if ($otherProvisioned) {
            $GLOBALS['regressionMeta'][$otherId] = ['customer_id'=>'customer-test', 'subscription_id'=>$otherId, 'status'=>'active'];
        }
        $GLOBALS['regressionCalls'] = [];
        ReleemServerApi::setTransport(function ($method, $url, $headers, $body) {
            $GLOBALS['regressionCalls'][] = ['method'=>$method, 'payload'=>json_decode($body ?? 'null', true)];
            return ['status'=>200, 'body'=>'{}'];
        });
        return ['serviceid'=>$id, 'serverpassword'=>'plain.token-1', 'serverhostname'=>'api2.releem.com',
            'serversecure'=>'on', 'paymentmethodname'=>'Bank Transfer'];
    }

    foreach (['Suspended', 'Cancelled'] as $status) {
        foreach ([[1, 42, false], [1, 42, true], [42, 1, false]] as [$id, $otherId, $provisioned]) {
            $params = lifecycleFixture($id, $otherId, $provisioned);
            $result = releem_server_sync_service_unlocked($params, $status);
            regressionCheck($result === 'success', "$status must succeed for service $id");
            regressionCheck(count($GLOBALS['regressionCalls']) === 1
                && $GLOBALS['regressionCalls'][0]['method'] === 'PATCH'
                && $GLOBALS['regressionCalls'][0]['payload']['subscription']['status'] === 'deleted',
                "$status must deactivate service $id despite duplicate $otherId (provisioned=$provisioned)");
        }
    }
    $params = lifecycleFixture(42, 1, true);
    regressionCheck(releem_server_sync_service_unlocked($params, 'Suspended') === 'success'
        && count($GLOBALS['regressionCalls']) === 0, 'Suspending a duplicate must preserve the lower-ID provisioned owner');
    foreach ([['customer_id'=>'', 'status'=>'active'], ['customer_id'=>'customer-test', 'status'=>'deleted']] as $meta) {
        $params = lifecycleFixture(42, 1, true);
        $GLOBALS['regressionMeta'][1] = $meta;
        regressionCheck(releem_server_sync_service_unlocked($params, 'Suspended') === 'success'
            && count($GLOBALS['regressionCalls']) === 1, 'An empty customer link or deleted subscription cannot suppress deactivation');
    }
    $params = lifecycleFixture(42, 1, true);
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') !== 'success'
        && count($GLOBALS['regressionCalls']) === 0, 'Active duplicate provisioning must remain blocked');

    foreach (['ciphertextABC==', 'encrypted-throws'] as $broken) {
        $params = lifecycleFixture(1, 42, false);
        $GLOBALS['regressionRows']['tblservers'][0]['password'] = $broken;
        $params['serverpassword'] = $broken;
        $credentials = releem_server_resolve_credentials($params, (object) ['server'=>1]);
        regressionCheck($credentials['error'] !== '' && $credentials['key'] === '', 'Reject undecryptable stored and parameter credentials: ' . $broken);
        regressionCheck(releem_server_sync_service_unlocked($params, 'Suspended') !== 'success'
            && count($GLOBALS['regressionCalls']) === 0, 'Never send an undecryptable parameter token to the API');
    }
    $params = lifecycleFixture(1, 42, false);
    $params['serverpassword'] = 'ciphertextABC==';
    $credentials = releem_server_resolve_credentials($params, (object) ['server'=>1]);
    regressionCheck($credentials['key'] === 'plain.token-1' && $credentials['error'] === '', 'Use the valid stored token over a broken parameter');
    $params['serverpassword'] = 'plain.token-1';
    $credentials = releem_server_resolve_credentials($params, (object) ['server'=>1]);
    regressionCheck($credentials['key'] === 'plain.token-1' && $credentials['error'] === '', 'Preserve normal decrypted WHMCS parameter support');
    $GLOBALS['regressionRows']['tblservers'] = [];
    $params['serverpassword'] = 'ciphertextABC==';
    $credentials = releem_server_resolve_credentials($params, (object) []);
    regressionCheck($credentials['error'] !== '' && $credentials['key'] === '', 'Reject ambiguous undecryptable parameter without a server record');
    $params['serverpassword'] = 'encrypted-valid-token';
    $credentials = releem_server_resolve_credentials($params, (object) []);
    regressionCheck($credentials['key'] === 'plain.token-1' && $credentials['error'] === '', 'Accept successfully decrypted parameters when no server record is available');
    $params['serverpassword'] = 'plain.token-1';
    regressionCheck(releem_TestConnection($params)['success'], 'Test Connection must still accept an unsaved plain-text token');
    ReleemServerApi::setTransport(null);

    foreach (['Suspended', 'Cancelled', 'Terminated'] as $siblingStatus) {
        $params = lifecycleFixture(1, 42, true);
        unset($GLOBALS['regressionMeta'][1]);
        $GLOBALS['regressionRows']['tblhosting'][1]['domainstatus'] = $siblingStatus;
        regressionCheck(releem_server_sync_service_unlocked($params, 'Cancelled') === 'success'
            && count($GLOBALS['regressionCalls']) === 0
            && !isset($GLOBALS['regressionMeta'][1]), 'An unlinked service must not adopt or update a ' . $siblingStatus . ' sibling on delete');
    }

    foreach (['Free Account', 'One Time'] as $cycle) {
        $params = lifecycleFixture(1, 42, false);
        $GLOBALS['regressionRows']['tblhosting'][0]['billingcycle'] = $cycle;
        $GLOBALS['regressionRows']['tblhosting'][0]['nextduedate'] = '0000-00-00';
        regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success'
            && $GLOBALS['regressionCalls'][0]['payload']['subscription']['valid_to'] === '2036-10-01T23:59:59Z',
            'Sync must use the same stable registration-based window as reconcile for ' . $cycle);
        $params['regdate'] = '2026-12-01';
        regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success'
            && $GLOBALS['regressionCalls'][1]['payload']['subscription']['valid_to'] === '2036-12-01T23:59:59Z',
            'An explicit registration date must override the stored date for ' . $cycle);
        unset($params['regdate']);
        $GLOBALS['regressionRows']['tblhosting'][0]['regdate'] = '0000-00-00';
        $GLOBALS['regressionCalls'] = [];
        regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'Service registration date is missing or invalid'
            && count($GLOBALS['regressionCalls']) === 0, 'A missing registration date must not create a moving window');
    }

    // The first successfully provisioned service owns the subscription, regardless of ID order.
    $params = lifecycleFixture(42, 1, false);
    $GLOBALS['regressionRows']['tblhosting'][1]['domainstatus'] = 'Pending';
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success', 'Provision the higher-ID service first');
    $params['serviceid'] = 1;
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') !== 'success'
        && count($GLOBALS['regressionCalls']) === 1, 'A later lower-ID service must not take over the provisioned owner');
    $GLOBALS['regressionRows']['tblhosting'][1]['domainstatus'] = 'Active';
    $params['serviceid'] = 42;
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success', 'An unprovisioned lower-ID Active duplicate must not block its owner');
    $params['serviceid'] = 1;
    $GLOBALS['regressionCalls'] = [];
    regressionCheck(releem_server_sync_service_unlocked($params, 'Suspended') === 'success'
        && count($GLOBALS['regressionCalls']) === 0, 'Ending a lower-ID duplicate must preserve the higher-ID owner');

    $params = lifecycleFixture(42, 1, false);
    $GLOBALS['regressionMeta'] = [];
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') !== 'success'
        && count($GLOBALS['regressionCalls']) === 0, 'Unprovisioned Active services must still choose one candidate deterministically');

    $params = lifecycleFixture(1, 42, false);
    $GLOBALS['regressionMeta'] = [];
    $GLOBALS['regressionRows']['tblhosting'][1]['domainstatus'] = 'Suspended';
    ReleemServerApi::setTransport(function () {
        return ['status'=>200, 'body'=>json_encode([['id'=>'existing-customer', 'email'=>'test@example.invalid']])];
    });
    regressionCheck(strpos(releem_server_sync_service_unlocked($params, 'Active'),
        'enable "Link existing Releem customers by email"') !== false, 'Adoption error must use the exact addon setting label');
    ReleemServerApi::setTransport(null);

    $params = lifecycleFixture(10, 5, false);
    $GLOBALS['regressionMeta'][10]['status'] = 'deleted';
    $GLOBALS['regressionRows']['tblhosting'][0]['domainstatus'] = 'Suspended';
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success', 'A suspended provisioned owner must retain priority over an unprovisioned lower-ID duplicate');
    $GLOBALS['regressionMeta'][10]['status'] = 'deleted';
    $GLOBALS['regressionRows']['tblhosting'][0]['domainstatus'] = 'Active';
    regressionCheck(releem_server_sync_service_unlocked($params) === 'success', 'Re-sync must restore the former owner');
    $GLOBALS['regressionMeta'][10]['status'] = 'deleted';
    $params['serviceid'] = 5;
    $callsBefore = count($GLOBALS['regressionCalls']);
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') !== 'success'
        && count($GLOBALS['regressionCalls']) === $callsBefore, 'A lower-ID duplicate must not steal a provisioned owner awaiting restoration');

    $params = lifecycleFixture(1, 42, true);
    $GLOBALS['regressionMeta'][1]['status'] = 'deleted';
    $GLOBALS['regressionRows']['tblhosting'][0]['domainstatus'] = 'Suspended';
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') !== 'success'
        && count($GLOBALS['regressionCalls']) === 0, 'A suspended former owner must not steal a different live provisioned owner');
    $GLOBALS['regressionRows']['tblhosting'][0]['domainstatus'] = 'Active';
    $params['serviceid'] = 42;
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success', 'A live owner must not lose to a former lower-ID owner awaiting restoration');

    function adoptionFixture()
    {
        $params = lifecycleFixture(1, 42, false);
        $GLOBALS['regressionMeta'] = [];
        $GLOBALS['regressionRows']['tblhosting'][1]['domainstatus'] = 'Suspended';
        $GLOBALS['regressionRows']['tbladdonmodules'] = [['module'=>'releem_setup', 'setting'=>'allow_email_adoption', 'value'=>'on']];
        ReleemServerApi::setTransport(function ($method, $url, $headers, $body) {
            $GLOBALS['regressionCalls'][] = ['method'=>$method, 'payload'=>json_decode($body ?? 'null', true)];
            $customer = ['id'=>'adopted-customer', 'email'=>'test@example.invalid', 'api_key'=>'public-test-key'];
            return ['status'=>200, 'body'=>json_encode($method === 'GET' ? [$customer] : $customer)];
        });
        return $params;
    }
    $params = adoptionFixture();
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success'
        && $GLOBALS['regressionMeta'][1]['adopted_email_hash'] === hash('sha256', 'test@example.invalid'), 'Adoption must persist the original matched email hash');
    $GLOBALS['regressionRows']['tblclients'][0]['email'] = 'attacker@example.invalid';
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success'
        && $GLOBALS['regressionMeta'][1]['adopted_email_hash'] === hash('sha256', 'test@example.invalid'), 'Later syncs must never replace adoption provenance with the current email');
    $GLOBALS['regressionRows']['tblhosting'][0]['domainstatus'] = 'Suspended';
    $GLOBALS['regressionMeta'][1]['status'] = 'deleted';
    $GLOBALS['regressionRows']['tblhosting'][1]['domainstatus'] = 'Active';
    $params['serviceid'] = 42;
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') === 'success'
        && $GLOBALS['regressionMeta'][42]['adopted_email_hash'] === hash('sha256', 'test@example.invalid'), 'A sibling customer link must retain the original adoption provenance');
    $params = adoptionFixture();
    $GLOBALS['failAdoptionWrite'] = true;
    regressionCheck(releem_server_sync_service_unlocked($params, 'Active') !== 'success'
        && count($GLOBALS['regressionCalls']) === 1 && !isset($GLOBALS['regressionMeta'][1]), 'A failed identity write must stop adoption before caching a key or patching the customer');
    $GLOBALS['failAdoptionWrite'] = false;
    ReleemServerApi::setTransport(null);

    if ($failures) {
        fwrite(STDERR, implode("\n", $failures) . "\n");
        exit(1);
    }
    echo "ok\n";
}
