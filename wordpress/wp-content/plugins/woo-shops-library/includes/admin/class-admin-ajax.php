<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_Ajax')) {
    class WSL_Admin_Ajax {
        public static function init() {
            add_action('wp_ajax_wsl_handle_ajax', [__CLASS__, 'handle_ajax']);
            add_action('wp_ajax_wsl_test_scrape', [__CLASS__, 'test_scrape']);
            add_action('wp_ajax_wsl_refresh_prices', [__CLASS__, 'refresh_prices']);
            add_action('wp_ajax_wsl_download_html', [__CLASS__, 'download_html']);
            add_action('wp_ajax_wsl_test_price_scraping', [__CLASS__, 'test_price_scraping']);
        }

        public static function handle_ajax() {
            check_ajax_referer('wsl_ajax_nonce', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Unauthorized', 'woo-shops-library')]);
            }

            $action_type = isset($_POST['sub_action']) ? sanitize_key($_POST['sub_action']) : '';
            switch ($action_type) {
                case 'update_custom_cta':
                    $link_id = intval($_POST['link_id']);
                    $custom_cta = sanitize_text_field(wp_unslash($_POST['custom_cta']));
                    $custom_cta_price = sanitize_text_field(wp_unslash($_POST['custom_cta_price']));
                    $result = WSL_Links_DB::update_link($link_id, [
                        'custom_cta'       => $custom_cta,
                        'custom_cta_price' => $custom_cta_price,
                    ]);
                    if (false === $result) {
                        wp_send_json_error(['message' => __('Update failed.', 'woo-shops-library')]);
                    }
                    wp_send_json_success(['message' => __('Link updated successfully.', 'woo-shops-library')]);
                    break;

                case 'delete_link':
                    wp_send_json_error(['message' => __('Use POST method for deletion.', 'woo-shops-library')]);
                    break;

                default:
                    wp_send_json_error(['message' => __('Invalid sub_action', 'woo-shops-library')]);
            }
        }

        public static function test_scrape() {
            error_log("WSL_Admin_Ajax::test_scrape: AJAX request received");
            check_ajax_referer('wsl_test_scrape_nonce', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                error_log("WSL_Admin_Ajax::test_scrape: Unauthorized access");
                wp_send_json_error(['message' => __('Unauthorized', 'woo-shops-library')]);
            }

            $shop_url = sanitize_text_field($_POST['shop_url'] ?? '');
            $methods_raw = wp_unslash($_POST['methods'] ?? '[]');
            $methods = json_decode($methods_raw, true);
            
            foreach ($methods as &$method) {
                if (isset($method['parameter'])) {
                    $method['parameter'] = str_replace('\"', '"', stripslashes($method['parameter']));
                }
            }
            unset($method);

            error_log("WSL_Admin_Ajax::test_scrape: shop_url=$shop_url, methods_raw=$methods_raw, methods=" . print_r($methods, true));

            if (empty($shop_url)) {
                error_log("WSL_Admin_Ajax::test_scrape: No shop URL provided");
                wp_send_json_error(['message' => __('No shop URL provided', 'woo-shops-library')]);
            }

            if (!is_array($methods) || count($methods) !== 3) {
                error_log("WSL_Admin_Ajax::test_scrape: Invalid methods format, expected 3 methods");
                wp_send_json_error(['message' => __('Invalid methods format: Expected array of 3 methods', 'woo-shops-library')]);
            }

            $link_obj = WSL_Links_DB::get_links_by_shop($shop_url, ['limit' => 1]);
            $link = !empty($link_obj) && isset($link_obj[0]->link) ? $link_obj[0]->link : '';
            error_log("WSL_Admin_Ajax::test_scrape: link=$link");

            if (!$link) {
                error_log("WSL_Admin_Ajax::test_scrape: No links available for shop $shop_url");
                wp_send_json_error(['message' => __('No links available for preview.', 'woo-shops-library')]);
                return;
            }

            $prices = WSL_Price_Retrieval::fetch_price($link, $methods);
            error_log("WSL_Admin_Ajax::test_scrape: fetch_price result=" . print_r($prices, true));

            if (is_string($prices) && strpos($prices, 'Scraping failed:') === 0) {
                error_log("WSL_Admin_Ajax::test_scrape: Scraping failed with message: $prices");
                wp_send_json_error(['message' => $prices]);
            } else {
                error_log("WSL_Admin_Ajax::test_scrape: Scraping succeeded with prices: " . print_r($prices, true));
                wp_send_json_success($prices);
            }
        }

        public static function refresh_prices() {
            check_ajax_referer('wsl_refresh_prices_nonce', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Unauthorized', 'woo-shops-library')]);
            }

            $shop_url = sanitize_text_field($_POST['shop_url'] ?? '');
            $methods_raw = wp_unslash($_POST['methods'] ?? '[]');
            $methods = json_decode($methods_raw, true);
            $offset = absint($_POST['offset'] ?? 0);
            $batch_size = 50;

            foreach ($methods as &$method) {
                if (isset($method['parameter'])) {
                    $method['parameter'] = str_replace('\"', '"', stripslashes($method['parameter']));
                }
            }
            unset($method);

            error_log("WSL_Admin_Ajax::refresh_prices: Starting for shop_url=$shop_url, offset=$offset");

            if (empty($shop_url)) {
                wp_send_json_error(['message' => __('No shop URL provided', 'woo-shops-library')]);
            }

            if (!is_array($methods) || count($methods) !== 3) {
                wp_send_json_error(['message' => __('Invalid methods format: Expected array of 3 methods', 'woo-shops-library')]);
            }

            $links = WSL_Links_DB::get_links_by_shop($shop_url, ['limit' => $batch_size, 'offset' => $offset]);
            error_log("WSL_Admin_Ajax::refresh_prices: Found " . count($links) . " links");
            if (empty($links)) {
                wp_send_json_success(['processed' => 0]);
            }

            $processed = 0;
            foreach ($links as $link) {
                error_log("WSL_Admin_Ajax::refresh_prices: Processing link ID {$link->id}, URL: {$link->link}");
                $prices = WSL_Price_Retrieval::fetch_price($link->link, $methods);
                if (is_string($prices)) {
                    error_log("WSL_Admin_Ajax::refresh_prices: Fetch failed for {$link->link}: $prices");
                    continue;
                }
                if (isset($prices['current_price'])) {
                    $current_price = $prices['current_price'];
                    $original_price = $prices['original_price'];
                    $voucher_price = $prices['voucher_price'];
                    $discount_percentage = $prices['discount_percentage'];
                    $price_status = $prices['price_status'];

                    error_log("WSL_Admin_Ajax::refresh_prices: Fetched prices for {$link->link} - Current: '$current_price', Original: '$original_price', Voucher: '$voucher_price', Discount%: $discount_percentage, Status: $price_status");

                    $update_result = WSL_Links_DB::store_link_prices(
                        $link->id,
                        $current_price,
                        $original_price,
                        $voucher_price,
                        $discount_percentage,
                        $price_status
                    );
                    if ($update_result) {
                        $processed++;
                    } else {
                        error_log("WSL_Admin_Ajax::refresh_prices: Failed to store prices for link ID {$link->id}, URL: {$link->link}");
                    }
                } else {
                    error_log("WSL_Admin_Ajax::refresh_prices: No valid price data returned for {$link->link}: " . print_r($prices, true));
                }
            }

            error_log("WSL_Admin_Ajax::refresh_prices: Processed $processed links for shop_url=$shop_url");
            wp_send_json_success(['processed' => $processed]);
        }

        public static function download_html() {
            error_log("WSL_Admin_Ajax::download_html: AJAX request received");
            check_ajax_referer('wsl_download_html_nonce', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                error_log("WSL_Admin_Ajax::download_html: Unauthorized access");
                wp_send_json_error(['message' => __('Unauthorized', 'woo-shops-library')]);
            }

            $shop_url = sanitize_text_field($_POST['shop_url'] ?? '');
            error_log("WSL_Admin_Ajax::download_html: shop_url=$shop_url");

            if (empty($shop_url)) {
                error_log("WSL_Admin_Ajax::download_html: No shop URL provided");
                wp_send_json_error(['message' => __('No shop URL provided', 'woo-shops-library')]);
            }

            $link_obj = WSL_Links_DB::get_links_by_shop($shop_url, ['limit' => 1]);
            $link = !empty($link_obj) && isset($link_obj[0]->link) ? $link_obj[0]->link : '';
            error_log("WSL_Admin_Ajax::download_html: link=$link");

            if (!$link) {
                error_log("WSL_Admin_Ajax::download_html: No links available for shop $shop_url");
                wp_send_json_error(['message' => __('No links available for preview.', 'woo-shops-library')]);
                return;
            }

            $html = WSL_Price_Retrieval::fetch_html($link);
            error_log("WSL_Admin_Ajax::download_html: fetch_html result length=" . strlen($html));

            if (strpos($html, 'HTML scraping failed:') === 0) {
                error_log("WSL_Admin_Ajax::download_html: HTML scraping failed with message: $html");
                wp_send_json_error(['message' => $html]);
            } else {
                error_log("WSL_Admin_Ajax::download_html: HTML scraping succeeded");
                wp_send_json_success(['html' => $html]);
            }
        }

        public static function test_price_scraping() {
            error_log("WSL_Admin_Ajax::test_price_scraping: AJAX request received");
            check_ajax_referer('wsl_test_price_scraping_nonce', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                error_log("WSL_Admin_Ajax::test_price_scraping: Unauthorized access");
                wp_send_json_error(['message' => __('Unauthorized', 'woo-shops-library')]);
            }

            $shop_url = sanitize_text_field($_POST['shop_url'] ?? '');
            $discount_test_raw = isset($_POST['discount_test']) ? wp_unslash($_POST['discount_test']) : '{}';
            $voucher_test_raw = isset($_POST['voucher_test']) ? wp_unslash($_POST['voucher_test']) : '{}';
            $discount_test = is_array($discount_test_raw) ? $discount_test_raw : json_decode($discount_test_raw, true);
            $voucher_test = is_array($voucher_test_raw) ? $voucher_test_raw : json_decode($voucher_test_raw, true);
            $methods_raw = wp_unslash($_POST['methods'] ?? '[]');
            $methods = json_decode($methods_raw, true);

            foreach ($methods as &$method) {
                if (isset($method['parameter'])) {
                    $method['parameter'] = str_replace('\"', '"', stripslashes($method['parameter']));
                }
            }
            unset($method);

            error_log("WSL_Admin_Ajax::test_price_scraping: Raw POST data - discount_test_raw=" . (is_array($discount_test_raw) ? print_r($discount_test_raw, true) : $discount_test_raw) . ", voucher_test_raw=" . (is_array($voucher_test_raw) ? print_r($voucher_test_raw, true) : $voucher_test_raw));
            error_log("WSL_Admin_Ajax::test_price_scraping: shop_url=$shop_url, discount_test=" . print_r($discount_test, true) . ", voucher_test=" . print_r($voucher_test, true) . ", methods=" . print_r($methods, true));

            if (empty($shop_url)) {
                error_log("WSL_Admin_Ajax::test_price_scraping: No shop URL provided");
                wp_send_json_error(['message' => __('No shop URL provided', 'woo-shops-library')]);
            }

            if (!is_array($methods) || count($methods) !== 3) {
                error_log("WSL_Admin_Ajax::test_price_scraping: Invalid methods format");
                wp_send_json_error(['message' => __('Invalid methods format: Expected array of 3 methods', 'woo-shops-library')]);
            }

            $result = [];

            // Process Discount Test
            if (!empty($discount_test['url'])) {
                $discount_url = esc_url_raw($discount_test['url']);
                $expected_current = floatval($discount_test['expected_current'] ?? 0);
                $expected_original = floatval($discount_test['expected_original'] ?? 0);

                $transient_key = 'wsl_test_discount_' . md5($discount_url . json_encode($methods));
                $cached_result = get_transient($transient_key);
                if ($cached_result !== false) {
                    error_log("WSL_Admin_Ajax::test_price_scraping: Returning cached discount result for $discount_url");
                    $result['discount'] = $cached_result;
                } else {
                    $prices = WSL_Price_Retrieval::fetch_price($discount_url, $methods);
                    error_log("WSL_Admin_Ajax::test_price_scraping: Discount fetch_price result=" . print_r($prices, true));

                    if (is_string($prices) && strpos($prices, 'Scraping failed:') === 0) {
                        $result['discount'] = ['error' => $prices];
                    } else {
                        $current = floatval($prices['current_price'] ?? 0);
                        $original = floatval($prices['original_price'] ?? 0);
                        $voucher = floatval($prices['voucher_price'] ?? 0);
                        $discount_percentage = floatval($prices['discount_percentage'] ?? 0);
                        $price_status = $prices['price_status'] ?? 'Standard';

                        $validation = '';
                        $test_status = 'Success';
                        if ($current <= 0) {
                            $validation = '<span class="validation-error">⚠️ No current price found</span>';
                            $test_status = 'Failed';
                        } elseif ($expected_current > 0 && abs($current - $expected_current) > 0.01) {
                            $validation .= '<span class="validation-error">⚠️ Current mismatch: Expected ' . $expected_current . ', got ' . $current . '</span><br>';
                            $test_status = 'Failed';
                        } elseif ($expected_original > 0 && abs($original - $expected_original) > 0.01) {
                            $validation .= '<span class="validation-error">⚠️ Original mismatch: Expected ' . $expected_original . ', got ' . $original . '</span><br>';
                            $test_status = 'Failed';
                        } elseif ($current >= $original && $original > 0) {
                            $validation .= '<span class="validation-warning">⚠️ Invalid discount: Current ≥ Original</span><br>';
                            $test_status = 'Failed';
                        } else {
                            $validation = '<span class="validation-success">✅ Discount values match</span>';
                        }

                        // Store test results
                        $link = WSL_Links_DB::get_link_by_url($discount_url);
                        if ($link) {
                            WSL_Links_DB::store_link_prices(
                                $link->id,
                                $prices['current_price'],
                                $prices['original_price'],
                                $prices['voucher_price'],
                                $discount_percentage,
                                $price_status,
                                $test_status
                            );
                        } else {
                            error_log("WSL_Admin_Ajax::test_price_scraping: No link found for URL $discount_url to store test results");
                        }

                        $result['discount'] = [
                            'url' => $discount_url,
                            'current_price' => $prices['current_price'] ?: '',
                            'original_price' => $prices['original_price'] ?: '',
                            'voucher_price' => $prices['voucher_price'] ?: '',
                            'expected_current' => $expected_current ? strval($expected_current) : '',
                            'expected_original' => $expected_original ? strval($expected_original) : '',
                            'discount_percentage' => $discount_percentage,
                            'price_status' => $price_status,
                            'test_status' => $test_status,
                            'validation' => $validation
                        ];
                        set_transient($transient_key, $result['discount'], 5 * MINUTE_IN_SECONDS);
                    }
                }
            }

            // Process Voucher Test
            if (!empty($voucher_test['url'])) {
                $voucher_url = esc_url_raw($voucher_test['url']);
                $expected_current = floatval($voucher_test['expected_current'] ?? 0);
                $expected_voucher = floatval($voucher_test['expected_voucher'] ?? 0);

                $transient_key = 'wsl_test_voucher_' . md5($voucher_url . json_encode($methods));
                $cached_result = get_transient($transient_key);
                if ($cached_result !== false) {
                    error_log("WSL_Admin_Ajax::test_price_scraping: Returning cached voucher result for $voucher_url");
                    $result['voucher'] = $cached_result;
                } else {
                    $prices = WSL_Price_Retrieval::fetch_price($voucher_url, $methods);
                    error_log("WSL_Admin_Ajax::test_price_scraping: Voucher fetch_price result=" . print_r($prices, true));

                    if (is_string($prices) && strpos($prices, 'Scraping failed:') === 0) {
                        $result['voucher'] = ['error' => $prices];
                    } else {
                        $current = floatval($prices['current_price'] ?? 0);
                        $original = floatval($prices['original_price'] ?? 0);
                        $voucher = floatval($prices['voucher_price'] ?? 0);
                        $discount_percentage = floatval($prices['discount_percentage'] ?? 0);
                        $price_status = $prices['price_status'] ?? 'Standard';

                        $validation = '';
                        $test_status = 'Success';
                        if ($current <= 0) {
                            $validation = '<span class="validation-error">⚠️ No current price found</span>';
                            $test_status = 'Failed';
                        } elseif ($expected_current > 0 && abs($current - $expected_current) > 0.01) {
                            $validation .= '<span class="validation-error">⚠️ Current mismatch: Expected ' . $expected_current . ', got ' . $current . '</span><br>';
                            $test_status = 'Failed';
                        } elseif ($expected_voucher > 0 && abs($voucher - $expected_voucher) > 0.01) {
                            $validation .= '<span class="validation-error">⚠️ Voucher mismatch: Expected ' . $expected_voucher . ', got ' . $voucher . '</span><br>';
                            $test_status = 'Failed';
                        } elseif ($voucher >= $current && $voucher > 0) {
                            $validation .= '<span class="validation-warning">⚠️ Invalid voucher: Voucher ≥ Current</span><br>';
                            $test_status = 'Failed';
                        } else {
                            $validation = '<span class="validation-success">✅ Voucher values match</span>';
                        }

                        // Store test results
                        $link = WSL_Links_DB::get_link_by_url($voucher_url);
                        if ($link) {
                            WSL_Links_DB::store_link_prices(
                                $link->id,
                                $prices['current_price'],
                                $prices['original_price'],
                                $prices['voucher_price'],
                                $discount_percentage,
                                $price_status,
                                $test_status
                            );
                        } else {
                            error_log("WSL_Admin_Ajax::test_price_scraping: No link found for URL $voucher_url to store test results");
                        }

                        $result['voucher'] = [
                            'url' => $voucher_url,
                            'current_price' => $prices['current_price'] ?: '',
                            'original_price' => $prices['original_price'] ?: '',
                            'voucher_price' => $prices['voucher_price'] ?: '',
                            'expected_current' => $expected_current ? strval($expected_current) : '',
                            'expected_voucher' => $expected_voucher ? strval($expected_voucher) : '',
                            'discount_percentage' => $discount_percentage,
                            'price_status' => $price_status,
                            'test_status' => $test_status,
                            'validation' => $validation
                        ];
                        set_transient($transient_key, $result['voucher'], 5 * MINUTE_IN_SECONDS);
                    }
                }
            }

            if (empty($result)) {
                error_log("WSL_Admin_Ajax::test_price_scraping: No valid URLs provided for testing");
                wp_send_json_error(['message' => __('No valid URLs provided for testing.', 'woo-shops-library')]);
            }

            error_log("WSL_Admin_Ajax::test_price_scraping: Test completed - " . print_r($result, true));
            wp_send_json_success($result);
        }
    }

    WSL_Admin_Ajax::init();
}