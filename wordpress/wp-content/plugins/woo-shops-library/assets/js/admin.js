document.addEventListener('DOMContentLoaded', () => {
    // Toggle Add New Link form
    const toggleButton = document.getElementById('wsl-add-new-link-toggle');
    const form = document.getElementById('wsl-add-new-link-form');

    if (toggleButton && form) {
        toggleButton.addEventListener('click', () => {
            form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
        });
    }

    // Validate Price Retrieval Parameter on blur
    const priceInput = document.getElementById('price_retrieval_parameter');
    const validationOutput = document.getElementById('prp_validation');

    if (priceInput && validationOutput) {
        priceInput.addEventListener('blur', () => {
            const inputVal = priceInput.value.trim();
            let validationMsg = '';

            if (inputVal === '') {
                validationMsg = 'Leave blank to use default content extraction.';
            } else if (inputVal.startsWith('/')) {
                validationMsg = 'XPath expression detected.';
            } else {
                validationMsg = 'CSS selector detected. (e.g., span.price or #price)';
            }

            validationOutput.textContent = validationMsg;
            validationOutput.style.display = 'block';
            validationOutput.style.opacity = '1';

            setTimeout(() => {
                validationOutput.style.transition = 'opacity 0.5s';
                validationOutput.style.opacity = '0';
                setTimeout(() => {
                    validationOutput.style.display = 'none';
                    validationOutput.style.transition = '';
                }, 500);
            }, 2000);
        });
    }

    // Dynamic Product Name lookup
    const productIdInput = document.getElementById('product_id');
    const productNameInput = document.getElementById('product_name');

    if (productIdInput && productNameInput) {
        productIdInput.addEventListener('blur', () => {
            const productId = productIdInput.value.trim();
            console.log('Product ID entered:', productId);
            if (productId) {
                fetchProductName(productId);
            } else {
                productNameInput.value = '';
                console.log('Product ID cleared');
            }
        });
    }

    function fetchProductName(productId) {
        console.log('Fetching product name for ID:', productId);
        fetch(WSLAjax.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                action: 'wsl_get_product_name',
                nonce: WSLAjax.nonce,
                product_id: productId
            })
        })
        .then(response => {
            console.log('Raw response:', response);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.json();
        })
        .then(data => {
            console.log('Parsed response data:', data);
            if (data.success) {
                productNameInput.value = data.data.product_name || 'Product not found';
                console.log('Product name set:', data.data.product_name);
            } else {
                productNameInput.value = 'Product not found';
                console.log('Error from server:', data.data ? data.data.message : 'No message');
            }
        })
        .catch(error => {
            productNameInput.value = 'Error fetching product';
            console.error('AJAX fetch error:', error);
        });
    }

    // Add custom event listener for price retrieval test
    const testScrapeButton = document.getElementById('test-scrape');
    if (testScrapeButton) {
        testScrapeButton.addEventListener('click', () => {
            const methodMode = document.getElementById('method_mode').value;
            const currentParameter = document.getElementById('current_parameter').value;
            const originalParameter = document.getElementById('original_parameter').value;
            const voucherParameter = document.getElementById('voucher_parameter').value;

            const methods = [
                { mode: methodMode, parameter: currentParameter },
                { mode: methodMode, parameter: originalParameter },
                { mode: methodMode, parameter: voucherParameter }
            ];

            const requestData = {
                action: 'wsl_test_scrape',
                shop_url: window.shopUrl, // Set in render_price_retrieval_page
                methods: JSON.stringify(methods),
                nonce: WSLAjax.nonce
            };
            console.log('Sending Test Scrape Request:', requestData);

            fetch(WSLAjax.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: new URLSearchParams(requestData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Test Scrape Response:', data);
                const preview = document.getElementById('price-preview');
                if (data.success) {
                    preview.innerHTML = `
                        <p>Current Price: ${data.data.current_price || 'N/A'}</p>
                        <p>Original Price: ${data.data.original_price || 'N/A'}</p>
                        <p>Voucher Price: ${data.data.voucher_price || 'N/A'}</p>
                        <p>Discount Percentage: ${data.data.discount_percentage || '0'}%</p>
                        <p>Price Status: ${data.data.price_status}</p>
                    `;
                } else {
                    preview.innerHTML = `<p>Error: ${data.data.message || 'Unknown error'}</p>`;
                }
            })
            .catch(error => {
                console.error('Test Scrape AJAX Error:', error);
                document.getElementById('price-preview').innerHTML = `<p>Error: Unable to connect to server - ${error.message}</p>`;
            });
        });
    }

    // Test Price Scraping
    const testPriceScrapingButton = document.getElementById('test-price-scraping');
    if (testPriceScrapingButton) {
        testPriceScrapingButton.addEventListener('click', () => {
            const discountTestUrl = document.getElementById('discount_test_url').value.trim();
            const discountExpectedCurrent = document.getElementById('discount_expected_current').value.trim();
            const discountExpectedOriginal = document.getElementById('discount_expected_original').value.trim();
            const voucherTestUrl = document.getElementById('voucher_test_url').value.trim();
            const voucherExpectedCurrent = document.getElementById('voucher_expected_current').value.trim();
            const voucherExpectedVoucher = document.getElementById('voucher_expected_voucher').value.trim();

            const methodMode = document.getElementById('method_mode').value;
            const currentParameter = document.getElementById('current_parameter').value;
            const originalParameter = document.getElementById('original_parameter').value;
            const voucherParameter = document.getElementById('voucher_parameter').value;

            const methods = [
                { mode: methodMode, parameter: currentParameter },
                { mode: methodMode, parameter: originalParameter },
                { mode: methodMode, parameter: voucherParameter }
            ];

            const discountTestJson = JSON.stringify({
                url: discountTestUrl,
                expected_current: discountExpectedCurrent,
                expected_original: discountExpectedOriginal
            });
            const voucherTestJson = JSON.stringify({
                url: voucherTestUrl,
                expected_current: voucherExpectedCurrent,
                expected_voucher: voucherExpectedVoucher
            });

            const requestData = {
                action: 'wsl_test_price_scraping',
                shop_url: window.shopUrl,
                discount_test: discountTestJson,
                voucher_test: voucherTestJson,
                methods: JSON.stringify(methods),
                nonce: WSLAjax.nonce
            };
            console.log('Sending Test Price Scraping Request:', requestData);

            document.getElementById('test-loading').style.display = 'block';
            document.querySelector('.wsl-test-table').style.display = 'none';
            document.getElementById('test-no-results').style.display = 'none';
            document.getElementById('copy-test-results').style.display = 'none';

            // Use jQuery AJAX with explicit data type to ensure strings are sent
            jQuery.ajax({
                url: WSLAjax.ajaxUrl,
                method: 'POST',
                data: requestData,
                dataType: 'json',
                success: function(response) {
                    console.log('Test Price Scraping Response:', response);
                    document.getElementById('test-loading').style.display = 'none';
                    if (response.success) {
                        const formatPrice = (price) => price ? `${price}` : 'N/A';
                        const tbody = document.getElementById('test-results-body');
                        tbody.innerHTML = '';

                        if (response.data.discount) {
                            const discountRow = document.createElement('tr');
                            discountRow.innerHTML = `
                                <td>Discount</td>
                                <td>${response.data.discount.url}</td>
                                <td>${formatPrice(response.data.discount.current_price)}</td>
                                <td>${formatPrice(response.data.discount.original_price)}</td>
                                <td>${formatPrice(response.data.discount.voucher_price)}</td>
                                <td>${formatPrice(response.data.discount.expected_current)}</td>
                                <td>${formatPrice(response.data.discount.expected_original)}</td>
                                <td>${response.data.discount.discount_percentage ? response.data.discount.discount_percentage + '%' : '0%'}</td>
                                <td>${response.data.discount.price_status}</td>
                                <td>${response.data.discount.validation}</td>
                            `;
                            tbody.appendChild(discountRow);
                        }

                        if (response.data.voucher) {
                            const voucherRow = document.createElement('tr');
                            voucherRow.innerHTML = `
                                <td>Voucher</td>
                                <td>${response.data.voucher.url}</td>
                                <td>${formatPrice(response.data.voucher.current_price)}</td>
                                <td>${formatPrice(response.data.voucher.original_price)}</td>
                                <td>${formatPrice(response.data.voucher.voucher_price)}</td>
                                <td>${formatPrice(response.data.voucher.expected_current)}</td>
                                <td>${formatPrice(response.data.voucher.expected_voucher)}</td>
                                <td>${response.data.voucher.discount_percentage ? response.data.voucher.discount_percentage + '%' : '0%'}</td>
                                <td>${response.data.voucher.price_status}</td>
                                <td>${response.data.voucher.validation}</td>
                            `;
                            tbody.appendChild(voucherRow);
                        }

                        document.querySelector('.wsl-test-table').style.display = 'table';
                        document.getElementById('copy-test-results').style.display = 'inline-block';
                    } else {
                        document.getElementById('test-no-results').textContent = `Error: ${response.data.message || 'Unknown error'}`;
                        document.getElementById('test-no-results').style.display = 'block';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Test Price Scraping AJAX Error:', status, error);
                    document.getElementById('test-loading').style.display = 'none';
                    document.getElementById('test-no-results').textContent = `Error: Unable to connect to server - ${error}`;
                    document.getElementById('test-no-results').style.display = 'block';
                }
            });
        });
    }

    // Copy Test Results
    const copyTestResultsButton = document.getElementById('copy-test-results');
    if (copyTestResultsButton) {
        copyTestResultsButton.addEventListener('click', () => {
            const text = document.getElementById('test-results-body').innerText.trim();
            navigator.clipboard.writeText(text).then(() => {
                alert('Results copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy results:', err);
            });
        });
    }

    // Currency form submission and validation
    const currencyForm = document.getElementById('wsl-currency-form');
    if (currencyForm) {
        // Add New Currency validation
        currencyForm.addEventListener('submit', (e) => {
            const actionInput = currencyForm.querySelector('input[name="currency_action"]');
            if (!actionInput || actionInput.value === 'add') {
                const codeInput = document.getElementById('new_currency_code');
                const symbolInput = document.getElementById('new_currency_symbol');
                const nameInput = document.getElementById('new_currency_name');

                if (!codeInput.value.trim() || !symbolInput.value.trim() || !nameInput.value.trim()) {
                    e.preventDefault();
                    alert('Please fill in all currency fields (Code, Symbol, Name).');
                    return;
                }

                if (codeInput.value.length > 10) {
                    e.preventDefault();
                    alert('Currency code must be 10 characters or less.');
                    return;
                }
            }
        });

        // jQuery-based handlers for currency management
        jQuery(document).ready(function($) {
            // Drag-and-drop for currency list
            if (typeof $.fn.sortable !== 'undefined') {
                $('#currency-list').sortable({
                    handle: '.handle',
                    placeholder: 'ui-state-highlight',
                    update: function(event, ui) {
                        // Get the new order from the DOM directly
                        const order = [];
                        $('#currency-list .currency-item').each(function() {
                            const key = $(this).data('key');
                            if (typeof key !== 'undefined') {
                                order.push(key);
                            }
                        });
                        console.log('New currency order from DOM:', order);
                        $('#currency_order').val(order.join(','));
                        const $form = $('#wsl-currency-form');
                        $form.find('input[name="currency_action"]').remove(); // Clear previous action
                        $form.append('<input type="hidden" name="currency_action" value="reorder" />');
                        console.log('Submitting form with order:', $('#currency_order').val());
                        $form[0].submit(); // Native submission
                    }
                });
                console.log('jQuery UI Sortable initialized for #currency-list');
            } else {
                console.error('jQuery UI Sortable is not loaded. Drag-and-drop will not work.');
            }

            // Save currency
            $(document).on('click', '.save-currency', function(e) {
                e.preventDefault();
                const $li = $(this).closest('.currency-item');
                const key = $li.data('key');
                const code = $li.find(`input[name="edit_currency_code_${key}"]`).val();
                const symbol = $li.find(`input[name="edit_currency_symbol_${key}"]`).val();
                const name = $li.find(`input[name="edit_currency_name_${key}"]`).val();
                const position = $li.find(`select[name="edit_currency_position_${key}"]`).val();

                const $form = $('#wsl-currency-form');
                $form.find('input[name="currency_action"]').remove();
                $form
                    .append('<input type="hidden" name="currency_action" value="save" />')
                    .append(`<input type="hidden" name="edit_currency_key" value="${key}" />`)
                    .append(`<input type="hidden" name="edit_currency_code" value="${code}" />`)
                    .append(`<input type="hidden" name="edit_currency_symbol" value="${symbol}" />`)
                    .append(`<input type="hidden" name="edit_currency_name" value="${name}" />`)
                    .append(`<input type="hidden" name="edit_currency_position" value="${position}" />`)
                    .submit();
            });

            // Remove currency
            $(document).on('click', '.remove-currency', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to remove this currency?')) {
                    const key = $(this).data('key');
                    const $form = $('#wsl-currency-form');
                    $form.find('input[name="currency_action"]').remove();
                    $form
                        .append('<input type="hidden" name="currency_action" value="remove" />')
                        .append(`<input type="hidden" name="remove_currency_key" value="${key}" />`)
                        .submit();
                }
            });

            // Set default currency
            $(document).on('click', '.set-default-currency', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                const $form = $('#wsl-currency-form');
                $form.find('input[name="currency_action"]').remove();
                $form
                    .append('<input type="hidden" name="currency_action" value="set_default" />')
                    .append(`<input type="hidden" name="new_default_currency" value="${code}" />`)
                    .submit();
            });
        });
    }
});