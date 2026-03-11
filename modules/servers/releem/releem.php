<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!class_exists('ReleemServerApi')) {
    class ReleemServerApi
    {
        private $apiKey;
        private $endpoint;

        public function __construct($apiKey, $endpoint = 'https://api2.releem.com')
        {
            $this->apiKey = (string) $apiKey;
            $this->endpoint = rtrim((string) $endpoint, '/');
        }

        public function createCustomer(array $data)
        {
            return $this->request('POST', '/v1/customers', $data);
        }

        public function updateCustomer($customerId, array $data)
        {
            return $this->request('PATCH', '/v1/customers/' . rawurlencode((string) $customerId), $data);
        }

        public function getCustomerByEmail($email)
        {
            try {
                return $this->request('GET', '/v1/customers/by-email/' . rawurlencode((string) $email));
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                    return null;
                }

                throw $e;
            }
        }

        private function request($method, $path, array $data = null)
        {
            $url = $this->endpoint . $path;
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Releem-Secret-Key: ' . $this->apiKey,
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            if ($data !== null && in_array($method, ['POST', 'PATCH'], true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                throw new Exception('Releem API transport error: ' . $curlError);
            }

            $decoded = json_decode($response, true);
            if ($decoded === null && $response !== '' && strtolower((string) $response) !== 'null') {
                $decoded = ['raw_response' => $response];
            }

            if ($httpCode >= 400) {
                throw new Exception('Releem API Error: HTTP ' . $httpCode . ' - ' . $response);
            }

            return is_array($decoded) ? $decoded : [];
        }
    }
}

if (!function_exists('releem_MetaData')) {
    function releem_MetaData()
    {
        return [
            'DisplayName' => 'Releem',
            'APIVersion' => '1.1',
            'RequiresServer' => false,
        ];
    }
}

if (!function_exists('releem_ConfigOptions')) {
    function releem_ConfigOptions()
    {
        $productId = releem_server_detect_admin_product_id();
        $linkedServerCountOptionId = $productId > 0
            ? releem_server_find_linked_server_count_option_id($productId)
            : 0;

        return [
            'partner_api_key' => [
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Releem Partner Secret Key',
            ],
            'api_endpoint' => [
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'https://api2.releem.com',
                'Description' => 'Releem API endpoint URL',
            ],
            'server_count_option_id' => [
                'Type' => 'text',
                'Size' => '10',
                'Default' => $linkedServerCountOptionId > 0 ? (string) $linkedServerCountOptionId : '',
                'Description' => 'Optional override for the WHMCS configurable option ID used for server count selection',
            ],
        ];
    }
}

if (!function_exists('releem_CreateAccount')) {
    function releem_CreateAccount(array $params)
    {
        return releem_server_sync_service($params, 'Active');
    }
}

if (!function_exists('releem_SuspendAccount')) {
    function releem_SuspendAccount(array $params)
    {
        return releem_server_sync_service($params, 'Suspended');
    }
}

if (!function_exists('releem_UnsuspendAccount')) {
    function releem_UnsuspendAccount(array $params)
    {
        return releem_server_sync_service($params, 'Active');
    }
}

if (!function_exists('releem_TerminateAccount')) {
    function releem_TerminateAccount(array $params)
    {
        return releem_server_sync_service($params, 'Cancelled');
    }
}

if (!function_exists('releem_ChangePackage')) {
    function releem_ChangePackage(array $params)
    {
        return releem_server_sync_service($params, 'Active');
    }
}

