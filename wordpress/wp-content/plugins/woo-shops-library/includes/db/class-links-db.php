<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

class WSL_Links_DB {
    private static $links_table = '';
    private static $prices_table = '';

    public static function init() {
        global $wpdb;
        if (empty(self::$links_table)) {
            self::$links_table = $wpdb->prefix . 'wsl_links';
            self::$prices_table = $wpdb->prefix . 'wsl_link_prices';
        }
    }

    public static function insert_link($data) {
        global $wpdb;
        self::init();

        $defaults = [
            'link'              => '',
            'product_id'        => 0,
            'product_name'      => '',
            'current_price'     => '',
            'original_price'    => '',
            'voucher_price'     => '',
            'discount_percentage' => 0.00,
            'price_status'      => 'Standard',
            'shop_url'          => '',
            'custom_cta'        => '',
            'custom_cta_price'  => '',
            'reference_count'   => 0,
            'link_date_modified' => current_time('mysql'),
        ];
        $data = wp_parse_args($data, $defaults);

        $data['product_id'] = (int) $data['product_id'];
        $data['reference_count'] = (int) $data['reference_count'];
        $data['link'] = sanitize_text_field($data['link']);
        $data['product_name'] = substr(sanitize_text_field($data['product_name']), 0, 255);
        $data['current_price'] = substr(sanitize_text_field($data['current_price']), 0, 50);
        $data['original_price'] = substr(sanitize_text_field($data['original_price']), 0, 50);
        $data['voucher_price'] = substr(sanitize_text_field($data['voucher_price']), 0, 50);
        $data['discount_percentage'] = floatval($data['discount_percentage']);
        $data['price_status'] = in_array($data['price_status'], ['Standard', 'Discount', 'Voucher']) ? $data['price_status'] : 'Standard';
        $data['shop_url'] = sanitize_text_field($data['shop_url']);
        $data['custom_cta'] = sanitize_text_field($data['custom_cta']);
        $data['custom_cta_price'] = sanitize_text_field($data['custom_cta_price']);

        if (empty($data['link']) || empty($data['product_id']) || empty($data['product_name']) || empty($data['shop_url'])) {
            error_log("WSL_Links_DB::insert_link failed: Required field is empty. Data: " . print_r($data, true));
            return false;
        }

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::insert_link failed: Table " . self::$links_table . " does not exist.");
            return false;
        }

        $insert_data = [
            'link'             => $data['link'],
            'product_id'       => $data['product_id'],
            'product_name'     => $data['product_name'],
            'current_price'    => $data['current_price'],
            'original_price'   => $data['original_price'],
            'voucher_price'    => $data['voucher_price'],
            'discount_percentage' => $data['discount_percentage'],
            'price_status'     => $data['price_status'],
            'shop_url'         => $data['shop_url'],
            'custom_cta'       => $data['custom_cta'],
            'custom_cta_price' => $data['custom_cta_price'],
            'reference_count'  => $data['reference_count'],
            'link_date_modified' => $data['link_date_modified'],
        ];
        $format = ['%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s'];

