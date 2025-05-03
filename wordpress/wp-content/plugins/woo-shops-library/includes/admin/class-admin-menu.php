<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_Menu')) {
    class WSL_Admin_Menu {
        /**
         * Initialize admin menus and hooks.
         */
        public static function init() {
            add_submenu_page(
                'woocommerce',
                __('Shops Library', 'woo-shops-library'),
                __('Shops Library', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_shops_library',
                ['WooShopsLibrary\WSL_Admin_Shops', 'render_shops_library_page']
            );

            add_submenu_page(
                null,
                __('Shops Library Settings', 'woo-shops-library'),
                __('Shops Library Settings', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_settings',
                ['WooShopsLibrary\WSL_Admin_Config', 'render_settings_page']
            );

            add_submenu_page(
                null,
                __('Edit Shop', 'woo-shops-library'),
                __('Edit Shop', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_edit_shop',
                ['WooShopsLibrary\WSL_Admin_Shops', 'render_edit_shop_page']
            );

            add_submenu_page(
                null,
                __('Edit Link', 'woo-shops-library'),
                __('Edit Link', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_edit_link',
                ['WooShopsLibrary\WSL_Admin_Links', 'render_edit_link_page']
            );

            add_submenu_page(
                null,
                __('Links Details', 'woo-shops-library'),
                __('Links Details', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_links_details',
                ['WooShopsLibrary\WSL_Admin_Links', 'render_links_details_page']
            );

            add_submenu_page(
                null,
                __('Price Retrieval', 'woo-shops-library'),
                __('Price Retrieval', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_price_retrieval',
                ['WooShopsLibrary\WSL_Admin_Config', 'render_price_retrieval_page']
            );

            add_submenu_page(
                'woocommerce',
                __('Price History', 'woo-shops-library'),
                __('Price History', 'woo-shops-library'),
                'manage_woocommerce',
                'wsl_price_history',
                ['WooShopsLibrary\WSL_Admin_History', 'render_price_history_page']
            );

            // Register cron event for background price fetching
            add_action('wsl_fetch_initial_price', [__CLASS__, 'fetch_initial_price'], 10, 2);
        }

        /**
         * Background task to fetch initial price for a link.
         *
         * @param int    $link_id Link ID.
         * @param string $link Link URL.
         */
        public static function fetch_initial_price($link_id, $link) {
            $shop_url = parse_url($link, PHP_URL_HOST);
            $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
            $shop = WSL_Shops_DB::get_shop_by_url($shop_url_normalized);
            $methods = $shop ? json_decode($shop->price_retrieval_methods ?? '[]', true) : [];

            if (empty($methods)) {
                $methods = [
                    ['mode' => 'css', 'parameter' => '.price'],
                    ['mode' => 'css', 'parameter' => '.discount-price']
                ];
            }

            $product_prices = WSL_Price_Retrieval::fetch_price($link, $methods);
            if (is_array($product_prices) && (isset($product_prices['price']) || isset($product_prices['price_discount']))) {
                $standard_price = $product_prices['price'] ?? '';
                $discount_price = $product_prices['price_discount'] ?? '';
                WSL_Links_DB::store_link_prices($link_id, $standard_price, $discount_price);
                update_option("wsl_last_scraped_{$shop_url_normalized}", [
                    'timestamp' => current_time('mysql'),
                    'price' => $standard_price,
                    'price_discount' => $discount_price,
                    'error' => ''
                ]);
            } else {
                error_log("WSL_Admin_Menu::fetch_initial_price failed for link ID $link_id ($link): " . print_r($product_prices, true));
                update_option("wsl_last_scraped_{$shop_url_normalized}", [
                    'timestamp' => current_time('mysql'),
                    'price' => '',
                    'price_discount' => '',
                    'error' => $product_prices
                ]);
            }
        }
    }
}