if (!function_exists('releem_ClientArea')) {
    function releem_ClientArea(array $params)
    {
        $serviceId = releem_server_extract_service_id($params);
        $productId = !empty($params['pid']) ? (int) $params['pid'] : (!empty($params['packageid']) ? (int) $params['packageid'] : 0);

        $publicApiKey = releem_server_get_product_custom_field_value($serviceId, $productId, 'releem_public_api_key');

        return [
            'templatefile' => 'clientarea',
            'vars' => [
                'releemPublicApiKey' => $publicApiKey,
                'releemWhmInstallCommand' => $publicApiKey !== ''
                    ? releem_server_build_whm_install_command($publicApiKey)
                    : '',
                'releemWhmModuleDocsUrl' => 'https://releem.s3.amazonaws.com/v2/whm/whm-install.sh',
                'releemDocsLinuxManualUrl' => 'https://docs.releem.com/releem-agent/installation-guides/self-managed-servers-manual-installation-linux',
                'releemDocsMysqlPermissionsUrl' => 'https://docs.releem.com/releem-agent/mysql-permissions',
                'releemDocsCpanelTroubleshootingUrl' => 'https://docs.releem.com/getting-started/how-to-check-if-releem-agent-is-working',
            ],
        ];
    }
}

if (!function_exists('releem_server_sync_service')) {
    function releem_server_sync_service(array $params, $forcedStatus = null)
    {
        try {
            $serviceId = releem_server_extract_service_id($params);
            if ($serviceId <= 0) {
                return 'Unable to resolve service ID';
            }

            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if (!$service) {
                return 'Service not found';
            }

            $partnerApiKey = releem_server_get_module_setting($params, 'partner_api_key', 1);
            $apiEndpoint = releem_server_get_module_setting($params, 'api_endpoint', 2);
            $packageId = isset($service->packageid) ? (int) $service->packageid : 0;
            $serverCountOptionId = releem_server_get_server_count_option_id($params, $packageId);

            if ($apiEndpoint === '') {
                $apiEndpoint = 'https://api2.releem.com';
            }

            if ($partnerApiKey === '') {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Partner API key not configured');
                return 'Partner API key not configured';
            }

            $serverCount = releem_server_get_selected_server_count($params, $serviceId, $serverCountOptionId);
            if ($serverCount <= 0) {
                releem_server_log(
                    'sync',
                    ['serviceid' => $serviceId, 'configid' => $serverCountOptionId],
                    'Unable to resolve selected server count'
                );
                return 'Unable to resolve selected server count';
            }

            $releemPlanId = (string) $serverCount;

            $clientId = isset($service->userid) ? (int) $service->userid : 0;
            if ($clientId <= 0) {
                return 'Service client is missing';
            }

            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if (!$client) {
                return 'Client not found';
            }

            $email = isset($client->email) ? trim((string) $client->email) : '';
            if ($email === '') {
                return 'Client email is empty';
            }

            $company = isset($client->companyname) ? trim((string) $client->companyname) : '';
            $fullName = trim((string) $client->firstname . ' ' . (string) $client->lastname);
            $name = $company !== '' ? $company : ($fullName !== '' ? $fullName : $email);

            $whmcsStatus = $forcedStatus !== null
                ? (string) $forcedStatus
                : (isset($service->domainstatus) ? (string) $service->domainstatus : 'Active');
            $releemStatus = releem_server_map_status($whmcsStatus);

            $existingCustomerId = releem_server_get_customer_id($serviceId, $packageId);

            $api = new ReleemServerApi($partnerApiKey, $apiEndpoint);

            if ($existingCustomerId === '') {
                $existingCustomer = $api->getCustomerByEmail($email);
                if (is_array($existingCustomer) && !empty($existingCustomer['id'])) {
                    $existingCustomerId = (string) $existingCustomer['id'];
                    releem_server_set_service_meta($serviceId, ['customer_id' => $existingCustomerId]);
                    releem_server_store_customer_public_api_key($serviceId, $packageId, $existingCustomer);
                }
            }

            if ($existingCustomerId === '') {
                $payload = [
                    'email' => $email,
                    'name' => $name,
                ];

                $response = $api->createCustomer($payload);
                if (empty($response['id'])) {
                    throw new Exception('Create customer response missing id');
                }

                $existingCustomerId = (string) $response['id'];
                releem_server_set_service_meta($serviceId, ['customer_id' => $existingCustomerId]);
                releem_server_store_customer_public_api_key($serviceId, $packageId, $response);

                releem_server_log('createCustomer', releem_server_redact($payload), ['serviceid' => $serviceId, 'ok' => true]);
            }

            $payload = [
                'name' => $name,
                'subscription' => [
                    'plan_id' => $releemPlanId,
                    'status' => $releemStatus,
                    'subscription_email' => $email,
                ],
            ];

            $response = $api->updateCustomer($existingCustomerId, $payload);
            releem_server_set_service_meta($serviceId, [
                'customer_id' => $existingCustomerId,
                'plan_id' => $releemPlanId,
                'status' => $releemStatus,
            ]);
            releem_server_store_customer_public_api_key($serviceId, $packageId, $response);

            releem_server_log(
                'updateCustomer',
                ['customer_id' => $existingCustomerId, 'payload' => releem_server_redact($payload)],
                ['serviceid' => $serviceId, 'ok' => true]
            );

            return 'success';
        } catch (Exception $e) {
            releem_server_log('sync', releem_server_redact($params), $e->getMessage());
            return 'Releem sync failed. Check module log.';
        }
    }
}

