<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!class_exists('ReleemServerApi')) {
    class ReleemServerApi
    {
        const DEFAULT_ENDPOINT = 'https://api2.releem.com';
        const MAX_ERROR_BODY = 500;
        const LOOKUP_PAGE_GUARD = 10;

        /** @var callable|null Test seam: function ($method, $url, array $headers, $body) => ['status' => int, 'body' => string] */
        private static $transport = null;

        private static $connectTimeout = 10;
        private static $totalTimeout = 30;

        private $apiKey;
        private $endpoint;

        public static function setTransport($transport)
        {
            self::$transport = $transport;
        }

        /**
         * Shorter timeouts for callers that run inside other WHMCS requests (payment hooks, cron).
         */
        public static function setTimeouts($connectSeconds, $totalSeconds)
        {
            self::$connectTimeout = max(1, (int) $connectSeconds);
            self::$totalTimeout = max(1, (int) $totalSeconds);
        }

        public static function resetTimeouts()
        {
            self::$connectTimeout = 10;
            self::$totalTimeout = 30;
        }

        /**
         * The partner token may only be sent to Releem: releem.com and its subdomains (api2.releem.com,
         * api2.dev.releem.com). IP literals, localhost and foreign hosts are refused so a changed server record
         * cannot redirect the token elsewhere.
         */
        public static function isAllowedHost($host)
        {
            $host = strtolower(trim((string) $host));
            if (substr($host, -1) === '.') {
                $host = substr($host, 0, -1);
            }
            if (
                $host === ''
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || filter_var($host, FILTER_VALIDATE_IP) !== false
                || preg_match('/^[0-9.]+$/', $host)
            ) {
                return false;
            }

            return $host === 'releem.com' || substr($host, -11) === '.releem.com';
        }

        /**
         * Validates and normalizes the API endpoint. Only plain https URLs on Releem hosts are accepted so
         * the secret key is never sent in cleartext or to a third party.
         */
        public static function normalizeEndpoint($endpoint)
        {
            $endpoint = trim((string) $endpoint);
            if ($endpoint === '') {
                return self::DEFAULT_ENDPOINT;
            }

            $parts = parse_url($endpoint);
            if (
                !is_array($parts)
                || !isset($parts['scheme'], $parts['host'])
                || strtolower($parts['scheme']) !== 'https'
                || $parts['host'] === ''
                || !self::isAllowedHost($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
                || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))
                || preg_match('/\s/', $endpoint)
            ) {
                throw new InvalidArgumentException('Releem API endpoint must be a valid https:// URL on a releem.com host');
            }

            $host = strtolower(rtrim($parts['host'], '.'));
            $port = isset($parts['port']) ? (int) $parts['port'] : 443;

            return 'https://' . $host . ($port === 443 ? '' : ':' . $port);
        }

        public function __construct($apiKey, $endpoint = self::DEFAULT_ENDPOINT)
        {
            $apiKey = trim((string) $apiKey);
            // Printable ASCII only: prevents header injection through the configured secret.
            if ($apiKey === '' || !preg_match('/^[\x21-\x7e]+$/', $apiKey)) {
                throw new InvalidArgumentException('Releem secret key is empty or contains invalid characters');
            }

            $this->apiKey = $apiKey;
            $this->endpoint = self::normalizeEndpoint($endpoint);

            if (function_exists('releem_server_register_secret')) {
                releem_server_register_secret($this->apiKey);
            }
        }

        /**
         * Read-only credential check: looks up a customer that cannot exist. A valid token answers 2xx with an empty
         * list; a bad token answers 401/403. Nothing is created or changed.
         */
        public function testConnection()
        {
            $this->request('GET', '/v1/customers?filter=' . rawurlencode('releem-connection-test@invalid.invalid'));

            return true;
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
            // Apiary defines email lookup through the list endpoint with the filter param.
            $response = $this->request('GET', '/v1/customers?filter=' . rawurlencode((string) $email));
            if (empty($response)) {
                return null;
            }

            // A single customer object instead of a list.
            if (isset($response['id']) || isset($response['email'])) {
                $response = [$response];
            }

            // Only a plain list of customer objects is understood. Wrapped, paginated or otherwise unexpected shapes
            // must never be read as "customer does not exist": that would create duplicate customers.
            foreach ($response as $position => $customer) {
                if (!is_int($position) || !is_array($customer) || !isset($customer['email']) || !isset($customer['id'])) {
                    throw new Exception('Unexpected customer lookup response shape');
                }
            }

            $matches = [];
            foreach ($response as $customer) {
                if (empty($customer['email']) || empty($customer['id'])) {
                    continue;
                }

                if (strcasecmp(trim((string) $customer['email']), trim((string) $email)) === 0) {
                    $matches[] = $customer;
                }
            }

            if (empty($matches)) {
                // The list endpoint may be paginated. A full page without an exact match cannot prove the customer
                // is absent, and treating it as absent would create a duplicate customer.
                if (count($response) >= self::LOOKUP_PAGE_GUARD) {
                    throw new Exception('Customer lookup returned a full page without an exact match; refusing to create a duplicate');
                }

                return null;
            }

            if (count($matches) > 1) {
                throw new Exception('Multiple Releem customers found for the client email');
            }

            return $matches[0];
        }

        private function request($method, $path, ?array $data = null)
        {
            $url = $this->endpoint . $path;
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Releem-Secret-Key: ' . $this->apiKey,
            ];

            $body = null;
            if ($data !== null && in_array($method, ['POST', 'PATCH'], true)) {
                $body = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
                if ($body === false) {
                    throw new Exception('Releem API request could not be encoded as JSON');
                }
            }

            $result = self::$transport !== null
                ? call_user_func(self::$transport, $method, $url, $headers, $body)
                : $this->curlTransport($method, $url, $headers, $body);

            $httpCode = (int) $result['status'];
            $response = (string) $result['body'];

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new Exception(
                    'Releem API Error: HTTP ' . $httpCode . ' - ' . substr($response, 0, self::MAX_ERROR_BODY)
                );
            }

            if (trim($response) === '') {
                return [];
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Releem API returned a non-JSON response (HTTP ' . $httpCode . ')');
            }

            // A non-empty body must be a JSON object/array: scalars and null are never a valid Releem reply.
            if (!is_array($decoded)) {
                throw new Exception('Releem API returned an unexpected JSON value (HTTP ' . $httpCode . ')');
            }

            return $decoded;
        }

        private function curlTransport($method, $url, array $headers, $body)
        {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::$totalTimeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
            } elseif (defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            if ($response === false) {
                throw new Exception('Releem API transport error: ' . $curlError);
            }

            return ['status' => $httpCode, 'body' => (string) $response];
        }
    }
}

