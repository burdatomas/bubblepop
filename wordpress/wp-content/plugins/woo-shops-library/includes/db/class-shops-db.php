<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

class WSL_Shops_DB {
    private static $table_name = '';

    /**
     * Initialize the table name once during class loading.
     */
    public static function init() {
        global $wpdb;
        if (empty(self::$table_name)) {
            self::$table_name = $wpdb->prefix . 'wsl_shops';
        }
    }

    /**
     * Insert or update a shop.
     *
     * @param string $shop_url Shop URL.
     * @param string $shop_name Shop name.
     * @param array  $data Additional data including currency_code.
     * @return bool Success status.
     */
    public static function upsert_shop($shop_url, $shop_name, $data = []) {
        global $wpdb;
        self::init();

        $defaults = [
            'link_count' => 0,
            'shop_date_modified' => current_time('mysql'),
            'price_retrieval_methods' => '',
            'currency_code' => get_option('wsl_default_currency', 'USD')
        ];
        $data = wp_parse_args($data, $defaults);
        $data['shop_url'] = sanitize_text_field($shop_url);
        $data['shop_name'] = sanitize_text_field($shop_name);
        $data['currency_code'] = sanitize_text_field($data['currency_code']);

        // Validate currency_code against available currencies
        $currencies = get_option('wsl_currencies', []);
        $valid_codes = array_column($currencies, 'code');
        if (!in_array($data['currency_code'], $valid_codes)) {
            $data['currency_code'] = get_option('wsl_default_currency', 'USD');
            error_log("WSL_Shops_DB::upsert_shop: Invalid currency_code '{$data['currency_code']}' for $shop_url, falling back to default");
        }

        $existing = self::get_shop_by_url($shop_url);
        if ($existing) {
            $result = $wpdb->update(self::$table_name, $data, ['shop_url' => $shop_url]);
        } else {
            $result = $wpdb->insert(self::$table_name, $data);
        }

        if ($result === false) {
            error_log("WSL_Shops_DB::upsert_shop failed for $shop_url: " . $wpdb->last_error);
        }
        return $result !== false;
    }

    /**
     * Delete a shop by URL.
     *
     * @param string $shop_url Shop URL.
     * @return bool Success status.
     */
    public static function delete_shop($shop_url) {
        global $wpdb;
        self::init();

        $result = $wpdb->delete(self::$table_name, ['shop_url' => sanitize_text_field($shop_url)]);
        if ($result === false) {
            error_log("WSL_Shops_DB::delete_shop failed for $shop_url: " . $wpdb->last_error);
        }
        return $result !== false;
    }

    /**
     * Get a shop by URL.
     *
     * @param string $shop_url Shop URL.
     * @return object|null Shop data or null.
     */
    public static function get_shop_by_url($shop_url) {
        global $wpdb;
        self::init();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_name . " WHERE shop_url = %s", sanitize_text_field($shop_url)));
    }

    /**
     * Get shops with pagination and search.
     *
     * @param array $args Query arguments.
     * @return array Shop records.
     */
    public static function get_shops($args = []) {
        global $wpdb;
        self::init();

        $defaults = [
            'search'   => '',
            'order_by' => 'shop_date_modified',
            'order'    => 'DESC',
            'limit'    => 40,
            'offset'   => 0
        ];
        $args = wp_parse_args($args, $defaults);

        $query = "SELECT * FROM " . self::$table_name;
        $params = [];

        if (!empty($args['search'])) {
            $query .= " WHERE shop_url LIKE %s OR shop_name LIKE %s";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        $query .= " ORDER BY " . esc_sql($args['order_by']) . " " . esc_sql($args['order']);
        if ($args['limit'] > 0) {
            $query .= " LIMIT %d OFFSET %d";
            $params[] = $args['limit'];
            $params[] = $args['offset'];
        }

        return $wpdb->get_results($wpdb->prepare($query, $params)) ?: [];
    }

    /**
     * Get total shop count.
     *
     * @param string $search Search term.
     * @return int Shop count.
     */
    public static function get_shops_count($search = '') {
        global $wpdb;
        self::init();

        $query = "SELECT COUNT(*) FROM " . self::$table_name;
        $params = [];

        if (!empty($search)) {
            $query .= " WHERE shop_url LIKE %s OR shop_name LIKE %s";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        return (int) $wpdb->get_var($wpdb->prepare($query, $params));
    }

    /**
     * Recalculate link count for a shop.
     *
     * @param string $shop_url Shop URL.
     * @return void
     */
    public static function recalc_link_count($shop_url) {
        global $wpdb;
        self::init();

        $count = WSL_Links_DB::get_links_count_by_shop($shop_url);
        $result = $wpdb->update(self::$table_name, ['link_count' => $count], ['shop_url' => sanitize_text_field($shop_url)]);
        if ($result === false) {
            error_log("WSL_Shops_DB::recalc_link_count failed for $shop_url: " . $wpdb->last_error);
        }
    }

    /**
     * Update link count for a shop.
     *
     * @param string $shop_url Shop URL.
     * @param int    $count Link count.
     * @return bool Success status.
     */
    public static function update_link_count($shop_url, $count) {
        global $wpdb;
        self::init();

        $result = $wpdb->update(
            self::$table_name,
            ['link_count' => (int) $count],
            ['shop_url' => sanitize_text_field($shop_url)],
            ['%d'],
            ['%s']
        );

        if ($result === false) {
            error_log("WSL_Shops_DB::update_link_count failed for $shop_url: " . $wpdb->last_error);
        }
        return $result !== false;
    }

    /**
     * Get currency details for a shop.
     *
     * @param string $shop_url Shop URL.
     * @return array Array with 'symbol' and 'position' or defaults.
     */
    public static function get_shop_currency_details($shop_url) {
        $shop = self::get_shop_by_url($shop_url);
        $currency_code = $shop ? $shop->currency_code : get_option('wsl_default_currency', 'USD');
        $currencies = get_option('wsl_currencies', []);
        foreach ($currencies as $currency) {
            if ($currency['code'] === $currency_code) {
                return [
                    'symbol' => $currency['symbol'],
                    'position' => $currency['position'] ?? 'before' // Default to 'before' if not set
                ];
            }
        }
        return ['symbol' => '$', 'position' => 'before']; // Fallback to USD defaults
    }

    /**
     * Get currency symbol for a shop (legacy method, maintained for compatibility).
     *
     * @param string $shop_url Shop URL.
     * @return string Currency symbol or default.
     */
    public static function get_shop_currency_symbol($shop_url) {
        $details = self::get_shop_currency_details($shop_url);
        return $details['symbol'];
    }
}

// Initialize table name on class load to prevent repeated calls
WSL_Shops_DB::init();