if (!function_exists('releem_server_extract_service_id')) {
    function releem_server_extract_service_id(array $params)
    {
        if (!empty($params['serviceid'])) {
            return (int) $params['serviceid'];
        }

        if (!empty($params['model']) && method_exists($params['model'], 'getAttribute')) {
            return (int) $params['model']->getAttribute('id');
        }

        return 0;
    }
}

if (!function_exists('releem_server_get_param')) {
    function releem_server_get_param(array $params, $name)
    {
        if (isset($params[$name]) && trim((string) $params[$name]) !== '') {
            return trim((string) $params[$name]);
        }

        $nameLower = strtolower((string) $name);
        foreach ($params as $key => $value) {
            if (strtolower((string) $key) === $nameLower && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}

if (!function_exists('releem_server_build_whm_install_command')) {
    function releem_server_build_whm_install_command($publicApiKey)
    {
        $publicApiKey = trim((string) $publicApiKey);
        if ($publicApiKey === '') {
            return '';
        }

        return 'bash -c "$(curl -L https://releem.s3.amazonaws.com/v2/whm/whm-install.sh)" --api-key='
            . escapeshellarg($publicApiKey);
    }
}

if (!function_exists('releem_server_store_customer_public_api_key')) {
    function releem_server_store_customer_public_api_key($serviceId, $productId, array $customer)
    {
        $publicApiKey = releem_server_extract_customer_public_api_key($customer);
        if ($publicApiKey !== '') {
            releem_server_set_product_custom_field_value($serviceId, $productId, 'releem_public_api_key', $publicApiKey);
        }
    }
}

if (!function_exists('releem_server_get_customer_id')) {
    function releem_server_get_customer_id($serviceId, $productId)
    {
        $serviceId = (int) $serviceId;
        $productId = (int) $productId;

        $meta = releem_server_get_service_meta($serviceId);
        if (!empty($meta['customer_id'])) {
            return (string) $meta['customer_id'];
        }

        if ($productId > 0) {
            $legacyCustomerId = releem_server_get_product_custom_field_value($serviceId, $productId, 'releem_customer_id');
            if ($legacyCustomerId !== '') {
                releem_server_set_service_meta($serviceId, ['customer_id' => $legacyCustomerId]);
                return $legacyCustomerId;
            }
        }

        return '';
    }
}

if (!function_exists('releem_server_get_service_meta')) {
    function releem_server_get_service_meta($serviceId)
    {
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0 || !releem_server_ensure_meta_table()) {
            return [];
        }

        $row = Capsule::table('mod_releem_service_meta')
            ->where('service_id', $serviceId)
            ->select('customer_id', 'plan_id', 'status')
            ->first();

        if (!$row) {
            return [];
        }

        return [
            'customer_id' => isset($row->customer_id) ? (string) $row->customer_id : '',
            'plan_id' => isset($row->plan_id) ? (string) $row->plan_id : '',
            'status' => isset($row->status) ? (string) $row->status : '',
        ];
    }
}

if (!function_exists('releem_server_set_service_meta')) {
    function releem_server_set_service_meta($serviceId, array $values)
    {
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0 || !releem_server_ensure_meta_table()) {
            return false;
        }

        $allowedKeys = ['customer_id', 'plan_id', 'status'];
        $data = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $values)) {
                $data[$key] = (string) $values[$key];
            }
        }

        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $existing = Capsule::table('mod_releem_service_meta')
            ->where('service_id', $serviceId)
            ->select('service_id')
            ->first();

        if ($existing) {
            Capsule::table('mod_releem_service_meta')
                ->where('service_id', $serviceId)
                ->update($data);
        } else {
            $data['service_id'] = $serviceId;
            Capsule::table('mod_releem_service_meta')->insert($data);
        }

        return true;
    }
}

