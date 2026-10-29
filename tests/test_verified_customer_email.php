<?php

namespace WHMCS\Database {
    class Capsule
    {
        public static function table($table) { return new \EmailTestQuery($table); }
    }
}
namespace WHMCS\User {
    class Client
    {
        public static $record;
        public static function find($id) { return self::$record; }
        public $email = 'customer@example.invalid';
        public $verified = true;
        public $user;
        public $fail = false;
        public function owner()
        {
            if ($this->fail) { throw new \Exception('Owner lookup failed'); }
            return $this->user;
        }
        public function isEmailAddressVerified() { return $this->verified; }
    }
}
namespace {
    define('WHMCS', true);
    class EmailTestQuery
    {
        private $table;
        public function __construct($table) { $this->table = $table; }
        public function where(...$args) { return $this; }
        public function select(...$args) { return $this; }
        public function value($key) { return $GLOBALS['allowAdoption'] ? 'on' : ''; }
        public function first()
        {
            return $this->table === 'tblhosting' ? (object) ['userid'=>1] : $GLOBALS['clientRow'];
        }
    }
    function releem_server_get_service_meta($id) { return ['adopted'=>1]; }
    function releem_server_store_customer_public_api_key($id, $pid, $response) { $GLOBALS['storedKey'] = $response['api_key']; }
    function releem_server_set_product_custom_field_value($id, $pid, $field, $value) { $GLOBALS['storedKey'] = $value; }
    require __DIR__ . '/../modules/servers/releem/releem.php';

    $failures = [];
    function checkEmail($condition, $message) { if (!$condition) { $GLOBALS['failures'][] = $message; } }
    $GLOBALS['allowAdoption'] = false;
    $client = (object) ['id'=>1, 'email'=>'customer@example.invalid', 'email_verified'=>1];
    $GLOBALS['clientRow'] = $client;
    $model = new \WHMCS\User\Client();
    $model->user = (object) ['email'=>'CUSTOMER@example.invalid'];
    \WHMCS\User\Client::$record = $model;
    unset($client->email_verified);
    checkEmail(releem_server_client_email_is_verified($client), 'A verified WHMCS 8 owner matching the client email must be accepted without a legacy column');
    checkEmail(releem_server_adoption_allowed($client), 'Adoption must use WHMCS 8 email verification');
    checkEmail(releem_server_may_reveal_public_api_key(1), 'Key visibility must use the same verification');
    releem_server_sync_public_api_key(1, 1, ['api_key'=>'public-test-key'], true, $client);
    checkEmail($GLOBALS['storedKey'] === 'public-test-key', 'A verified matching owner may receive the public key');

    $client->email_verified = 1;
    foreach (['unverified', 'different_owner_email', 'different_client_email', 'missing_owner', 'missing_model', 'lookup_failure'] as $case) {
        $model->fail = $case === 'lookup_failure';
        $model->verified = $case !== 'unverified';
        $model->email = $case === 'different_client_email' ? 'other@example.invalid' : $client->email;
        $model->user = $case === 'missing_owner' ? null : (object) ['email'=>$case === 'different_owner_email' ? 'other@example.invalid' : $client->email];
        \WHMCS\User\Client::$record = $case === 'missing_model' ? null : $model;
        checkEmail(!releem_server_client_email_is_verified($client), 'Stale legacy verification must not authorize ' . $case);
        checkEmail(!releem_server_adoption_allowed($client), 'Adoption must reject ' . $case);
        $GLOBALS['allowAdoption'] = true;
        checkEmail(releem_server_adoption_allowed($client), 'Explicit adoption setting remains supported');
        checkEmail(!releem_server_may_reveal_public_api_key(1), 'Adoption setting must not reveal a key for ' . $case);
        releem_server_sync_public_api_key(1, 1, ['api_key'=>'public-test-key'], true, $client);
        checkEmail($GLOBALS['storedKey'] === '', 'Key storage must reject ' . $case);
        $GLOBALS['allowAdoption'] = false;
    }
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "ok\n";
}