        $result = $wpdb->insert(self::$links_table, $insert_data, $format);
        if ($result === false) {
            error_log("WSL_Links_DB::insert_link failed: " . $wpdb->last_error);
        }
        return $result !== false ? $wpdb->insert_id : false;
    }

    public static function update_link($link_id, $data) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::update_link failed: Table " . self::$links_table . " does not exist.");
            return false;
        }

        $data['link_date_modified'] = current_time('mysql');
        $result = $wpdb->update(self::$links_table, $data, ['id' => (int) $link_id]);
        if ($result === false) {
            error_log("WSL_Links_DB::update_link failed for ID $link_id: " . $wpdb->last_error);
        }
        return $result !== false;
    }

    public static function store_link_prices($link_id, $current_price, $original_price, $voucher_price, $discount_percentage, $price_status, $test_status = null) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'") || !$wpdb->get_var("SHOW TABLES LIKE '" . self::$prices_table . "'")) {
            error_log("WSL_Links_DB::store_link_prices failed: One or both tables do not exist.");
            return false;
        }

        $current_price = is_null($current_price) ? '' : substr(sanitize_text_field($current_price), 0, 50);
        $original_price = is_null($original_price) ? '' : substr(sanitize_text_field($original_price), 0, 50);
        $voucher_price = is_null($voucher_price) ? '' : substr(sanitize_text_field($voucher_price), 0, 50);
        $discount_percentage = is_null($discount_percentage) ? 0.00 : floatval($discount_percentage);
        $price_status = in_array($price_status, ['Standard', 'Discount', 'Voucher']) ? $price_status : 'Standard';
        $test_status = is_null($test_status) ? null : (in_array($test_status, ['Success', 'Failed']) ? $test_status : null);

        error_log("WSL_Links_DB::store_link_prices: Input for link ID $link_id - Current: '$current_price', Original: '$original_price', Voucher: '$voucher_price', Discount%: $discount_percentage, Status: $price_status, Test Status: " . ($test_status ?? 'NULL'));

        // Insert into historical prices table
        $price_data = [
            'link_id'          => (int) $link_id,
            'current_price'    => $current_price,
            'original_price'   => $original_price,
            'voucher_price'    => $voucher_price,
            'discount_percentage' => $discount_percentage,
            'price_status'     => $price_status,
            'test_status'      => $test_status, // New field for test status
            'date_scraped'     => current_time('mysql'),
        ];
        $price_format = ['%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s'];
        $price_result = $wpdb->insert(self::$prices_table, $price_data, $price_format);
        if ($price_result === false) {
            error_log("WSL_Links_DB::store_link_prices: Failed to insert into " . self::$prices_table . " for link ID $link_id: " . $wpdb->last_error);
            return false;
        }

        // Update latest prices in wsl_links
        $link_data = [
            'current_price'    => $current_price,
            'original_price'   => $original_price,
            'voucher_price'    => $voucher_price,
            'discount_percentage' => $discount_percentage,
            'price_status'     => $price_status,
            'link_date_modified' => current_time('mysql'),
        ];
        $link_result = $wpdb->update(self::$links_table, $link_data, ['id' => (int) $link_id], ['%s', '%s', '%s', '%f', '%s', '%s'], ['%d']);
        if ($link_result === false) {
            error_log("WSL_Links_DB::store_link_prices: Failed to update " . self::$links_table . " for link ID $link_id: " . $wpdb->last_error);
            return false;
        }

        error_log("WSL_Links_DB::store_link_prices: Successfully stored prices for link ID $link_id");
        return true;
    }

    public static function delete_link($link_id) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::delete_link failed: Table " . self::$links_table . " does not exist.");
            return false;
        }

        $price_result = $wpdb->delete(self::$prices_table, ['link_id' => (int) $link_id]);
        if ($price_result === false) {
            error_log("WSL_Links_DB::delete_link failed to delete price history for ID $link_id: " . $wpdb->last_error);
        }

        $link_result = $wpdb->delete(self::$links_table, ['id' => (int) $link_id]);
        if ($link_result === false) {
            error_log("WSL_Links_DB::delete_link failed for ID $link_id: " . $wpdb->last_error);
        }
        return $link_result !== false;
    }

    public static function delete_links_by_shop($shop_url) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::delete_links_by_shop failed: Table " . self::$links_table . " does not exist.");
            return false;
        }

        $link_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::$links_table . " WHERE shop_url = %s", $shop_url));
        if (!empty($link_ids)) {
            $placeholders = implode(',', array_fill(0, count($link_ids), '%d'));
            $price_result = $wpdb->query($wpdb->prepare("DELETE FROM " . self::$prices_table . " WHERE link_id IN ($placeholders)", ...$link_ids));
            if ($price_result === false) {
                error_log("WSL_Links_DB::delete_links_by_shop failed to delete price history for shop $shop_url: " . $wpdb->last_error);
            }
        }

        $link_result = $wpdb->delete(self::$links_table, ['shop_url' => sanitize_text_field($shop_url)]);
        if ($link_result === false) {
            error_log("WSL_Links_DB::delete_links_by_shop failed for $shop_url: " . $wpdb->last_error);
        }
        return $link_result !== false;
    }

    public static function get_link_by_id($link_id) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::get_link_by_id failed: Table " . self::$links_table . " does not exist.");
            return null;
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$links_table . " WHERE id = %d", (int) $link_id));
    }

    public static function get_link_by_url($link_url) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::get_link_by_url failed: Table " . self::$links_table . " does not exist.");
            return null;
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$links_table . " WHERE link = %s", sanitize_text_field($link_url)));
    }

    public static function get_links_by_shop($shop_url, $args = []) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::get_links_by_shop failed: Table " . self::$links_table . " does not exist.");
            return [];
        }

        $defaults = [
            'order_by' => 'link_date_modified',
            'order'    => 'DESC',
            'limit'    => 40,
            'offset'   => 0,
            'search'   => ''
        ];
        $args = wp_parse_args($args, $defaults);

        $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
        $post_types_in = "('post','page')";

        $query = "SELECT DISTINCT " . self::$links_table . ".* 
                  FROM " . self::$links_table . "
                  LEFT JOIN $wpdb->posts 
                  ON $wpdb->posts.post_content LIKE CONCAT('%', " . self::$links_table . ".link, '%')
                  WHERE " . self::$links_table . ".shop_url = %s";
        $params = [$shop_url_normalized];

        if (!empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $query .= " AND (" . self::$links_table . ".link LIKE %s 
                             OR " . self::$links_table . ".product_name LIKE %s 
                             OR ($wpdb->posts.post_type IN $post_types_in 
                                 AND $wpdb->posts.post_status = 'publish' 
                                 AND $wpdb->posts.post_title LIKE %s))";
            $params[] = $search_term;
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

    public static function get_links_count_by_shop($shop_url, $search = '') {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::get_links_count_by_shop failed: Table " . self::$links_table . " does not exist.");
            return 0;
        }

        $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
        $post_types_in = "('post','page')";

        $query = "SELECT COUNT(DISTINCT " . self::$links_table . ".id) 
                  FROM " . self::$links_table . "
                  LEFT JOIN $wpdb->posts 
                  ON $wpdb->posts.post_content LIKE CONCAT('%', " . self::$links_table . ".link, '%')
                  WHERE " . self::$links_table . ".shop_url = %s";
        $params = [$shop_url_normalized];

        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $query .= " AND (" . self::$links_table . ".link LIKE %s 
                             OR " . self::$links_table . ".product_name LIKE %s 
                             OR ($wpdb->posts.post_type IN $post_types_in 
                                 AND $wpdb->posts.post_status = 'publish' 
                                 AND $wpdb->posts.post_title LIKE %s))";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        return (int) $wpdb->get_var($wpdb->prepare($query, $params));
    }

    public static function get_price_history($args = []) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$prices_table . "'")) {
            error_log("WSL_Links_DB::get_price_history failed: Table " . self::$prices_table . " does not exist.");
            return [];
        }

        $defaults = [
            'shop_url' => '',
            'search' => '',
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'order_by' => 'date_scraped',
            'order' => 'DESC',
            'limit' => 40,
            'offset' => 0
        ];
        $args = wp_parse_args($args, $defaults);

        $query = "SELECT lp.id, lp.link_id, lp.current_price, lp.original_price, lp.voucher_price, lp.discount_percentage, lp.price_status, lp.date_scraped, l.link, l.product_name
                  FROM " . self::$prices_table . " lp
                  INNER JOIN " . self::$links_table . " l ON lp.link_id = l.id
                  WHERE lp.date_scraped BETWEEN %s AND %s";
        $params = [$args['start_date'], $args['end_date']];

        if (!empty($args['shop_url'])) {
            $query .= " AND l.shop_url = %s";
            $params[] = sanitize_text_field($args['shop_url']);
        }

        if (!empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $query .= " AND (l.link LIKE %s OR l.product_name LIKE %s)";
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

    public static function get_price_history_count($args = []) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$prices_table . "'")) {
            error_log("WSL_Links_DB::get_price_history_count failed: Table " . self::$prices_table . " does not exist.");
            return 0;
        }

        $defaults = [
            'shop_url' => '',
            'search' => '',
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d')
        ];
        $args = wp_parse_args($args, $defaults);

        $query = "SELECT COUNT(*) 
                  FROM " . self::$prices_table . " lp
                  INNER JOIN " . self::$links_table . " l ON lp.link_id = l.id
                  WHERE lp.date_scraped BETWEEN %s AND %s";
        $params = [$args['start_date'], $args['end_date']];

        if (!empty($args['shop_url'])) {
            $query .= " AND l.shop_url = %s";
            $params[] = sanitize_text_field($args['shop_url']);
        }

        if (!empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $query .= " AND (l.link LIKE %s OR l.product_name LIKE %s)";
            $params[] = $search_term;
            $params[] = $search_term;
        }

        return (int) $wpdb->get_var($wpdb->prepare($query, $params));
    }

    public static function get_post_count_by_link($link_url) {
        global $wpdb;

        if (!$wpdb->get_var("SHOW TABLES LIKE '$wpdb->posts'")) {
            error_log("WSL_Links_DB::get_post_count_by_link failed: Posts table does not exist.");
            return 0;
        }

        $like = '%' . $wpdb->esc_like($link_url) . '%';
        $post_types_in = "('post','page')";

        $query = $wpdb->prepare(
            "SELECT COUNT(*) 
             FROM $wpdb->posts 
             WHERE post_type IN $post_types_in
               AND post_status = 'publish'
               AND post_content LIKE %s",
            $like
        );

        return (int) $wpdb->get_var($query);
    }

    public static function get_posts_by_link($link_url, $search = '') {
        global $wpdb;

        if (!$wpdb->get_var("SHOW TABLES LIKE '$wpdb->posts'")) {
            error_log("WSL_Links_DB::get_posts_by_link failed: Posts table does not exist.");
            return [];
        }

        $cache_key = 'wsl_posts_' . md5($link_url . $search);
        $referenced_posts = get_transient($cache_key);

        if (false === $referenced_posts) {
            $like = '%' . $wpdb->esc_like($link_url) . '%';
            $post_types_in = "('post','page')";

            $sql = "SELECT ID, post_title
                    FROM $wpdb->posts
                    WHERE post_type IN $post_types_in
                      AND post_status = 'publish'
                      AND post_content LIKE %s";
            $params = [$like];

            if (!empty($search)) {
                $sql .= " AND post_title LIKE %s";
                $search_term = '%' . $wpdb->esc_like($search) . '%';
                $params[] = $search_term;
            }

            $sql .= " ORDER BY post_date DESC";
            $referenced_posts = $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
            set_transient($cache_key, $referenced_posts, HOUR_IN_SECONDS);
        }

        return $referenced_posts;
    }

    public static function invalidate_post_transients($post_content) {
        global $wpdb;
        self::init();

        if (!$wpdb->get_var("SHOW TABLES LIKE '" . self::$links_table . "'")) {
            error_log("WSL_Links_DB::invalidate_post_transients failed: Table " . self::$links_table . " does not exist.");
            return;
        }

        $links = $wpdb->get_col("SELECT link FROM " . self::$links_table) ?: [];
        foreach ($links as $link) {
            if (strpos($post_content, $link) !== false) {
                $cache_key_base = 'wsl_posts_' . md5($link);
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '_transient_' . $cache_key_base . '%'
                ));
            }
        }
    }
}

WSL_Links_DB::init();