if (!function_exists('releem_server_ensure_meta_table')) {
    function releem_server_ensure_meta_table()
    {
        static $checked = false;
        static $available = false;

        if ($checked) {
            return $available;
        }

        $checked = true;

        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable('mod_releem_service_meta')) {
                $schema->create('mod_releem_service_meta', function ($table) {
                    $table->integer('service_id')->unsigned();
                    $table->string('customer_id', 191)->nullable();
                    $table->string('plan_id', 191)->nullable();
                    $table->string('status', 50)->nullable();
                    $table->timestamp('updated_at')->nullable();
                    $table->primary('service_id');
                });
            }

            $available = true;
        } catch (Exception $e) {
            releem_server_log('schema', ['table' => 'mod_releem_service_meta'], $e->getMessage());
            $available = false;
        }

        return $available;
    }
}

if (!function_exists('releem_server_extract_customer_public_api_key')) {
    function releem_server_extract_customer_public_api_key(array $customer)
    {
        foreach (['public-api-key', 'public_api_key', 'api'] as $field) {
            if (!empty($customer[$field])) {
                return trim((string) $customer[$field]);
            }
        }

        return '';
    }
}

if (!function_exists('releem_server_get_module_setting')) {
    function releem_server_get_module_setting(array $params, $name, $fallbackIndex)
    {
        $value = releem_server_get_param($params, $name);
        if ($value !== '') {
            return $value;
        }

        $configKey = 'configoption' . (int) $fallbackIndex;
        if (isset($params[$configKey]) && trim((string) $params[$configKey]) !== '') {
            return trim((string) $params[$configKey]);
        }

        return '';
    }
}

if (!function_exists('releem_server_get_server_count_option_id')) {
    function releem_server_get_server_count_option_id(array $params, $productId = 0)
    {
        $serverCountOptionId = releem_server_get_param($params, 'server_count_option_id');
        if ($serverCountOptionId !== '') {
            return (int) $serverCountOptionId;
        }

        $legacyOptionId = (int) releem_server_get_module_setting($params, 'plan_config_option_id', 3);
        if ($legacyOptionId > 0) {
            return $legacyOptionId;
        }

        return releem_server_find_linked_server_count_option_id($productId);
    }
}

if (!function_exists('releem_server_setup_product')) {
    function releem_server_setup_product($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return [
                'ok' => false,
                'message' => 'Invalid product ID.',
            ];
        }

        $serverCountOptionId = releem_server_ensure_server_count_option($productId);
        if ($serverCountOptionId <= 0) {
            return [
                'ok' => false,
                'message' => 'Failed to create or link the Servers configurable option.',
            ];
        }

        $fieldId = releem_server_ensure_public_api_key_field($productId);
        if ($fieldId <= 0) {
            return [
                'ok' => false,
                'message' => 'Failed to create releem_public_api_key custom field.',
            ];
        }

        releem_server_set_server_count_option_setting($productId, $serverCountOptionId);

        return [
            'ok' => true,
            'message' => 'Releem product setup completed.',
            'server_count_option_id' => $serverCountOptionId,
            'public_api_key_field_id' => $fieldId,
        ];
    }
}

