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
            'description' => 'Releem services dashboard, sales report and product setup for the Releem WHMCS module.',
            'version' => '1.3.2',
            'author' => 'Releem',
            'fields' => [
                'allow_email_adoption' => [
                    'FriendlyName' => 'Link existing Releem customers by email',
                    'Type' => 'yesno',
                    'Description' => 'Allow a client to be linked to a Releem customer that already exists for the same email address even when the email is not verified in WHMCS. The public API key stays hidden until WHMCS verifies that email. Leave off unless you trust the clients\' email addresses: an unverified profile email can still be attached to that customer\'s subscription.',
                ],
            ],
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
        return [
            'status' => 'success',
            'description' => 'Releem Setup addon deactivated. Warning: the Releem renewal (InvoicePaid) and daily reconcile hooks '
                . 'are loaded by this addon, so Releem subscriptions are no longer kept in sync with WHMCS until it is activated again.',
        ];
    }
}

if (!function_exists('releem_setup_pages')) {
    /**
     * Dashboard pages: key => [label, icon, external url (optional)].
     */
    function releem_setup_pages()
    {
        return [
            'home' => ['label' => 'Services', 'icon' => 'fa fa-list'],
            'report' => ['label' => 'Report', 'icon' => 'fa fa-pie-chart'],
            'setup' => ['label' => 'Product Setup', 'icon' => 'fa fa-cogs'],
            'documentation' => ['label' => 'Documentation', 'icon' => 'fa fa-book', 'url' => 'https://docs.releem.com'],
        ];
    }
}

