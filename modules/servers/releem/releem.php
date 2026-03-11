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

        public function __construct($apiKey, $endpoint = 'https://api.releem.com')
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
        return [
            'partner_api_key' => [
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Releem Partner Secret Key',
            ],
            'api_endpoint' => [
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'https://api.releem.com',
                'Description' => 'Releem API endpoint URL',
            ],
            'plan_config_option_id' => [
                'Type' => 'text',
                'Size' => '10',
                'Description' => 'WHMCS configurable option ID used for plan selection',
            ],
            'plan_mapping' => [
                'Type' => 'textarea',
                'Rows' => '4',
                'Description' => 'JSON mapping of WHMCS configurable option value ID to Releem plan ID. Example: {"11":1,"12":2,"13":3}',
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
            $planConfigOptionId = (int) releem_server_get_module_setting($params, 'plan_config_option_id', 3);
            $planMappingRaw = releem_server_get_module_setting($params, 'plan_mapping', 4);

            if ($apiEndpoint === '') {
                $apiEndpoint = 'https://api.releem.com';
            }

            if ($partnerApiKey === '') {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Partner API key not configured');
                return 'Partner API key not configured';
            }

            $planMapping = releem_server_parse_plan_mapping($planMappingRaw);
            if (empty($planMapping)) {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Plan mapping is empty or invalid');
                return 'Plan mapping is empty or invalid';
            }

            $selectedOptionId = releem_server_get_selected_option_id($serviceId, $planConfigOptionId);
            if ($selectedOptionId <= 0) {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Unable to resolve selected configurable option value ID');
                return 'Unable to resolve selected configurable option value ID';
            }

            $selectedOptionKey = (string) $selectedOptionId;
            if (!isset($planMapping[$selectedOptionKey])) {
                releem_server_log(
                    'sync',
                    ['serviceid' => $serviceId, 'option_id' => $selectedOptionId],
                    'No plan mapping found for selected option ID'
                );
                return 'No plan mapping found for selected option ID';
            }

            $releemPlanId = (int) $planMapping[$selectedOptionKey];
            if ($releemPlanId <= 0) {
                releem_server_log(
                    'sync',
                    ['serviceid' => $serviceId, 'option_id' => $selectedOptionId],
                    'Mapped Releem plan ID is invalid'
                );
                return 'Mapped Releem plan ID is invalid';
            }

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

            $packageId = isset($service->packageid) ? (int) $service->packageid : 0;
            $existingCustomerId = (string) releem_server_get_product_custom_field_value(
                $serviceId,
                $packageId,
                'releem_customer_id'
            );

            $api = new ReleemServerApi($partnerApiKey, $apiEndpoint);

            if ($existingCustomerId === '') {
                $payload = [
                    'email' => $email,
                    'name' => $name,
                    'plan_id' => (int) $releemPlanId,
                    'status' => $releemStatus,
                ];

                $response = $api->createCustomer($payload);
                if (empty($response['id'])) {
                    throw new Exception('Create customer response missing id');
                }

                releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_customer_id', (string) $response['id']);
                if (!empty($response['api_key'])) {
                    releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_api_key', (string) $response['api_key']);
                }
                releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_plan_id', (string) ((int) $releemPlanId));
                releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_status', $releemStatus);

                releem_server_log('createCustomer', releem_server_redact($payload), ['serviceid' => $serviceId, 'ok' => true]);
                return 'success';
            }

            $payload = [
                'plan_id' => (int) $releemPlanId,
                'status' => $releemStatus,
            ];

            $response = $api->updateCustomer($existingCustomerId, $payload);
            releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_plan_id', (string) ((int) $releemPlanId));
            releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_status', $releemStatus);
            if (!empty($response['api_key'])) {
                releem_server_set_product_custom_field_value($serviceId, $packageId, 'releem_api_key', (string) $response['api_key']);
            }

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

if (!function_exists('releem_server_parse_plan_mapping')) {
    function releem_server_parse_plan_mapping($rawJson)
    {
        $rawJson = trim((string) $rawJson);
        if ($rawJson === '') {
            return [];
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $optionId => $planId) {
            $optionId = (string) $optionId;
            $planId = (int) $planId;
            if ($optionId !== '' && $planId > 0) {
                $result[$optionId] = $planId;
            }
        }

        return $result;
    }
}

if (!function_exists('releem_server_get_selected_option_id')) {
    function releem_server_get_selected_option_id($serviceId, $planConfigOptionId)
    {
        $serviceId = (int) $serviceId;
        $planConfigOptionId = (int) $planConfigOptionId;

        if ($serviceId <= 0) {
            return 0;
        }

        if ($planConfigOptionId > 0) {
            $row = Capsule::table('tblhostingconfigoptions')
                ->where('relid', $serviceId)
                ->where('configid', $planConfigOptionId)
                ->select('optionid')
                ->first();

            if ($row && isset($row->optionid)) {
                return (int) $row->optionid;
            }
        }

        $rows = Capsule::table('tblhostingconfigoptions')
            ->where('relid', $serviceId)
            ->select('optionid')
            ->limit(2)
            ->get();

        if (count($rows) === 1 && isset($rows[0]->optionid)) {
            return (int) $rows[0]->optionid;
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
            $fieldId = (int) Capsule::table('tblcustomfields')->insertGetId([
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => $fieldName,
                'fieldtype' => 'text',
                'description' => '',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 0,
                'required' => 0,
                'showorder' => 0,
                'showinvoice' => 0,
                'sortorder' => 0,
            ]);
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
