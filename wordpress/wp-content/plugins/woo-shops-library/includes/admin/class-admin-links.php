<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_Links')) {
    class WSL_Admin_Links {
        /**
         * Render the Links Details page with all price fields.
         */
        public static function render_links_details_page() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-shops-library'));
            }
            $notices = WSL_Admin_Config::handle_post_requests();
            $transient_notices = get_transient('wsl_admin_notices');
            if ($transient_notices) {
                $notices = array_merge($notices, $transient_notices);
                delete_transient('wsl_admin_notices');
            }

            $shop_url = isset($_GET['shop_url']) ? sanitize_text_field(wp_unslash($_GET['shop_url'])) : '';
            if (empty($shop_url) || !preg_match('/^[a-zA-Z0-9\.\-_]+$/', $shop_url)) {
                wp_die(esc_html__('Invalid or no shop specified.', 'woo-shops-library'));
            }
            $shop = WSL_Shops_DB::get_shop_by_url($shop_url);
            if (!$shop) {
                wp_die(esc_html__('Shop not found.', 'woo-shops-library'));
            }

            $per_page = get_option('wsl_links_per_page', 40);
            $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
            $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            $valid_orderby = ['link', 'product_id', 'product_name', 'current_price', 'original_price', 'voucher_price', 'discount_percentage', 'price_status', 'reference_count', 'link_date_modified'];
            $orderby = (isset($_GET['orderby']) && in_array(sanitize_key($_GET['orderby']), $valid_orderby))
                ? sanitize_key($_GET['orderby'])
                : 'link_date_modified';
            $order = (isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']))
                ? strtoupper($_GET['order'])
                : 'DESC';

            $shop_url_normalized = strtolower(preg_replace('/^www\./', '', $shop_url));
            $args = [
                'order_by' => $orderby,
                'order'    => $order,
                'limit'    => $per_page,
                'offset'   => ($current_page - 1) * $per_page,
                'search'   => $search
            ];
            $links = WSL_Links_DB::get_links_by_shop($shop_url_normalized, $args);
            $total_items = WSL_Links_DB::get_links_count_by_shop($shop_url_normalized, $search);
            $total_pages = ceil($total_items / $per_page);

            WSL_Shops_DB::update_link_count($shop_url_normalized, $total_items);
            foreach ($links as $link) {
                $updated_count = WSL_Links_DB::get_post_count_by_link($link->link);
                if ($updated_count !== (int) $link->reference_count) {
                    WSL_Links_DB::update_link($link->id, ['reference_count' => $updated_count]);
                    $link->reference_count = $updated_count;
                }
            }

            $shop_name = !empty($shop->shop_name) ? $shop->shop_name : ucfirst(preg_replace('/\.[^.]+$/', '', $shop_url));
            $currency_details = WSL_Shops_DB::get_shop_currency_details($shop_url);
            $currency_symbol = $currency_details['symbol'];
            $currency_position = $currency_details['position'];

            // Helper function to format price with position
            $format_price = function($price) use ($currency_symbol, $currency_position) {
                if (!$price) return 'N/A';
                return $currency_position === 'before' ? $currency_symbol . ' ' . $price : $price . ' ' . $currency_symbol;
            };

            ?>
            <div class="wrap wsl-links-details-page">
                <h1 class="wp-heading-inline"><?php printf(esc_html__('Links for %s', 'woo-shops-library'), esc_html($shop->shop_name)); ?></h1>
                <?php if (!empty($notices)): ?>
                    <?php foreach ($notices as $notice): ?>
                        <div class="notice <?php echo esc_attr($notice['type']); ?> wsl-notice is-dismissible">
                            <p><?php echo esc_html($notice['message']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="wsl_links_details" />
                    <input type="hidden" name="shop_url" value="<?php echo esc_attr($shop_url); ?>" />
                    <p class="search-box">
                        <label class="screen-reader-text" for="link-search-input"><?php esc_html_e('Search', 'woo-shops-library'); ?></label>
                        <input type="search" id="link-search-input" name="s" value="<?php echo esc_attr($search); ?>" />
                        <input type="submit" class="button" value="<?php esc_attr_e('Search', 'woo-shops-library'); ?>" />
                    </p>
                </form>

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_shops_library')); ?>" class="button"><?php esc_html_e('Back to Shops', 'woo-shops-library'); ?></a>
                    </div>
                </div>

                <table class="wsl-table">
                    <thead>
                        <tr>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'link', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Link', 'woo-shops-library'); ?> <?php echo $orderby === 'link' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'product_id', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Product ID', 'woo-shops-library'); ?> <?php echo $orderby === 'product_id' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'product_name', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Product Name', 'woo-shops-library'); ?> <?php echo $orderby === 'product_name' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
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
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'reference_count', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Reference Count', 'woo-shops-library'); ?> <?php echo $orderby === 'reference_count' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><?php esc_html_e('Custom CTA', 'woo-shops-library'); ?></th>
                            <th><?php esc_html_e('Custom CTA Price', 'woo-shops-library'); ?></th>
                            <th><a href="<?php echo esc_url(add_query_arg(['orderby' => 'link_date_modified', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                <?php esc_html_e('Date Modified', 'woo-shops-library'); ?> <?php echo $orderby === 'link_date_modified' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                            <th><?php esc_html_e('Actions', 'woo-shops-library'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($links)): ?>
                            <tr><td colspan="13"><?php esc_html_e('No links found.', 'woo-shops-library'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($links as $link): ?>
                                <?php
                                $custom_cta = $link->custom_cta ?: get_option('wsl_default_cta', '');
                                $custom_cta_price = $link->custom_cta_price ?: get_option('wsl_default_cta_price', '');
                                $custom_cta = str_replace('{shop_name}', $shop_name, $custom_cta);
                                $custom_cta_price = str_replace(
                                    ['{product_price}', '{shop_name}'],
                                    [$link->current_price ? $format_price($link->current_price) : '', $shop_name],
                                    $custom_cta_price
                                );
                                ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html($link->link); ?>
                                    </td>
                                    <td><?php echo esc_html($link->product_id); ?></td>
                                    <td><?php echo esc_html($link->product_name); ?></td>
                                    <td><?php echo esc_html($format_price($link->current_price)); ?></td>
                                    <td><?php echo esc_html($format_price($link->original_price)); ?></td>
                                    <td><?php echo esc_html($format_price($link->voucher_price)); ?></td>
                                    <td><?php echo esc_html($link->discount_percentage ? number_format($link->discount_percentage, 2) . '%' : '0%'); ?></td>
                                    <td><?php echo esc_html($link->price_status); ?></td>
                                    <td><?php echo esc_html($link->reference_count); ?></td>
                                    <td><?php echo esc_html($custom_cta); ?></td>
                                    <td><?php echo esc_html($custom_cta_price); ?></td>
                                    <td><?php echo esc_html($link->link_date_modified); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url($link->link); ?>" target="_blank" class="button"><?php esc_html_e('Visit', 'woo-shops-library'); ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_edit_link&link_id=' . $link->id . '&shop_url=' . urlencode($shop_url))); ?>">
                                                    <?php esc_html_e('Edit', 'woo-shops-library'); ?>
                                                </a> |
                                            </span>
                                            <span class="delete">
                                                <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this link?', 'woo-shops-library'); ?>');">
                                                    <?php wp_nonce_field('wsl_delete_link_' . $link->id, 'wsl_delete_link_nonce'); ?>
                                                    <input type="hidden" name="link_id" value="<?php echo esc_attr($link->id); ?>" />
                                                    <input type="hidden" name="shop_url" value="<?php echo esc_attr($shop_url); ?>" />
                                                    <input type="hidden" name="action" value="delete_link" />
                                                    <button type="submit" class="button-link delete"><?php esc_html_e('Delete', 'woo-shops-library'); ?></button>
                                                </form>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="tablenav bottom">
                    <?php echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'prev_text' => __('« Prev'),
                        'next_text' => __('Next »'),
                    ]); ?>
                </div>
            </div>
            <?php
        }

        /**
         * Render the Edit Link page.
         */
        public static function render_edit_link_page() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-shops-library'));
            }
            $link_id = isset($_GET['link_id']) ? absint($_GET['link_id']) : 0;
            $shop_url = isset($_GET['shop_url']) ? sanitize_text_field(wp_unslash($_GET['shop_url'])) : '';
            if (!$link_id) {
                wp_die(esc_html__('Invalid or no link specified.', 'woo-shops-library'));
            }
            $link = WSL_Links_DB::get_link_by_id($link_id);
            if (!$link) {
                wp_die(esc_html__('Link not found.', 'woo-shops-library'));
            }

            $notice = '';
            if (isset($_POST['wsl_edit_link_nonce']) && wp_verify_nonce($_POST['wsl_edit_link_nonce'], 'wsl_edit_link')) {
                $custom_cta = sanitize_text_field(wp_unslash($_POST['custom_cta']));
                $custom_cta_price = sanitize_text_field(wp_unslash($_POST['custom_cta_price']));
                $result = WSL_Links_DB::update_link($link_id, [
                    'custom_cta'       => $custom_cta,
                    'custom_cta_price' => $custom_cta_price,
                ]);

                if (false !== $result) {
                    $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Link updated successfully.', 'woo-shops-library') . '</p></div>';
                    $link = WSL_Links_DB::get_link_by_id($link_id);
                } else {
                    $notice = '<div class="notice notice-error wsl-notice is-dismissible"><p>' . esc_html__('Update failed.', 'woo-shops-library') . '</p></div>';
                }
            }
            ?>
            <div class="wrap wsl-edit-link-page">
                <h1 class="wp-heading-inline"><?php esc_html_e('Edit Link', 'woo-shops-library'); ?></h1>
                <?php echo $notice; ?>
                <form method="post">
                    <?php wp_nonce_field('wsl_edit_link', 'wsl_edit_link_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="link"><?php esc_html_e('Link', 'woo-shops-library'); ?></label></th>
                            <td><input type="text" id="link" name="link" value="<?php echo esc_attr($link->link); ?>" class="regular-text" readonly /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="custom_cta"><?php esc_html_e('Custom CTA', 'woo-shops-library'); ?></label></th>
                            <td>
                                <input type="text" id="custom_cta" name="custom_cta" value="<?php echo esc_attr($link->custom_cta); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e('Use {shop_name} as a placeholder for the shop name.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="custom_cta_price"><?php esc_html_e('Custom CTA Price', 'woo-shops-library'); ?></label></th>
                            <td>
                                <input type="text" id="custom_cta_price" name="custom_cta_price" value="<?php echo esc_attr($link->custom_cta_price); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e('Use {product_price} and/or {shop_name} as placeholders.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'woo-shops-library'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_links_details&shop_url=' . urlencode($shop_url))); ?>" class="button"><?php esc_html_e('Back to Links Details', 'woo-shops-library'); ?></a>
                    </p>
                </form>
            </div>
            <?php
        }
    }
}