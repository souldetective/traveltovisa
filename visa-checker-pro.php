<?php
/**
 * Plugin Name: Visa Checker Pro
 * Description: Google Sheets-driven visa checker with virtual routes, REST APIs, and deterministic decision engine.
 * Version: 1.1.0
 * Author: TravelToVisa
 */

if (!defined('ABSPATH')) {
    exit;
}

final class VCP_Plugin {
    const VERSION = '1.1.0';
    const NS = 'vcp/v1';
    const SPREADSHEET_ID = '1dABGDwcITktPXb6gzGux6hiIXEKO9P1-UFJdcmFgCLU';

    private static $instance;

    private $tables = [
        'passport_data',
        'destination_seo_pages',
        'visa_data_cache',
        'affiliate_partners',
        'health_requirements',
        'visa_processing_info',
        'user_accounts',
        'saved_routes',
        'user_commissions',
        'site_settings',
        'menu_items',
        'footer_links',
        'baseline_import_log',
        'sitemap_pages',
        'api_keys',
        'pages',
    ];

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_rewrites']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('template_include', [$this, 'template_loader']);
        add_action('template_redirect', [$this, 'handle_special_routes']);
        add_action('wp_head', [$this, 'render_route_seo'], 1);

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('vcp_visa_checker', [$this, 'shortcode']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_vcp_sync_google', [$this, 'admin_sync_google']);
        add_action('admin_post_vcp_reload_overrides', [$this, 'admin_reload_overrides']);

        add_action('vcp_weekly_sync', [$this, 'sync_from_google_sheets']);
    }

