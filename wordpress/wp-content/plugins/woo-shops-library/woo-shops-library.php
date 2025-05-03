<?php
/**
 * Plugin Name: Shops Library for WooCommerce
 * Plugin URI:  https://example.com
 * Description: Adds a "Shops Library" under WooCommerce. Stores shops, links, CTA overrides, price retrieval, etc.
 * Version:     1.0.3
 * Author:      Your Name
 * Author URI:  https://example.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-shops-library
 * WC tested up to: 7.5
 * Requires at least: 5.5
 * Requires PHP: 7.4
 *
 * @package WooShopsLibrary
 */

namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

class Woo_Shops_Library {
    private static $instance = null;
    const VERSION = '1.0.3';

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_constants();
        $this->load_dependencies();
        $this->setup_hooks();
    }

    private function define_constants(): void {
        define(__NAMESPACE__ . '\PLUGIN_FILE', __FILE__);
        define(__NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path(__FILE__));
        define(__NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url(__FILE__));
        define(__NAMESPACE__ . '\PLUGIN_VERSION', self::VERSION);
        if (!defined('WSL_SCRAPER_API_KEY')) {
            define('WSL_SCRAPER_API_KEY', getenv('WSL_SCRAPER_API_KEY') ?: 'default-key');
        }
    }

    private function load_dependencies(): void {
        $required_files = [
            'includes/class-activator.php',
            'includes/class-deactivator.php',
            'includes/db/class-shops-db.php',
            'includes/db/class-links-db.php',
            'includes/admin/class-admin-menu.php',
            'includes/admin/class-admin-shops.php',
            'includes/admin/class-admin-links.php',
            'includes/admin/class-admin-config.php',
            'includes/admin/class-admin-ajax.php',
            'includes/admin/class-admin-history.php',
            'includes/class-price-retrieval.php',
        ];
        foreach ($required_files as $file) {
            $file_path = PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                error_log(sprintf('[Woo Shops Library] Required file %s not found.', $file));
            }
        }
    }

    private function setup_hooks(): void {
        add_action('plugins_loaded', [$this, 'check_woocommerce']);
        register_activation_hook(PLUGIN_FILE, [WSL_Activator::class, 'activate']);
        register_deactivation_hook(PLUGIN_FILE, [WSL_Deactivator::class, 'deactivate']);
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [WSL_Admin_Menu::class, 'init']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_wsl_get_product_name', [$this, 'ajax_get_product_name']);
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        add_action('save_post', [$this, 'invalidate_post_transients']);
        add_action('before_delete_post', [$this, 'invalidate_post_transients']);
    }

    public function check_woocommerce(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
        }
    }

    public function woocommerce_missing_notice(): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Shops Library for WooCommerce requires WooCommerce to be installed and active.', 'woo-shops-library')
        );
    }

    public function init(): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ini_set('log_errors', 1);
            ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
        }
        load_plugin_textdomain('woo-shops-library', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_admin_assets(): void {
        wp_enqueue_style('wp-admin-css', includes_url('css/wp-admin.min.css'), [], PLUGIN_VERSION);
        wp_enqueue_style('woo-shops-library-admin', PLUGIN_URL . 'assets/css/admin.css', ['wp-admin-css'], time());
        wp_enqueue_script('jquery-ui-sortable', includes_url('js/jquery/ui/sortable.min.js'), ['jquery'], null, true);
        wp_enqueue_script('woo-shops-library-admin', PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'jquery-ui-sortable'], PLUGIN_VERSION, true);
        wp_localize_script('woo-shops-library-admin', 'WSLAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wsl_ajax_nonce'),
        ]);
    }

    public function declare_hpos_compatibility(): void {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', PLUGIN_FILE, true);
        }
    }

    public function ajax_get_product_name() {
        check_ajax_referer('wsl_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'woo-shops-library')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->get_type() !== 'variation') {
                wp_send_json_success(['product_name' => $product->get_name()]);
            } else {
                wp_send_json_error(['message' => __('Invalid Product ID', 'woo-shops-library')]);
            }
        } else {
            wp_send_json_error(['message' => __('No Product ID provided', 'woo-shops-library')]);
        }
    }

    public function invalidate_post_transients($post_id) {
        $post = get_post($post_id);
        if ($post && in_array($post->post_type, ['post', 'page']) && $post->post_status !== 'trash') {
            WSL_Links_DB::invalidate_post_transients($post->post_content);
        }
    }
}

Woo_Shops_Library::get_instance();