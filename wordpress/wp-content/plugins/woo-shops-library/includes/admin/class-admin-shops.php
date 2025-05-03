<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WSL_Admin_Shops')) {
    class WSL_Admin_Shops {
        /**
         * Render the Shops Library page.
         */
        public static function render_shops_library_page() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-shops-library'));
            }
            $notices = WSL_Admin_Config::handle_post_requests();

            $per_page = get_option('wsl_shops_per_page', 40);
            $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
            $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            $orderby = isset($_GET['orderby']) && in_array(sanitize_key($_GET['orderby']), [
                'shop_url', 'shop_name', 'link_count', 'scraping_defined', 'currency_code', 'shop_date_modified'
            ]) ? sanitize_key($_GET['orderby']) : 'shop_date_modified';
            $order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC'])
                ? strtoupper($_GET['order'])
                : 'DESC';

            $args = [
                'search'   => $search,
                'order_by' => $orderby,
                'order'    => $order,
                'limit'    => $per_page,
                'offset'   => ($current_page - 1) * $per_page
            ];
            $shops = WSL_Shops_DB::get_shops($args);

            // Custom sorting for Scraping Defined
            if ($orderby === 'scraping_defined') {
                usort($shops, function($a, $b) use ($order) {
                    $methods_a = json_decode($a->price_retrieval_methods ?? '[]', true);
                    $methods_b = json_decode($b->price_retrieval_methods ?? '[]', true);
                    $defined_a = (!empty($methods_a) && is_array($methods_a) && !empty($methods_a[0]['parameter'])) ? 'Yes' : 'No';
                    $defined_b = (!empty($methods_b) && is_array($methods_b) && !empty($methods_b[0]['parameter'])) ? 'Yes' : 'No';
                    return $order === 'ASC' ? strcmp($defined_a, $defined_b) : strcmp($defined_b, $defined_a);
                });
            }

            $total_items = WSL_Shops_DB::get_shops_count($search);
            $total_pages = ceil($total_items / $per_page);

            $products = wc_get_products(['limit' => -1, 'status' => 'publish', 'type' => ['simple', 'variable']]);
            $product_data = [];
            foreach ($products as $product) {
                $product_data[$product->get_id()] = $product->get_name();
            }
            $product_json = json_encode($product_data);

            ?>
            <div class="wrap wsl-shops-library-page">
                <h1 class="wp-heading-inline"><?php esc_html_e('Shops Library', 'woo-shops-library'); ?></h1>

                <?php if (!empty($notices)): ?>
                    <?php foreach ($notices as $notice): ?>
                        <div class="notice <?php echo esc_attr($notice['type']); ?> wsl-notice is-dismissible">
                            <p><?php echo esc_html($notice['message']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="wsl_shops_library" />
                    <p class="search-box">
                        <label class="screen-reader-text" for="shop-search-input">
                            <?php esc_html_e('Search Shops', 'woo-shops-library'); ?>
                        </label>
                        <input type="search" id="shop-search-input" name="s" value="<?php echo esc_attr($search); ?>" />
                        <input type="submit" class="button" value="<?php esc_attr_e('Search Shops', 'woo-shops-library'); ?>" />
                    </p>
                </form>

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <button class="button" id="wsl-add-new-link-toggle">
                            <?php esc_html_e('Add New Link', 'woo-shops-library'); ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_settings')); ?>"
                           class="button button-secondary">
                           <?php esc_html_e('Settings', 'woo-shops-library'); ?>
                        </a>
                    </div>
                </div>

                <div id="wsl-add-new-link-form" class="wsl-add-new-link-form" style="display:none;">
                    <form method="post" id="wsl-add-new-link-form-submit">
                        <?php wp_nonce_field('wsl_add_new_link', 'wsl_nonce_link'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="product_id"><?php esc_html_e('Product ID', 'woo-shops-library'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="product_id" id="product_id" class="regular-text" required />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="product_name"><?php esc_html_e('Product Name', 'woo-shops-library'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="product_name" id="product_name" class="regular-text" readonly />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="link"><?php esc_html_e('Link (URL)', 'woo-shops-library'); ?></label>
                                </th>
                                <td>
                                    <input type="url" name="link" id="link" class="regular-text" required />
                                </td>
                            </tr>
                        </table>
                        <input type="hidden" name="action" value="add_new_link" />
                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Add', 'woo-shops-library'); ?>
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_shops_library')); ?>"
                               class="button">
                               <?php esc_html_e('Close', 'woo-shops-library'); ?>
                            </a>
                        </p>
                    </form>

                    <script type="text/javascript">
                        document.addEventListener('DOMContentLoaded', () => {
                            const products = <?php echo $product_json; ?>;
                            const productIdInput = document.getElementById('product_id');
                            const productNameInput = document.getElementById('product_name');
                            const form = document.getElementById('wsl-add-new-link-form-submit');

                            if (productIdInput && productNameInput) {
                                productIdInput.addEventListener('input', () => {
                                    const productId = productIdInput.value.trim();
                                    if (productId && products[productId]) {
                                        productNameInput.value = products[productId];
                                    } else {
                                        productNameInput.value = '<?php echo esc_js(__('Product not found', 'woo-shops-library')); ?>';
                                    }
                                });
                            }

                            if (form) {
                                form.addEventListener('submit', (e) => {
                                    const productId = productIdInput.value.trim();
                                    if (!productId || !products[productId]) {
                                        e.preventDefault();
                                        alert('<?php esc_html_e('Please enter a valid WooCommerce Product ID.', 'woo-shops-library'); ?>');
                                    }
                                });
                            }
                        });
                    </script>
                </div>

                <table class="wsl-table">
                    <thead>
                        <tr>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(['orderby' => 'shop_url', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                    <?php esc_html_e('Shop URL', 'woo-shops-library'); ?>
                                    <?php echo $orderby === 'shop_url' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(['orderby' => 'shop_name', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                    <?php esc_html_e('Shop Name', 'woo-shops-library'); ?>
                                    <?php echo $orderby === 'shop_name' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(['orderby' => 'link_count', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                    <?php esc_html_e('Link Count', 'woo-shops-library'); ?>
                                    <?php echo $orderby === 'link_count' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(['orderby' => 'scraping_defined', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                    <?php esc_html_e('Scraping Defined', 'woo-shops-library'); ?>
                                    <?php echo $orderby === 'scraping_defined' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(['orderby' => 'currency_code', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                    <?php esc_html_e('Currency', 'woo-shops-library'); ?>
                                    <?php echo $orderby === 'currency_code' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(['orderby' => 'shop_date_modified', 'order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                    <?php esc_html_e('Date Modified', 'woo-shops-library'); ?>
                                    <?php echo $orderby === 'shop_date_modified' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                </a>
                            </th>
                            <th><?php esc_html_e('Actions', 'woo-shops-library'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shops as $shop): ?>
                            <?php 
                            $currency_details = WSL_Shops_DB::get_shop_currency_details($shop->shop_url);
                            $methods = json_decode($shop->price_retrieval_methods ?? '[]', true);
                            $scraping_defined = (!empty($methods) && is_array($methods) && !empty($methods[0]['parameter'])) ? 'Yes' : 'No';
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($shop->shop_url); ?>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_edit_shop&shop_url=' . urlencode($shop->shop_url))); ?>">
                                                <?php esc_html_e('Edit', 'woo-shops-library'); ?>
                                            </a> | 
                                        </span>
                                        <span class="delete">
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this shop? This will also delete all associated links.', 'woo-shops-library'); ?>');">
                                                <?php wp_nonce_field('wsl_delete_shop_' . $shop->shop_url, 'wsl_delete_shop_nonce'); ?>
                                                <input type="hidden" name="shop_url" value="<?php echo esc_attr($shop->shop_url); ?>" />
                                                <input type="hidden" name="action" value="delete_shop" />
                                                <button type="submit" class="button-link">
                                                    <?php esc_html_e('Delete', 'woo-shops-library'); ?>
                                                </button>
                                            </form>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($shop->shop_name); ?></td>
                                <td><?php echo intval($shop->link_count); ?></td>
                                <td><?php echo esc_html($scraping_defined); ?></td>
                                <td><?php echo esc_html($currency_details['symbol']); ?></td>
                                <td><?php echo esc_html($shop->shop_date_modified); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_links_details&shop_url=' . urlencode($shop->shop_url))); ?>"
                                       class="button">
                                       <?php esc_html_e('View Links', 'woo-shops-library'); ?>
                                    </a>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_price_retrieval&shop_url=' . urlencode($shop->shop_url))); ?>"
                                       class="button">
                                       <?php esc_html_e('Price Retrieval', 'woo-shops-library'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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
         * Render the Edit Shop page.
         */
        public static function render_edit_shop_page() {
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
            if (isset($_POST['wsl_edit_shop_nonce']) && wp_verify_nonce($_POST['wsl_edit_shop_nonce'], 'wsl_edit_shop')) {
                $new_shop_name = sanitize_text_field(wp_unslash($_POST['shop_name']));
                $new_currency_code = sanitize_text_field(wp_unslash($_POST['currency_code']));
                $currencies = get_option('wsl_currencies', []);
                $valid_codes = array_column($currencies, 'code');
                $data = [
                    'shop_name' => $new_shop_name,
                    'link_count' => $shop->link_count,
                    'currency_code' => in_array($new_currency_code, $valid_codes) ? $new_currency_code : get_option('wsl_default_currency', 'USD')
                ];
                WSL_Shops_DB::upsert_shop($shop_url, $new_shop_name, $data);
                $notice = '<div class="notice notice-success wsl-notice is-dismissible"><p>' . esc_html__('Shop updated successfully.', 'woo-shops-library') . '</p></div>';
                $shop = WSL_Shops_DB::get_shop_by_url($shop_url);
            }

            $currencies = get_option('wsl_currencies', []);

            ?>
            <div class="wrap wsl-edit-shop-page">
                <h1 class="wp-heading-inline"><?php esc_html_e('Edit Shop', 'woo-shops-library'); ?></h1>
                <?php echo $notice; ?>
                <form method="post">
                    <?php wp_nonce_field('wsl_edit_shop', 'wsl_edit_shop_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="shop_url"><?php esc_html_e('Shop URL', 'woo-shops-library'); ?></label></th>
                            <td><input type="text" id="shop_url" name="shop_url" value="<?php echo esc_attr($shop->shop_url); ?>" class="regular-text" readonly /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="shop_name"><?php esc_html_e('Shop Name', 'woo-shops-library'); ?></label></th>
                            <td><input type="text" id="shop_name" name="shop_name" value="<?php echo esc_attr($shop->shop_name); ?>" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="currency_code"><?php esc_html_e('Currency', 'woo-shops-library'); ?></label></th>
                            <td>
                                <select name="currency_code" id="currency_code" class="regular-text">
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo esc_attr($currency['code']); ?>" <?php selected($shop->currency_code, $currency['code']); ?>>
                                            <?php echo esc_html("{$currency['name']} ({$currency['symbol']})"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Select the currency for this shop\'s prices. Position is managed in Settings.', 'woo-shops-library'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="save_shop" class="button button-primary"><?php esc_html_e('Save', 'woo-shops-library'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wsl_shops_library')); ?>" class="button"><?php esc_html_e('Back to Shops Library', 'woo-shops-library'); ?></a>
                    </p>
                </form>
            </div>
            <?php
        }
    }
}