    public function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'vcp_' . $name;
    }

    public function activate() {
        $this->create_tables();
        $this->register_rewrites();
        flush_rewrite_rules();

        if (!wp_next_scheduled('vcp_weekly_sync')) {
            wp_schedule_event(time(), 'weekly', 'vcp_weekly_sync');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('vcp_weekly_sync');
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $schemas = [];

        $schemas[] = "CREATE TABLE {$this->table('passport_data')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            country_name VARCHAR(191) NOT NULL,
            iso2 CHAR(2) NOT NULL,
            slug VARCHAR(2) NOT NULL,
            destinations LONGTEXT NOT NULL,
            last_synced DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY iso2 (iso2),
            KEY slug (slug)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$this->table('destination_seo_pages')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            destination_iso2 CHAR(2) NOT NULL,
            country_name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            canonical VARCHAR(255) NULL,
            robots VARCHAR(191) NULL,
            og_title VARCHAR(255) NULL,
            og_description TEXT NULL,
            twitter_title VARCHAR(255) NULL,
            twitter_description TEXT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            is_indexable TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY slug (slug)
        ) {$charset};";

        $simple = [
            'visa_data_cache' => 'cache_key VARCHAR(191) UNIQUE, cache_value LONGTEXT, expires_at DATETIME',
            'affiliate_partners' => 'name VARCHAR(191), code VARCHAR(100), destination_iso2 CHAR(2), content TEXT, is_active TINYINT(1) DEFAULT 1',
            'health_requirements' => 'from_iso2 CHAR(2), to_iso2 CHAR(2), content TEXT',
            'visa_processing_info' => 'from_iso2 CHAR(2), to_iso2 CHAR(2), processing_time VARCHAR(100), notes TEXT',
            'user_accounts' => 'wp_user_id BIGINT UNSIGNED, role_name VARCHAR(100), status VARCHAR(30)',
            'saved_routes' => 'wp_user_id BIGINT UNSIGNED, payload LONGTEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
            'user_commissions' => 'wp_user_id BIGINT UNSIGNED, amount DECIMAL(10,2), status VARCHAR(30), created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
            'site_settings' => 'setting_key VARCHAR(191) UNIQUE, setting_value LONGTEXT',
            'menu_items' => 'label VARCHAR(191), url VARCHAR(255), sort_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1',
            'footer_links' => 'label VARCHAR(191), url VARCHAR(255), sort_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1',
            'baseline_import_log' => 'status VARCHAR(30), message TEXT, rows_imported INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
            'sitemap_pages' => 'url VARCHAR(255) UNIQUE, priority VARCHAR(10), changefreq VARCHAR(20), is_included TINYINT(1) DEFAULT 1, is_indexable TINYINT(1) DEFAULT 1',
            'api_keys' => 'service_name VARCHAR(100) UNIQUE, api_key TEXT, is_active TINYINT(1) DEFAULT 1',
            'pages' => 'slug VARCHAR(191) UNIQUE, title VARCHAR(255), body LONGTEXT, is_published TINYINT(1) DEFAULT 1, is_indexable TINYINT(1) DEFAULT 1',
        ];

        foreach ($simple as $table => $cols) {
            $schemas[] = "CREATE TABLE {$this->table($table)} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                {$cols},
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) {$charset};";
        }

        foreach ($schemas as $schema) {
            dbDelta($schema);
        }
    }

    public function register_rewrites() {
        add_rewrite_rule('^visa-guide/?$', 'index.php?vcp_route=visa-guide', 'top');
        add_rewrite_rule('^visa-guide/([a-z]{2})/?$', 'index.php?vcp_route=visa-guide-passport&vcp_passport=$matches[1]', 'top');
        add_rewrite_rule('^do-i-need-a-visa-to/?$', 'index.php?vcp_route=destination-landing', 'top');
        add_rewrite_rule('^do-i-need-a-visa-to-visit/([^/]+)/?$', 'index.php?vcp_route=destination-page&vcp_destination=$matches[1]', 'top');
        add_rewrite_rule('^page/([^/]+)/?$', 'index.php?vcp_route=cms-page&vcp_page=$matches[1]', 'top');
        add_rewrite_rule('^sitemap\.xml$', 'index.php?vcp_route=sitemap', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'vcp_route';
        $vars[] = 'vcp_passport';
        $vars[] = 'vcp_destination';
        $vars[] = 'vcp_page';

        return $vars;
    }

    public function template_loader($template) {
        $route = get_query_var('vcp_route');
        if (!$route) {
            return $template;
        }

        $file = plugin_dir_path(__FILE__) . 'templates/' . $route . '.php';
        if (file_exists($file)) {
            return $file;
        }

        status_header(404);
        return get_404_template();
    }

    public function handle_special_routes() {
        if (get_query_var('vcp_route') === 'sitemap') {
            $this->render_sitemap();
            exit;
        }
    }

    private function canonical_for_current_route() {
        global $wp;
        return home_url(user_trailingslashit($wp->request));
    }

    public function render_route_seo() {
        $route = get_query_var('vcp_route');
        if (!$route) {
            return;
        }

        $meta = $this->route_meta($route);
        echo '<title>' . esc_html($meta['title']) . "</title>\n";
        echo '<meta name="description" content="' . esc_attr($meta['description']) . '" />' . "\n";
        echo '<meta name="robots" content="' . esc_attr($meta['robots']) . '" />' . "\n";
        echo '<link rel="canonical" href="' . esc_url($meta['canonical']) . '" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($meta['og_title']) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($meta['og_description']) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($meta['canonical']) . '" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($meta['twitter_title']) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($meta['twitter_description']) . '" />' . "\n";

        $json_ld = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $meta['title'],
                'description' => $meta['description'],
                'url' => $meta['canonical'],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $meta['title'], 'item' => $meta['canonical']],
                ],
            ],
        ];

        echo '<script type="application/ld+json">' . wp_json_encode($json_ld) . "</script>\n";
    }

    private function route_meta($route) {
        global $wpdb;

        $default = [
            'title' => 'Visa Checker Pro',
            'description' => 'Check visa requirements quickly.',
            'robots' => 'index,follow',
            'canonical' => $this->canonical_for_current_route(),
            'og_title' => 'Visa Checker Pro',
            'og_description' => 'Check visa requirements quickly.',
            'twitter_title' => 'Visa Checker Pro',
            'twitter_description' => 'Check visa requirements quickly.',
        ];

        if ($route === 'destination-page') {
            $slug = sanitize_title(get_query_var('vcp_destination'));
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('destination_seo_pages')} WHERE slug=%s", $slug), ARRAY_A);
            if ($row) {
                $default['title'] = $row['title'] ?: 'Do I need a visa to visit ' . $row['country_name'] . '?';
                $default['description'] = $row['description'] ?: $default['description'];
                $default['canonical'] = $row['canonical'] ?: $default['canonical'];
                $default['robots'] = !empty($row['is_indexable']) ? 'index,follow' : 'noindex,follow';
                $default['og_title'] = $row['og_title'] ?: $default['title'];
                $default['og_description'] = $row['og_description'] ?: $default['description'];
                $default['twitter_title'] = $row['twitter_title'] ?: $default['title'];
                $default['twitter_description'] = $row['twitter_description'] ?: $default['description'];
            }
        }

        if ($route === 'visa-guide-passport') {
            $iso2 = strtolower(get_query_var('vcp_passport'));
            $default['title'] = strtoupper($iso2) . ' Passport Visa Guide';
            $default['description'] = 'Visa requirements for travelers holding ' . strtoupper($iso2) . ' passport.';
            $default['og_title'] = $default['title'];
            $default['og_description'] = $default['description'];
            $default['twitter_title'] = $default['title'];
            $default['twitter_description'] = $default['description'];
        }

        return $default;
    }

    private function render_sitemap() {
        global $wpdb;
        header('Content-Type: application/xml; charset=utf-8');

        $urls = [
            home_url('/visa-guide/'),
            home_url('/do-i-need-a-visa-to/'),
        ];

        $passports = $wpdb->get_col("SELECT slug FROM {$this->table('passport_data')}");
        foreach ($passports as $slug) {
            $urls[] = home_url('/visa-guide/' . strtolower($slug) . '/');
        }

        $destinations = $wpdb->get_col("SELECT slug FROM {$this->table('destination_seo_pages')} WHERE is_published=1");
        foreach ($destinations as $slug) {
            $urls[] = home_url('/do-i-need-a-visa-to-visit/' . $slug . '/');
        }

        $pages = $wpdb->get_col("SELECT slug FROM {$this->table('pages')} WHERE is_published=1");
        foreach ($pages as $slug) {
            $urls[] = home_url('/page/' . $slug . '/');
        }

        $extras = $wpdb->get_col("SELECT url FROM {$this->table('sitemap_pages')} WHERE is_included=1 AND is_indexable=1");
        $urls = array_unique(array_merge($urls, $extras));

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            echo '<url><loc>' . esc_url($url) . '</loc></url>';
        }
        echo '</urlset>';
    }

    public function register_rest_routes() {
        register_rest_route(self::NS, '/visa-check', [
            'methods' => 'POST',
            'callback' => [$this, 'api_visa_check'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/countries', [
            'methods' => 'GET',
            'callback' => fn() => $this->rest_table('passport_data'),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/passport-data', [
            'methods' => 'GET',
            'callback' => fn() => $this->rest_table('passport_data'),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/passport-data/(?P<code>[a-zA-Z]{2})', [
            'methods' => 'GET',
            'callback' => [$this, 'api_passport_data_single'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/visa/destinations', [
            'methods' => 'GET',
            'callback' => fn() => $this->rest_table('destination_seo_pages'),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/visa/destinations/(?P<slug>[a-z0-9\-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'api_destination_single'],
            'permission_callback' => '__return_true',
        ]);

        $map = [
            'affiliate-partners' => 'affiliate_partners',
            'site-settings' => 'site_settings',
            'menu-items' => 'menu_items',
            'footer-links' => 'footer_links',
            'health-requirements' => 'health_requirements',
        ];

        foreach ($map as $path => $table) {
            register_rest_route(self::NS, '/' . $path, [
                'methods' => 'GET',
                'callback' => fn() => $this->rest_table($table),
                'permission_callback' => '__return_true',
            ]);
        }

        register_rest_route(self::NS, '/visa-processing/(?P<from>[a-zA-Z]{2})/(?P<to>[a-zA-Z]{2})', [
            'methods' => 'GET',
            'callback' => [$this, 'api_visa_processing'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/admin/passport-data/sync-google-sheets', [
            'methods' => 'POST',
            'callback' => [$this, 'api_sync_google'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(self::NS, '/admin/reload-visa-overrides', [
            'methods' => 'POST',
            'callback' => [$this, 'api_reload_overrides'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        foreach ($this->tables as $table) {
            $endpoint = '/admin/' . str_replace('_', '-', $table);
            register_rest_route(self::NS, $endpoint, [
                [
                    'methods' => 'GET',
                    'callback' => fn() => $this->rest_table($table),
                    'permission_callback' => [$this, 'admin_permission'],
                ],
                [
                    'methods' => 'POST',
                    'callback' => function (WP_REST_Request $request) use ($table) {
                        return $this->rest_create($table, $request->get_json_params());
                    },
                    'permission_callback' => [$this, 'admin_permission'],
                ],
            ]);

            register_rest_route(self::NS, $endpoint . '/(?P<id>\d+)', [
                [
                    'methods' => 'PUT,PATCH',
                    'callback' => function (WP_REST_Request $request) use ($table) {
                        return $this->rest_update($table, (int) $request['id'], $request->get_json_params());
                    },
                    'permission_callback' => [$this, 'admin_permission'],
                ],
                [
                    'methods' => 'DELETE',
                    'callback' => function (WP_REST_Request $request) use ($table) {
                        return $this->rest_delete($table, (int) $request['id']);
                    },
                    'permission_callback' => [$this, 'admin_permission'],
                ],
            ]);
        }
    }

    public function admin_permission() {
        return current_user_can('manage_options');
    }

    private function rest_table($table) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table($table)}", ARRAY_A);
        return rest_ensure_response($rows);
    }

    private function rest_create($table, $payload) {
        global $wpdb;
        $clean = $this->sanitize_array($payload);
        $wpdb->insert($this->table($table), $clean);
        return rest_ensure_response(['id' => (int) $wpdb->insert_id]);
    }

    private function rest_update($table, $id, $payload) {
        global $wpdb;
        $clean = $this->sanitize_array($payload);
        $wpdb->update($this->table($table), $clean, ['id' => $id]);
        return rest_ensure_response(['updated' => true]);
    }

    private function rest_delete($table, $id) {
        global $wpdb;
        $wpdb->delete($this->table($table), ['id' => $id]);
        return rest_ensure_response(['deleted' => true]);
    }

    private function sanitize_array($payload) {
        $clean = [];
        foreach ((array) $payload as $key => $value) {
            $k = sanitize_key($key);
            $clean[$k] = is_scalar($value) ? sanitize_text_field((string) $value) : wp_json_encode($value);
        }

        return $clean;
    }

    public function api_passport_data_single($request) {
        global $wpdb;
        $iso2 = strtoupper(sanitize_text_field($request['code']));
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('passport_data')} WHERE iso2=%s", $iso2), ARRAY_A);

        return $row ? rest_ensure_response($row) : new WP_Error('not_found', 'Passport data not found', ['status' => 404]);
    }

    public function api_destination_single($request) {
        global $wpdb;
        $slug = sanitize_title($request['slug']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('destination_seo_pages')} WHERE slug=%s", $slug), ARRAY_A);

        return $row ? rest_ensure_response($row) : new WP_Error('not_found', 'Destination page not found', ['status' => 404]);
    }

    public function api_visa_processing($request) {
        global $wpdb;
        $from = strtoupper(sanitize_text_field($request['from']));
        $to = strtoupper(sanitize_text_field($request['to']));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('visa_processing_info')} WHERE from_iso2=%s AND to_iso2=%s",
            $from,
            $to
        ), ARRAY_A);

        return rest_ensure_response($row ?: []);
    }

    public function api_sync_google() {
        return rest_ensure_response($this->sync_from_google_sheets());
    }

    public function api_reload_overrides() {
        return rest_ensure_response($this->reload_visa_overrides());
    }

    public function api_visa_check(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $input = [
            'passport_country' => strtoupper(sanitize_text_field($payload['passport_country'] ?? '')),
            'destination_country' => strtoupper(sanitize_text_field($payload['destination_country'] ?? '')),
            'residence_country' => strtoupper(sanitize_text_field($payload['residence_country'] ?? '')),
            'supporting_document' => strtolower(sanitize_text_field($payload['supporting_document'] ?? '')),
            'include_transit' => !empty($payload['include_transit']),
            'transit_country' => strtoupper(sanitize_text_field($payload['transit_country'] ?? '')),
            'layover_duration' => sanitize_text_field($payload['layover_duration'] ?? 'under_24h'),
            'destination_country_name' => sanitize_text_field($payload['destination_country_name'] ?? $payload['destination_country'] ?? ''),
        ];

        return rest_ensure_response($this->run_decision_engine($input));
    }

    private function run_decision_engine($input) {
        global $wpdb;

        $passport = $input['passport_country'];
        $destination = $input['destination_country'];

        if (!$passport || !$destination) {
            return ['error' => 'passport_country and destination_country are required'];
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('passport_data')} WHERE iso2=%s",
            $passport
        ), ARRAY_A);

        if (!$row) {
            return ['error' => 'Passport country not found'];
        }

        $destinations = json_decode($row['destinations'], true);
        if (!is_array($destinations)) {
            $destinations = [];
        }

        $baseline = strtolower($destinations[$destination] ?? 'embassy_visa');
        $shortCircuit = ['visa_free', 'visa_on_arrival', 'evisa', 'eta'];

        $main = [
            'status' => $baseline,
            'source' => 'baseline',
        ];

        // Spec: override evaluation is allowed only when baseline is embassy_visa.
        if (!in_array($baseline, $shortCircuit, true) && $baseline === 'embassy_visa') {
            $override = $this->evaluate_override($passport, $destination, $input, $baseline);
            if (!empty($override['applied'])) {
                $main = [
                    'status' => $override['status'],
                    'source' => 'override',
                    'matched_rule' => $override['matched_rule'],
                ];
            } else {
                $main['message'] = 'Safe mode: no qualifying override rule found; baseline retained.';
            }
        }

        return [
            'mainVisa' => $main,
            'transitVisa' => $this->transit_engine($input),
            'affiliateRecommendations' => $this->affiliate_recommendations($destination),
            'embassyLink' => 'https://www.embassypages.com/' . sanitize_title($input['destination_country_name']),
        ];
    }

    private function result_strength($status) {
        $map = [
            'visa_free' => 1,
            'visa_on_arrival' => 2,
            'eta' => 3,
            'evisa' => 4,
            'embassy_visa' => 5,
            'not_allowed' => 6,
        ];

        return $map[$status] ?? 5;
    }

    private function evaluate_override($passport, $destination, $input, $baseline) {
        $rules = get_transient('vcp_overrides_rules');
        if (!is_array($rules)) {
            $this->reload_visa_overrides();
            $rules = get_transient('vcp_overrides_rules');
            if (!is_array($rules)) {
                $rules = [];
            }
        }

        $baselineScore = $this->result_strength($baseline);
        $best = [
            'applied' => false,
            'status' => $baseline,
            'score' => $baselineScore,
            'matched_rule' => null,
        ];

        foreach ($rules as $rule) {
            if (($rule['passport_iso2'] ?? '') !== $passport || ($rule['destination_iso2'] ?? '') !== $destination) {
                continue;
            }

            if (!$this->override_rule_matches($rule, $input)) {
                continue;
            }

            $candidate = strtolower($rule['result'] ?? 'embassy_visa');
            $score = $this->result_strength($candidate);

            // Apply only if candidate is strictly better than baseline/current best.
            if ($score < $best['score']) {
                $best = [
                    'applied' => true,
                    'status' => $candidate,
                    'score' => $score,
                    'matched_rule' => [
                        'res_bucket' => $rule['res_bucket'] ?? '',
                        'doc_qualifier' => $rule['doc_qualifier'] ?? '',
                        'passport_condition_type' => $rule['passport_condition_type'] ?? 'ALL',
                    ],
                ];
            }
        }

        return $best;
    }

    private function override_rule_matches($rule, $input) {
        $resBucket = $this->map_res_bucket($input['residence_country']);
        $docQualifier = $this->map_doc_qualifier($input['supporting_document']);

        $ruleRes = strtoupper(trim($rule['res_bucket'] ?? 'ANY'));
        if ($ruleRes !== 'ANY' && $ruleRes !== $resBucket) {
            return false;
        }

        $ruleDoc = strtoupper(trim($rule['doc_qualifier'] ?? 'ANY'));
        if ($ruleDoc !== 'ANY' && $ruleDoc !== $docQualifier) {
            return false;
        }

        $conditionType = strtoupper(trim($rule['passport_condition_type'] ?? 'ALL'));
        $passportListRaw = trim((string) ($rule['passport_condition_list'] ?? ''));
        $passportList = array_filter(array_map('trim', explode(',', strtoupper($passportListRaw))));

        $passport = strtoupper($input['passport_country']);

        if ($conditionType === 'LIST' && !in_array($passport, $passportList, true)) {
            return false;
        }

        if ($conditionType === 'EXCEPT' && in_array($passport, $passportList, true)) {
            return false;
        }

        return true;
    }

    private function map_res_bucket($countryIso2) {
        $map = get_transient('vcp_passport_lists');
        if (!is_array($map)) {
            $map = [];
        }

        $iso2 = strtoupper($countryIso2);
        return strtoupper($map[$iso2] ?? 'ANY');
    }

    private function map_doc_qualifier($document) {
        $doc = strtolower(trim($document));
        $allowed = ['us_visa', 'uk_visa', 'schengen_visa', 'residence_permit'];
        if (in_array($doc, $allowed, true)) {
            return strtoupper($doc);
        }

        return 'ANY';
    }

    private function transit_engine($input) {
        if (empty($input['include_transit'])) {
            return ['required' => false, 'reason' => 'Transit not requested'];
        }

        $transitCountry = strtoupper($input['transit_country']);
        $layover = $input['layover_duration'];

        $strict = ['US', 'UK', 'FR', 'DE', 'SG'];
        if (in_array($transitCountry, $strict, true)) {
            return ['required' => true, 'reason' => 'Hardcoded transit rule for ' . $transitCountry];
        }

        if ($layover === 'over_24h') {
            return ['required' => true, 'reason' => 'Layover exceeds 24 hours'];
        }

        return ['required' => false, 'reason' => 'No transit visa needed'];
    }

    private function affiliate_recommendations($destinationIso2) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('affiliate_partners')} WHERE destination_iso2=%s AND is_active=1",
            $destinationIso2
        ), ARRAY_A);
    }

    private function slugify_country_name($name) {
        $slug = remove_accents((string) $name);
        $slug = sanitize_title($slug);
        return strtolower($slug);
    }

    public function sync_from_google_sheets() {
        global $wpdb;

        $jwt = $this->build_google_service_jwt();
        if (is_wp_error($jwt)) {
            $wpdb->insert($this->table('baseline_import_log'), [
                'status' => 'error',
                'message' => $jwt->get_error_message(),
                'rows_imported' => 0,
            ]);

            return ['ok' => false, 'message' => $jwt->get_error_message()];
        }

        $token = $this->google_access_token($jwt);
        if (is_wp_error($token)) {
            $wpdb->insert($this->table('baseline_import_log'), [
                'status' => 'error',
                'message' => $token->get_error_message(),
                'rows_imported' => 0,
            ]);

            return ['ok' => false, 'message' => $token->get_error_message()];
        }

        $baselineRows = $this->fetch_sheet_values($token, 'Main Passport Data!A:G');
        $overrideRows = $this->fetch_sheet_values($token, 'VISA_OVERRIDES!A:K');
        $passportListRows = $this->fetch_sheet_values($token, 'PASSPORT_LISTS!A:B');

        if (is_wp_error($baselineRows) || is_wp_error($overrideRows) || is_wp_error($passportListRows)) {
            $errors = [];
            foreach ([$baselineRows, $overrideRows, $passportListRows] as $v) {
                if (is_wp_error($v)) {
                    $errors[] = $v->get_error_message();
                }
            }

            $message = implode('; ', $errors);
            $wpdb->insert($this->table('baseline_import_log'), [
                'status' => 'error',
                'message' => $message,
                'rows_imported' => 0,
            ]);

            return ['ok' => false, 'message' => $message];
        }

        $imported = $this->import_passport_data_rows($baselineRows);
        $this->cache_override_rows($overrideRows);
        $this->cache_passport_lists($passportListRows);

        $wpdb->insert($this->table('baseline_import_log'), [
            'status' => 'success',
            'message' => 'Google Sheets sync completed.',
            'rows_imported' => $imported,
        ]);

        return ['ok' => true, 'rows_imported' => $imported];
    }

    private function build_google_service_jwt() {
        $creds = $this->google_credentials();
        if (is_wp_error($creds)) {
            return $creds;
        }

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $claims = [
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64url(wp_json_encode($header)),
            $this->base64url(wp_json_encode($claims)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            return new WP_Error('vcp_jwt_sign', 'Unable to sign Google service-account JWT.');
        }

        $segments[] = $this->base64url($signature);
        return implode('.', $segments);
    }

    private function google_credentials() {
        $path = defined('VCP_GOOGLE_CREDENTIALS_PATH') ? VCP_GOOGLE_CREDENTIALS_PATH : '';
        $json = getenv('VCP_GOOGLE_CREDENTIALS_JSON');

        if ($path && file_exists($path)) {
            $raw = file_get_contents($path);
            $data = json_decode((string) $raw, true);
            if (!empty($data['client_email']) && !empty($data['private_key'])) {
                return $data;
            }
        }

        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['client_email']) && !empty($data['private_key'])) {
                return $data;
            }
        }

        return new WP_Error('vcp_creds_missing', 'Missing valid Google service-account credentials.');
    }

    private function google_access_token($jwt) {
        $res = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 30,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        if (is_wp_error($res)) {
            return new WP_Error('vcp_google_auth_http', $res->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($body['access_token'])) {
            return new WP_Error('vcp_google_auth_failed', 'Google token exchange failed.');
        }

        return $body['access_token'];
    }

    private function fetch_sheet_values($accessToken, $range) {
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s?majorDimension=ROWS',
            rawurlencode(self::SPREADSHEET_ID),
            rawurlencode($range)
        );

        $res = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);

        if (is_wp_error($res)) {
            return new WP_Error('vcp_sheet_fetch', $res->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($body['values']) || !is_array($body['values'])) {
            return new WP_Error('vcp_sheet_fetch_failed', 'Failed to fetch range: ' . $range);
        }

        return $body['values'];
    }

    private function import_passport_data_rows($rows) {
        global $wpdb;

        if (count($rows) <= 1) {
            return 0;
        }

        $headers = array_map('trim', $rows[0]);
        $body = array_slice($rows, 1);
        $count = 0;

        foreach ($body as $cells) {
            $row = $this->row_to_assoc($headers, $cells);
            $country = sanitize_text_field($row['country_name'] ?? $row['country'] ?? '');
            $iso2 = strtoupper(sanitize_text_field($row['iso2'] ?? $row['passport_iso2'] ?? ''));
            $destRaw = $row['destinations'] ?? $row['destination_json'] ?? '{}';

            if (!$country || strlen($iso2) !== 2) {
                continue;
            }

            $destinations = is_array($destRaw) ? $destRaw : json_decode((string) $destRaw, true);
            if (!is_array($destinations)) {
                $destinations = [];
            }

            $wpdb->replace($this->table('passport_data'), [
                'country_name' => $country,
                'iso2' => $iso2,
                'slug' => strtolower($iso2),
                'destinations' => wp_json_encode($destinations),
                'last_synced' => current_time('mysql'),
            ]);
            $count++;
        }

        return $count;
    }

    private function cache_override_rows($rows) {
        if (count($rows) <= 1) {
            set_transient('vcp_overrides_rules', [], WEEK_IN_SECONDS);
            return;
        }

        $headers = array_map('trim', $rows[0]);
        $body = array_slice($rows, 1);
        $rules = [];

        foreach ($body as $cells) {
            $row = $this->row_to_assoc($headers, $cells);
            $rules[] = [
                'passport_iso2' => strtoupper(sanitize_text_field($row['passport_iso2'] ?? $row['passport'] ?? '')),
                'destination_iso2' => strtoupper(sanitize_text_field($row['destination_iso2'] ?? $row['destination'] ?? '')),
                'res_bucket' => strtoupper(sanitize_text_field($row['res_bucket'] ?? 'ANY')),
                'doc_qualifier' => strtoupper(sanitize_text_field($row['doc_qualifier'] ?? 'ANY')),
                'passport_condition_type' => strtoupper(sanitize_text_field($row['passport_condition_type'] ?? 'ALL')),
                'passport_condition_list' => sanitize_text_field($row['passport_condition_list'] ?? ''),
                'result' => strtolower(sanitize_text_field($row['result'] ?? 'embassy_visa')),
            ];
        }

        set_transient('vcp_overrides_rules', $rules, WEEK_IN_SECONDS);
    }

    private function cache_passport_lists($rows) {
        if (count($rows) <= 1) {
            set_transient('vcp_passport_lists', [], WEEK_IN_SECONDS);
            return;
        }

        $headers = array_map('trim', $rows[0]);
        $body = array_slice($rows, 1);
        $map = [];

        foreach ($body as $cells) {
            $row = $this->row_to_assoc($headers, $cells);
            $iso2 = strtoupper(sanitize_text_field($row['iso2'] ?? $row['country_iso2'] ?? ''));
            $bucket = strtoupper(sanitize_text_field($row['res_bucket'] ?? $row['bucket'] ?? 'ANY'));
            if (strlen($iso2) === 2) {
                $map[$iso2] = $bucket;
            }
        }

        set_transient('vcp_passport_lists', $map, WEEK_IN_SECONDS);
    }

    public function reload_visa_overrides() {
        $jwt = $this->build_google_service_jwt();
        if (is_wp_error($jwt)) {
            return ['ok' => false, 'message' => $jwt->get_error_message()];
        }

        $token = $this->google_access_token($jwt);
        if (is_wp_error($token)) {
            return ['ok' => false, 'message' => $token->get_error_message()];
        }

        $overrideRows = $this->fetch_sheet_values($token, 'VISA_OVERRIDES!A:K');
        if (is_wp_error($overrideRows)) {
            return ['ok' => false, 'message' => $overrideRows->get_error_message()];
        }

        $this->cache_override_rows($overrideRows);

        return ['ok' => true, 'message' => 'Overrides cache reloaded'];
    }

    private function row_to_assoc($headers, $values) {
        $assoc = [];
        foreach ($headers as $idx => $header) {
            $key = strtolower(trim((string) $header));
            if (!$key) {
                continue;
            }
            $assoc[$key] = isset($values[$idx]) ? trim((string) $values[$idx]) : '';
        }
        return $assoc;
    }

    private function base64url($input) {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    public function register_admin_menu() {
        add_menu_page('Visa Checker Pro', 'Visa Checker Pro', 'manage_options', 'vcp-admin', [$this, 'admin_page'], 'dashicons-admin-site-alt3', 30);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $tabs = ['API Keys', 'Passport Data', 'Pages', 'CMS', 'Passport SEO', 'Destination SEO', 'Sitemap', 'Affiliate'];
        $lastSync = get_option('vcp_last_sync_at', 'Never');

        ?>
        <div class="wrap">
            <h1>Visa Checker Pro</h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab) : ?>
                    <span class="nav-tab"><?php echo esc_html($tab); ?></span>
                <?php endforeach; ?>
            </h2>
            <p><strong>Last sync:</strong> <?php echo esc_html($lastSync); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
                <?php wp_nonce_field('vcp_sync_google'); ?>
                <input type="hidden" name="action" value="vcp_sync_google" />
                <button class="button button-primary">Sync from Google Sheets</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <?php wp_nonce_field('vcp_reload_overrides'); ?>
                <input type="hidden" name="action" value="vcp_reload_overrides" />
                <button class="button">Reload overrides</button>
            </form>
        </div>
        <?php
    }

    public function admin_sync_google() {
        check_admin_referer('vcp_sync_google');
        if (!$this->admin_permission()) {
            wp_die('Unauthorized');
        }

        $result = $this->sync_from_google_sheets();
        if (!empty($result['ok'])) {
            update_option('vcp_last_sync_at', current_time('mysql'));
        }

        wp_safe_redirect(admin_url('admin.php?page=vcp-admin'));
        exit;
    }

    public function admin_reload_overrides() {
        check_admin_referer('vcp_reload_overrides');
        if (!$this->admin_permission()) {
            wp_die('Unauthorized');
        }

        $this->reload_visa_overrides();
        wp_safe_redirect(admin_url('admin.php?page=vcp-admin'));
        exit;
    }

    public function shortcode() {
        ob_start();
        ?>
        <form id="vcp-visa-checker">
            <input name="passport_country" placeholder="Passport country ISO2" required />
            <input name="destination_country" placeholder="Destination country ISO2" required />
            <input name="residence_country" placeholder="Residence country (optional)" />
            <input name="supporting_document" placeholder="Supporting document (optional)" />
            <label><input type="checkbox" name="include_transit" id="vcp-include-transit" /> Include transit</label>
            <div id="vcp-transit-wrap" style="display:none;">
                <input name="transit_country" placeholder="Transit country ISO2" />
                <select name="layover_duration">
                    <option value="under_24h">Under 24h</option>
                    <option value="over_24h">Over 24h</option>
                </select>
            </div>
            <button type="submit">Check Visa</button>
        </form>
        <div id="vcp-results"></div>
        <script>
            const transitCheckbox = document.getElementById('vcp-include-transit');
            const transitWrap = document.getElementById('vcp-transit-wrap');
            transitCheckbox.addEventListener('change', () => {
                transitWrap.style.display = transitCheckbox.checked ? 'block' : 'none';
            });

            document.getElementById('vcp-visa-checker').addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                const payload = Object.fromEntries(fd.entries());
                payload.include_transit = fd.get('include_transit') === 'on';

                const res = await fetch('<?php echo esc_url_raw(rest_url(self::NS . '/visa-check')); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                const safeMode = data?.mainVisa?.message ? '<p><strong>Safe mode:</strong> ' + data.mainVisa.message + '</p>' : '';
                const override = data?.mainVisa?.source === 'override' ? '<p><strong>Override applied</strong></p>' : '';

                document.getElementById('vcp-results').innerHTML =
                    '<div class="vcp-card"><h3>Main Visa</h3><pre>' + JSON.stringify(data.mainVisa, null, 2) + '</pre></div>' +
                    '<div class="vcp-card"><h3>Transit Visa</h3><pre>' + JSON.stringify(data.transitVisa, null, 2) + '</pre></div>' +
                    '<div class="vcp-card"><h3>Affiliate</h3><pre>' + JSON.stringify(data.affiliateRecommendations, null, 2) + '</pre></div>' +
                    override + safeMode;
            });
        </script>
        <?php

        return ob_get_clean();
    }
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['weekly'] = [
        'interval' => WEEK_IN_SECONDS,
        'display' => 'Once Weekly',
    ];

    return $schedules;
});

VCP_Plugin::instance();