if (!function_exists('releem_server_detect_admin_product_id')) {
    function releem_server_detect_admin_product_id()
    {
        if (!defined('ADMINAREA')) {
            return 0;
        }

        if (empty($_REQUEST['id']) || !ctype_digit((string) $_REQUEST['id'])) {
            return 0;
        }

        return (int) $_REQUEST['id'];
    }
}

if (!function_exists('releem_server_ensure_server_count_option')) {
    function releem_server_ensure_server_count_option($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }

        $existingOptionId = releem_server_find_linked_server_count_option_id($productId);
        if ($existingOptionId > 0) {
            releem_server_ensure_server_count_values($existingOptionId);
            return $existingOptionId;
        }

        $groupId = releem_server_find_reusable_server_count_group_id($productId);
        if ($groupId <= 0) {
            $groupId = releem_server_insert_get_id('tblproductconfiggroups', [
                'name' => 'Releem Servers',
                'description' => releem_server_get_server_count_group_description($productId),
                'order' => 0,
                'sortorder' => 0,
                'hidden' => 0,
            ]);
        }

        if ($groupId <= 0) {
            return 0;
        }

        releem_server_ensure_product_config_group_link($groupId, $productId);

        $optionId = releem_server_find_server_count_option_id_in_group($groupId);
        if ($optionId <= 0) {
            $optionId = releem_server_insert_get_id('tblproductconfigoptions', [
                'gid' => $groupId,
                'optionname' => 'Servers',
                'optiontype' => 1,
                'qtyminimum' => 0,
                'qtymaximum' => 0,
                'order' => 0,
                'sortorder' => 0,
                'hidden' => 0,
            ]);
        }

        if ($optionId <= 0) {
            return 0;
        }

        releem_server_ensure_server_count_values($optionId);

        return $optionId;
    }
}

if (!function_exists('releem_server_get_server_count_group_description')) {
    function releem_server_get_server_count_group_description($productId)
    {
        return 'Auto-created by Releem module for product #' . (int) $productId;
    }
}

if (!function_exists('releem_server_find_reusable_server_count_group_id')) {
    function releem_server_find_reusable_server_count_group_id($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }

        $linkedGroup = Capsule::table('tblproductconfiglinks as l')
            ->join('tblproductconfiggroups as g', 'g.id', '=', 'l.gid')
            ->where('l.pid', $productId)
            ->where(function ($query) use ($productId) {
                $query->where('g.name', 'Releem Servers')
                    ->orWhere('g.description', releem_server_get_server_count_group_description($productId))
                    ->orWhere('g.description', 'Auto-created by Releem module');
            })
            ->orderBy('g.id', 'asc')
            ->select('g.id')
            ->first();

        if ($linkedGroup && isset($linkedGroup->id)) {
            return (int) $linkedGroup->id;
        }

        $exactGroup = Capsule::table('tblproductconfiggroups')
            ->where('description', releem_server_get_server_count_group_description($productId))
            ->orderBy('id', 'asc')
            ->select('id')
            ->first();

        if ($exactGroup && isset($exactGroup->id)) {
            return (int) $exactGroup->id;
        }

        $legacyGroup = Capsule::table('tblproductconfiggroups as g')
            ->join('tblproductconfigoptions as o', 'o.gid', '=', 'g.id')
            ->leftJoin('tblproductconfiglinks as l', 'l.gid', '=', 'g.id')
            ->where(function ($query) {
                $query->where('g.name', 'Releem Servers')
                    ->orWhere('g.description', 'Auto-created by Releem module');
            })
            ->where(function ($query) {
                $query->where('o.optionname', 'Servers')
                    ->orWhere('o.optionname', 'like', 'Servers|%');
            })
            ->whereNull('l.pid')
            ->orderBy('g.id', 'asc')
            ->select('g.id')
            ->first();

        return ($legacyGroup && isset($legacyGroup->id)) ? (int) $legacyGroup->id : 0;
    }
}

