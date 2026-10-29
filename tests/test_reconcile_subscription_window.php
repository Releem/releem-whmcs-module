<?php

namespace WHMCS\Database {
    class Capsule
    {
        public static function table($table) { return new \WindowTestQuery(); }
    }
}
namespace {
    define('WHMCS', true);
    class WindowTestQuery
    {
        private $columns = [];
        public function join(...$args) { return $this; }
        public function where(...$args) { return $this; }
        public function whereIn(...$args) { return $this; }
        public function whereNotNull(...$args) { return $this; }
        public function orderBy(...$args) { return $this; }
        public function select(...$columns) { $this->columns = $columns; return $this; }
        public function get()
        {
            $row = [];
            foreach ($this->columns as $column) {
                $parts = explode(' as ', $column);
                $name = $parts[1] ?? preg_replace('/^.*\./', '', $parts[0]);
                $row[$name] = $GLOBALS['windowRow'][$parts[0]] ?? null;
            }
            return [(object) $row];
        }
    }
    function releem_server_ensure_meta_table() { return true; }
    function releem_server_find_other_active_service_id($id, $clientId, $provisionedOnly = false)
    {
        return $provisionedOnly ? ($GLOBALS['otherWindowOwner'] ?? 0) : ($GLOBALS['otherWindowActive'] ?? 0);
    }
    function releem_server_get_product_option_setting($id) { return 1; }
    function releem_server_get_selected_server_count($params, $id, $optionId) { return 1; }
    function releem_server_resync_service($id)
    {
        $GLOBALS['windowAttempts'][] = $id;
        $GLOBALS['windowRow']['m.valid_to'] = $GLOBALS['expectedWindow'];
        return 'success';
    }
    require __DIR__ . '/../modules/servers/releem/releem.php';
    $failures = [];
    foreach (['Free Account', 'One Time', 'Monthly'] as $cycle) {
        $GLOBALS['windowAttempts'] = [];
        $GLOBALS['expectedWindow'] = $cycle === 'Monthly' ? '2026-11-01T23:59:59Z' : '2036-10-01T23:59:59Z';
        $GLOBALS['windowRow'] = [
            'h.id'=>42, 'h.userid'=>7, 'h.packageid'=>1, 'h.domainstatus'=>'Active',
            'h.regdate'=>'2026-10-01', 'h.billingcycle'=>$cycle,
            'h.nextduedate'=>$cycle === 'Monthly' ? '2026-11-01' : '0000-00-00',
            'm.customer_id'=>'customer-test', 'm.status'=>'active', 'm.valid_to'=>'2020-01-01T23:59:59Z', 'm.number_servers'=>1,
        ];
        if (releem_server_reconcile_all() !== [42=>'success']) { $failures[] = 'Must reconcile an incorrect window for ' . $cycle; }
        if (releem_server_reconcile_all() !== [] || $GLOBALS['windowAttempts'] !== [42]) {
            $failures[] = 'Must converge after one correction for ' . $cycle;
        }
    }
    $GLOBALS['otherWindowActive'] = 1;
    $GLOBALS['windowRow']['m.valid_to'] = '2020-01-01T23:59:59Z';
    if (releem_server_reconcile_all() !== [42=>'success']) {
        $failures[] = 'Reconcile must not skip the higher-ID provisioned owner because of an unprovisioned lower-ID duplicate';
    }
    $GLOBALS['otherWindowOwner'] = 42;
    $GLOBALS['otherWindowActive'] = 42;
    $GLOBALS['windowRow']['h.id'] = 1;
    $GLOBALS['windowRow']['m.status'] = 'deleted';
    if (releem_server_reconcile_all() !== []) {
        $failures[] = 'Reconcile must skip a lower-ID duplicate while a higher-ID provisioned owner exists';
    }
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "ok\n";
}
