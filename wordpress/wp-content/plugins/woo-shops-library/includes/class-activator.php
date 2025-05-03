<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

class WSL_Activator {
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Create wsl_shops table
        $sql_shops = "CREATE TABLE {$wpdb->prefix}wsl_shops (
            shop_url VARCHAR(255) NOT NULL PRIMARY KEY,
            shop_name VARCHAR(255) NOT NULL,
            link_count INT DEFAULT 0,
            currency_code VARCHAR(10) NOT NULL,
            price_retrieval_methods TEXT,
            shop_date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $charset_collate;";

        // Create wsl_links table
        $sql_links = "CREATE TABLE {$wpdb->prefix}wsl_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            link TEXT NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            current_price VARCHAR(50),
            original_price VARCHAR(50),
            voucher_price VARCHAR(50),
            discount_percentage DECIMAL(5,2) DEFAULT 0.00,
            price_status VARCHAR(50) DEFAULT 'Standard',
            shop_url VARCHAR(255) NOT NULL,
            custom_cta TEXT,
            custom_cta_price TEXT,
            reference_count INT DEFAULT 0,
            link_date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX shop_url_idx (shop_url)
        ) $charset_collate;";

        // Create wsl_link_prices table with test_status
        $sql_prices = "CREATE TABLE {$wpdb->prefix}wsl_link_prices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            link_id BIGINT UNSIGNED NOT NULL,
            current_price VARCHAR(50),
            original_price VARCHAR(50),
            voucher_price VARCHAR(50),
            discount_percentage DECIMAL(5,2) DEFAULT 0.00,
            price_status VARCHAR(50) DEFAULT 'Standard',
            test_status VARCHAR(20),
            date_scraped DATETIME NOT NULL,
            INDEX link_id_idx (link_id),
            FOREIGN KEY (link_id) REFERENCES {$wpdb->prefix}wsl_links(id) ON DELETE CASCADE
        ) $charset_collate;";

        // Execute table creation
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_shops);
        dbDelta($sql_links);
        dbDelta($sql_prices);

        // Initialize default currencies with position if not already set
        $currencies = get_option('wsl_currencies');
        if (!$currencies) {
            $default_currencies = [
                ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'position' => 'before'],
                ['code' => 'CZK', 'symbol' => 'Kč', 'name' => 'Czech Koruna', 'position' => 'after'],
                ['code' => 'EUR', 'symbol' => '€', 'name' => 'Euro', 'position' => 'before']
            ];
            update_option('wsl_currencies', $default_currencies);
        }

        // Set CZK as default currency if not already set
        if (!get_option('wsl_default_currency')) {
            update_option('wsl_default_currency', 'CZK');
        }

        // Add DB version for future upgrades
        if (!get_option('wsl_db_version')) {
            update_option('wsl_db_version', '1.1');
        }
    }
}