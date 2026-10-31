<?php

namespace WHMCS\Database {
    class Capsule
    {
        public static $mode = 'success';
        public static $releases = 0;
        public static function connection() { return new self(); }
        public function getDatabaseName() { return 'whmcs-test'; }
        public static function select($sql, $args)
        {
            if (strpos($sql, 'RELEASE_LOCK') !== false) { self::$releases++; return []; }
            if (self::$mode === 'exception') { throw new \Exception('GET_LOCK unavailable'); }
            if (self::$mode === 'empty') { return []; }
            return [(object) ['acquired'=>self::$mode === 'null' ? null : (self::$mode === 'timeout' ? 0 : 1)]];
        }
    }
}
namespace {
    define('WHMCS', true);
    require __DIR__ . '/../modules/servers/releem/releem.php';
    $failures = [];
    foreach (['exception', 'empty', 'null', 'timeout'] as $mode) {
        \WHMCS\Database\Capsule::$mode = $mode;
        $calls = 0;
        $caught = false;
        try {
            releem_server_with_client_lock(7, function () use (&$calls) { $calls++; });
        } catch (\Throwable $e) { $caught = true; }
        if (!$caught || $calls !== 0 || \WHMCS\Database\Capsule::$releases !== 0) {
            $failures[] = 'Must refuse sync without an acquired lock: ' . $mode;
        }
    }
    \WHMCS\Database\Capsule::$mode = 'success';
    $calls = 0;
    $result = releem_server_with_client_lock(7, function () use (&$calls) { $calls++; return 'success'; });
    if ($result !== 'success' || $calls !== 1 || \WHMCS\Database\Capsule::$releases !== 1) {
        $failures[] = 'An acquired lock must run the sync once and be released';
    }
    $caught = false;
    try {
        releem_server_with_client_lock(7, function () { throw new \Exception('Sync failed'); });
    } catch (\Throwable $e) { $caught = $e->getMessage() === 'Sync failed'; }
    if (!$caught || \WHMCS\Database\Capsule::$releases !== 2) { $failures[] = 'A failing sync must still release its lock'; }
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "ok\n";
}
