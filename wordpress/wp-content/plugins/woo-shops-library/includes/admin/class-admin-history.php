<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_History')) {
    class WSL_Admin_History {
        /**
         * Render the Price History page.
         */
        public static function render_price_history_page() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-shops-library'));
            }

            global $wpdb;
            $shop_url = isset($_GET['shop_url']) ? sanitize_text_field($_GET['shop_url']) : '';
            $link_search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
            $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
            $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
            $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
            $per_page = get_option('wsl_links_per_page', 40);
            $orderby = isset($_GET['orderby']) && in_array(sanitize_key($_GET['orderby']), ['date_scraped', 'current_price', 'original_price', 'voucher_price', 'discount_percentage', 'price_status']) 
                ? sanitize_key($_GET['orderby']) 
                : 'date_scraped';
            $order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) 
                ? strtoupper($_GET['order']) 
                : 'DESC';

            $args = [
                'shop_url' => $shop_url,
                'search' => $link_search,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'limit' => $per_page,
                'offset' => ($paged - 1) * $per_page,
                'order_by' => $orderby,
                'order' => $order
            ];

            $prices = WSL_Links_DB::get_price_history($args);
            $total_items = WSL_Links_DB::get_price_history_count($args);
            $total_pages = ceil($total_items / $per_page);

            $shops = $wpdb->get_results("SELECT shop_url, shop_name FROM {$wpdb->prefix}wsl_shops ORDER BY shop_name ASC");
            $currency_details = $shop_url ? WSL_Shops_DB::get_shop_currency_details($shop_url) : null;

            // Helper function to format price with position
            $format_price = function($price, $link_id) use ($currency_details, $wpdb) {
                if (!$price) return 'N/A';
                if ($currency_details) {
                    // Single shop filter applied
                    $symbol = $currency_details['symbol'];
                    $position = $currency_details['position'];
                } else {
                    // All shops view, fetch per link
                    $shop_url = $wpdb->get_var($wpdb->prepare("SELECT shop_url FROM {$wpdb->prefix}wsl_links WHERE id = %d", $link_id));
                    $details = WSL_Shops_DB::get_shop_currency_details($shop_url);
                    $symbol = $details['symbol'];
                    $position = $details['position'];
                }
                return $position === 'before' ? $symbol . ' ' . $price : $price . ' ' . $symbol;
            };

            ?>
            <div class="wrap wsl-price-history-page">
                <h1 class="wp-heading-inline"><?php esc_html_e('Price History', 'woo-shops-library'); ?></h1>
                <form method="get">
                    <input type="hidden" name="page" value="wsl_price_history" />
                    <p class="search-box">
                        <label for="shop_url" class="screen-reader-text"><?php esc_html_e('Filter by Shop', 'woo-shops-library'); ?></label>
                        <select name="shop_url" id="shop_url">
                            <option value=""><?php esc_html_e('All Shops', 'woo-shops-library'); ?></option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo esc_attr($shop->shop_url); ?>" <?php selected($shop_url, $shop->shop_url); ?>><?php echo esc_html($shop->shop_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="link-search-input" class="screen-reader-text"><?php esc_html_e('Search Links', 'woo-shops-library'); ?></label>
                        <input type="search" id="link-search-input" name="s" value="<?php echo esc_attr($link_search); ?>" placeholder="<?php esc_attr_e('Search links or products...', 'woo-shops-library'); ?>" />
                        <label for="start_date"><?php esc_html_e('From:', 'woo-shops-library'); ?></label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" />
                        <label for="end_date"><?php esc_html_e('To:', 'woo-shops-library'); ?></label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" />
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'woo-shops-library'); ?>" />
                    </p>
                </form>

                <table class="wsl-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'date_scraped', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Date Scraped', 'woo-shops-library'); ?> <?php echo $orderby === 'date_scraped' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'current_price', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Current Price', 'woo-shops-library'); ?> <?php echo $orderby === 'current_price' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'original_price', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Original Price', 'woo-shops-library'); ?> <?php echo $orderby === 'original_price' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'voucher_price', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Voucher Price', 'woo-shops-library'); ?> <?php echo $orderby === 'voucher_price' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'discount_percentage', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Discount %', 'woo-shops-library'); ?> <?php echo $orderby === 'discount_percentage' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'price_status', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Price Status', 'woo-shops-library'); ?> <?php echo $orderby === 'price_status' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><?php esc_html_e('Link', 'woo-shops-library'); ?></th>
                            <th><?php esc_html_e('Product Name', 'woo-shops-library'); ?></th>
                            <th><?php esc_html_e('Actions', 'woo-shops-library'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($prices)): ?>
                            <tr><td colspan="9"><?php esc_html_e('No price history found.', 'woo-shops-library'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($prices as $price): ?>
                                <tr>
                                    <td><?php echo esc_html($price->date_scraped); ?></td>
                                    <td><?php echo esc_html($format_price($price->current_price, $price->link_id)); ?></td>
                                    <td><?php echo esc_html($format_price($price->original_price, $price->link_id)); ?></td>
                                    <td><?php echo esc_html($format_price($price->voucher_price, $price->link_id)); ?></td>
                                    <td><?php echo esc_html($price->discount_percentage ? number_format($price->discount_percentage, 2) . '%' : '0%'); ?></td>
                                    <td><?php echo esc_html($price->price_status); ?></td>
                                    <td><span title="<?php echo esc_attr($price->link); ?>"><?php echo esc_html(wp_trim_words($price->link, 5, '...')); ?></span></td>
                                    <td><?php echo esc_html($price->product_name); ?></td>
                                    <td><a href="<?php echo esc_url($price->link); ?>" target="_blank" class="button"><?php esc_html_e('View', 'woo-shops-library'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="tablenav bottom">
                    <?php echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'total' => $total_pages,
                        'current' => $paged,
                        'prev_text' => __('« Prev'),
                        'next_text' => __('Next »'),
                    ]); ?>
                </div>
            </div>
            <?php
        }
    }
}