if (!function_exists('releem_MetaData')) {
    function releem_MetaData()
    {
        return [
            'DisplayName' => 'Releem',
            'APIVersion' => '1.1',
            // The Releem API is configured as a WHMCS server: hostname, port, Secure (SSL) and the API token (password).
            'RequiresServer' => true,
            'DefaultNonSSLPort' => '443',
            'DefaultSSLPort' => '443',
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
            // The label is only a display name; the value is read by position (configoption1).
            'Number of Database Servers' => [
                'Type' => 'text',
                'Size' => '10',
                'Default' => $linkedServerCountOptionId > 0 ? (string) $linkedServerCountOptionId : '',
                'Description' => 'Configurable option ID that holds the number of database servers (filled in automatically by Releem Setup > Setup Product)',
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
        // Follow the real WHMCS service status: a package change must not re-activate a suspended service.
        return releem_server_sync_service($params, null);
    }
}

if (!function_exists('releem_TestConnection')) {
    /**
     * Powers the "Test Connection" button on Configuration -> System Settings -> Servers.
     */
    function releem_TestConnection(array $params)
    {
        try {
            // The server form can contain a new, unsaved plain-text token.
            $credentials = releem_server_resolve_credentials($params, (object) [], true);
            if ($credentials['error'] !== '') {
                return ['success' => false, 'error' => $credentials['error']];
            }

            releem_server_register_secret($credentials['key']);
            $api = new ReleemServerApi($credentials['key'], $credentials['endpoint']);
            ReleemServerApi::setTimeouts(5, 15);
            try {
                $api->testConnection();
            } finally {
                ReleemServerApi::resetTimeouts();
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (preg_match('/HTTP (401|403)\b/', $message, $matches)) {
                $reason = preg_match('/"message"\s*:\s*"([^"]{1,120})"/', $message, $reasonMatch) ? ' Reason: ' . $reasonMatch[1] . '.' : '';
                $message = 'The Releem API rejected the Partner API Token (HTTP ' . $matches[1] . ').' . $reason
                    . ' Make sure the token belongs to this environment (dev token for api2.dev.releem.com, production token for api2.releem.com).';
            } elseif (preg_match('/HTTP (\d{3})\b/', $message, $matches)) {
                $message = 'The Releem API answered with an error (HTTP ' . $matches[1] . ').';
            }
            releem_server_log('TestConnection', ['hostname' => releem_server_get_param($params, 'serverhostname')], $message);

            return ['success' => false, 'error' => releem_server_redact($message)];
        }
    }
}

if (!function_exists('releem_AdminCustomButtonArray')) {
    function releem_AdminCustomButtonArray()
    {
        return ['Sync with Releem' => 'SyncNow'];
    }
}

if (!function_exists('releem_SyncNow')) {
    function releem_SyncNow(array $params)
    {
        return releem_server_sync_service($params, null);
    }
}

if (!function_exists('releem_ClientArea')) {
    function releem_ClientArea(array $params)
    {
        $serviceId = releem_server_extract_service_id($params);
        $productId = !empty($params['pid']) ? (int) $params['pid'] : (!empty($params['packageid']) ? (int) $params['packageid'] : 0);

        $isActive = false;
        $publicApiKey = '';
        if ($serviceId > 0) {
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->select('domainstatus')->first();
            $isActive = $service && strtolower((string) $service->domainstatus) === 'active';
        }

        // Never hand out the key for suspended, cancelled or terminated services, or for an
        // adopted customer while the WHMCS email is still unverified.
        if ($isActive && releem_server_may_reveal_public_api_key($serviceId)) {
            $publicApiKey = releem_server_get_product_custom_field_value($serviceId, $productId, 'releem_public_api_key');
        }

        return [
            'templatefile' => 'clientarea',
            'vars' => [
                'releemServiceActive' => $isActive,
                'releemPublicApiKey' => $publicApiKey,
                'releemTicketDeptId' => $isActive ? releem_server_get_ticket_department_id() : 0,
                'releemDocsLinuxManualUrl' => 'https://docs.releem.com/releem-agent/installation-guides/self-managed-servers-manual-installation-linux',
                'releemDocsMysqlPermissionsUrl' => 'https://docs.releem.com/releem-agent/mysql-permissions',
                'releemDocsCpanelTroubleshootingUrl' => 'https://docs.releem.com/getting-started/how-to-check-if-releem-agent-is-working',
            ],
        ];
    }
}

if (!function_exists('releem_server_get_ticket_department_id')) {
    /**
     * The first department a client can open a ticket in. submitticket.php?step=2 needs a department ID, otherwise
     * WHMCS drops the pre-filled message and returns to the department list. 0 when none can be determined.
     */
    function releem_server_get_ticket_department_id()
    {
        try {
            $id = Capsule::table('tblticketdepartments')
                ->where(function ($query) {
                    $query->where('hidden', '')->orWhereNull('hidden')->orWhere('hidden', '!=', 'on');
                })
                ->orderBy('order', 'asc')
                ->orderBy('id', 'asc')
                ->value('id');

            return (int) $id;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('releem_server_with_client_lock')) {
    /**
     * Serializes syncs per WHMCS client so concurrent requests (order accept + admin click + hooks) cannot create
     * duplicate Releem customers. Uses a MySQL named lock on the shared WHMCS connection.
     */
    function releem_server_with_client_lock($clientId, callable $callback)
    {
        // MySQL lock names are server-wide: include the database so several WHMCS installs on one server (with
        // overlapping client IDs) do not block each other.
        $scope = 'x';
        try {
            $scope = substr(md5((string) Capsule::connection()->getDatabaseName()), 0, 12);
        } catch (\Throwable $e) {
            // Falls back to the unscoped name.
        }
        $lockName = 'releem_' . $scope . '_client_' . (int) $clientId;
        $acquired = false;

        try {
            $row = Capsule::select('SELECT GET_LOCK(?, 15) AS acquired', [$lockName]);
            $acquired = !empty($row) && (int) $row[0]->acquired === 1;
        } catch (\Throwable $e) {
            // Locking unavailable (non-MySQL driver): continue unlocked rather than blocking provisioning.
            return $callback();
        }

        if (!$acquired) {
            throw new Exception('Another Releem sync for this client is in progress; try again shortly');
        }

        try {
            return $callback();
        } finally {
            try {
                Capsule::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable $e) {
                // The lock is released automatically when the connection closes.
            }
        }
    }
}

if (!function_exists('releem_server_sync_service')) {
    function releem_server_sync_service(array $params, $forcedStatus = null)
    {
        try {
            $serviceId = releem_server_extract_service_id($params);
            $clientId = $serviceId > 0
                ? (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid')
                : 0;

            if ($clientId <= 0) {
                // Let the unlocked implementation produce the precise error message.
                return releem_server_sync_service_unlocked($params, $forcedStatus);
            }

            return releem_server_with_client_lock($clientId, function () use ($params, $forcedStatus) {
                return releem_server_sync_service_unlocked($params, $forcedStatus);
            });
        } catch (\Throwable $e) {
            releem_server_log('sync', ['serviceid' => isset($serviceId) ? (int) $serviceId : 0], $e->getMessage());
            return 'Releem sync failed. Check module log.';
        }
    }
}

if (!function_exists('releem_server_sync_service_unlocked')) {
    function releem_server_sync_service_unlocked(array $params, $forcedStatus = null)
    {
        $serviceId = 0;

        try {
            $serviceId = releem_server_extract_service_id($params);
            if ($serviceId <= 0) {
                return 'Unable to resolve service ID';
            }

            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if (!$service) {
                return 'Service not found';
            }

            $credentials = releem_server_resolve_credentials($params, $service);
            if ($credentials['error'] !== '') {
                releem_server_log('sync', ['serviceid' => $serviceId], $credentials['error']);
                return $credentials['error'];
            }
            $partnerApiKey = $credentials['key'];
            $apiEndpoint = $credentials['endpoint'];
            releem_server_register_secret($partnerApiKey);

            $packageId = isset($service->packageid) ? (int) $service->packageid : 0;

            $whmcsStatus = $forcedStatus !== null
                ? (string) $forcedStatus
                : (isset($service->domainstatus) ? (string) $service->domainstatus : 'Active');
            if ($forcedStatus === null && strtolower(trim($whmcsStatus)) === 'pending') {
                return 'Service is still pending; activate it before syncing with Releem';
            }

            $releemStatus = releem_server_map_status($whmcsStatus);
            $isDelete = $releemStatus === 'deleted';
            $meta = releem_server_get_service_meta($serviceId);

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

            // One Releem subscription exists per Releem customer, so only one active service per client may own it.
            $otherActiveServiceId = releem_server_find_other_active_service_id($serviceId, $clientId, $isDelete);
            if ($otherActiveServiceId > 0) {
                if ($isDelete && $otherActiveServiceId < $serviceId) {
                    releem_server_log(
                        'sync',
                        ['serviceid' => $serviceId],
                        'Skipped status change: subscription belongs to active Releem service #' . $otherActiveServiceId
                    );
                    releem_server_set_service_meta($serviceId, ['status' => $releemStatus]);
                    return 'success';
                }

                if (!$isDelete && $otherActiveServiceId < $serviceId) {
                    $message = 'Client already has an active Releem service (#' . $otherActiveServiceId
                        . '); only one Releem service per client is supported';
                    releem_server_log('sync', ['serviceid' => $serviceId], $message);
                    return $message;
                }
            }

            $serverCountOptionId = releem_server_get_server_count_option_id($params, $packageId);
            if (!$isDelete && $serverCountOptionId > 0 && !releem_server_option_is_linked_to_product($serverCountOptionId, $packageId)) {
                $message = '"Number of Database Servers" option ID ' . $serverCountOptionId . ' is not linked to this product';
                releem_server_log('sync', ['serviceid' => $serviceId, 'configid' => $serverCountOptionId], $message);
                return $message;
            }

            $serverCount = releem_server_get_selected_server_count($params, $serviceId, $serverCountOptionId);
            if ($serverCount <= 0 && $isDelete) {
                $serverCount = releem_server_parse_server_count(isset($meta['number_servers']) ? $meta['number_servers'] : '');
                if ($serverCount <= 0) {
                    $serverCount = 1;
                }
            }
            if ($serverCount <= 0) {
                releem_server_log(
                    'sync',
                    ['serviceid' => $serviceId, 'configid' => $serverCountOptionId],
                    'Unable to resolve selected server count'
                );
                return 'Unable to resolve selected server count';
            }

            $subscriptionId = 0;
            if (!empty($meta['subscription_id']) && ctype_digit((string) $meta['subscription_id'])) {
                // Keep the subscription identity stable once it has been sent to Releem.
                $subscriptionId = (int) $meta['subscription_id'];
            }
            if ($subscriptionId <= 0) {
                $subscriptionId = releem_server_get_numeric_subscription_id($service, $params);
            }

            $startedAt = releem_server_format_service_datetime(
                releem_server_get_param($params, 'regdate') !== ''
                    ? releem_server_get_param($params, 'regdate')
                    : (isset($service->regdate) ? $service->regdate : ''),
                false
            );
            $validTo = releem_server_get_service_valid_to($service, $params, !$isDelete);
            $amount = releem_server_get_service_amount($service, $params);
            $paymentMethod = releem_server_get_payment_method_label($params, $service);

            if ($subscriptionId <= 0) {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Service subscription ID is missing or invalid');
                return 'Service subscription ID is missing or invalid';
            }

            if (!$isDelete && releem_server_subscription_id_in_use($subscriptionId, $serviceId)) {
                $message = 'Releem subscription ID ' . $subscriptionId . ' is already used by another service';
                releem_server_log('sync', ['serviceid' => $serviceId], $message);
                return $message;
            }

            // Ending a subscription must never be blocked by missing billing data (Free/One Time services, etc.).
            if ($isDelete) {
                $startedAt = $startedAt !== '' ? $startedAt : releem_server_format_service_datetime(gmdate('Y-m-d'), false);
                $validTo = $validTo !== '' ? $validTo : releem_server_format_service_datetime(gmdate('Y-m-d'), true);
                $paymentMethod = $paymentMethod !== '' ? $paymentMethod : 'Unknown';
            }

            if ($startedAt === '') {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Service registration date is missing or invalid');
                return 'Service registration date is missing or invalid';
            }

            if ($validTo === '') {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Service next due date is missing or invalid');
                return 'Service next due date is missing or invalid';
            }

            if ($paymentMethod === '') {
                releem_server_log('sync', ['serviceid' => $serviceId], 'Service payment method is missing');
                return 'Service payment method is missing';
            }

            $existingCustomerId = releem_server_get_customer_id($serviceId, $packageId);
            // A customer that was found by email (not created by this module) belongs to someone whose ownership of
            // the email was never proven here. Its name is never overwritten.
            $isAdopted = !empty($meta['adopted']);

            $api = new ReleemServerApi($partnerApiKey, $apiEndpoint);

            if ($existingCustomerId === '' && !$isDelete) {
                // Reuse the Releem customer already linked to another service of this client (survives email changes).
                $linked = releem_server_find_client_customer($clientId, $serviceId);
                if ($linked['customer_id'] !== '') {
                    $existingCustomerId = $linked['customer_id'];
                    $isAdopted = $linked['adopted'];
                    releem_server_safe_meta_write($serviceId, ['customer_id' => $existingCustomerId, 'adopted' => $isAdopted ? 1 : 0]);
                }
            }

            // Looking a customer up by email is only done when creating or changing a subscription. Ending a
            // subscription never touches a customer this service has not been linked to.
            if ($existingCustomerId === '' && !$isDelete) {
                $existingCustomer = $api->getCustomerByEmail($email);
                if (is_array($existingCustomer) && !empty($existingCustomer['id'])) {
                    if (!releem_server_adoption_allowed($client)) {
                        $message = 'A Releem customer already exists for this client email, but the email is not verified in WHMCS; '
                            . 'verify the email or enable "Allow linking existing Releem customers by email" in Releem Setup';
                        releem_server_log('sync', ['serviceid' => $serviceId], $message);
                        return $message;
                    }

                    $existingCustomerId = (string) $existingCustomer['id'];
                    $isAdopted = true;
                    releem_server_safe_meta_write($serviceId, ['customer_id' => $existingCustomerId, 'adopted' => 1]);
                    releem_server_sync_public_api_key($serviceId, $packageId, $existingCustomer, true, $client);
                }
            }

            if ($existingCustomerId === '' && $isDelete) {
                // Nothing was ever provisioned for this client in Releem, so there is nothing to deactivate.
                releem_server_log('sync', ['serviceid' => $serviceId], 'No Releem customer found; nothing to deactivate');
                return 'success';
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
                releem_server_safe_meta_write($serviceId, ['customer_id' => $existingCustomerId, 'adopted' => 0]);
                releem_server_store_customer_public_api_key($serviceId, $packageId, $response);

                releem_server_log('createCustomer', releem_server_redact($payload), ['serviceid' => $serviceId, 'ok' => true]);
            }

            $payload = [
                'subscription' => [
                    'id' => $subscriptionId,
                    'started_at' => $startedAt,
                    'valid_to' => $validTo,
                    'status' => $releemStatus,
                    'subscription_email' => $email,
                    'number_servers' => $serverCount,
                    'amount' => $amount,
                    'invoiceid' => releem_server_get_invoice_id($params, $serviceId),
                    'payment_method' => $paymentMethod,
                ],
            ];
            if (!$isAdopted) {
                $payload = ['name' => $name] + $payload;
            }

            $response = $api->updateCustomer($existingCustomerId, $payload);

            releem_server_log(
                'updateCustomer',
                ['customer_id' => $existingCustomerId, 'payload' => releem_server_redact($payload)],
                ['serviceid' => $serviceId, 'ok' => true]
            );

            // The Releem update has succeeded: local bookkeeping problems must not turn it into a failure.
            releem_server_safe_meta_write($serviceId, [
                'customer_id' => $existingCustomerId,
                'adopted' => $isAdopted ? 1 : 0,
                'subscription_id' => $subscriptionId,
                'number_servers' => $serverCount,
                'status' => $releemStatus,
                'valid_to' => $validTo,
            ]);
            try {
                releem_server_sync_public_api_key($serviceId, $packageId, $response, $isAdopted, $client);
            } catch (\Throwable $e) {
                releem_server_log('storePublicApiKey', ['serviceid' => $serviceId], $e->getMessage());
            }

            return 'success';
        } catch (\Throwable $e) {
            releem_server_log('sync', ['serviceid' => $serviceId], $e->getMessage());
            return 'Releem sync failed. Check module log.';
        }
    }
}

if (!function_exists('releem_server_safe_meta_write')) {
    function releem_server_safe_meta_write($serviceId, array $values)
    {
        try {
            return releem_server_set_service_meta($serviceId, $values);
        } catch (\Throwable $e) {
            releem_server_log('meta', ['serviceid' => (int) $serviceId], $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('releem_server_find_other_active_service_id')) {
    /**
     * Lowest ID of another Active Releem service owned by the same client, or 0.
     * Ending a subscription only defers to services with a confirmed active Releem link.
     */
    function releem_server_find_other_active_service_id($serviceId, $clientId, $provisionedOnly = false)
    {
        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', (int) $clientId)
            ->where('h.id', '!=', (int) $serviceId)
            ->where('h.domainstatus', 'Active')
            ->where('p.servertype', 'releem');

        if ($provisionedOnly) {
            if (!releem_server_ensure_meta_table()) {
                return 0;
            }
            $query->join('mod_releem_service_meta as m', 'm.service_id', '=', 'h.id')
                ->where('m.customer_id', '!=', '')
                ->where('m.status', 'active');
        }

        $row = $query->orderBy('h.id', 'asc')
            ->select('h.id')
            ->first();

        return ($row && isset($row->id)) ? (int) $row->id : 0;
    }
}

if (!function_exists('releem_server_adoption_allowed')) {
    /**
     * Linking an existing Releem customer found by email is only safe when the client's ownership of that email is
     * established: the email is verified in WHMCS, or an administrator explicitly enabled linking in Releem Setup.
     */
    function releem_server_adoption_allowed($client)
    {
        try {
            $setting = Capsule::table('tbladdonmodules')
                ->where('module', 'releem_setup')
                ->where('setting', 'allow_email_adoption')
                ->value('value');
            if (releem_server_is_truthy($setting)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Treated as "not enabled".
        }

        return releem_server_client_email_is_verified($client);
    }
}

if (!function_exists('releem_server_find_client_customer')) {
    /**
     * The Releem customer already linked to another service of the client, with its "adopted" flag.
     *
     * @return array ['customer_id' => string, 'adopted' => bool]
     */
    function releem_server_find_client_customer($clientId, $serviceId)
    {
        $none = ['customer_id' => '', 'adopted' => false];
        if (!releem_server_ensure_meta_table()) {
            return $none;
        }

        $row = Capsule::table('tblhosting as h')
            ->join('mod_releem_service_meta as m', 'm.service_id', '=', 'h.id')
            ->where('h.userid', (int) $clientId)
            ->where('h.id', '!=', (int) $serviceId)
            ->whereNotNull('m.customer_id')
            ->where('m.customer_id', '!=', '')
            ->orderBy('h.id', 'desc')
            ->select('m.customer_id', 'm.adopted')
            ->first();

        if (!$row || !isset($row->customer_id)) {
            return $none;
        }

        return ['customer_id' => (string) $row->customer_id, 'adopted' => !empty($row->adopted)];
    }
}

if (!function_exists('releem_server_find_client_customer_id')) {
    function releem_server_find_client_customer_id($clientId, $serviceId)
    {
        $linked = releem_server_find_client_customer($clientId, $serviceId);

        return $linked['customer_id'];
    }
}

if (!function_exists('releem_server_option_is_linked_to_product')) {
    function releem_server_option_is_linked_to_product($optionId, $productId)
    {
        $optionId = (int) $optionId;
        $productId = (int) $productId;
        if ($optionId <= 0 || $productId <= 0) {
            return false;
        }

        $row = Capsule::table('tblproductconfigoptions as o')
            ->join('tblproductconfiglinks as l', 'l.gid', '=', 'o.gid')
            ->where('o.id', $optionId)
            ->where('l.pid', $productId)
            ->select('o.id')
            ->first();

        return (bool) $row;
    }
}

if (!function_exists('releem_server_build_params')) {
    /**
     * Builds the standard WHMCS module params outside a module call (hooks, cron).
     */
    function releem_server_build_params($serviceId)
    {
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0) {
            return null;
        }

        try {
            if (class_exists('\WHMCS\Module\Server')) {
                $server = new \WHMCS\Module\Server();
                if ($server->loadByServiceID($serviceId)) {
                    return $server->buildParams();
                }
            }
        } catch (\Throwable $e) {
            releem_server_log('buildParams', ['serviceid' => $serviceId], $e->getMessage());
        }

        // Older WHMCS releases: legacy helper.
        if (!function_exists('ModuleBuildParams') && defined('ROOTDIR') && is_file(ROOTDIR . '/includes/modulefunctions.php')) {
            require_once ROOTDIR . '/includes/modulefunctions.php';
        }
        if (function_exists('ModuleBuildParams')) {
            $params = ModuleBuildParams($serviceId);
            if (is_array($params)) {
                return $params;
            }
        }

        return null;
    }
}

if (!function_exists('releem_server_resync_service')) {
    /**
     * Re-syncs one already provisioned Releem service from WHMCS state (renewals, cron).
     */
    function releem_server_resync_service($serviceId)
    {
        $serviceId = (int) $serviceId;

        try {
            $row = Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.id', $serviceId)
                ->where('p.servertype', 'releem')
                ->select('h.id', 'h.domainstatus')
                ->first();
            if (!$row) {
                return 'not-a-releem-service';
            }

            if (!in_array(strtolower((string) $row->domainstatus), ['active', 'suspended', 'terminated', 'cancelled', 'fraud'], true)) {
                return 'skipped-status';
            }

            $meta = releem_server_get_service_meta($serviceId);
            if (empty($meta['customer_id'])) {
                return 'not-provisioned';
            }

            $params = releem_server_build_params($serviceId);
            if (!is_array($params)) {
                releem_server_log('resync', ['serviceid' => $serviceId], 'Unable to build module params');
                return 'no-params';
            }

            return releem_server_sync_service($params, null);
        } catch (\Throwable $e) {
            releem_server_log('resync', ['serviceid' => $serviceId], $e->getMessage());
            return 'error';
        }
    }
}

if (!function_exists('releem_server_is_sync_failure')) {
    function releem_server_is_sync_failure($result)
    {
        return in_array($result, ['Releem sync failed. Check module log.', 'no-params', 'error'], true);
    }
}

if (!function_exists('releem_server_resync_invoice')) {
    function releem_server_resync_invoice($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return [];
        }

        $results = [];
        try {
            $items = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->where('type', 'Hosting')
                ->where('relid', '>', 0)
                ->pluck('relid')
                ->unique();

            // This runs inside payment processing: keep Releem calls short, the daily reconcile retries failures.
            ReleemServerApi::setTimeouts(5, 10);
            $consecutiveFailures = 0;
            foreach ($items as $serviceId) {
                $results[(int) $serviceId] = releem_server_resync_service((int) $serviceId);
                $consecutiveFailures = releem_server_is_sync_failure($results[(int) $serviceId]) ? $consecutiveFailures + 1 : 0;
                if ($consecutiveFailures >= 2) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            releem_server_log('resyncInvoice', ['invoiceid' => $invoiceId], $e->getMessage());
        } finally {
            ReleemServerApi::resetTimeouts();
        }

        return $results;
    }
}

if (!function_exists('releem_server_reconcile_all')) {
    /**
     * Daily safety net: re-sync services whose WHMCS state differs from what was last sent to Releem.
     */
    function releem_server_reconcile_all($limit = 100)
    {
        $results = [];
        $consecutiveFailures = 0;

        try {
            if (!releem_server_ensure_meta_table()) {
                return $results;
            }

            ReleemServerApi::setTimeouts(10, 20);

            $rows = Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->join('mod_releem_service_meta as m', 'm.service_id', '=', 'h.id')
                ->where('p.servertype', 'releem')
                ->whereIn('h.domainstatus', ['Active', 'Suspended', 'Terminated', 'Cancelled', 'Fraud'])
                ->whereNotNull('m.customer_id')
                ->where('m.customer_id', '!=', '')
                // Least recently attempted first, so rows that never converge cannot starve the others.
                ->orderBy('m.updated_at', 'asc')
                ->orderBy('h.id', 'asc')
                ->select('h.id', 'h.userid', 'h.packageid', 'h.domainstatus', 'h.nextduedate', 'h.billingcycle', 'h.regdate', 'm.status as meta_status', 'm.valid_to as meta_valid_to', 'm.number_servers as meta_servers')
                ->get();

            $optionIds = [];
            foreach ($rows as $row) {
                if (count($results) >= (int) $limit) {
                    break;
                }

                $expectedStatus = releem_server_map_status($row->domainstatus);

                // An active service that lost to a lower-ID active service of the same client can never sync; retrying
                // it every day only burns the run limit and API calls.
                if ($expectedStatus === 'active') {
                    $ownerId = releem_server_find_other_active_service_id((int) $row->id, (int) $row->userid);
                    if ($ownerId > 0 && $ownerId < (int) $row->id) {
                        continue;
                    }
                }

                $expectedValidTo = releem_server_get_service_valid_to($row);
                $statusDiffers = (string) $row->meta_status !== $expectedStatus;
                $validToDiffers = $expectedStatus === 'active'
                    && $expectedValidTo !== ''
                    && (string) $row->meta_valid_to !== $expectedValidTo;

                // Server count edited directly in WHMCS (admin side) never triggers ChangePackage.
                $serversDiffer = false;
                if ($expectedStatus === 'active') {
                    $packageId = (int) $row->packageid;
                    if (!isset($optionIds[$packageId])) {
                        $configured = releem_server_get_product_option_setting($packageId);
                        $optionIds[$packageId] = $configured > 0
                            ? $configured
                            : releem_server_find_linked_server_count_option_id($packageId);
                    }
                    $currentServers = releem_server_get_selected_server_count([], (int) $row->id, $optionIds[$packageId]);
                    $serversDiffer = $currentServers > 0 && (string) $currentServers !== (string) $row->meta_servers;
                }

                if ($statusDiffers || $validToDiffers || $serversDiffer) {
                    $results[(int) $row->id] = releem_server_resync_service((int) $row->id);

                    if ($results[(int) $row->id] !== 'success') {
                        // Rotate unresolved rows to the back of the queue.
                        Capsule::table('mod_releem_service_meta')
                            ->where('service_id', (int) $row->id)
                            ->update(['updated_at' => date('Y-m-d H:i:s')]);
                    }

                    // Stop early during a Releem outage instead of waiting on a timeout for every service.
                    $consecutiveFailures = releem_server_is_sync_failure($results[(int) $row->id]) ? $consecutiveFailures + 1 : 0;
                    if ($consecutiveFailures >= 3) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            releem_server_log('reconcile', [], $e->getMessage());
        } finally {
            ReleemServerApi::resetTimeouts();
        }

        if (!empty($results)) {
            releem_server_log('reconcile', ['count' => count($results)], $results);
        }

        return $results;
    }
}

if (!function_exists('releem_server_extract_service_id')) {
    function releem_server_extract_service_id(array $params)
    {
        if (!empty($params['serviceid'])) {
            return (int) $params['serviceid'];
        }

        if (!empty($params['model']) && is_object($params['model']) && method_exists($params['model'], 'getAttribute')) {
            return (int) $params['model']->getAttribute('id');
        }

        return 0;
    }
}

if (!function_exists('releem_server_get_param')) {
    function releem_server_get_param(array $params, $name)
    {
        if (isset($params[$name]) && is_scalar($params[$name]) && trim((string) $params[$name]) !== '') {
            return trim((string) $params[$name]);
        }

        $nameLower = strtolower((string) $name);
        foreach ($params as $key => $value) {
            if (strtolower((string) $key) === $nameLower && is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
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

if (!function_exists('releem_server_client_email_is_verified')) {
    function releem_server_client_email_is_verified($client)
    {
        if (!is_object($client) || empty($client->email)) {
            return false;
        }

        // WHMCS 8 verifies the account owner's user email, which can differ from the client's billing email.
        // A verified owner only proves ownership of the address actually used for adoption when both match.
        if (class_exists('WHMCS\\User\\Client') && method_exists('WHMCS\\User\\Client', 'owner')) {
            try {
                if (empty($client->id)) {
                    return false;
                }
                $model = \WHMCS\User\Client::find((int) $client->id);
                if (!$model) {
                    return false;
                }
                $owner = $model->owner();
                $email = trim((string) $client->email);

                return $owner
                    && strcasecmp(trim((string) $owner->email), $email) === 0
                    && strcasecmp(trim((string) $model->email), $email) === 0
                    && $model->isEmailAddressVerified();
            } catch (\Throwable $e) {
                releem_server_log('emailVerification', ['clientid' => isset($client->id) ? (int) $client->id : 0], $e->getMessage());
                return false;
            }
        }

        // WHMCS 7 predates separate user accounts and keeps verification on the client itself.
        return isset($client->email_verified) && releem_server_is_truthy($client->email_verified);
    }
}

if (!function_exists('releem_server_may_reveal_public_api_key')) {
    /**
     * An adopted customer belongs to an account this module did not create. Its public API key is shown only after
     * WHMCS has verified the client email, including when an administrator allowed linking by unverified email.
     */
    function releem_server_may_reveal_public_api_key($serviceId)
    {
        $meta = releem_server_get_service_meta($serviceId);
        if (empty($meta['adopted'])) {
            return true;
        }

        $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->select('userid')->first();
        if (!$service || empty($service->userid)) {
            return false;
        }

        $client = Capsule::table('tblclients')->where('id', (int) $service->userid)->first();

        return releem_server_client_email_is_verified($client);
    }
}

if (!function_exists('releem_server_sync_public_api_key')) {
    function releem_server_sync_public_api_key($serviceId, $productId, array $customer, $isAdopted, $client)
    {
        if ($isAdopted && !releem_server_client_email_is_verified($client)) {
            releem_server_set_product_custom_field_value($serviceId, $productId, 'releem_public_api_key', '');
            return;
        }

        releem_server_store_customer_public_api_key($serviceId, $productId, $customer);
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
            ->select('customer_id', 'plan_id', 'subscription_id', 'number_servers', 'status', 'valid_to', 'adopted')
            ->first();

        if (!$row) {
            return [];
        }

        return [
            'customer_id' => isset($row->customer_id) ? (string) $row->customer_id : '',
            'plan_id' => isset($row->plan_id) ? (string) $row->plan_id : '',
            'subscription_id' => isset($row->subscription_id) ? (string) $row->subscription_id : '',
            'number_servers' => isset($row->number_servers) ? (string) $row->number_servers : '',
            'status' => isset($row->status) ? (string) $row->status : '',
            'valid_to' => isset($row->valid_to) ? (string) $row->valid_to : '',
            'adopted' => !empty($row->adopted) ? '1' : '',
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

        $allowedKeys = ['customer_id', 'plan_id', 'subscription_id', 'number_servers', 'status', 'valid_to', 'adopted'];
        $data = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $values)) {
                $data[$key] = (string) $values[$key];
            }
        }

        if (!isset($data['plan_id']) && isset($data['number_servers'])) {
            $data['plan_id'] = $data['number_servers'];
        }

        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $exists = function () use ($serviceId) {
            return (bool) Capsule::table('mod_releem_service_meta')
                ->where('service_id', $serviceId)
                ->select('service_id')
                ->first();
        };

        if (!$exists()) {
            try {
                Capsule::table('mod_releem_service_meta')->insert($data + ['service_id' => $serviceId]);
                return true;
            } catch (\Throwable $e) {
                // A concurrent request created the row first: fall through to update it.
                if (!$exists()) {
                    throw $e;
                }
            }
        }

        Capsule::table('mod_releem_service_meta')
            ->where('service_id', $serviceId)
            ->update($data);

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
                    $table->bigInteger('subscription_id')->unsigned()->nullable();
                    $table->integer('number_servers')->unsigned()->nullable();
                    $table->string('status', 50)->nullable();
                    $table->string('valid_to', 40)->nullable();
                    $table->boolean('adopted')->default(0);
                    $table->timestamp('updated_at')->nullable();
                    $table->primary('service_id');
                });
            } else {
                if (!$schema->hasColumn('mod_releem_service_meta', 'subscription_id')) {
                    $schema->table('mod_releem_service_meta', function ($table) {
                        $table->bigInteger('subscription_id')->unsigned()->nullable()->after('plan_id');
                    });
                } else {
                    // Older installs used a 32-bit column that rejects large numeric gateway subscription IDs.
                    $column = Capsule::select("SHOW COLUMNS FROM `mod_releem_service_meta` LIKE 'subscription_id'");
                    if (!empty($column) && isset($column[0]->Type) && stripos((string) $column[0]->Type, 'bigint') === false) {
                        Capsule::statement('ALTER TABLE `mod_releem_service_meta` MODIFY `subscription_id` BIGINT UNSIGNED NULL');
                    }
                }

                if (!$schema->hasColumn('mod_releem_service_meta', 'number_servers')) {
                    $schema->table('mod_releem_service_meta', function ($table) {
                        $table->integer('number_servers')->unsigned()->nullable()->after('subscription_id');
                    });
                }

                if (!$schema->hasColumn('mod_releem_service_meta', 'valid_to')) {
                    $schema->table('mod_releem_service_meta', function ($table) {
                        $table->string('valid_to', 40)->nullable()->after('status');
                    });
                }

                if (!$schema->hasColumn('mod_releem_service_meta', 'adopted')) {
                    $schema->table('mod_releem_service_meta', function ($table) {
                        $table->boolean('adopted')->default(0)->after('valid_to');
                    });
                }
            }

            $available = true;
        } catch (\Throwable $e) {
            releem_server_log('schema', ['table' => 'mod_releem_service_meta'], $e->getMessage());
            $available = false;
        }

        return $available;
    }
}

if (!function_exists('releem_server_extract_customer_public_api_key')) {
    function releem_server_extract_customer_public_api_key(array $customer)
    {
        foreach (['api_key', 'public-api-key', 'public_api_key', 'api'] as $field) {
            if (!empty($customer[$field]) && is_scalar($customer[$field])) {
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
        if (isset($params[$configKey]) && is_scalar($params[$configKey]) && trim((string) $params[$configKey]) !== '') {
            return trim((string) $params[$configKey]);
        }

        return '';
    }
}

if (!function_exists('releem_server_decrypt_secret')) {
    /**
     * WHMCS stores passwords encrypted and does not always decrypt them in $params. decrypt() returns an empty string
     * for anything that is not an encrypted blob, so a value that is already plain text is kept as-is.
     */
    function releem_server_decrypt_secret($raw, $mustBeEncrypted = false)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return $raw;
        }

        // A value read straight from the database is always encrypted by WHMCS. If it cannot be decrypted (changed
        // encryption hash, corrupted value) the ciphertext must never be sent to Releem as if it were the token.
        $fallback = $mustBeEncrypted ? '' : $raw;
        if (!function_exists('decrypt')) {
            return $fallback;
        }

        try {
            $decrypted = decrypt($raw);
        } catch (\Throwable $e) {
            return $fallback;
        }

        // decrypt() returns binary garbage (not an empty string) for some plain-text inputs such as tokens that
        // contain a "." (found with a real Releem token). Only a clean printable result is a real decryption.
        if (is_string($decrypted) && trim($decrypted) !== '' && preg_match('/^[\x21-\x7e]+$/', trim($decrypted))) {
            return trim($decrypted);
        }

        return $fallback;
    }
}

if (!function_exists('releem_server_is_truthy')) {
    function releem_server_is_truthy($value)
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'y'], true);
    }
}

if (!function_exists('releem_server_build_endpoint_from_server')) {
    /**
     * Builds https://host[:port] from a WHMCS server record. Plain HTTP is never allowed because the API token is
     * sent in a header. The hostname may be entered with or without "https://".
     *
     * @return array ['endpoint' => string, 'error' => string]
     */
    function releem_server_build_endpoint_from_server($hostname, $port, $secure)
    {
        $hostname = trim((string) $hostname);
        if ($hostname === '') {
            return ['endpoint' => '', 'error' => 'Releem server address is empty (set the server Hostname to api2.releem.com)'];
        }

        if (stripos($hostname, 'http://') === 0) {
            return ['endpoint' => '', 'error' => 'Releem server address must not use http://; use api2.releem.com with Secure (SSL) enabled'];
        }

        $hostname = preg_replace('#^https://#i', '', $hostname);
        $hostname = preg_replace('#[/?\#].*$#', '', (string) $hostname);

        $embeddedPort = '';
        if (preg_match('/^([A-Za-z0-9.-]+):([0-9]{1,5})$/', $hostname, $m)) {
            $hostname = $m[1];
            $embeddedPort = $m[2];
        }

        $hostname = rtrim($hostname, '.');
        if (!preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $hostname)) {
            return ['endpoint' => '', 'error' => 'Releem server address is not a valid hostname'];
        }

        if (!ReleemServerApi::isAllowedHost($hostname)) {
            return ['endpoint' => '', 'error' => 'Releem server address must be a releem.com host (use api2.releem.com); the API token is never sent elsewhere'];
        }

        if (!releem_server_is_truthy($secure)) {
            return ['endpoint' => '', 'error' => 'Enable "Secure" (SSL) on the Releem server record; the API token is only sent over HTTPS'];
        }

        $port = trim((string) $port);
        if ($port === '' || $port === '0') {
            $port = $embeddedPort !== '' ? $embeddedPort : '443';
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return ['endpoint' => '', 'error' => 'Releem server port is invalid (use 443)'];
        }

        $endpoint = 'https://' . strtolower($hostname) . ((int) $port === 443 ? '' : ':' . (int) $port);

        return ['endpoint' => $endpoint, 'error' => ''];
    }
}

if (!function_exists('releem_server_load_server_record')) {
    /**
     * The server record assigned to the service, or the first active server of the product's server group
     * (services created before a server was assigned still need to be suspended/terminated).
     */
    function releem_server_load_server_record($service)
    {
        $serverId = isset($service->server) ? (int) $service->server : 0;
        if ($serverId > 0) {
            $row = Capsule::table('tblservers')->where('id', $serverId)->first();
            if ($row) {
                return $row;
            }
        }

        $packageId = isset($service->packageid) ? (int) $service->packageid : 0;
        if ($packageId > 0) {
            $groupId = (int) Capsule::table('tblproducts')->where('id', $packageId)->value('servergroup');
            if ($groupId > 0) {
                return Capsule::table('tblservers as s')
                    ->join('tblservergroupsrel as r', 'r.serverid', '=', 's.id')
                    ->where('r.groupid', $groupId)
                    ->where('s.type', 'releem')
                    ->where('s.disabled', 0)
                    ->orderBy('s.id', 'asc')
                    ->select('s.*')
                    ->first();
            }
        }

        return null;
    }
}

if (!function_exists('releem_server_resolve_credentials')) {
    /**
     * The API token and endpoint come from the WHMCS server record (hostname, port, Secure, password = token).
     * Installs from before the server record existed kept them in the product settings (configoption1 = key,
     * configoption2 = endpoint); that is still honoured when no server record is available.
     *
     * @return array ['key' => string, 'endpoint' => string, 'error' => string]
     */
    function releem_server_resolve_credentials(array $params, $service, $allowPlaintextPassword = false)
    {
        // Provisioning trusts the encrypted server record, not an ambiguous printable parameter that may be ciphertext.
        // Only Test Connection accepts a raw token from the unsaved administrator form.
        $record = $allowPlaintextPassword ? null : releem_server_load_server_record($service);
        $key = releem_server_decrypt_secret(releem_server_get_param($params, 'serverpassword'), !$allowPlaintextPassword);
        $host = releem_server_get_param($params, 'serverhostname');
        $port = releem_server_get_param($params, 'serverport');
        $secure = releem_server_get_param($params, 'serversecure');

        if ($record || $key === '' || $host === '') {
            if (!$record) {
                $record = releem_server_load_server_record($service);
            }
            if ($record) {
                $key = releem_server_decrypt_secret(isset($record->password) ? $record->password : '', true);
                $host = trim((string) (isset($record->hostname) && trim((string) $record->hostname) !== '' ? $record->hostname : (isset($record->ipaddress) ? $record->ipaddress : '')));
                $port = isset($record->port) ? (string) $record->port : '';
                $secure = isset($record->secure) ? (string) $record->secure : '';
            }
        }

        if ($host === '') {
            $host = releem_server_get_param($params, 'serverip');
        }

        if ($key !== '' && $host !== '') {
            $built = releem_server_build_endpoint_from_server($host, $port, $secure);
            if ($built['error'] !== '') {
                return ['key' => '', 'endpoint' => '', 'error' => $built['error']];
            }

            try {
                return ['key' => $key, 'endpoint' => ReleemServerApi::normalizeEndpoint($built['endpoint']), 'error' => ''];
            } catch (InvalidArgumentException $e) {
                return ['key' => '', 'endpoint' => '', 'error' => $e->getMessage()];
            }
        }

        // Legacy product-level settings.
        $legacyKey = releem_server_decrypt_secret(releem_server_get_param($params, 'configoption1'));
        if ($legacyKey !== '' && !ctype_digit($legacyKey)) {
            try {
                $endpoint = ReleemServerApi::normalizeEndpoint(releem_server_get_param($params, 'configoption2'));
            } catch (InvalidArgumentException $e) {
                return ['key' => '', 'endpoint' => '', 'error' => $e->getMessage()];
            }

            return ['key' => $legacyKey, 'endpoint' => $endpoint, 'error' => ''];
        }

        return [
            'key' => '',
            'endpoint' => '',
            'error' => 'Releem secret key is not configured or could not be decrypted (add a Releem server with the API token as its password and assign it to the product)',
        ];
    }
}

if (!function_exists('releem_server_get_server_count_option_id')) {
    function releem_server_get_server_count_option_id(array $params, $productId = 0)
    {
        // Module setting 1 is server_count_option_id. Older installs stored it in setting 3 (plan_config_option_id slot)
        // and kept the partner key in setting 1, which is not numeric and therefore skipped here.
        foreach (['configoption1', 'configoption3', 'server_count_option_id', 'plan_config_option_id'] as $key) {
            $value = releem_server_get_param($params, $key);
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
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

        $product = Capsule::table('tblproducts')->where('id', $productId)->select('id', 'servertype')->first();
        if (!$product || strtolower((string) $product->servertype) !== 'releem') {
            return [
                'ok' => false,
                'message' => 'Product not found or it does not use the Releem server module.',
            ];
        }

        releem_server_ensure_meta_table();

        try {
            return Capsule::connection()->transaction(function () use ($productId) {
                $serverCountOptionId = releem_server_ensure_server_count_option($productId);
                if ($serverCountOptionId <= 0) {
                    throw new RuntimeException('Failed to create or link the Servers configurable option.');
                }

                $fieldId = releem_server_ensure_public_api_key_field($productId);
                if ($fieldId <= 0) {
                    throw new RuntimeException('Failed to create releem_public_api_key custom field.');
                }

                releem_server_set_server_count_option_setting($productId, $serverCountOptionId);

                return [
                    'ok' => true,
                    'message' => 'Releem product setup completed.',
                    'server_count_option_id' => $serverCountOptionId,
                    'public_api_key_field_id' => $fieldId,
                ];
            });
        } catch (\Throwable $e) {
            releem_server_log('setupProduct', ['productid' => $productId], $e->getMessage());
            return [
                'ok' => false,
                'message' => $e instanceof RuntimeException
                    ? $e->getMessage() . ' No changes were saved.'
                    : 'Product setup failed and was rolled back. Check the module log.',
            ];
        }
    }
}

if (!function_exists('releem_server_detect_admin_product_id')) {
    function releem_server_detect_admin_product_id()
    {
        if (!defined('ADMINAREA')) {
            return 0;
        }

        if (empty($_REQUEST['id']) || !is_scalar($_REQUEST['id']) || !ctype_digit((string) $_REQUEST['id'])) {
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

        if (!releem_server_ensure_product_config_group_link($groupId, $productId)) {
            throw new RuntimeException('Failed to link the Releem Servers option group to the product.');
        }

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
                releem_server_ensure_option_pricing((int) $value->id);
            }
        }

        for ($count = 1; $count <= 10; $count++) {
            if (isset($counts[$count])) {
                continue;
            }

            $subId = releem_server_insert_get_id('tblproductconfigoptionssub', [
                'configid' => $optionId,
                'optionname' => (string) $count,
                'sortorder' => $count,
                'order' => $count,
                'hidden' => 0,
            ]);
            if ($subId > 0) {
                releem_server_ensure_option_pricing($subId);
            }
        }
    }
}

if (!function_exists('releem_server_ensure_option_pricing')) {
    /**
     * WHMCS expects a pricing row per currency for every configurable option value. Missing rows are added as free;
     * existing (possibly priced) rows are never touched.
     */
    function releem_server_ensure_option_pricing($subOptionId)
    {
        $subOptionId = (int) $subOptionId;
        if ($subOptionId <= 0) {
            return;
        }

        try {
            $currencies = Capsule::table('tblcurrencies')->pluck('id');
            foreach ($currencies as $currencyId) {
                $exists = Capsule::table('tblpricing')
                    ->where('type', 'configoptions')
                    ->where('currency', (int) $currencyId)
                    ->where('relid', $subOptionId)
                    ->exists();
                if ($exists) {
                    continue;
                }

                releem_server_insert_row('tblpricing', [
                    'type' => 'configoptions',
                    'currency' => (int) $currencyId,
                    'relid' => $subOptionId,
                    'msetupfee' => 0,
                    'qsetupfee' => 0,
                    'ssetupfee' => 0,
                    'asetupfee' => 0,
                    'bsetupfee' => 0,
                    'tsetupfee' => 0,
                    'monthly' => 0,
                    'quarterly' => 0,
                    'semiannually' => 0,
                    'annually' => 0,
                    'biennially' => 0,
                    'triennially' => 0,
                ]);
            }
        } catch (\Throwable $e) {
            releem_server_log('pricing', ['suboptionid' => $subOptionId], $e->getMessage());
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
        } catch (\Throwable $e) {
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

if (!function_exists('releem_server_get_product_option_setting')) {
    /**
     * The configured server-count option ID of a product: setting 1, or setting 3 on legacy installs. 0 when unset.
     */
    function releem_server_get_product_option_setting($productId)
    {
        $row = Capsule::table('tblproducts')->where('id', (int) $productId)->select('configoption1', 'configoption3')->first();
        if (!$row) {
            return 0;
        }

        foreach ([$row->configoption1, $row->configoption3] as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
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
        if (!isset($columns['configoption1'])) {
            return false;
        }

        // Keep a valid manual override (an option that is really linked to this product).
        $current = releem_server_get_product_option_setting($productId);
        if ($current > 0 && releem_server_option_is_linked_to_product($current, $productId)) {
            return true;
        }

        $row = Capsule::table('tblproducts')->where('id', $productId)->select('configoption1')->first();
        $slot1 = $row ? trim((string) $row->configoption1) : '';

        // A non-numeric value in setting 1 is a legacy partner key that still works without a server record:
        // do not overwrite it, use the legacy slot 3 instead.
        $column = ($slot1 === '' || ctype_digit($slot1)) ? 'configoption1' : 'configoption3';
        if (!isset($columns[$column])) {
            return false;
        }

        Capsule::table('tblproducts')
            ->where('id', $productId)
            ->where('servertype', 'releem')
            ->update([$column => (string) $serverCountOptionId]);

        return true;
    }
}

if (!function_exists('releem_server_parse_server_count')) {
    /**
     * Strict parse: the whole value must be a positive whole number, optionally followed by "server(s)"
     * or given as the "value|label" form WHMCS uses for option names. Free text is never scanned for digits.
     */
    function releem_server_parse_server_count($value)
    {
        if (is_int($value) || is_float($value)) {
            $count = (int) $value;
            return ($count > 0 && (float) $count === (float) $value && $count <= 999999999) ? $count : 0;
        }

        if (!is_string($value)) {
            return 0;
        }

        $parts = explode('|', $value, 2);
        $candidate = trim($parts[0]);
        if ($candidate === '') {
            return 0;
        }

        if (preg_match('/^([1-9][0-9]{0,8})(?:\s*servers?)?$/i', $candidate, $matches)) {
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

        if ($serviceId <= 0 || $serverCountOptionId <= 0) {
            return 0;
        }

        $option = Capsule::table('tblproductconfigoptions')
            ->where('id', $serverCountOptionId)
            ->select('id', 'optionname', 'optiontype')
            ->first();
        if (!$option) {
            return 0;
        }

        // WHMCS keys $params['configoptions'] by option name (the part before "|").
        if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
            $nameParts = explode('|', (string) $option->optionname, 2);
            $optionName = trim($nameParts[0]);
            if ($optionName !== '' && isset($params['configoptions'][$optionName])) {
                $count = releem_server_parse_server_count($params['configoptions'][$optionName]);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        $row = Capsule::table('tblhostingconfigoptions')
            ->where('relid', $serviceId)
            ->where('configid', $serverCountOptionId)
            ->select('optionid', 'qty')
            ->first();

        return releem_server_extract_server_count_from_config_row($row, $option);
    }
}

if (!function_exists('releem_server_extract_server_count_from_config_row')) {
    function releem_server_extract_server_count_from_config_row($row, $option = null)
    {
        if (!$row) {
            return 0;
        }

        // Option type 4 is "Quantity": the selected number is the quantity itself.
        $isQuantity = $option && isset($option->optiontype) && (int) $option->optiontype === 4;
        if ($isQuantity) {
            return isset($row->qty) ? releem_server_parse_server_count((int) $row->qty) : 0;
        }

        if (empty($row->optionid)) {
            return 0;
        }

        $subOption = Capsule::table('tblproductconfigoptionssub')
            ->where('id', (int) $row->optionid)
            ->select('optionname')
            ->first();

        if ($subOption && isset($subOption->optionname)) {
            return releem_server_parse_server_count((string) $subOption->optionname);
        }

        return 0;
    }
}

if (!function_exists('releem_server_map_status')) {
    function releem_server_map_status($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'suspended':
            case 'terminated':
            case 'cancelled':
            case 'fraud':
                return 'deleted';
            default:
                return 'active';
        }
    }
}

if (!function_exists('releem_server_get_numeric_subscription_id')) {
    /**
     * The Releem subscription ID is the WHMCS service ID. Gateway subscription IDs are deliberately not used: they
     * live in a different number space and can equal the ID of an unrelated service. Up to 9 digits keeps the value
     * inside a signed 32-bit API integer.
     */
    function releem_server_get_numeric_subscription_id($service, array $params = [])
    {
        $candidates = [];
        if (isset($service->id)) {
            $candidates[] = trim((string) $service->id);
        }
        $candidates[] = releem_server_get_param($params, 'serviceid');

        foreach ($candidates as $value) {
            if ($value !== '' && ctype_digit($value) && strlen($value) <= 9 && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }
}

if (!function_exists('releem_server_subscription_id_in_use')) {
    /**
     * True when another service already sent the same subscription ID to Releem (older installs derived it from the
     * gateway subscription ID).
     */
    function releem_server_subscription_id_in_use($subscriptionId, $serviceId)
    {
        if (!releem_server_ensure_meta_table()) {
            return false;
        }

        return (bool) Capsule::table('mod_releem_service_meta')
            ->where('subscription_id', (int) $subscriptionId)
            ->where('service_id', '!=', (int) $serviceId)
            ->select('service_id')
            ->first();
    }
}

if (!function_exists('releem_server_is_non_recurring_cycle')) {
    function releem_server_is_non_recurring_cycle($billingCycle)
    {
        return in_array(strtolower(trim((string) $billingCycle)), ['free account', 'free', 'one time', 'onetime'], true);
    }
}

if (!function_exists('releem_server_get_service_valid_to')) {
    /**
     * Shared subscription window calculation for provisioning and daily reconciliation.
     */
    function releem_server_get_service_valid_to($service, array $params = [], $includeNonRecurring = true)
    {
        $dueDate = releem_server_get_param($params, 'nextduedate');
        $validTo = releem_server_format_service_datetime(
            $dueDate !== '' ? $dueDate : (isset($service->nextduedate) ? $service->nextduedate : ''),
            true
        );
        if ($validTo !== '' || !$includeNonRecurring
            || !releem_server_is_non_recurring_cycle(isset($service->billingcycle) ? $service->billingcycle : '')) {
            return $validTo;
        }

        $regDate = releem_server_get_param($params, 'regdate');
        $startedAt = releem_server_format_service_datetime(
            $regDate !== '' ? $regDate : (isset($service->regdate) ? $service->regdate : ''),
            false
        );

        // A missing registration date cannot define a stable non-recurring window; sync reports it as invalid.
        return $startedAt !== '' ? releem_server_non_recurring_valid_to($startedAt) : '';
    }
}

if (!function_exists('releem_server_non_recurring_valid_to')) {
    /**
     * Subscription end for services that never renew: ten years after the start date (or today when unknown).
     */
    function releem_server_non_recurring_valid_to($startedAt)
    {
        $base = $startedAt !== '' ? strtotime($startedAt) : false;
        if ($base === false) {
            $base = time();
        }

        return gmdate('Y-m-d', strtotime('+10 years', $base)) . 'T23:59:59Z';
    }
}

if (!function_exists('releem_server_format_service_datetime')) {
    function releem_server_format_service_datetime($value, $endOfDay = false)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            if (!checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                return '';
            }

            return $value . ($endOfDay ? 'T23:59:59Z' : 'T00:00:00Z');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
    }
}

if (!function_exists('releem_server_get_service_amount')) {
    function releem_server_get_service_amount($service, array $params = [])
    {
        // tblhosting.amount is the recurring amount of the service (it has no recurringamount column).
        if (isset($service->amount) && is_numeric(trim((string) $service->amount))) {
            return round((float) $service->amount, 2);
        }

        return 0.0;
    }
}

if (!function_exists('releem_server_get_invoice_id')) {
    function releem_server_get_invoice_id(array $params, $serviceId = 0)
    {
        foreach (['invoiceid', 'invoice_id'] as $paramName) {
            $value = releem_server_get_param($params, $paramName);
            if ($value !== '' && ctype_digit($value) && strlen($value) <= 9) {
                return (int) $value;
            }
        }

        // WHMCS does not pass the invoice to module calls: use the latest paid invoice that billed this service.
        $serviceId = (int) $serviceId;
        if ($serviceId > 0) {
            try {
                $invoiceId = Capsule::table('tblinvoiceitems as i')
                    ->join('tblinvoices as v', 'v.id', '=', 'i.invoiceid')
                    ->where('i.relid', $serviceId)
                    ->where('i.type', 'Hosting')
                    ->where('v.status', 'Paid')
                    ->max('i.invoiceid');

                return (int) $invoiceId;
            } catch (\Throwable $e) {
                return 0;
            }
        }

        return 0;
    }
}

if (!function_exists('releem_server_get_payment_method_label')) {
    function releem_server_get_payment_method_label(array $params, $service)
    {
        foreach (['paymentmethodname', 'paymentmethoddisplayname', 'payment_method_name'] as $paramName) {
            $value = releem_server_get_param($params, $paramName);
            if ($value !== '') {
                return $value;
            }
        }

        $paymentMethod = releem_server_get_param($params, 'paymentmethod');
        if ($paymentMethod === '') {
            $paymentMethod = isset($service->paymentmethod) ? trim((string) $service->paymentmethod) : '';
        }
        if ($paymentMethod === '') {
            return '';
        }

        $friendlyName = releem_server_find_payment_gateway_friendly_name($paymentMethod);
        if ($friendlyName !== '') {
            return $friendlyName;
        }

        // Fallback to a readable label when WHMCS exposes only the system gateway name.
        return releem_server_humanize_identifier($paymentMethod);
    }
}

if (!function_exists('releem_server_find_payment_gateway_friendly_name')) {
    function releem_server_find_payment_gateway_friendly_name($gateway)
    {
        $gateway = trim((string) $gateway);
        if ($gateway === '') {
            return '';
        }

        $columns = releem_server_get_table_columns('tblpaymentgateways');
        if (!isset($columns['gateway']) || !isset($columns['setting']) || !isset($columns['value'])) {
            return '';
        }

        try {
            $row = Capsule::table('tblpaymentgateways')
                ->where('gateway', $gateway)
                ->where(function ($query) {
                    $query->where('setting', 'FriendlyName')
                        ->orWhere('setting', 'name');
                })
                ->orderBy('setting', 'asc')
                ->select('value')
                ->first();
        } catch (\Throwable $e) {
            releem_server_log('paymentMethodLookup', ['gateway' => $gateway], $e->getMessage());
            return '';
        }

        return ($row && isset($row->value)) ? trim((string) $row->value) : '';
    }
}

if (!function_exists('releem_server_humanize_identifier')) {
    function releem_server_humanize_identifier($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[_-]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return ucwords(trim((string) $value));
    }
}

if (!function_exists('releem_server_field_name_matches')) {
    /**
     * WHMCS stores custom field names as "name" or "name|friendly label".
     */
    function releem_server_field_name_matches($storedName, $fieldName)
    {
        $parts = explode('|', (string) $storedName, 2);

        return trim($parts[0]) === (string) $fieldName;
    }
}

if (!function_exists('releem_server_find_product_custom_field')) {
    function releem_server_find_product_custom_field($productId, $fieldName)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return null;
        }

        $rows = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where('fieldname', 'like', $fieldName . '%')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($rows as $row) {
            if (releem_server_field_name_matches($row->fieldname, $fieldName)) {
                return $row;
            }
        }

        return null;
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
            ->where('f.fieldname', 'like', $fieldName . '%');

        if ($productId > 0) {
            $query->where('f.relid', $productId);
        }

        $rows = $query->select('f.fieldname', 'v.value')->get();
        foreach ($rows as $row) {
            if (releem_server_field_name_matches($row->fieldname, $fieldName)) {
                return (string) $row->value;
            }
        }

        return '';
    }
}

if (!function_exists('releem_server_ensure_public_api_key_field')) {
    function releem_server_ensure_public_api_key_field($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }

        $existing = releem_server_find_product_custom_field($productId, 'releem_public_api_key');
        if ($existing) {
            // The module renders the key itself; customers must not be able to edit it on the product page.
            if (isset($existing->adminonly) && (string) $existing->adminonly !== 'on' && (int) $existing->adminonly !== 1) {
                Capsule::table('tblcustomfields')->where('id', (int) $existing->id)->update(['adminonly' => 'on']);
            }

            return (int) $existing->id;
        }

        return releem_server_insert_get_id('tblcustomfields', [
            'type' => 'product',
            'relid' => $productId,
            'fieldname' => 'releem_public_api_key',
            'fieldtype' => 'text',
            'description' => 'Public API key for Releem agent installation',
            'fieldoptions' => '',
            'regexpr' => '',
            'adminonly' => 'on',
            'required' => '',
            'showorder' => '',
            'showinvoice' => '',
            'sortorder' => 0,
        ]);
    }
}

if (!function_exists('releem_server_find_public_api_key_field_id')) {
    function releem_server_find_public_api_key_field_id($productId)
    {
        $field = releem_server_find_product_custom_field($productId, 'releem_public_api_key');

        return $field ? (int) $field->id : 0;
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

        if ($fieldName === 'releem_public_api_key') {
            // Also upgrades legacy, customer-editable fields to admin-only.
            $fieldId = releem_server_ensure_public_api_key_field($productId);
        } else {
            $field = releem_server_find_product_custom_field($productId, $fieldName);
            $fieldId = ($field && isset($field->id)) ? (int) $field->id : 0;
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
            releem_server_insert_row('tblcustomfieldsvalues', [
                'fieldid' => $fieldId,
                'relid' => $serviceId,
                'value' => (string) $value,
            ]);
        }

        return true;
    }
}

if (!function_exists('releem_server_register_secret')) {
    /**
     * Remembers secret strings (partner key) so they can be scrubbed from anything that is logged.
     */
    function releem_server_register_secret($secret = null)
    {
        static $secrets = [];

        if ($secret !== null) {
            $secret = (string) $secret;
            if (strlen($secret) >= 6) {
                $secrets[$secret] = true;
            }
        }

        return array_keys($secrets);
    }
}

if (!function_exists('releem_server_is_sensitive_key')) {
    function releem_server_is_sensitive_key($key)
    {
        return (bool) preg_match(
            '/(pass|secret|api[_-]?key|token|hash|^configoption\d+$|^configoptions$|clientsdetails|^client$|^model$|^customfields?$|accesskey|cardnum|cvv)/i',
            (string) $key
        );
    }
}

if (!function_exists('releem_server_is_personal_key')) {
    /**
     * Customer personal data (email address, names) is kept out of the module log.
     */
    function releem_server_is_personal_key($key)
    {
        return (bool) preg_match('/^(email|name|subscription_?email|firstname|lastname|companyname)$/i', (string) $key);
    }
}

if (!function_exists('releem_server_redact')) {
    function releem_server_redact($data)
    {
        if (is_string($data)) {
            foreach (releem_server_register_secret() as $secret) {
                $data = str_replace($secret, '[REDACTED]', $data);
            }

            // Email addresses in free text (API error bodies, exception messages).
            $mailScrubbed = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[EMAIL]', $data);
            if (is_string($mailScrubbed)) {
                $data = $mailScrubbed;
            }

            // API error bodies may echo key material.
            $scrubbed = preg_replace('/("(?:[a-z_-]*(?:api[_-]?key|token|secret|password)[a-z_-]*)"\s*:\s*")[^"]*(")/i', '$1[REDACTED]$2', $data);

            return is_string($scrubbed) ? $scrubbed : $data;
        }

        if (!is_array($data)) {
            return is_object($data) ? '[OBJECT]' : $data;
        }

        $redacted = [];
        foreach ($data as $key => $value) {
            if (releem_server_is_sensitive_key($key) || releem_server_is_personal_key($key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = releem_server_redact($value);
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