if (!function_exists('releem_setup_h')) {
    function releem_setup_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('releem_setup_assets_url')) {
    function releem_setup_assets_url()
    {
        $base = '';
        if (class_exists('\WHMCS\Utility\Environment\WebHelper') && method_exists('\WHMCS\Utility\Environment\WebHelper', 'getBaseUrl')) {
            $base = rtrim((string) \WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');
        }

        // The admin directory sits next to modules/, so a relative URL works when the base URL is unknown.
        return ($base !== '' ? $base : '..') . '/modules/addons/releem_setup/assets';
    }
}

if (!function_exists('releem_setup_page_url')) {
    function releem_setup_page_url($page, array $extra = [])
    {
        $params = ['module' => 'releem_setup'];
        if ($page !== 'home') {
            $params['page'] = $page;
        }

        return 'addonmodules.php?' . http_build_query($params + $extra);
    }
}

if (!function_exists('releem_setup_output')) {
    function releem_setup_output(array $vars)
    {
        releem_setup_require_server_module();

        $pages = releem_setup_pages();
        $page = (isset($_GET['page']) && is_string($_GET['page']) && isset($pages[$_GET['page']]) && empty($pages[$_GET['page']]['url']))
            ? $_GET['page']
            : 'home';

        $message = null;
        if ($page === 'setup') {
            $message = releem_setup_handle_setup_post();
        }

        switch ($page) {
            case 'report':
                $content = releem_setup_render_report();
                break;
            case 'setup':
                $content = releem_setup_render_setup($message);
                break;
            default:
                $content = releem_setup_render_home();
        }

        echo releem_setup_render_layout($page, $pages, $content, isset($vars['version']) ? (string) $vars['version'] : '');
    }
}

if (!function_exists('releem_setup_render_layout')) {
    function releem_setup_render_layout($currentPage, array $pages, $content, $version)
    {
        $assets = releem_setup_assets_url();
        $cacheBust = '?v=' . rawurlencode($version !== '' ? $version : '1');

        $nav = '';
        foreach ($pages as $key => $page) {
            $external = !empty($page['url']);
            $href = $external ? $page['url'] : releem_setup_page_url($key);
            $nav .= '<li class="rl-nav__item' . (!$external && $key === $currentPage ? ' is-active' : '') . '">'
                . '<a class="rl-nav__link" href="' . releem_setup_h($href) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>'
                . '<i class="rl-nav__icon ' . releem_setup_h($page['icon']) . '"></i>'
                . '<span>' . releem_setup_h($page['label']) . '</span></a></li>';
        }

        $crumbs = '<li><a href="' . releem_setup_h(releem_setup_page_url('home')) . '">Releem</a></li>';
        if ($currentPage !== 'home') {
            $crumbs .= '<li>' . releem_setup_h($pages[$currentPage]['label']) . '</li>';
        } else {
            $crumbs .= '<li>' . releem_setup_h($pages['home']['label']) . '</li>';
        }

        $html = '<link rel="stylesheet" href="' . releem_setup_h($assets) . '/css/releem.css' . $cacheBust . '">';
        $html .= '<div id="releem-app">';
        $html .= '<div class="rl-navbar">';
        $html .= '<div class="rl-navbar__top">';
        $html .= '<a class="rl-brand" href="' . releem_setup_h(releem_setup_page_url('home')) . '">'
            . '<span class="rl-brand__logo"><img src="' . releem_setup_h($assets) . '/img/releem-icon.png" alt="Releem"></span>'
            . '<span class="rl-brand__text">Releem<span class="rl-brand__sub">WHMCS Module Dashboard</span></span></a>';
        $html .= '<div class="rl-navbar__vendor">Powered by <a href="https://releem.com" target="_blank" rel="noopener">releem.com</a>'
            . ($version !== '' ? ' &middot; v' . releem_setup_h($version) : '') . '</div>';
        $html .= '</div>';
        $html .= '<ul class="rl-nav">' . $nav . '</ul>';
        $html .= '</div>';
        $html .= '<div class="rl-main">';
        $html .= '<ul class="rl-breadcrumb">' . $crumbs . '</ul>';
        $html .= $content;
        $html .= '</div></div>';
        $html .= '<script src="' . releem_setup_h($assets) . '/js/chart.umd.min.js' . $cacheBust . '"></script>';
        $html .= '<script src="' . releem_setup_h($assets) . '/js/releem.js' . $cacheBust . '"></script>';

        return $html;
    }
}

if (!function_exists('releem_setup_handle_setup_post')) {
    function releem_setup_handle_setup_post()
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($requestMethod !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'setup_product') {
            return null;
        }

        if (!releem_setup_validate_csrf_token(isset($_POST['releem_setup_token']) ? $_POST['releem_setup_token'] : '')) {
            return ['type' => 'error', 'text' => 'Your session token was invalid. Reload the page and try again.'];
        }
        if (!releem_setup_can_edit_products()) {
            return ['type' => 'error', 'text' => 'Your administrator role is not allowed to edit products/services.'];
        }

        $productId = (isset($_POST['product_id']) && is_scalar($_POST['product_id']) && ctype_digit((string) $_POST['product_id']))
            ? (int) $_POST['product_id']
            : 0;
        if ($productId <= 0) {
            return ['type' => 'error', 'text' => 'Invalid product ID.'];
        }
        if (!function_exists('releem_server_setup_product')) {
            return ['type' => 'error', 'text' => 'Releem server module could not be loaded.'];
        }

        $result = releem_server_setup_product($productId);
        $text = !empty($result['message']) ? (string) $result['message'] : 'Setup finished.';
        if (!empty($result['ok']) && !empty($result['server_count_option_id'])) {
            $text .= ' Servers option ID: ' . (int) $result['server_count_option_id'] . '.';
        }

        return ['type' => !empty($result['ok']) ? 'success' : 'error', 'text' => $text];
    }
}

