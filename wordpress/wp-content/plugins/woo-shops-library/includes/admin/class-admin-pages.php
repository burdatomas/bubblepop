<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_Pages')) {
    class WSL_Admin_Pages {
        /**
         * Deprecated: This class has been split into WSL_Admin_Shops, WSL_Admin_Links, and WSL_Admin_Config.
         * Update references in woo-shops-library.php or other files to use the new classes.
         */
        public static function render_shops_library_page() {
            error_log('WSL_Admin_Pages is deprecated. Use WSL_Admin_Shops::render_shops_library_page() instead.');
            WSL_Admin_Shops::render_shops_library_page();
        }

        public static function render_settings_page() {
            error_log('WSL_Admin_Pages is deprecated. Use WSL_Admin_Config::render_settings_page() instead.');
            WSL_Admin_Config::render_settings_page();
        }

        public static function render_edit_shop_page() {
            error_log('WSL_Admin_Pages is deprecated. Use WSL_Admin_Shops::render_edit_shop_page() instead.');
            WSL_Admin_Shops::render_edit_shop_page();
        }

        public static function render_edit_link_page() {
            error_log('WSL_Admin_Pages is deprecated. Use WSL_Admin_Links::render_edit_link_page() instead.');
            WSL_Admin_Links::render_edit_link_page();
        }

        public static function render_links_details_page() {
            error_log('WSL_Admin_Pages is deprecated. Use WSL_Admin_Links::render_links_details_page() instead.');
            WSL_Admin_Links::render_links_details_page();
        }

        public static function render_price_retrieval_page() {
            error_log('WSL_Admin_Pages is deprecated. Use WSL_Admin_Config::render_price_retrieval_page() instead.');
            WSL_Admin_Config::render_price_retrieval_page();
        }
    }
}