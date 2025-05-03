<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_Config')) {
    class WSL_Admin_Config {
        public static function render_settings_page() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-shops-library'));
            }

            $notice = '';
            $currencies = get_option('wsl_currencies', []);
            $default_currency = get_option('wsl_default_currency', 'USD');

            if (isset($_POST['wsl_settings_nonce']) && wp_verify_nonce($_POST['wsl_settings_nonce'], 'wsl_save_settings')) {
                update_option('wsl_default_cta', sanitize_text_field($_POST['wsl_default_cta']));
                update_option('wsl_default_cta_price', sanitize_text_field($_POST['wsl_default_cta_price']));
                $shops_per_page = absint($_POST['wsl_shops_per_page']);
                $links_per_page = absint($_POST['wsl_links_per_page']);
                $scrape_periodicity = sanitize_text_field($_POST['wsl_scrape_periodicity']);
                update_option('wsl_shops_per_page', $shops_per_page > 0 ? $shops_per_page : 40);
                update_option('wsl_links_per_page', $links_per_page > 0 ? $links_per_page : 40);
                update_option('wsl_scrape_periodicity', in_array($scrape_periodicity, ['daily', 'weekly', 'monthly']) ? $scrape_periodicity : 'weekly');

                $schedules = ['daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly'];
                $periodicity = get_option('wsl_scrape_periodicity', 'weekly');
                if (!wp_next_scheduled('wsl_scrape_all_prices')) {
                    wp_schedule_event(time(), $schedules[$periodicity], 'wsl_scrape_all_prices');
                } else {
                    wp_clear_scheduled_hook('wsl_scrape_all_prices');
                    wp_schedule_event(time(), $schedules[$periodicity], 'wsl_scrape_all_prices');
                }

                $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Settings saved.', 'woo-shops-library') . '</p></div>';
            }

            if (isset($_POST['wsl_currency_nonce']) && wp_verify_nonce($_POST['wsl_currency_nonce'], 'wsl_save_currencies')) {
                $action = sanitize_text_field($_POST['currency_action']);
                if ($action === 'add' && !empty($_POST['new_currency_code']) && !empty($_POST['new_currency_symbol']) && !empty($_POST['new_currency_name'])) {
                    $new_code = strtoupper(sanitize_text_field($_POST['new_currency_code']));
                    $new_symbol = sanitize_text_field($_POST['new_currency_symbol']);
                    $new_name = sanitize_text_field($_POST['new_currency_name']);
                    $new_position = in_array($_POST['new_currency_position'], ['before', 'after']) ? $_POST['new_currency_position'] : 'before';
                    if (strlen($new_code) <= 10 && !array_key_exists($new_code, array_column($currencies, 'code', 'code'))) {
                        $currencies[] = ['code' => $new_code, 'symbol' => $new_symbol, 'name' => $new_name, 'position' => $new_position];
                        update_option('wsl_currencies', $currencies);
                        $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Currency added.', 'woo-shops-library') . '</p></div>';
                    } else {
                        $notice = '<div class="notice notice-error wsl-notice is-dismissible"><p>' . esc_html__('Invalid or duplicate currency code.', 'woo-shops-library') . '</p></div>';
                    }
                } elseif ($action === 'save' && !empty($_POST['edit_currency_code']) && !empty($_POST['edit_currency_symbol']) && !empty($_POST['edit_currency_name']) && !empty($_POST['edit_currency_key'])) {
                    $key = absint($_POST['edit_currency_key']);
                    if (isset($currencies[$key])) {
                        $new_code = strtoupper(sanitize_text_field($_POST['edit_currency_code']));
                        $new_symbol = sanitize_text_field($_POST['edit_currency_symbol']);
                        $new_name = sanitize_text_field($_POST['edit_currency_name']);
                        $new_position = in_array($_POST['edit_currency_position'], ['before', 'after']) ? $_POST['edit_currency_position'] : 'before';
                        if (strlen($new_code) <= 10 && ($new_code === $currencies[$key]['code'] || !array_key_exists($new_code, array_column($currencies, 'code', 'code')))) {
                            $currencies[$key] = ['code' => $new_code, 'symbol' => $new_symbol, 'name' => $new_name, 'position' => $new_position];
                            update_option('wsl_currencies', $currencies);
                            $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Currency updated.', 'woo-shops-library') . '</p></div>';
                        } else {
                            $notice = '<div class="notice notice-error wsl-notice is-dismissible"><p>' . esc_html__('Invalid or duplicate currency code.', 'woo-shops-library') . '</p></div>';
                        }
                    }
                } elseif ($action === 'remove' && !empty($_POST['remove_currency_key'])) {
                    $key = absint($_POST['remove_currency_key']);
                    if (isset($currencies[$key])) {
                        $removed_code = $currencies[$key]['code'];
                        unset($currencies[$key]);
                        $currencies = array_values($currencies);
                        update_option('wsl_currencies', $currencies);
                        if ($default_currency === $removed_code) {
                            update_option('wsl_default_currency', $currencies[0]['code'] ?? 'USD');
                        }
                        $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Currency removed.', 'woo-shops-library') . '</p></div>';
                    }
                } elseif ($action === 'reorder' && !empty($_POST['currency_order'])) {
                    $order = sanitize_text_field($_POST['currency_order']);
                    $new_order = array_map('absint', explode(',', $order));
                    $reordered = [];
                    foreach ($new_order as $index) {
                        if (isset($currencies[$index])) {
                            $reordered[] = $currencies[$index];
                        }
                    }
                    if (!empty($reordered)) {
                        error_log("WSL_Admin_Config: Reordering currencies - New order: " . print_r($reordered, true));
                        update_option('wsl_currencies', $reordered);
                        $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Currencies reordered.', 'woo-shops-library') . '</p></div>';
                    } else {
                        error_log("WSL_Admin_Config: Reorder failed - Invalid order: " . print_r($new_order, true));
                        $notice = '<div class="notice notice-error wsl-notice is-dismissible"><p>' . esc_html__('Failed to reorder currencies: invalid order data.', 'woo-shops-library') . '</p></div>';
                    }
                } elseif ($action === 'set_default' && !empty($_POST['new_default_currency'])) {
                    $new_default = strtoupper(sanitize_text_field($_POST['new_default_currency']));
                    if (in_array($new_default, array_column($currencies, 'code'))) {
                        update_option('wsl_default_currency', $new_default);
                        $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Default currency updated.', 'woo-shops-library') . '</p></div>';
                    }
                }
            }

            $default_cta = get_option('wsl_default_cta', '');
            $default_cta_price = get_option('wsl_default_cta_price', '');
            $shops_per_page = get_option('wsl_shops_per_page', 40);
            $links_per_page = get_option('wsl_links_per_page', 40);
            $scrape_periodicity = get_option('wsl_scrape_periodicity', 'weekly');
            $currencies = get_option('wsl_currencies', []);
            $default_currency = get_option('wsl_default_currency', 'USD');

            ?>
            <div class="wrap wsl-settings-page">
                <h1 class="wp-heading-inline"><?php esc_html_e('Shops Library Settings', 'woo-shops-library'); ?></h1>
                <?php echo $notice; ?>

                <form method="post">
                    <?php wp_nonce_field('wsl_save_settings', 'wsl_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="wsl_default_cta"><?php esc_html_e('Default CTA Text', 'woo-shops-library'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="wsl_default_cta" id="wsl_default_cta" value="<?php echo esc_attr($default_cta); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e('Use {shop_name} as a placeholder for the shop name.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wsl_default_cta_price"><?php esc_html_e('Default CTA with Price Text', 'woo-shops-library'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="wsl_default_cta_price" id="wsl_default_cta_price" value="<?php echo esc_attr($default_cta_price); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e('Use {product_price} and/or {shop_name} as placeholders.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wsl_shops_per_page"><?php esc_html_e('Shops Per Page', 'woo-shops-library'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="wsl_shops_per_page" id="wsl_shops_per_page" value="<?php echo esc_attr($shops_per_page); ?>" class="regular-text" min="1" required />
                                <p class="description"><?php esc_html_e('Number of shops to display per page.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wsl_links_per_page"><?php esc_html_e('Links Per Page', 'woo-shops-library'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="wsl_links_per_page" id="wsl_links_per_page" value="<?php echo esc_attr($links_per_page); ?>" class="regular-text" min="1" required />
                                <p class="description"><?php esc_html_e('Number of links to display per page.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wsl_scrape_periodicity"><?php esc_html_e('Price Scrape Periodicity', 'woo-shops-library'); ?></label>
                            </th>
                            <td>
                                <select name="wsl_scrape_periodicity" id="wsl_scrape_periodicity" class="regular-text">
                                    <option value="daily" <?php selected($scrape_periodicity, 'daily'); ?>><?php esc_html_e('Daily', 'woo-shops-library'); ?></option>
                                    <option value="weekly" <?php selected($scrape_periodicity, 'weekly'); ?>><?php esc_html_e('Weekly', 'woo-shops-library'); ?></option>
                                    <option value="monthly" <?php selected($scrape_periodicity, 'monthly'); ?>><?php esc_html_e('Monthly', 'woo-shops-library'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('How often to automatically scrape prices.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'woo-shops-library'); ?></button>
                    </p>
                </form>

                <h2><?php esc_html_e('Currency Management', 'woo-shops-library'); ?></h2>
                <form method="post" id="wsl-currency-form">
                    <?php wp_nonce_field('wsl_save_currencies', 'wsl_currency_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Add New Currency', 'woo-shops-library'); ?></th>
                            <td>
                                <input type="text" name="new_currency_code" id="new_currency_code" placeholder="Code (e.g., USD)" maxlength="10" class="regular-text" style="width: 100px;" />
                                <input type="text" name="new_currency_symbol" id="new_currency_symbol" placeholder="Symbol (e.g., $)" class="regular-text" style="width: 100px;" />
                                <input type="text" name="new_currency_name" id="new_currency_name" placeholder="Name (e.g., US Dollar)" class="regular-text" style="width: 150px;" />
                                <select name="new_currency_position" id="new_currency_position" class="regular-text" style="width: 120px;">
                                    <option value="before"><?php esc_html_e('Before Price', 'woo-shops-library'); ?></option>
                                    <option value="after"><?php esc_html_e('After Price', 'woo-shops-library'); ?></option>
                                </select>
                                <button type="submit" name="currency_action" value="add" class="button"><?php esc_html_e('Add', 'woo-shops-library'); ?></button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Currencies', 'woo-shops-library'); ?></th>
                            <td>
                                <ul id="currency-list" style="list-style: none; padding: 0;">
                                    <?php if (empty($currencies)): ?>
                                        <li><?php esc_html_e('No currencies defined.', 'woo-shops-library'); ?></li>
                                    <?php else: ?>
                                        <?php foreach ($currencies as $key => $currency): ?>
                                            <li class="currency-item" data-key="<?php echo esc_attr($key); ?>" style="margin-bottom: 10px;">
                                                <span class="handle" style="cursor: move; margin-right: 10px;">☰</span>
                                                <input type="text" name="edit_currency_code_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($currency['code']); ?>" class="regular-text" style="width: 100px;" readonly />
                                                <input type="text" name="edit_currency_symbol_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($currency['symbol']); ?>" class="regular-text" style="width: 100px;" />
                                                <input type="text" name="edit_currency_name_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($currency['name']); ?>" class="regular-text" style="width: 150px;" />
                                                <select name="edit_currency_position_<?php echo esc_attr($key); ?>" class="regular-text" style="width: 120px;">
                                                    <option value="before" <?php selected($currency['position'] ?? 'before', 'before'); ?>><?php esc_html_e('Before Price', 'woo-shops-library'); ?></option>
                                                    <option value="after" <?php selected($currency['position'] ?? 'before', 'after'); ?>><?php esc_html_e('After Price', 'woo-shops-library'); ?></option>
                                                </select>
                                                <button type="button" class="button save-currency"><?php esc_html_e('Save', 'woo-shops-library'); ?></button>
                                                <button type="submit" name="currency_action" value="remove" class="button remove-currency"><?php esc_html_e('Remove', 'woo-shops-library'); ?></button>
                                                <?php if ($default_currency === $currency['code']): ?>
                                                    <span class="default-label"><?php esc_html_e('Default', 'woo-shops-library'); ?></span>
                                                <?php else: ?>
                                                    <button type="submit" name="currency_action" value="set_default" class="button set-default-currency" data-code="<?php echo esc_attr($currency['code']); ?>"><?php esc_html_e('Set as Default', 'woo-shops-library'); ?></button>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                                <input type="hidden" name="currency_order" id="currency_order" value="" />
                            </td>
                        </tr>
                    </table>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=wsl_shops_library')); ?>" class="button"><?php esc_html_e('Back to Shops Library', 'woo-shops-library'); ?></a></p>
                </form>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        if (typeof $.ui === 'undefined' || typeof $.ui.sortable === 'undefined') {
                            console.error('jQuery UI Sortable is not loaded. Drag-and-drop will not work.');
                        } else {
                            $('#currency-list').sortable({
                                handle: '.handle',
                                placeholder: 'ui-state-highlight',
                                update: function() {
                                    var order = $(this).sortable('toArray', {attribute: 'data-key'});
                                    $('#currency_order').val(order.join(','));
                                    $('#wsl-currency-form')
                                        .append('<input type="hidden" name="currency_action" value="reorder" />')
                                        .submit();
                                }
                            });
                        }

                        $('.save-currency').on('click', function() {
                            var $li = $(this).closest('.currency-item');
                            var key = $li.data('key');
                            var code = $li.find('input[name="edit_currency_code_' + key + '"]').val();
                            var symbol = $li.find('input[name="edit_currency_symbol_' + key + '"]').val();
                            var name = $li.find('input[name="edit_currency_name_' + key + '"]').val();
                            var position = $li.find('select[name="edit_currency_position_' + key + '"]').val();

                            $('#wsl-currency-form')
                                .append('<input type="hidden" name="currency_action" value="save" />')
                                .append('<input type="hidden" name="edit_currency_key" value="' + key + '" />')
                                .append('<input type="hidden" name="edit_currency_code" value="' + code + '" />')
                                .append('<input type="hidden" name="edit_currency_symbol" value="' + symbol + '" />')
                                .append('<input type="hidden" name="edit_currency_name" value="' + name + '" />')
                                .append('<input type="hidden" name="edit_currency_position" value="' + position + '" />')
                                .submit();
                        });

                        $('.remove-currency').on('click', function() {
                            if (confirm('<?php esc_html_e('Are you sure you want to remove this currency?', 'woo-shops-library'); ?>')) {
                                var key = $(this).data('key');
                                $('#wsl-currency-form')
                                    .append('<input type="hidden" name="currency_action" value="remove" />')
                                    .append('<input type="hidden" name="remove_currency_key" value="' + key + '" />')
                                    .submit();
                            }
                        });

                        $('.set-default-currency').on('click', function() {
                            var code = $(this).data('code');
                            $('#wsl-currency-form')
                                .append('<input type="hidden" name="currency_action" value="set_default" />')
                                .append('<input type="hidden" name="new_default_currency" value="' + code + '" />')
                                .submit();
                        });
                    });
                </script>

                <style>
                    .currency-item { display: flex; align-items: center; }
                    .currency-item input, .currency-item select { margin-right: 10px; }
                    .currency-item .button { margin-right: 10px; }
                    .currency-item .default-label { margin-right: 10px; color: green; }
                    .ui-state-highlight { height: 40px; background: #f0f0f0; border: 1px dashed #ccc; }
                </style>
            </div>
            <?php
        }

        public static function render_price_retrieval_page() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-shops-library'));
            }
            $shop_url = isset($_GET['shop_url']) ? sanitize_text_field(wp_unslash($_GET['shop_url'])) : '';
            if (empty($shop_url) || !preg_match('/^[a-zA-Z0-9\.\-_]+$/', $shop_url)) {
                wp_die(esc_html__('Invalid or no shop specified.', 'woo-shops-library'));
            }
            $shop = WSL_Shops_DB::get_shop_by_url($shop_url);
            if (!$shop) {
                wp_die(esc_html__('Shop not found.', 'woo-shops-library'));
            }

            $notice = '';
            $last_scraped = get_option("wsl_last_scraped_{$shop_url}", [
                'timestamp' => '',
                'current_price' => '',
                'original_price' => '',
                'voucher_price' => '',
                'discount_percentage' => 0.00,
                'price_status' => 'Standard',
                'error' => ''
            ]);
            $links = WSL_Links_DB::get_links_by_shop($shop_url);

            if (isset($_POST['wsl_price_retrieval_nonce']) && wp_verify_nonce($_POST['wsl_price_retrieval_nonce'], 'wsl_save_price_retrieval')) {
                $method_mode = sanitize_text_field(wp_unslash($_POST['method_mode'] ?? 'css'));
                $current_parameter = wp_unslash($_POST['current_parameter'] ?? '');
                $original_parameter = wp_unslash($_POST['original_parameter'] ?? '');
                $voucher_parameter = wp_unslash($_POST['voucher_parameter'] ?? '');
                $valid_modes = ['css', 'xpath'];
                if (!in_array($method_mode, $valid_modes)) {
                    $method_mode = 'css';
                }
                $methods = [
                    ['mode' => $method_mode, 'parameter' => $current_parameter],
                    ['mode' => $method_mode, 'parameter' => $original_parameter],
                    ['mode' => $method_mode, 'parameter' => $voucher_parameter]
                ];

                if (isset($_POST['manual_refresh'])) {
                    if ($links) {
                        $total_links = count($links);
                        $processed = 0;
                        $errors = [];
                        foreach ($links as $link) {
                            $result = WSL_Price_Retrieval::fetch_price($link->link, $methods);
                            error_log("WSL_Admin_Config::render_price_retrieval_page: Fetched prices for {$link->link}: " . print_r($result, true));
                            if (is_array($result) && isset($result['current_price'])) {
                                if (WSL_Links_DB::store_link_prices(
                                    $link->id,
                                    $result['current_price'],
                                    $result['original_price'],
                                    $result['voucher_price'],
                                    $result['discount_percentage'],
                                    $result['price_status']
                                )) {
                                    $processed++;
                                    update_option("wsl_last_scraped_{$shop_url}", [
                                        'timestamp' => current_time('mysql'),
                                        'current_price' => $result['current_price'],
                                        'original_price' => $result['original_price'],
                                        'voucher_price' => $result['voucher_price'],
                                        'discount_percentage' => $result['discount_percentage'],
                                        'price_status' => $result['price_status'],
                                        'error' => ''
                                    ]);
                                } else {
                                    $errors[] = "Failed to store prices for {$link->link}";
                                }
                            } else {
                                $errors[] = "Failed to scrape {$link->link}: " . ($result ?: 'Unknown error');
                            }
                        }
                        if ($errors) {
                            $notice = '<div class="notice notice-error wsl-notice is-dismissible"><p>' . 
                                      sprintf(esc_html__('Processed %d of %d links successfully. Errors: %s', 'woo-shops-library'), $processed, $total_links, implode('; ', $errors)) . 
                                      '</p></div>';
                        } else {
                            $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . 
                                      sprintf(esc_html__('Processed %d of %d links successfully.', 'woo-shops-library'), $processed, $total_links) . 
                                      '</p></div>';
                        }
                    } else {
                        $notice = '<div class="notice notice-warning wsl-notice is-dismissible"><p>' . esc_html__('No links found to refresh.', 'woo-shops-library') . '</p></div>';
                    }
                } else {
                    WSL_Shops_DB::upsert_shop($shop_url, $shop->shop_name, ['price_retrieval_methods' => json_encode($methods, JSON_UNESCAPED_SLASHES)]);
                    $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Price retrieval settings updated successfully.', 'woo-shops-library') . '</p></div>';
                }
                $shop = WSL_Shops_DB::get_shop_by_url($shop_url);
                $last_scraped = get_option("wsl_last_scraped_{$shop_url}", [
                    'timestamp' => '',
                    'current_price' => '',
                    'original_price' => '',
                    'voucher_price' => '',
                    'discount_percentage' => 0.00,
                    'price_status' => 'Standard',
                    'error' => ''
                ]);
            }

            $existing_methods = json_decode($shop->price_retrieval_methods ?? '[]', true);
            $method_mode = !empty($existing_methods) ? esc_attr($existing_methods[0]['mode'] ?? 'css') : 'css';
            $current_parameter = isset($existing_methods[0]['parameter']) ? esc_attr($existing_methods[0]['parameter']) : '';
            $original_parameter = isset($existing_methods[1]['parameter']) ? esc_attr($existing_methods[1]['parameter']) : '';
            $voucher_parameter = isset($existing_methods[2]['parameter']) ? esc_attr($existing_methods[2]['parameter']) : '';

            $currency_details = WSL_Shops_DB::get_shop_currency_details($shop_url);
            $currency_symbol = $currency_details['symbol'];
            $currency_position = $currency_details['position'];

            $format_price = function($price) use ($currency_symbol, $currency_position) {
                if (!$price) return 'N/A';
                return $currency_position === 'before' ? $currency_symbol . ' ' . $price : $price . ' ' . $currency_symbol;
            };

            ?>
            <div class="wrap wsl-price-retrieval-page">
                <h1 class="wp-heading-inline"><?php esc_html_e('Price Retrieval for', 'woo-shops-library'); ?> <?php echo esc_html($shop->shop_name); ?></h1>
                <?php echo $notice; ?>

                <form method="post" id="wsl-price-retrieval-form">
                    <?php wp_nonce_field('wsl_save_price_retrieval', 'wsl_price_retrieval_nonce'); ?>
                    <div class="wsl-price-settings">
                        <h2><?php esc_html_e('Scraping Configuration', 'woo-shops-library'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label><?php esc_html_e('Shop URL', 'woo-shops-library'); ?></label></th>
                                <td><input type="text" value="<?php echo esc_attr($shop->shop_url); ?>" class="regular-text" readonly /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="method_mode"><?php esc_html_e('Scraping Method', 'woo-shops-library'); ?></label></th>
                                <td>
                                    <select name="method_mode" id="method_mode" class="regular-text">
                                        <option value="css" <?php selected($method_mode, 'css'); ?>><?php esc_html_e('CSS Selector', 'woo-shops-library'); ?></option>
                                        <option value="xpath" <?php selected($method_mode, 'xpath'); ?>><?php esc_html_e('XPath', 'woo-shops-library'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Select the method to apply to all price fields (CSS or XPath).', 'woo-shops-library'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" colspan="2"><h3><?php esc_html_e('Parameters', 'woo-shops-library'); ?></h3></th>
                            </tr>
                            <tr>
                                <th scope="row"><label for="current_parameter"><?php esc_html_e('Current Price', 'woo-shops-library'); ?></label></th>
                                <td>
                                    <input type="text" name="current_parameter" id="current_parameter" value="<?php echo esc_attr($current_parameter); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g., .price or //span[@class=\'price\']', 'woo-shops-library'); ?>" required />
                                    <p class="description"><?php esc_html_e('Parameter for current price (e.g., ".price" for CSS, "//span[@class=\'price\']" for XPath).', 'woo-shops-library'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="original_parameter"><?php esc_html_e('Price Before Discount', 'woo-shops-library'); ?></label></th>
                                <td>
                                    <input type="text" name="original_parameter" id="original_parameter" value="<?php echo esc_attr($original_parameter); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g., .original-price or //del[@class=\'original\']', 'woo-shops-library'); ?>" />
                                    <p class="description"><?php esc_html_e('Parameter for price before discount (e.g., ".original-price" for CSS, "//del[@class=\'original\']" for XPath).', 'woo-shops-library'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="voucher_parameter"><?php esc_html_e('Price After Voucher', 'woo-shops-library'); ?></label></th>
                                <td>
                                    <input type="text" name="voucher_parameter" id="voucher_parameter" value="<?php echo esc_attr($voucher_parameter); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g., .voucher-price or //span[@class=\'voucher\']', 'woo-shops-library'); ?>" />
                                    <p class="description"><?php esc_html_e('Parameter for price after voucher (e.g., ".voucher-price" for CSS, "//span[@class=\'voucher\']" for XPath).', 'woo-shops-library'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="save_price_retrieval" class="button button-primary"><?php esc_html_e('Save Settings', 'woo-shops-library'); ?></button>
                            <button type="button" id="test-scrape" class="button"><?php esc_html_e('Test Configuration', 'woo-shops-library'); ?></button>
                            <button type="button" id="download-html" class="button"><?php esc_html_e('Download Sample HTML', 'woo-shops-library'); ?></button>
                        </p>
                    </div>

                    <div class="wsl-price-test">
                        <h2><?php esc_html_e('Test Price Scraping', 'woo-shops-library'); ?></h2>
                        <div class="wsl-accordion">
                            <div class="wsl-accordion-item">
                                <h3 class="wsl-accordion-header"><?php esc_html_e('Discount Scenario', 'woo-shops-library'); ?> <span class="wsl-spinner" id="discount-spinner" style="display:none;"></span></h3>
                                <div class="wsl-accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><label for="discount_test_url"><?php esc_html_e('Test URL', 'woo-shops-library'); ?></label></th>
                                            <td>
                                                <input type="url" name="discount_test_url" id="discount_test_url" class="regular-text" placeholder="<?php esc_attr_e('Enter a URL with a discount', 'woo-shops-library'); ?>" required />
                                                <p class="description"><?php esc_html_e('URL expected to have a discounted price.', 'woo-shops-library'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="discount_expected_current"><?php esc_html_e('Expected Current Price', 'woo-shops-library'); ?></label></th>
                                            <td>
                                                <input type="number" step="0.01" name="discount_expected_current" id="discount_expected_current" class="regular-text" placeholder="<?php esc_attr_e('e.g., 269', 'woo-shops-library'); ?>" />
                                                <p class="description"><?php esc_html_e('Expected current price after discount.', 'woo-shops-library'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="discount_expected_original"><?php esc_html_e('Expected Original Price', 'woo-shops-library'); ?></label></th>
                                            <td>
                                                <input type="number" step="0.01" name="discount_expected_original" id="discount_expected_original" class="regular-text" placeholder="<?php esc_attr_e('e.g., 299', 'woo-shops-library'); ?>" />
                                                <p class="description"><?php esc_html_e('Expected price before discount.', 'woo-shops-library'); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                    <p class="submit">
                                        <button type="button" id="test-discount" class="button button-secondary"><?php esc_html_e('Test Discount', 'woo-shops-library'); ?></button>
                                    </p>
                                </div>
                            </div>
                            <div class="wsl-accordion-item">
                                <h3 class="wsl-accordion-header"><?php esc_html_e('Voucher Scenario', 'woo-shops-library'); ?> <span class="wsl-spinner" id="voucher-spinner" style="display:none;"></span></h3>
                                <div class="wsl-accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><label for="voucher_test_url"><?php esc_html_e('Test URL', 'woo-shops-library'); ?></label></th>
                                            <td>
                                                <input type="url" name="voucher_test_url" id="voucher_test_url" class="regular-text" placeholder="<?php esc_attr_e('Enter a URL with a voucher', 'woo-shops-library'); ?>" required />
                                                <p class="description"><?php esc_html_e('URL expected to have a voucher price.', 'woo-shops-library'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="voucher_expected_current"><?php esc_html_e('Expected Current Price', 'woo-shops-library'); ?></label></th>
                                            <td>
                                                <input type="number" step="0.01" name="voucher_expected_current" id="voucher_expected_current" class="regular-text" placeholder="<?php esc_attr_e('e.g., 799', 'woo-shops-library'); ?>" />
                                                <p class="description"><?php esc_html_e('Expected current price before voucher.', 'woo-shops-library'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="voucher_expected_voucher"><?php esc_html_e('Expected Voucher Price', 'woo-shops-library'); ?></label></th>
                                            <td>
                                                <input type="number" step="0.01" name="voucher_expected_voucher" id="voucher_expected_voucher" class="regular-text" placeholder="<?php esc_attr_e('e.g., 749', 'woo-shops-library'); ?>" />
                                                <p class="description"><?php esc_html_e('Expected price after voucher.', 'woo-shops-library'); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                    <p class="submit">
                                        <button type="button" id="test-voucher" class="button button-secondary"><?php esc_html_e('Test Voucher', 'woo-shops-library'); ?></button>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wsl-test-results">
                        <h2><?php esc_html_e('Test Results', 'woo-shops-library'); ?></h2>
                        <table class="wsl-table wsl-test-table" style="display:none;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Scenario', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('URL', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Scraped Current', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Scraped Original', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Scraped Voucher', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Expected Current', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Expected Other', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Discount %', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Status', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Test Status', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Validation', 'woo-shops-library'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="test-results-body"></tbody>
                        </table>
                        <p id="test-no-results"><?php esc_html_e('No results yet. Run a test to see scraped values.', 'woo-shops-library'); ?></p>
                        <p class="submit">
                            <button type="button" id="clear-results" class="button"><?php esc_html_e('Clear Results', 'woo-shops-library'); ?></button>
                            <button type="button" id="copy-test-results" class="button" style="display:none;"><?php esc_html_e('Copy Results', 'woo-shops-library'); ?></button>
                        </p>
                    </div>

                    <div class="wsl-price-actions">
                        <h2><?php esc_html_e('Price Management', 'woo-shops-library'); ?></h2>
                        <div id="price-preview">
                            <p><?php esc_html_e('Test a scrape or refresh prices to see results here.', 'woo-shops-library'); ?></p>
                            <?php if (!empty($last_scraped['timestamp'])): ?>
                                <p><?php printf(esc_html__('Last Scraped: %s'), esc_html($last_scraped['timestamp'])); ?></p>
                                <p><?php printf(esc_html__('Current Price: %s'), esc_html($format_price($last_scraped['current_price']))); ?></p>
                                <p><?php printf(esc_html__('Original Price: %s'), esc_html($format_price($last_scraped['original_price']))); ?></p>
                                <p><?php printf(esc_html__('Voucher Price: %s'), esc_html($format_price($last_scraped['voucher_price']))); ?></p>
                                <p><?php printf(esc_html__('Discount Percentage: %s%%'), esc_html($last_scraped['discount_percentage'] ?: '0')); ?></p>
                                <p><?php printf(esc_html__('Price Status: %s'), esc_html($last_scraped['price_status'])); ?></p>
                                <?php if (!empty($last_scraped['error'])): ?>
                                    <p><?php printf(esc_html__('Error: %s'), esc_html($last_scraped['error'])); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <h3><?php esc_html_e('Current Link Prices', 'woo-shops-library'); ?></h3>
                        <table class="wsl-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Link', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Current Price', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Original Price', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Voucher Price', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Discount %', 'woo-shops-library'); ?></th>
                                    <th><?php esc_html_e('Status', 'woo-shops-library'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($links)): ?>
                                    <tr><td colspan="6"><?php esc_html_e('No links found.', 'woo-shops-library'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($links as $link): ?>
                                        <tr>
                                            <td><?php echo esc_html($link->link); ?></td>
                                            <td><?php echo esc_html($format_price($link->current_price)); ?></td>
                                            <td><?php echo esc_html($format_price($link->original_price)); ?></td>
                                            <td><?php echo esc_html($format_price($link->voucher_price)); ?></td>
                                            <td><?php echo esc_html($link->discount_percentage ? number_format($link->discount_percentage, 2) . '%' : '0%'); ?></td>
                                            <td><?php echo esc_html($link->price_status); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <p>
                            <button type="submit" name="manual_refresh" id="refresh-prices" class="button button-primary"><?php esc_html_e('Refresh All Prices', 'woo-shops-library'); ?></button>
                            <span id="progress-bar" style="display:none;"><progress max="100" value="0"></progress> <span id="progress-text">0%</span></span>
                        </p>
                        <p><a href="<?php echo esc_url(admin_url('admin.php?page=wsl_shops_library')); ?>" class="button"><?php esc_html_e('Back to Shops Library', 'woo-shops-library'); ?></a></p>
                    </div>
                </form>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        window.shopUrl = '<?php echo esc_js($shop_url); ?>';
                        var totalLinks = <?php echo count($links); ?>;
                        var processedLinks = 0;

                        function testScrape() {
                            var methods = [
                                { mode: $('#method_mode').val(), parameter: $('#current_parameter').val() },
                                { mode: $('#method_mode').val(), parameter: $('#original_parameter').val() },
                                { mode: $('#method_mode').val(), parameter: $('#voucher_parameter').val() }
                            ];
                            var requestData = {
                                action: 'wsl_test_scrape',
                                shop_url: window.shopUrl,
                                methods: JSON.stringify(methods),
                                nonce: '<?php echo wp_create_nonce('wsl_test_scrape_nonce'); ?>'
                            };
                            console.log('Sending Test Scrape Request:', requestData);

                            $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: requestData,
                                success: function(response) {
                                    console.log('Test Scrape Response:', response);
                                    if (response.success) {
                                        var formatPrice = function(price) {
                                            if (!price) return 'N/A';
                                            return '<?php echo $currency_position === 'before' ? esc_js($currency_symbol) . " " : ""; ?>' + price + '<?php echo $currency_position === 'after' ? " " . esc_js($currency_symbol) : ""; ?>';
                                        };
                                        $('#price-preview').html(
                                            '<p>Current Price: ' + formatPrice(response.data.current_price) + '</p>' +
                                            '<p>Original Price: ' + formatPrice(response.data.original_price) + '</p>' +
                                            '<p>Voucher Price: ' + formatPrice(response.data.voucher_price) + '</p>' +
                                            '<p>Discount Percentage: ' + (response.data.discount_percentage || '0') + '%</p>' +
                                            '<p>Price Status: ' + response.data.price_status + '</p>'
                                        );
                                    } else {
                                        $('#price-preview').html('<p>Error: ' + (response.data.message || 'Unknown error') + '</p>');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.log('Test Scrape AJAX Error:', status, error);
                                    $('#price-preview').html('<p>Error: Unable to connect to server - ' + error + '</p>');
                                }
                            });
                        }

                        function downloadHtml() {
                            $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'wsl_download_html',
                                    shop_url: window.shopUrl,
                                    nonce: '<?php echo wp_create_nonce('wsl_download_html_nonce'); ?>'
                                },
                                success: function(response) {
                                    console.log('Download HTML Response:', response);
                                    if (response.success) {
                                        const blob = new Blob([response.data.html], { type: 'text/html' });
                                        const link = document.createElement('a');
                                        link.href = window.URL.createObjectURL(blob);
                                        link.download = window.shopUrl.replace(/[^a-z0-9]/gi, '_') + '_sample.html';
                                        link.click();
                                        $('#price-preview').html('<p>HTML downloaded successfully.</p>');
                                    } else {
                                        $('#price-preview').html('<p>Error: ' + (response.data.message || 'Unknown error') + '</p>');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.log('Download HTML AJAX Error:', status, error);
                                    $('#price-preview').html('<p>Error: Unable to connect to server - ' + error + '</p>');
                                }
                            });
                        }

                        function testPriceScraping(scenario) {
                            var testUrl, expectedCurrent, expectedOther, spinnerId;
                            if (scenario === 'discount') {
                                testUrl = $('#discount_test_url').val().trim();
                                expectedCurrent = $('#discount_expected_current').val().trim();
                                expectedOther = $('#discount_expected_original').val().trim();
                                spinnerId = '#discount-spinner';
                            } else {
                                testUrl = $('#voucher_test_url').val().trim();
                                expectedCurrent = $('#voucher_expected_current').val().trim();
                                expectedOther = $('#voucher_expected_voucher').val().trim();
                                spinnerId = '#voucher-spinner';
                            }

                            if (!testUrl) {
                                alert('<?php esc_html_e('Please enter a test URL.', 'woo-shops-library'); ?>');
                                return;
                            }

                            var methods = [
                                { mode: $('#method_mode').val(), parameter: $('#current_parameter').val() },
                                { mode: $('#method_mode').val(), parameter: $('#original_parameter').val() },
                                { mode: $('#method_mode').val(), parameter: $('#voucher_parameter').val() }
                            ];

                            var requestData = {
                                action: 'wsl_test_price_scraping',
                                shop_url: window.shopUrl,
                                discount_test: scenario === 'discount' ? JSON.stringify({ url: testUrl, expected_current: expectedCurrent, expected_original: expectedOther }) : '{}',
                                voucher_test: scenario === 'voucher' ? JSON.stringify({ url: testUrl, expected_current: expectedCurrent, expected_voucher: expectedOther }) : '{}',
                                methods: JSON.stringify(methods),
                                nonce: '<?php echo wp_create_nonce('wsl_test_price_scraping_nonce'); ?>'
                            };
                            console.log('Sending Test Price Scraping Request:', requestData);

                            $(spinnerId).show();
                            $('#test-no-results').hide();
                            $('#copy-test-results').hide();

                            $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: requestData,
                                success: function(response) {
                                    console.log('Test Price Scraping Response:', response);
                                    $(spinnerId).hide();
                                    if (response.success) {
                                        var formatPrice = function(price) {
                                            if (!price) return 'N/A';
                                            return '<?php echo $currency_position === 'before' ? esc_js($currency_symbol) . " " : ""; ?>' + price + '<?php echo $currency_position === 'after' ? " " . esc_js($currency_symbol) : ""; ?>';
                                        };
                                        var tbody = $('#test-results-body');
                                        if (!tbody.children().length) {
                                            tbody.empty();
                                            $('.wsl-test-table').show();
                                        }

                                        var data = response.data.discount || response.data.voucher;
                                        if (data) {
                                            var row = $('<tr></tr>');
                                            row.append($('<td></td>').text(scenario.charAt(0).toUpperCase() + scenario.slice(1)));
                                            row.append($('<td></td>').text(data.url));
                                            row.append($('<td></td>').text(formatPrice(data.current_price)));
                                            row.append($('<td></td>').text(formatPrice(data.original_price)));
                                            row.append($('<td></td>').text(formatPrice(data.voucher_price)));
                                            row.append($('<td></td>').text(formatPrice(data.expected_current)));
                                            row.append($('<td></td>').text(formatPrice(scenario === 'discount' ? data.expected_original : data.expected_voucher)));
                                            row.append($('<td></td>').text(data.discount_percentage ? data.discount_percentage + '%' : '0%'));
                                            row.append($('<td></td>').text(data.price_status));
                                            row.append($('<td></td>').text(data.test_status || 'N/A'));
                                            row.append($('<td></td>').html(data.validation));
                                            tbody.append(row);
                                            $('#copy-test-results').show();
                                        }
                                    } else {
                                        var errorMsg = 'Error: ' + (response.data.message || 'Unknown error');
                                        $(spinnerId).after('<p class="test-error">' + errorMsg + '</p>');
                                        setTimeout(() => $('.test-error').remove(), 3000);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.log('Test Price Scraping AJAX Error:', status, error);
                                    $(spinnerId).hide();
                                    $(spinnerId).after('<p class="test-error">Error: Unable to connect to server - ' + error + '</p>');
                                    setTimeout(() => $('.test-error').remove(), 3000);
                                }
                            });
                        }

                        $('#current_parameter, #original_parameter, #voucher_parameter').on('keyup', testScrape);
                        $('#method_mode').on('change', testScrape);
                        $('#test-scrape').on('click', testScrape);
                        $('#download-html').on('click', downloadHtml);
                        $('#test-discount').on('click', function() { testPriceScraping('discount'); });
                        $('#test-voucher').on('click', function() { testPriceScraping('voucher'); });

                        $('#copy-test-results').on('click', function() {
                            var text = $('#test-results-body').text().trim();
                            navigator.clipboard.writeText(text).then(() => {
                                alert('<?php esc_html_e('Results copied to clipboard!', 'woo-shops-library'); ?>');
                            });
                        });

                        $('#clear-results').on('click', function() {
                            $('#test-results-body').empty();
                            $('.wsl-test-table').hide();
                            $('#test-no-results').show();
                            $('#copy-test-results').hide();
                        });

                        $('#refresh-prices').on('click', function(e) {
                            e.preventDefault();
                            console.log('Refresh clicked, totalLinks:', totalLinks);
                            if (totalLinks === 0 || !totalLinks) {
                                console.log('No links to refresh');
                                alert('<?php esc_html_e('No links to refresh.', 'woo-shops-library'); ?>');
                                return;
                            }
                            $(this).prop('disabled', true);
                            $('#progress-bar').show();
                            var methods = [
                                { mode: $('#method_mode').val(), parameter: $('#current_parameter').val() },
                                { mode: $('#method_mode').val(), parameter: $('#original_parameter').val() },
                                { mode: $('#method_mode').val(), parameter: $('#voucher_parameter').val() }
                            ];
                            console.log('Starting processBatch with methods:', methods);
                            processBatch(0, methods);
                        });

                        function processBatch(offset, methods) {
                            console.log('Processing batch at offset:', offset);
                            $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'wsl_refresh_prices',
                                    shop_url: window.shopUrl,
                                    methods: JSON.stringify(methods),
                                    offset: offset,
                                    nonce: '<?php echo wp_create_nonce('wsl_refresh_prices_nonce'); ?>'
                                },
                                success: function(response) {
                                    console.log('AJAX success:', response);
                                    if (response.success) {
                                        processedLinks += response.data.processed;
                                        var percent = Math.min(100, Math.round((processedLinks / totalLinks) * 100));
                                        $('progress').val(percent);
                                        $('#progress-text').text(percent + '%');
                                        if (processedLinks < totalLinks) {
                                            processBatch(offset + response.data.processed, methods);
                                        } else {
                                            $('#refresh-prices').prop('disabled', false);
                                            $('#progress-bar').hide();
                                            location.reload();
                                        }
                                    } else {
                                        alert('Error: ' + (response.data.message || 'Unknown error'));
                                        $('#refresh-prices').prop('disabled', false);
                                        $('#progress-bar').hide();
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('AJAX error:', status, error);
                                    alert('<?php esc_html_e('Server error during refresh: ', 'woo-shops-library'); ?>' + error);
                                    $('#refresh-prices').prop('disabled', false);
                                    $('#progress-bar').hide();
                                }
                            });
                        }

                        // Accordion functionality
                        $('.wsl-accordion-header').on('click', function() {
                            var $content = $(this).next('.wsl-accordion-content');
                            var $item = $(this).parent('.wsl-accordion-item');
                            if ($item.hasClass('active')) {
                                $content.slideUp();
                                $item.removeClass('active');
                            } else {
                                $('.wsl-accordion-content').slideUp();
                                $('.wsl-accordion-item').removeClass('active');
                                $content.slideDown();
                                $item.addClass('active');
                            }
                        });
                    });
                </script>

                <style>
                    .wsl-price-settings, .wsl-price-actions, .wsl-price-test, .wsl-test-results { margin: 20px 0; }
                    #price-preview { margin: 10px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; }
                    #progress-bar { margin-left: 10px; }
                    progress { vertical-align: middle; }
                    .wsl-table { width: 100%; }
                    .wsl-table th, .wsl-table td { padding: 8px; }
                    .wsl-test-table .validation-success { color: green; }
                    .wsl-test-table .validation-warning { color: orange; }
                    .wsl-test-table .validation-error { color: red; }
                    .wsl-price-test, .wsl-test-results { background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04); }
                    .wsl-accordion-item { margin-bottom: 10px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; }
                    .wsl-accordion-header { padding: 10px 15px; cursor: pointer; background: #f6f7f7; display: flex; justify-content: space-between; align-items: center; }
                    .wsl-accordion-header:hover { background: #e9ecef; }
                    .wsl-accordion-content { padding: 15px; display: none; }
                    .wsl-accordion-item.active .wsl-accordion-content { display: block; }
                    .wsl-spinner { display: inline-block; width: 20px; height: 20px; background: url('<?php echo admin_url('images/spinner.gif'); ?>') no-repeat; background-size: contain; }
                    .test-error { color: #dc3232; margin: 5px 0 0; }
                </style>
            </div>
            <?php
        }

        public static function handle_post_requests() {
            $notices = [];
            if (!current_user_can('manage_woocommerce')) {
                return $notices;
            }

            if (isset($_POST['action']) && $_POST['action'] === 'delete_link' && isset($_POST['link_id']) && isset($_POST['wsl_delete_link_nonce'])) {
                $link_id = absint($_POST['link_id']);
                $shop_url = isset($_POST['shop_url']) ? sanitize_text_field(wp_unslash($_POST['shop_url'])) : '';
                if (!$link_id || empty($shop_url) || !preg_match('/^[a-zA-Z0-9\.\-_]+$/', $shop_url)) {
                    $notices[] = ['type' => 'notice-error', 'message' => __('Invalid link or shop data.', 'woo-shops-library')];
                } elseif (wp_verify_nonce($_POST['wsl_delete_link_nonce'], 'wsl_delete_link_' . $link_id)) {
                    $result = WSL_Links_DB::delete_link($link_id);
                    if ($result !== false) {
                        $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
                        WSL_Shops_DB::recalc_link_count($shop_url_normalized);
                        $transient_notices = [['type' => 'notice-success', 'message' => __('Link deleted successfully.', 'woo-shops-library')]];
                        set_transient('wsl_admin_notices', $transient_notices, 30);
                        wp_safe_redirect(add_query_arg('shop_url', urlencode($shop_url), admin_url('admin.php?page=wsl_links_details')));
                        exit;
                    } else {
                        $notices[] = ['type' => 'notice-error', 'message' => __('Error deleting link.', 'woo-shops-library')];
                    }
                } else {
                    $notices[] = ['type' => 'notice-error', 'message' => __('Nonce verification failed. Link not deleted.', 'woo-shops-library')];
                }
            }

            if (isset($_POST['action']) && $_POST['action'] === 'delete_shop' && isset($_POST['shop_url']) && isset($_POST['wsl_delete_shop_nonce'])) {
                $shop_url = sanitize_text_field(wp_unslash($_POST['shop_url']));
                if (empty($shop_url) || !preg_match('/^[a-zA-Z0-9\.\-_]+$/', $shop_url)) {
                    $notices[] = ['type' => 'notice-error', 'message' => __('Invalid shop URL.', 'woo-shops-library')];
                } elseif (wp_verify_nonce($_POST['wsl_delete_shop_nonce'], 'wsl_delete_shop_' . $shop_url)) {
                    $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
                    WSL_Links_DB::delete_links_by_shop($shop_url_normalized);
                    WSL_Shops_DB::delete_shop($shop_url_normalized);
                    $transient_notices = [['type' => 'notice-success', 'message' => __('Shop and all associated links deleted successfully.', 'woo-shops-library')]];
                    set_transient('wsl_admin_notices', $transient_notices, 30);
                    wp_safe_redirect(admin_url('admin.php?page=wsl_shops_library'));
                    exit;
                } else {
                    $notices[] = ['type' => 'notice-error', 'message' => __('Nonce verification failed. Shop not deleted.', 'woo-shops-library')];
                }
            }

            if (isset($_POST['action']) && $_POST['action'] === 'add_new_link') {
                if (isset($_POST['wsl_nonce_link']) && wp_verify_nonce($_POST['wsl_nonce_link'], 'wsl_add_new_link')) {
                    $product_id = absint(wp_unslash($_POST['product_id']));
                    $link = esc_url_raw(wp_unslash($_POST['link']));
                    if (!$product_id || !$link) {
                        $notices[] = ['type' => 'notice-error', 'message' => __('Invalid product ID or link URL.', 'woo-shops-library')];
                    } else {
                        $wc_product = wc_get_product($product_id);
                        if (!$wc_product || $wc_product->get_type() === 'variation') {
                            $notices[] = ['type' => 'notice-error', 'message' => __('Invalid Product ID. Please enter a valid WooCommerce product ID.', 'woo-shops-library')];
                        } else {
                            $shop_url = parse_url($link, PHP_URL_HOST);
                            $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
                            if (!preg_match('/^[a-zA-Z0-9\.\-_]+$/', $shop_url_normalized)) {
                                $notices[] = ['type' => 'notice-error', 'message' => __('Invalid shop URL derived from link.', 'woo-shops-library')];
                            } else {
                                $shop_name = ucfirst(preg_replace('/\.[^.]+$/', '', $shop_url_normalized));
                                $existing_shop = WSL_Shops_DB::get_shop_by_url($shop_url_normalized);
                                if (!$existing_shop) {
                                    WSL_Shops_DB::upsert_shop($shop_url_normalized, $shop_name, ['link_count' => 0]);
                                }
                                if (WSL_Links_DB::get_link_by_url($link)) {
                                    $notices[] = ['type' => 'notice-error', 'message' => __('Link already exists. Duplicate insertion is not allowed.', 'woo-shops-library')];
                                } else {
                                    $product_name = $wc_product->get_name();
                                    $custom_cta = '';
                                    $custom_cta_price = '';
                                    $insert_result = WSL_Links_DB::insert_link([
                                        'link'             => $link,
                                        'product_id'       => $product_id,
                                        'product_name'     => $product_name,
                                        'current_price'    => '',
                                        'original_price'   => '',
                                        'voucher_price'    => '',
                                        'discount_percentage' => 0.00,
                                        'price_status'     => 'Standard',
                                        'shop_url'         => $shop_url_normalized,
                                        'custom_cta'       => $custom_cta,
                                        'custom_cta_price' => $custom_cta_price
                                    ]);

                                    if ($insert_result !== false) {
                                        $link_id = $insert_result;
                                        WSL_Shops_DB::recalc_link_count($shop_url_normalized);
                                        if (!wp_next_scheduled('wsl_fetch_initial_price', [$link_id, $link])) {
                                            wp_schedule_single_event(time() + 10, 'wsl_fetch_initial_price', [$link_id, $link]);
                                        }
                                        $transient_notices = [['type' => 'notice-success', 'message' => __('Link added successfully. Price will be fetched shortly in the background.', 'woo-shops-library')]];
                                        set_transient('wsl_admin_notices', $transient_notices, 30);
                                        wp_safe_redirect(admin_url('admin.php?page=wsl_links_details&shop_url=' . urlencode($shop_url_normalized)));
                                        exit;
                                    } else {
                                        global $wpdb;
                                        $error = $wpdb->last_error ?: 'Unknown error';
                                        error_log("Failed to insert link: $link. Error: $error");
                                        $notices[] = ['type' => 'notice-error', 'message' => sprintf(__('Failed to add link. Database error: %s', 'woo-shops-library'), esc_html($error))];
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $notices[] = ['type' => 'notice-error', 'message' => __('Nonce verification failed. Link not added.', 'woo-shops-library')];
                }
            }

            return $notices;
        }
    }

    add_action('wsl_scrape_all_prices', function () {
        global $wpdb;
        $shops = $wpdb->get_results("SELECT shop_url, price_retrieval_methods FROM {$wpdb->prefix}wsl_shops");
        foreach ($shops as $shop) {
            $methods = json_decode($shop->price_retrieval_methods ?? '[]', true);
            if (empty($methods) || count($methods) !== 3) {
                $methods = [
                    ['mode' => 'css', 'parameter' => '.price'],
                    ['mode' => 'css', 'parameter' => '.original-price'],
                    ['mode' => 'css', 'parameter' => '.voucher-price']
                ];
            }

            $links = WSL_Links_DB::get_links_by_shop($shop->shop_url);
            foreach ($links as $link) {
                $result = WSL_Price_Retrieval::fetch_price($link->link, $methods);
                error_log("WSL_Admin_Config::wsl_scrape_all_prices: Fetched prices for {$link->link}: " . print_r($result, true));
                if (is_array($result) && isset($result['current_price'])) {
                    WSL_Links_DB::store_link_prices(
                        $link->id,
                        $result['current_price'],
                        $result['original_price'],
                        $result['voucher_price'],
                        $result['discount_percentage'],
                        $result['price_status']
                    );
                }
            }
        }
    });
}