if (!function_exists('releem_server_ensure_product_config_group_link')) {
    function releem_server_ensure_product_config_group_link($groupId, $productId)
    {
        $groupId = (int) $groupId;
        $productId = (int) $productId;

        if ($groupId <= 0 || $productId <= 0) {
            return false;
        }

        $existingLink = Capsule::table('tblproductconfiglinks')
            ->where('gid', $groupId)
            ->where('pid', $productId)
            ->select('gid')
            ->first();

        if ($existingLink) {
            return true;
        }

        return releem_server_insert_row('tblproductconfiglinks', [
            'gid' => $groupId,
            'pid' => $productId,
        ]);
    }
}

if (!function_exists('releem_server_find_server_count_option_id_in_group')) {
    function releem_server_find_server_count_option_id_in_group($groupId)
    {
        $groupId = (int) $groupId;
        if ($groupId <= 0) {
            return 0;
        }

        $row = Capsule::table('tblproductconfigoptions')
            ->where('gid', $groupId)
            ->where(function ($query) {
                $query->where('optionname', 'Servers')
                    ->orWhere('optionname', 'like', 'Servers|%');
            })
            ->orderBy('id', 'asc')
            ->select('id')
            ->first();

        return ($row && isset($row->id)) ? (int) $row->id : 0;
    }
}

if (!function_exists('releem_server_find_linked_server_count_option_id')) {
    function releem_server_find_linked_server_count_option_id($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }

        $row = Capsule::table('tblproductconfiglinks as l')
            ->join('tblproductconfigoptions as o', 'o.gid', '=', 'l.gid')
            ->where('l.pid', $productId)
            ->where(function ($query) {
                $query->where('o.optionname', 'Servers')
                    ->orWhere('o.optionname', 'like', 'Servers|%');
            })
            ->orderBy('o.id', 'asc')
            ->select('o.id')
            ->first();

        return ($row && isset($row->id)) ? (int) $row->id : 0;
    }
}

if (!function_exists('releem_server_ensure_server_count_values')) {
    function releem_server_ensure_server_count_values($optionId)
    {
        $optionId = (int) $optionId;
        if ($optionId <= 0) {
            return;
        }

        $existingValues = Capsule::table('tblproductconfigoptionssub')
            ->where('configid', $optionId)
            ->select('id', 'optionname')
            ->get();

        $counts = [];
        foreach ($existingValues as $value) {
            $count = releem_server_parse_server_count(isset($value->optionname) ? $value->optionname : '');
            if ($count > 0) {
                $counts[$count] = true;
            }
        }

        for ($count = 1; $count <= 10; $count++) {
            if (isset($counts[$count])) {
                continue;
            }

            releem_server_insert_row('tblproductconfigoptionssub', [
                'configid' => $optionId,
                'optionname' => (string) $count,
                'sortorder' => $count,
                'order' => $count,
                'hidden' => 0,
            ]);
        }
    }
}

if (!function_exists('releem_server_insert_get_id')) {
    function releem_server_insert_get_id($table, array $data)
    {
        $data = releem_server_filter_table_data($table, $data);
        if (empty($data)) {
            return 0;
        }

        return (int) Capsule::table($table)->insertGetId($data);
    }
}

if (!function_exists('releem_server_insert_row')) {
    function releem_server_insert_row($table, array $data)
    {
        $data = releem_server_filter_table_data($table, $data);
        if (empty($data)) {
            return false;
        }

        return Capsule::table($table)->insert($data);
    }
}