if (!function_exists('releem_setup_can_edit_products')) {
    /**
     * Product Setup changes products, configurable options and custom fields, so it requires the same admin
     * permission as editing a product. Denied when the permission API is unavailable.
     */
    function releem_setup_can_edit_products()
    {
        if (!function_exists('checkPermission')) {
            return false;
        }

        try {
            return (bool) checkPermission('Edit Products/Services', true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('releem_setup_whmcs_token_field')) {
    /**
     * WHMCS rejects admin POST requests that do not carry its own CSRF token.
     */
    function releem_setup_whmcs_token_field()
    {
        if (!function_exists('generate_token')) {
            return '';
        }

        $token = generate_token('plain');

        return '<input type="hidden" name="token" value="' . releem_setup_h($token) . '">';
    }
}

if (!function_exists('releem_setup_widget')) {
    function releem_setup_widget($title, $icon, $body, $flush = false)
    {
        return '<div class="rl-widget"><div class="rl-widget__top"><div class="rl-widget__title">'
            . ($icon !== '' ? '<i class="' . releem_setup_h($icon) . '"></i>' : '')
            . releem_setup_h($title) . '</div></div>'
            . '<div class="rl-widget__body' . ($flush ? ' rl-widget__body--flush' : '') . '">' . $body . '</div></div>';
    }
}

if (!function_exists('releem_setup_status_color')) {
    function releem_setup_status_color($status)
    {
        switch ($status) {
            case 'Active':
                return '#5bc758';
            case 'Suspended':
            case 'Fraud':
                return '#ed4040';
            case 'Pending':
                return '#ff9900';
            case 'Cancelled':
                return '#8a8f98';
            default:
                return '#000000';
        }
    }
}

if (!function_exists('releem_setup_status_label')) {
    function releem_setup_status_label($status)
    {
        return '<span class="rl-label" style="background:' . releem_setup_h(releem_setup_status_color($status)) . '">'
            . releem_setup_h($status) . '</span>';
    }
}

if (!function_exists('releem_setup_product_options')) {
    /**
     * All products using the Releem server module: id => name (group name added when names repeat).
     */
    function releem_setup_product_options()
    {
        $products = Capsule::table('tblproducts')
            ->leftJoin('tblproductgroups', 'tblproductgroups.id', '=', 'tblproducts.gid')
            ->where('tblproducts.servertype', 'releem')
            ->orderBy('tblproductgroups.order')
            ->orderBy('tblproducts.order')
            ->orderBy('tblproducts.name')
            ->get(['tblproducts.id', 'tblproducts.name', 'tblproductgroups.name as groupname']);

        $counts = [];
        foreach ($products as $product) {
            $counts[$product->name] = (isset($counts[$product->name]) ? $counts[$product->name] : 0) + 1;
        }

        $options = [];
        foreach ($products as $product) {
            $label = (string) $product->name;
            if ($counts[$product->name] > 1) {
                $label .= ' (' . ($product->groupname ?: '#' . $product->id) . ')';
            }
            $options[(int) $product->id] = $label;
        }

        return $options;
    }
}

if (!function_exists('releem_setup_meta_available')) {
    function releem_setup_meta_available()
    {
        try {
            return Capsule::schema()->hasTable('mod_releem_service_meta');
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('releem_setup_home_row_limit')) {
    function releem_setup_home_row_limit()
    {
        return 2000;
    }
}

if (!function_exists('releem_setup_render_home')) {
    function releem_setup_render_home()
    {
        $options = releem_setup_product_options();
        $productId = (isset($_GET['product']) && is_string($_GET['product']) && ctype_digit($_GET['product'])) ? (int) $_GET['product'] : 0;
        if ($productId > 0 && !isset($options[$productId])) {
            $productId = 0;
        }

        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->where('p.servertype', 'releem');

        $hasMeta = releem_setup_meta_available();
        if ($hasMeta) {
            $query->leftJoin('mod_releem_service_meta as m', 'm.service_id', '=', 'h.id');
        }
        if ($productId > 0) {
            $query->where('p.id', $productId);
        }

        $columns = ['h.id', 'h.userid', 'h.domain', 'h.domainstatus', 'p.name as productname', 'c.firstname', 'c.lastname', 'c.companyname'];
        if ($hasMeta) {
            $columns = array_merge($columns, ['m.customer_id', 'm.number_servers', 'm.valid_to']);
        }
        $totalRows = (int) (clone $query)->count();
        $rows = $query->orderBy('h.id', 'desc')->limit(releem_setup_home_row_limit())->get($columns);

        $filter = '<form method="get" action="addonmodules.php" style="margin:0;"><input type="hidden" name="module" value="releem_setup">'
            . '<label>Product <select class="rl-control" name="product" onchange="this.form.submit()"><option value="">All</option>';
        foreach ($options as $id => $name) {
            $filter .= '<option value="' . (int) $id . '"' . ($id === $productId ? ' selected' : '') . '>' . releem_setup_h($name) . '</option>';
        }
        $filter .= '</select></label></form>';

        $body = '';
        if ($totalRows > count($rows)) {
            $body .= '<div class="rl-alert rl-alert--info" style="margin:12px;">Showing the newest ' . (int) count($rows) . ' of ' . $totalRows
                . ' services. Use the Product filter to narrow the list.</div>';
        }
        $body .= '<div data-rl-table data-sort-col="0" data-sort-dir="desc">';
        $body .= '<div class="rl-toolbar">' . $filter
            . '<label>Show <select class="rl-control" data-rl-length><option>10</option><option selected>25</option><option>50</option><option>100</option><option>200</option><option value="0">All</option></select></label>'
            . '<span class="rl-toolbar__spacer"></span>'
            . '<label>Search <input type="search" class="rl-control" data-rl-search placeholder="Search..."></label></div>';
        $body .= '<table class="rl-table"><thead><tr>';
        foreach (['ID', 'Client', 'Product', 'Domain', 'Releem Customer ID', 'Servers', 'Valid Until', 'Status'] as $heading) {
            $body .= '<th class="rl-sortable">' . $heading . '</th>';
        }
        $body .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $company = trim((string) $row->companyname);
            $clientName = $company !== '' ? $company : trim($row->firstname . ' ' . $row->lastname);
            $validTo = $hasMeta && !empty($row->valid_to) ? substr((string) $row->valid_to, 0, 10) : '';
            $body .= '<tr>'
                . '<td><a href="clientsservices.php?userid=' . (int) $row->userid . '&amp;id=' . (int) $row->id . '">' . (int) $row->id . '</a></td>'
                . '<td><a href="clientssummary.php?userid=' . (int) $row->userid . '">' . releem_setup_h($clientName) . '</a></td>'
                . '<td>' . releem_setup_h($row->productname) . '</td>'
                . '<td>' . releem_setup_h($row->domain) . '</td>'
                . '<td>' . ($hasMeta && !empty($row->customer_id) ? releem_setup_h($row->customer_id) : '<span class="rl-muted">-</span>') . '</td>'
                . '<td>' . ($hasMeta && !empty($row->number_servers) ? (int) $row->number_servers : '<span class="rl-muted">-</span>') . '</td>'
                . '<td>' . ($validTo !== '' ? releem_setup_h($validTo) : '<span class="rl-muted">-</span>') . '</td>'
                . '<td data-sort="' . releem_setup_h($row->domainstatus) . '">' . releem_setup_status_label((string) $row->domainstatus) . '</td>'
                . '</tr>';
        }
        $body .= '<tr class="rl-empty-row"' . (count($rows) > 0 ? ' style="display:none"' : '') . '><td colspan="8">No Releem services found.</td></tr>';
        $body .= '</tbody></table>';
        $body .= '<div class="rl-pager"><span data-rl-info></span><span class="rl-pager__buttons" data-rl-pages></span></div></div>';

        return releem_setup_widget('Releem Services', 'fa fa-list', $body, true);
    }
}

if (!function_exists('releem_setup_report_periods')) {
    function releem_setup_report_periods()
    {
        return [
            'today' => 'Today',
            'lastWeek' => 'Last 7 days',
            'lastMonth' => 'Last 30 days',
            'lastYear' => 'Last year',
            'allTime' => 'All time',
            'custom' => 'Custom',
        ];
    }
}

if (!function_exists('releem_setup_valid_date')) {
    function releem_setup_valid_date($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);

        return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
    }
}

if (!function_exists('releem_setup_resolve_period')) {
    /**
     * @return array [from|null, to|null] as Y-m-d, null = no limit
     */
    function releem_setup_resolve_period($period, $from, $to)
    {
        $today = new \DateTime('today');

        switch ($period) {
            case 'today':
                return [$today->format('Y-m-d'), $today->format('Y-m-d')];
            case 'lastWeek':
                return [(clone $today)->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')];
            case 'lastMonth':
                return [(clone $today)->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')];
            case 'lastYear':
                return [(clone $today)->modify('-1 year')->modify('+1 day')->format('Y-m-d'), $today->format('Y-m-d')];
            case 'custom':
                $from = releem_setup_valid_date($from);
                $to = releem_setup_valid_date($to);
                if ($from !== null && $to !== null && $from > $to) {
                    list($from, $to) = [$to, $from];
                }

                return [$from, $to];
        }

        return [null, null];
    }
}

if (!function_exists('releem_setup_build_report')) {
    function releem_setup_build_report($from, $to)
    {
        $mainStatuses = ['Active', 'Suspended', 'Terminated', 'Cancelled'];
        $products = releem_setup_product_options();

        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('p.servertype', 'releem');
        if ($from !== null) {
            $query->where('h.regdate', '>=', $from);
        }
        if ($to !== null) {
            $query->where('h.regdate', '<=', $to);
        }
        $rows = $query->groupBy('h.packageid', 'h.domainstatus')
            ->get(['h.packageid', 'h.domainstatus', Capsule::raw('COUNT(*) as total')]);

        $byProduct = [];
        $statusTotals = array_fill_keys($mainStatuses, 0);
        $total = 0;
        foreach ($rows as $row) {
            $productId = (int) $row->packageid;
            $status = (string) $row->domainstatus;
            $count = (int) $row->total;

            if (!isset($byProduct[$productId])) {
                $byProduct[$productId] = [
                    'name' => isset($products[$productId]) ? $products[$productId] : ('#' . $productId),
                    'total' => 0,
                    'statuses' => array_fill_keys($mainStatuses, 0),
                    'other' => 0,
                ];
            }
            $byProduct[$productId]['total'] += $count;
            if (in_array($status, $mainStatuses, true)) {
                $byProduct[$productId]['statuses'][$status] += $count;
            } else {
                $byProduct[$productId]['other'] += $count;
            }
            $statusTotals[$status] = (isset($statusTotals[$status]) ? $statusTotals[$status] : 0) + $count;
            $total += $count;
        }

        usort($byProduct, function ($a, $b) {
            return $b['total'] === $a['total'] ? strcmp($a['name'], $b['name']) : $b['total'] - $a['total'];
        });
        foreach ($byProduct as &$product) {
            $product['share'] = $total > 0 ? round($product['total'] * 100 / $total, 1) : 0;
        }
        unset($product);

        // status of all Releem services right now, whatever their order date
        $current = array_fill_keys($mainStatuses, 0);
        $currentRows = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('p.servertype', 'releem')
            ->groupBy('h.domainstatus')
            ->get(['h.domainstatus', Capsule::raw('COUNT(*) as total')]);
        foreach ($currentRows as $row) {
            $status = (string) $row->domainstatus;
            $current[$status] = (isset($current[$status]) ? $current[$status] : 0) + (int) $row->total;
        }

        $list = function (array $totals) use ($mainStatuses) {
            $out = [];
            foreach ($mainStatuses as $status) {
                $out[] = ['status' => $status, 'count' => isset($totals[$status]) ? (int) $totals[$status] : 0];
            }
            foreach ($totals as $status => $count) {
                if (!in_array($status, $mainStatuses, true) && $count > 0) {
                    $out[] = ['status' => (string) $status, 'count' => (int) $count];
                }
            }

            return $out;
        };

        return [
            'total' => $total,
            'products' => $byProduct,
            'statusTotals' => $list($statusTotals),
            'currentTotals' => $list($current),
        ];
    }
}

if (!function_exists('releem_setup_render_report')) {
    function releem_setup_render_report()
    {
        $periods = releem_setup_report_periods();
        $period = (isset($_GET['period']) && is_string($_GET['period']) && isset($periods[$_GET['period']])) ? $_GET['period'] : 'lastMonth';
        $dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : '';
        $dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : '';
        list($from, $to) = releem_setup_resolve_period($period, $dateFrom, $dateTo);
        $report = releem_setup_build_report($from, $to);

        $palette = ['#f26b3a', '#2f8de4', '#5bc758', '#ff9900', '#9b59b6', '#1abc9c', '#e74c3c', '#34495e', '#f1c40f', '#7f8c8d'];

        $body = '<div class="rl-filters"><span class="rl-filters__label">Period:</span>';
        foreach ($periods as $key => $label) {
            $body .= '<a class="rl-btn' . ($key === $period ? ' rl-btn--primary' : '') . '" href="' . releem_setup_h(releem_setup_page_url('report', ['period' => $key])) . '">'
                . releem_setup_h($label) . '</a>';
        }
        if ($period === 'custom') {
            $body .= '<form method="get" action="addonmodules.php"><input type="hidden" name="module" value="releem_setup"><input type="hidden" name="page" value="report"><input type="hidden" name="period" value="custom">'
                . '<label>From <input type="date" class="rl-control" name="dateFrom" value="' . releem_setup_h($from) . '"></label>'
                . '<label>To <input type="date" class="rl-control" name="dateTo" value="' . releem_setup_h($to) . '"></label>'
                . '<button type="submit" class="rl-btn rl-btn--success">Apply</button></form>';
        }
        $body .= '</div>';

        $body .= '<div class="rl-range">Showing services ordered ';
        $body .= ($from !== null || $to !== null)
            ? 'between <b>' . releem_setup_h($from !== null ? $from : '...') . '</b> and <b>' . releem_setup_h($to !== null ? $to : '...') . '</b>'
            : '(<b>all time</b>)';
        $body .= '</div>';

        $body .= '<div class="rl-cards"><div class="rl-card rl-card--total"><div class="rl-card__value">' . (int) $report['total'] . '</div><div class="rl-card__label">Total sold</div></div>';
        foreach ($report['statusTotals'] as $item) {
            $color = releem_setup_status_color($item['status']);
            $body .= '<div class="rl-card" style="border-top-color:' . releem_setup_h($color) . '"><div class="rl-card__value" style="color:' . releem_setup_h($color) . '">'
                . (int) $item['count'] . '</div><div class="rl-card__label">' . releem_setup_h($item['status']) . '</div></div>';
        }
        $body .= '</div><div class="rl-hint">Counts are the current status of the services ordered in the selected period.</div>';

        $labels = [];
        $values = [];
        $colors = [];
        foreach ($report['products'] as $index => $product) {
            $labels[] = $product['name'];
            $values[] = $product['total'];
            $colors[] = $palette[$index % count($palette)];
        }

        $body .= '<div class="rl-report-main">';
        if ($report['total'] > 0) {
            $chart = json_encode(['labels' => $labels, 'values' => $values, 'colors' => $colors], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
            $body .= '<div class="rl-report-chart"><canvas data-chart="' . releem_setup_h($chart) . '"></canvas></div>';
        } else {
            $body .= '<div class="rl-report-chart"><div class="rl-empty">No services were ordered in this period.</div></div>';
        }

        $body .= '<div class="rl-report-table"><table class="rl-table"><thead><tr><th>Product</th><th class="rl-num">Sold</th><th class="rl-num">Active</th>'
            . '<th class="rl-num">Suspended</th><th class="rl-num">Terminated</th><th class="rl-num">Cancelled</th><th class="rl-num">Other</th><th class="rl-num">Share</th></tr></thead><tbody>';
        foreach ($report['products'] as $index => $product) {
            $body .= '<tr><td><span class="rl-dot" style="background:' . releem_setup_h($colors[$index]) . '"></span>' . releem_setup_h($product['name']) . '</td>'
                . '<td class="rl-num"><b>' . (int) $product['total'] . '</b></td>'
                . '<td class="rl-num">' . (int) $product['statuses']['Active'] . '</td>'
                . '<td class="rl-num">' . (int) $product['statuses']['Suspended'] . '</td>'
                . '<td class="rl-num">' . (int) $product['statuses']['Terminated'] . '</td>'
                . '<td class="rl-num">' . (int) $product['statuses']['Cancelled'] . '</td>'
                . '<td class="rl-num">' . (int) $product['other'] . '</td>'
                . '<td class="rl-num">' . releem_setup_h($product['share']) . '%</td></tr>';
        }
        if (empty($report['products'])) {
            $body .= '<tr class="rl-empty-row"><td colspan="8">No data for this period.</td></tr>';
        }
        $body .= '</tbody></table></div></div>';

        $body .= '<div class="rl-current"><span class="rl-filters__label">Current status of all Releem services:</span>';
        foreach ($report['currentTotals'] as $item) {
            $body .= '<span class="rl-pill">' . releem_setup_h($item['status']) . ': <b style="color:' . releem_setup_h(releem_setup_status_color($item['status'])) . '">'
                . (int) $item['count'] . '</b></span>';
        }
        $body .= '</div>';

        return releem_setup_widget('Sales Report', 'fa fa-pie-chart', $body);
    }
}

if (!function_exists('releem_setup_render_setup')) {
    function releem_setup_render_setup($message)
    {
        $csrfToken = releem_setup_get_csrf_token();
        $products = releem_setup_get_products();

        $out = '';
        if ($message) {
            $out .= '<div class="rl-alert rl-alert--' . ($message['type'] === 'success' ? 'success' : 'error') . '">' . releem_setup_h($message['text']) . '</div>';
        }

        if (!releem_setup_can_edit_products()) {
            $out .= '<div class="rl-alert rl-alert--info">Your administrator role is not allowed to edit products/services, so Product Setup is read-only.</div>';
        }

        $body = '<p class="rl-desc">Create or reuse the <code>Servers</code> configurable option (values <code>1..10</code>) and the <code>releem_public_api_key</code> custom field for Releem products.</p>';

        if (empty($products)) {
            $body .= '<div class="rl-alert rl-alert--info" style="margin:0;">No products using the Releem server module were found.</div>';

            return $out . releem_setup_widget('Releem Product Setup', 'fa fa-cogs', $body);
        }

        $body .= '<table class="rl-table"><thead><tr><th>ID</th><th>Name</th><th>Server Count Option</th><th>Public API Key Field</th><th>Action</th></tr></thead><tbody>';
        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $optionId = function_exists('releem_server_find_linked_server_count_option_id') ? (int) releem_server_find_linked_server_count_option_id($productId) : 0;
            $fieldId = function_exists('releem_server_find_public_api_key_field_id') ? (int) releem_server_find_public_api_key_field_id($productId) : 0;

            $body .= '<tr><td>' . $productId . '</td><td>' . releem_setup_h($product['name']) . '</td>'
                . '<td>' . ($optionId > 0 ? $optionId : '<span class="rl-muted">Missing</span>') . '</td>'
                . '<td>' . ($fieldId > 0 ? $fieldId : '<span class="rl-muted">Missing</span>') . '</td>'
                . '<td><form method="post" action="' . releem_setup_h(releem_setup_page_url('setup')) . '" style="margin:0;">'
                . '<input type="hidden" name="action" value="setup_product">'
                . '<input type="hidden" name="product_id" value="' . $productId . '">'
                . '<input type="hidden" name="releem_setup_token" value="' . releem_setup_h($csrfToken) . '">'
                . releem_setup_whmcs_token_field()
                . '<button type="submit" class="rl-btn rl-btn--primary"' . (releem_setup_can_edit_products() ? '' : ' disabled') . '>Setup Product</button></form></td></tr>';
        }
        $body .= '</tbody></table>';

        return $out . releem_setup_widget('Releem Product Setup', 'fa fa-cogs', $body);
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
            $_SESSION['releem_setup_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['releem_setup_token'];
    }
}

if (!function_exists('releem_setup_validate_csrf_token')) {
    function releem_setup_validate_csrf_token($submittedToken)
    {
        $expectedToken = releem_setup_get_csrf_token();
        $submittedToken = is_string($submittedToken) ? $submittedToken : '';

        if ($expectedToken === '' || $submittedToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $submittedToken);
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
