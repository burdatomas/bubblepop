<?php
namespace WooShopsLibrary;

if (!defined('ABSPATH')) {
    exit;
}

class WSL_Price_Retrieval {
    private static $scrape_server_url = 'http://127.0.0.1:3000';
    private static $api_key;
    private static $max_retries = 5;

    public static function init() {
        self::$api_key = defined('WSL_SCRAPER_API_KEY') ? WSL_SCRAPER_API_KEY : (getenv('WSL_SCRAPER_API_KEY') ?: 'default-key');
        if (empty(self::$api_key) || self::$api_key === 'default-key') {
            error_log("WSL_Price_Retrieval: API key not set. Define WSL_SCRAPER_API_KEY in wp-config.php or environment.");
        }
    }

    public static function fetch_price($url, $methods = []) {
        if (empty($url)) {
            return 'Scraping failed: No URL provided';
        }

        self::init();

        $attempts = 0;
        $shop_url = parse_url($url, PHP_URL_HOST);
        $shop_url_normalized = $shop_url ? strtolower(preg_replace('/^www\./', '', $shop_url)) : 'unknown';
        $link = WSL_Links_DB::get_link_by_url($url);
        $link_id = $link ? $link->id : 0;
        $last_error = '';
        $start_time = time();

        if (empty($methods) || count($methods) !== 3) {
            $methods = [
                ['mode' => 'css', 'parameter' => '.price'],
                ['mode' => 'css', 'parameter' => '.original-price'],
                ['mode' => 'css', 'parameter' => '.voucher-price']
            ];
        }

        $valid_modes = ['css', 'xpath'];
        foreach ($methods as $index => &$method) {
            if (!isset($method['mode']) || !in_array($method['mode'], $valid_modes)) {
                $method['mode'] = 'css';
            }
            if (!isset($method['parameter'])) {
                $method['parameter'] = '';
            }
            if ($method['mode'] === 'css' && !empty($method['parameter']) && !preg_match('/^[.#]?[a-zA-Z0-9_-]+(\s*[.#]?[a-zA-Z0-9_-]+)*$/', $method['parameter'])) {
                error_log("WSL_Price_Retrieval: Invalid CSS selector '{$method['parameter']}' for $url at index $index");
                return "Scraping failed: Invalid CSS selector '{$method['parameter']}'";
            }
            $method['parameter'] = sanitize_text_field($method['parameter']);
        }
        unset($method);

        while ($attempts < self::$max_retries) {
            $request_body = json_encode([
                'url' => $url,
                'methods' => $methods,
            ], JSON_UNESCAPED_SLASHES);
            error_log("WSL_Price_Retrieval: Sending price request to scraper (Attempt " . ($attempts + 1) . " of " . self::$max_retries . ") for link ID $link_id: $request_body");

            $response = wp_remote_post(self::$scrape_server_url . '/scrape', [
                'timeout' => 150,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::$api_key,
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'WooShopsLibrary/' . PLUGIN_VERSION . ' (WordPress Plugin)',
                ],
                'body' => $request_body,
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                error_log("WSL_Price_Retrieval: Attempt $attempts failed for $url (link ID $link_id) - $last_error");
                $attempts++;
                $elapsed = time() - $start_time;
                if ($attempts < self::$max_retries && $elapsed < 140) {
                    sleep(min(2 * $attempts, 140 - $elapsed));
                }
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            error_log("WSL_Price_Retrieval: Response received for $url (link ID $link_id) - Code: $response_code, Body: $body");

            if ($response_code === 200) {
                $data = json_decode($body, true);
                if (!$data || (!isset($data['current_price']) && !isset($data['original_price']) && !isset($data['voucher_price']))) {
                    $last_error = $data['error'] ?? 'No prices returned from server';
                    error_log("WSL_Price_Retrieval: Invalid response for $url (link ID $link_id) - $last_error");
                    $attempts++;
                    $elapsed = time() - $start_time;
                    if ($attempts < self::$max_retries && $elapsed < 140) {
                        sleep(min(2 * $attempts, 140 - $elapsed));
                    }
                    continue; // Retry on invalid response
                }

                $result = [
                    'current_price' => sanitize_text_field($data['current_price'] ?? ''),
                    'original_price' => sanitize_text_field($data['original_price'] ?? ''),
                    'voucher_price' => sanitize_text_field($data['voucher_price'] ?? ''),
                    'discount_percentage' => floatval($data['sale_percentage'] ?? 0.00),
                    'price_status' => in_array($data['price_status'], ['Standard', 'Discount', 'Voucher']) ? $data['price_status'] : 'Standard'
                ];
                error_log("WSL_Price_Retrieval: Success for $url (link ID $link_id) - " . print_r($result, true));
                if ($link_id) {
                    update_option("wsl_last_scraped_link_{$link_id}", array_merge($result, [
                        'timestamp' => current_time('mysql'),
                        'error' => ''
                    ]));
                }
                return $result;
            }

            $attempts++;
            $elapsed = time() - $start_time;
            $last_error = "HTTP $response_code: " . (json_decode($body, true)['error'] ?? 'Unknown server error');
            error_log("WSL_Price_Retrieval: Attempt " . ($attempts - 1) . " failed for $url (link ID $link_id) - $last_error");

            if ($response_code === 429) {
                // Respect rate limit with a longer delay
                sleep(10); // Wait 10 seconds before retrying on 429
            } else if ($response_code === 401) {
                error_log("WSL_Price_Retrieval: Authentication failed for $url (link ID $link_id)");
                break; // Permanent error, stop retrying
            }

            if ($attempts < self::$max_retries && $elapsed < 140) {
                sleep(min(2 * $attempts, 140 - $elapsed));
            }
        }

        error_log("WSL_Price_Retrieval: All $attempts attempts failed for $url (link ID $link_id) - $last_error (timed out or server error after 150s)");
        if ($link_id) {
            $cached = get_option("wsl_last_scraped_link_{$link_id}");
            if ($cached && !empty($cached['current_price'])) {
                error_log("WSL_Price_Retrieval: Falling back to cached result for $url (link ID $link_id): " . print_r($cached, true));
                return [
                    'current_price' => $cached['current_price'],
                    'original_price' => $cached['original_price'],
                    'voucher_price' => $cached['voucher_price'],
                    'discount_percentage' => $cached['discount_percentage'],
                    'price_status' => $cached['price_status']
                ];
            }

            update_option("wsl_last_scraped_link_{$link_id}", [
                'timestamp' => current_time('mysql'),
                'current_price' => '',
                'original_price' => '',
                'voucher_price' => '',
                'discount_percentage' => 0.00,
                'price_status' => 'Standard',
                'error' => $last_error
            ]);
        }
        return "Scraping failed: $last_error";
    }

    public static function fetch_html($url) {
        if (empty($url)) {
            return 'HTML scraping failed: No URL provided';
        }

        self::init();

        $attempts = 0;
        $shop_url = parse_url($url, PHP_URL_HOST);
        $shop_url_normalized = $shop_url ? strtolower(preg_replace('/^www\./', '', $shop_url)) : 'unknown';
        $last_error = '';
        $start_time = time();

        while ($attempts < self::$max_retries) {
            $request_body = json_encode(['url' => $url], JSON_UNESCAPED_SLASHES);
            error_log("WSL_Price_Retrieval: Sending HTML request to scraper (Attempt " . ($attempts + 1) . " of " . self::$max_retries . "): $request_body");

            $response = wp_remote_post(self::$scrape_server_url . '/scrape-html', [
                'timeout' => 150,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::$api_key,
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'WooShopsLibrary/' . PLUGIN_VERSION . ' (WordPress Plugin)',
                ],
                'body' => $request_body,
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                error_log("WSL_Price_Retrieval: HTML attempt $attempts failed for $url - $last_error");
                $attempts++;
                $elapsed = time() - $start_time;
                if ($attempts < self::$max_retries && $elapsed < 140) {
                    sleep(min(2 * $attempts, 140 - $elapsed));
                }
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            error_log("WSL_Price_Retrieval: HTML response received for $url - Code: $response_code, Body sample: " . substr($body, 0, 200));

            if ($response_code === 200) {
                $data = json_decode($body, true);
                if (!isset($data['html']) || empty($data['html'])) {
                    $last_error = $data['error'] ?? 'No HTML content returned';
                    error_log("WSL_Price_Retrieval: Invalid HTML response for $url - $last_error");
                    $attempts++;
                    $elapsed = time() - $start_time;
                    if ($attempts < self::$max_retries && $elapsed < 140) {
                        sleep(min(2 * $attempts, 140 - $elapsed));
                    }
                    continue;
                }

                $html = $data['html'];
                update_option("wsl_last_scraped_html_{$shop_url_normalized}", [
                    'timestamp' => current_time('mysql'),
                    'error' => ''
                ]);
                return $html;
            }

            $attempts++;
            $elapsed = time() - $start_time;
            $last_error = "HTTP $response_code: " . (json_decode($body, true)['error'] ?? 'Unknown server error');
            error_log("WSL_Price_Retrieval: HTML attempt " . ($attempts - 1) . " failed for $url - $last_error");

            if ($response_code === 429) {
                sleep(10); // Wait 10 seconds on 429
            }

            if ($attempts < self::$max_retries && $elapsed < 140) {
                sleep(min(2 * $attempts, 140 - $elapsed));
            }
        }

        error_log("WSL_Price_Retrieval: HTML failed after $attempts attempts for $url - $last_error (timed out or server error after 150s)");
        update_option("wsl_last_scraped_html_{$shop_url_normalized}", [
            'timestamp' => current_time('mysql'),
            'error' => $last_error
        ]);
        return "HTML scraping failed: $last_error";
    }
}

WSL_Price_Retrieval::init();