if (!function_exists('releem_server_filter_table_data')) {
    function releem_server_filter_table_data($table, array $data)
    {
        $columns = releem_server_get_table_columns($table);
        if (empty($columns)) {
            return $data;
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            if (isset($columns[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}

if (!function_exists('releem_server_get_table_columns')) {
    function releem_server_get_table_columns($table)
    {
        static $cache = [];

        $table = trim((string) $table);
        if ($table === '') {
            return [];
        }

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $listing = Capsule::schema()->getColumnListing($table);
        } catch (Exception $e) {
            $cache[$table] = [];
            return $cache[$table];
        }

        $columns = [];
        foreach ($listing as $column) {
            $columns[(string) $column] = true;
        }

        $cache[$table] = $columns;

        return $cache[$table];
    }
}

if (!function_exists('releem_server_set_server_count_option_setting')) {
    function releem_server_set_server_count_option_setting($productId, $serverCountOptionId)
    {
        $productId = (int) $productId;
        $serverCountOptionId = (int) $serverCountOptionId;

        if ($productId <= 0 || $serverCountOptionId <= 0) {
            return false;
        }

        $columns = releem_server_get_table_columns('tblproducts');
        if (!isset($columns['configoption3'])) {
            return false;
        }

        return (bool) Capsule::table('tblproducts')
            ->where('id', $productId)
            ->update(['configoption3' => (string) $serverCountOptionId]);
    }
}

if (!function_exists('releem_server_parse_server_count')) {
    function releem_server_parse_server_count($value)
    {
        if (is_int($value) || is_float($value)) {
            $count = (int) $value;
            return $count > 0 ? $count : 0;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            $count = (int) $value;
            return $count > 0 ? $count : 0;
        }

        if (preg_match('/\b([1-9][0-9]*)\b/', $value, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}

if (!function_exists('releem_server_get_selected_server_count')) {
    function releem_server_get_selected_server_count(array $params, $serviceId, $serverCountOptionId)
    {
        $serviceId = (int) $serviceId;
        $serverCountOptionId = (int) $serverCountOptionId;

        if ($serviceId <= 0) {
            return 0;
        }

        if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
            if ($serverCountOptionId > 0 && isset($params['configoptions'][$serverCountOptionId])) {
                $count = releem_server_parse_server_count($params['configoptions'][$serverCountOptionId]);
                if ($count > 0) {
                    return $count;
                }
            }

            if (count($params['configoptions']) === 1) {
                $value = reset($params['configoptions']);
                $count = releem_server_parse_server_count($value);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        if ($serverCountOptionId > 0) {
            $row = Capsule::table('tblhostingconfigoptions')
                ->where('relid', $serviceId)
                ->where('configid', $serverCountOptionId)
                ->select('optionid', 'qty')
                ->first();

            $count = releem_server_extract_server_count_from_config_row($row);
            if ($count > 0) {
                return $count;
            }
        }

        $rows = Capsule::table('tblhostingconfigoptions')
            ->where('relid', $serviceId)
            ->select('optionid', 'qty')
            ->limit(2)
            ->get();

        if (count($rows) === 1) {
            return releem_server_extract_server_count_from_config_row($rows[0]);
        }

        return 0;
    }
}

if (!function_exists('releem_server_extract_server_count_from_config_row')) {
    function releem_server_extract_server_count_from_config_row($row)
    {
        if (!$row) {
            return 0;
        }

        if (isset($row->qty)) {
            $count = releem_server_parse_server_count($row->qty);
            if ($count > 0) {
                return $count;
            }
        }

        if (empty($row->optionid)) {
            return 0;
        }

        $subOption = Capsule::table('tblproductconfigoptionssub')
            ->where('id', (int) $row->optionid)
            ->select('optionname')
            ->first();

        if ($subOption && isset($subOption->optionname)) {
            return releem_server_parse_server_count($subOption->optionname);
        }

        return 0;
    }
}

if (!function_exists('releem_server_map_status')) {
    function releem_server_map_status($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
            case 'completed':
                return 'active';
            case 'suspended':
                return 'suspended';
            case 'terminated':
            case 'cancelled':
                return 'cancelled';
            default:
                return 'active';
        }
    }
}

if (!function_exists('releem_server_get_product_custom_field_value')) {
    function releem_server_get_product_custom_field_value($serviceId, $productId, $fieldName)
    {
        $serviceId = (int) $serviceId;
        $productId = (int) $productId;
        $fieldName = trim((string) $fieldName);

        if ($serviceId <= 0 || $fieldName === '') {
            return '';
        }

        $query = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('v.relid', $serviceId)
            ->where('f.type', 'product')
            ->where(function ($inner) use ($fieldName) {
                $inner->where('f.fieldname', $fieldName)
                    ->orWhere('f.fieldname', 'like', $fieldName . '|%');
            });

        if ($productId > 0) {
            $query->where('f.relid', $productId);
        }

        $row = $query->select('v.value')->first();

        return ($row && isset($row->value)) ? (string) $row->value : '';
    }
}

if (!function_exists('releem_server_ensure_public_api_key_field')) {
    function releem_server_ensure_public_api_key_field($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }

        $fieldName = 'releem_public_api_key';

        $fieldId = releem_server_find_public_api_key_field_id($productId);
        if ($fieldId > 0) {
            return $fieldId;
        }

        return (int) Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'product',
            'relid' => $productId,
            'fieldname' => $fieldName,
            'fieldtype' => 'text',
            'description' => 'Public API key for Releem agent installation',
            'fieldoptions' => '',
            'regexpr' => '',
            'adminonly' => 0,
            'required' => 0,
            'showorder' => 0,
            'showinvoice' => 0,
            'sortorder' => 0,
        ]);
    }
}

if (!function_exists('releem_server_find_public_api_key_field_id')) {
    function releem_server_find_public_api_key_field_id($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }

        $fieldName = 'releem_public_api_key';
        $field = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where(function ($inner) use ($fieldName) {
                $inner->where('fieldname', $fieldName)
                    ->orWhere('fieldname', 'like', $fieldName . '|%');
            })
            ->select('id')
            ->first();

        return ($field && isset($field->id)) ? (int) $field->id : 0;
    }
}

if (!function_exists('releem_server_set_product_custom_field_value')) {
    function releem_server_set_product_custom_field_value($serviceId, $productId, $fieldName, $value)
    {
        $serviceId = (int) $serviceId;
        $productId = (int) $productId;
        $fieldName = trim((string) $fieldName);

        if ($serviceId <= 0 || $productId <= 0 || $fieldName === '') {
            return false;
        }

        $field = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where(function ($inner) use ($fieldName) {
                $inner->where('fieldname', $fieldName)
                    ->orWhere('fieldname', 'like', $fieldName . '|%');
            })
            ->select('id')
            ->first();

        if ($field && isset($field->id)) {
            $fieldId = (int) $field->id;
        } else {
            if ($fieldName === 'releem_public_api_key') {
                $fieldId = releem_server_ensure_public_api_key_field($productId);
            } else {
                return false;
            }
        }

        if ($fieldId <= 0) {
            return false;
        }

        $existing = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $serviceId)
            ->select('id')
            ->first();

        if ($existing && isset($existing->id)) {
            Capsule::table('tblcustomfieldsvalues')
                ->where('id', (int) $existing->id)
                ->update(['value' => (string) $value]);
        } else {
            Capsule::table('tblcustomfieldsvalues')->insert([
                'fieldid' => $fieldId,
                'relid' => $serviceId,
                'value' => (string) $value,
            ]);
        }

        return true;
    }
}

if (!function_exists('releem_server_redact')) {
    function releem_server_redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $redacted = [];
        foreach ($data as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, ['api_key', 'partner_api_key', 'secret', 'releem-secret-key'], true)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = is_array($value) ? releem_server_redact($value) : $value;
        }

        return $redacted;
    }
}

if (!function_exists('releem_server_log')) {
    function releem_server_log($action, $request, $response)
    {
        if (function_exists('logModuleCall')) {
            logModuleCall('releem', $action, releem_server_redact($request), releem_server_redact($response), '', []);
        }
    }
}
