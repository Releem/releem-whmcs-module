<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!function_exists('releem_setup_config')) {
    function releem_setup_config()
    {
        return [
            'name' => 'Releem Setup',
            'description' => 'Admin setup utility for Releem WHMCS products.',
            'version' => '1.0.0',
            'author' => 'Releem',
        ];
    }
}

if (!function_exists('releem_setup_activate')) {
    function releem_setup_activate()
    {
        return ['status' => 'success', 'description' => 'Releem Setup addon activated.'];
    }
}

if (!function_exists('releem_setup_deactivate')) {
    function releem_setup_deactivate()
    {
        return ['status' => 'success', 'description' => 'Releem Setup addon deactivated.'];
    }
}

if (!function_exists('releem_setup_output')) {
    function releem_setup_output(array $vars)
    {
        releem_setup_require_server_module();

        $message = null;
        $csrfToken = releem_setup_get_csrf_token();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'setup_product') {
            if (!releem_setup_validate_csrf_token(isset($_POST['releem_setup_token']) ? $_POST['releem_setup_token'] : '')) {
                $message = ['type' => 'error', 'text' => 'Your session token was invalid. Reload the page and try again.'];
            } else {
                $productId = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
                if ($productId <= 0) {
                    $message = ['type' => 'error', 'text' => 'Invalid product ID.'];
                } elseif (!function_exists('releem_server_setup_product')) {
                    $message = ['type' => 'error', 'text' => 'Releem server module could not be loaded.'];
                } else {
                    $result = releem_server_setup_product($productId);
                    $text = !empty($result['message']) ? (string) $result['message'] : 'Setup finished.';
                    if (!empty($result['ok']) && !empty($result['server_count_option_id'])) {
                        $text .= ' Servers option ID: ' . (int) $result['server_count_option_id'] . '.';
                    }
                    $message = [
                        'type' => !empty($result['ok']) ? 'success' : 'error',
                        'text' => $text,
                    ];
                }
            }
        }

        $products = releem_setup_get_products();

        echo '<h2>Releem Product Setup</h2>';
        echo '<p>Use this page to create or reuse the <code>Servers</code> configurable option, values <code>1..10</code>, and the <code>releem_public_api_key</code> custom field for Releem products.</p>';

        if ($message) {
            $className = $message['type'] === 'success' ? 'successbox' : 'errorbox';
            echo '<div class="' . $className . '" style="margin-bottom: 15px;">'
                . htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        if (empty($products)) {
            echo '<div class="infobox">No products using the Releem server module were found.</div>';
            return;
        }

        echo '<table class="table table-striped">';
        echo '<thead><tr>';
        echo '<th>ID</th><th>Name</th><th>Server Count Option</th><th>Public API Key Field</th><th>Action</th>';
        echo '</tr></thead><tbody>';

        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $optionId = function_exists('releem_server_find_linked_server_count_option_id')
                ? (int) releem_server_find_linked_server_count_option_id($productId)
                : 0;
            $fieldId = function_exists('releem_server_find_public_api_key_field_id')
                ? (int) releem_server_find_public_api_key_field_id($productId)
                : 0;

            echo '<tr>';
            echo '<td>' . $productId . '</td>';
            echo '<td>' . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . ($optionId > 0 ? $optionId : '<span class="text-muted">Missing</span>') . '</td>';
            echo '<td>' . ($fieldId > 0 ? $fieldId : '<span class="text-muted">Missing</span>') . '</td>';
            echo '<td>';
            echo '<form method="post" style="margin:0;">';
            echo '<input type="hidden" name="action" value="setup_product">';
            echo '<input type="hidden" name="product_id" value="' . $productId . '">';
            echo '<input type="hidden" name="releem_setup_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
            echo '<button type="submit" class="btn btn-default">Setup Product</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

if (!function_exists('releem_setup_require_server_module')) {
    function releem_setup_require_server_module()
    {
        $modulePath = dirname(__DIR__, 2) . '/servers/releem/releem.php';
        if (is_file($modulePath)) {
            require_once $modulePath;
        }
    }
}

if (!function_exists('releem_setup_get_csrf_token')) {
    function releem_setup_get_csrf_token()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION['releem_setup_token'])) {
            $_SESSION['releem_setup_token'] = releem_setup_generate_csrf_token();
        }

        return (string) $_SESSION['releem_setup_token'];
    }
}

if (!function_exists('releem_setup_generate_csrf_token')) {
    function releem_setup_generate_csrf_token()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(32));
        }

        return sha1(uniqid('releem_setup_', true) . mt_rand());
    }
}

if (!function_exists('releem_setup_validate_csrf_token')) {
    function releem_setup_validate_csrf_token($submittedToken)
    {
        $expectedToken = releem_setup_get_csrf_token();
        $submittedToken = (string) $submittedToken;

        if ($expectedToken === '' || $submittedToken === '') {
            return false;
        }

        return function_exists('hash_equals')
            ? hash_equals($expectedToken, $submittedToken)
            : $expectedToken === $submittedToken;
    }
}

if (!function_exists('releem_setup_get_products')) {
    function releem_setup_get_products()
    {
        $rows = Capsule::table('tblproducts')
            ->where('servertype', 'releem')
            ->orderBy('id', 'asc')
            ->select('id', 'name')
            ->get();

        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'id' => isset($row->id) ? (int) $row->id : 0,
                'name' => isset($row->name) ? (string) $row->name : '',
            ];
        }

        return $products;
    }
}
