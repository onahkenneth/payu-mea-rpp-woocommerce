<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Gateway_PayU extends WC_Payment_Gateway
{
    public const ID = 'payu';
    const NO_REFUND = ['erip'];
    const NO_CAPTURE = ['erip'];
    const NO_CANCEL = ['erip'];

    public const AVAILABLE_METHODS = [
        WC_PayU_Payment_Method_Dm::class,
        WC_PayU_Payment_Method_Card::class,
    ];

    /**
     * @var ?stdClass
     */
    protected ?stdClass $txnData = null;

    /**
     * Array mapping payment method string IDs to classes
     *
     * @var WC_PayU_Payment_Method[]
     */
    public $payment_methods = [];

    /**
     * WC_Gateway_PayU constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        protected ?WC_Logger $log = null,
        protected string $debug = 'no',
        protected string $prod_url = 'https://secure.payu.co.za',
        protected string $staging_url = 'https://staging.payu.co.za',
        protected string $notify_url = '',
        protected string $safekey = '',
        protected string $username = '',
        protected string $password = '',
        protected string $testmode = 'yes',
        protected string $extended_debug = '',
        protected string $payment_method = 'CREDITCARD',
        protected string $transaction_type = 'PAYMENT',
        protected string $dm_enabled = 'no',
        protected string $dm_transaction_type = 'PAYMENT'
    ) {
        // Setup general properties.
        $this->setup_properties();

        $this->payment_methods = [];

        foreach (self::AVAILABLE_METHODS as $payment_method_class) {
            $payment_method = new $payment_method_class();
            $this->payment_methods[$payment_method->get_id()] = $payment_method;
        }

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();


        // Define user variables
        $this->get_settings();

        // Logs
        if ('yes' === $this->debug && function_exists('wc_get_logger')) {
            $this->log = wc_get_logger();
        }

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // Payment listener/API hook
        add_action('woocommerce_api_wc_gateway_payu', [$this, 'process_payu_callback']);
    }

    protected function setup_properties()
    {
        $this->id = 'payu';
        $this->icon = WC_PayU::plugin_url() . '/assets/images/creditcard.png';
        $this->method_title = __('PayU Secure Payments', 'woocommerce-gateway-payu');
        $this->method_description = __('Accept credit/debit cards, EFT, Discovery miles, eBucks and many more');
        $this->has_fields = true;
        $this->supports = [
            'products',
            'refunds',
            'add_payment_method'
        ];
    }

    protected function get_settings()
    {
        $this->debug = $this->get_option('debug');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->safekey = $this->get_option('safekey');
        $this->username = $this->get_option('username');
        $this->password = $this->get_option('password');
        $this->testmode    = $this->get_option('testmode');
        $this->payment_method = $this->get_option('payment_method');
        $this->transaction_type = $this->get_option('transaction_type');
        $this->dm_enabled = $this->get_option('dm_enabled');
        $this->dm_transaction_type = $this->get_option('dm_transaction_type');
        $this->extended_debug = $this->get_option('extended_debug');
        $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_PayU', home_url());
    }

    public function log($message, $level = 'debug')
    {
        if ($this->debug) {
            if (empty($this->log)) {
                $this->log = wc_get_logger();
            }

            $this->log->log($level, $message, ['source' => 'payu']);
        }
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();

        if ($this->debug) {
            if (empty($this->log)) {
                $this->log = wc_get_logger();
            }

            $this->log->clear('payu');
        }

        return $saved;
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    public function init_form_fields()
    {
        $this->form_fields = include __DIR__ . '/settings-payu.php';
    }

    /**
     * Returns every currency supported by the gateway's payment methods.
     *
     * @return string[]
     */
    public function get_supported_currencies()
    {
        $currencies = [];

        foreach ($this->payment_methods as $payment_method) {
            $currencies = array_merge($currencies, (array) $payment_method->get_supported_currencies());
        }

        return array_values(array_unique($currencies));
    }

    /**
     * Returns the configured currency code, validated against the amount being charged.
     *
     * @param WC_Order|null $order The order being charged, or null to validate against the store currency.
     * @return string The validated currency code.
     * @throws CurrencyMismatchException When the configured currency is unsupported or does not match the amount.
     */
    public function get_currency_code($order = null)
    {
        $configured = $this->settings['currency'] ?? '';
        $expected = $order instanceof WC_Order ? $order->get_currency() : get_woocommerce_currency();
        $error = WC_PayU_Currency::get_error($configured, $expected, $this->get_supported_currencies());

        if ('' !== $error) {
            throw new CurrencyMismatchException($error);
        }

        return WC_PayU_Currency::normalize($configured);
    }

    /**
     * Validates and normalises the currency setting before it is saved.
     *
     * Rejecting the value here surfaces a misconfiguration in the admin instead of
     * letting PayU decline every transaction made with it.
     *
     * @param string $key The field key.
     * @param string $value The submitted value.
     * @return string The normalised currency, or the previously saved one when the submitted value is invalid.
     */
    public function validate_currency_field($key, $value)
    {
        $currency = WC_PayU_Currency::normalize($value);
        $error = WC_PayU_Currency::get_error($currency, get_woocommerce_currency(), $this->get_supported_currencies());

        if ('' !== $error) {
            WC_Admin_Settings::add_error($error);
            return $this->get_option($key);
        }

        return $currency;
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id)
    {
        $method = $_POST['payment_method'];

        try {
            $order = new WC_Order($order_id);
            $safekey = $this->settings['safekey'];

            // Discovery Miles separate login credentials prefix
            $payu_credentials_prefix = '';

            /** If Discovery Miles selected */
            if (
                isset($method) &&
                in_array($method, [WC_PayU_Payment_Methods::DISCOVERY_MILES, 'payu_discoverymiles']) &&
                !empty($this->settings['dm_safekey'])
            ) {
                $payu_credentials_prefix = 'dm';
                $safekey = $this->settings['dm_safekey'];
                $this->payment_method = strtoupper(WC_PayU_Payment_Methods::DISCOVERY_MILES);
            }

            $order->update_meta_data('_payu_credentials_prefix', $payu_credentials_prefix);
            $order->update_meta_data('_payu_transaction_payment_method', $method);

            if (('dm' !== $payu_credentials_prefix) && !empty($this->settings['dm_username'])) {
                $payment_methods = explode(',', $this->payment_method);
                $code = strtoupper(WC_PayU_Payment_Methods::DISCOVERY_MILES);

                if (in_array($code, $payment_methods)) {
                    $index = array_search($code, $payment_methods);
                    unset($payment_methods[$index]);
                }

                $this->payment_method = trim(implode(',', $payment_methods), ",");
            }

            // Config for SOAP client instantiation
            $config = $this->get_configuration();

            $txnData = $this->get_transaction_data($config, $order);
            $txnData['Safekey'] = $safekey;

            if ($method === WC_PayU_Payment_Methods::CARD) {
                $txnData['TransactionType'] = $this->transaction_type;
            } else {
                $this->transaction_type = $this->dm_transaction_type;
                $txnData['TransactionType'] = $this->transaction_type;
            }

            $order->update_meta_data('_payu_transaction_type', $this->transaction_type);

            // Do setTransaction
            $transaction = new PayU_Payment_Transaction($config);
            $this->txnData = $transaction->do_set_transaction($txnData);

            // Check setTransaction response
            if ($this->get_payu_reference() && $this->get_redirect_url()) {
                $pay_u_reference = $this->get_payu_reference();
                $set_transaction_notes = "PayU Reference: $pay_u_reference<br />";
                $set_transaction_notes .= 'Requested Methods: ' . $this->payment_method . "<br />";

                $this->save_transaction_id($order);
                $order->update_meta_data('_payu_transaction_payment_method', $this->get_txn_payment_method($order));
                $order->add_order_note(__('Redirecting to PayU <br />' . $set_transaction_notes, 'woocommerce-gateway-payu'));
                $order->update_status('pending', '', true);
            }
        } catch (PayUTransactionException $e) {
            $this->log(
                sprintf(' - Order %s: %s [%s] %s', $order_id, $e->getMessage(), $e->get_result_code(), $e->get_result_message()),
                'critical'
            );

            // Classic, pay-for-order and block checkout all show a thrown exception's message to the customer.
            // Tags are stripped rather than escaped: WooCommerce escapes notices itself, so escaping here would double-encode.
            throw new Exception(wp_strip_all_tags($e->getMessage()), 0, $e);
        } catch (Exception $e) {
            $this->log(sprintf(' - Order %s: %s', $order_id, $e->getMessage()), 'critical');

            // Other failures (SOAP faults, configuration errors) are not written for customers.
            throw new Exception(
                __('We could not start your PayU payment. Please try again or choose another payment method.', 'woocommerce-gateway-payu'),
                0,
                $e
            );
        }

        return [
            'result' => 'success',
            'redirect' => $this->get_redirect_url()
        ];
    }

    /**
     * Process payu server response
    */
    public function process_payu_callback()
    {
        if (!empty($_GET['action']) && $_GET['action'] === 'cancelled' && isset($_GET['order_id'])) {
            $this->process_cancel();
        } elseif (!empty($_GET['PayUReference'])) {
            $this->process_capture();
        } else {
            $this->process_ipn();
        }

        $this->txnData = null;
    }

    /**
     * Check if a payment method supports refund
     *
     * @param WC_Order $order The order object related to the transaction.
     * @return boolean
     */
    public function can_payment_method_refund($order)
    {
        $pm = $this->get_txn_payment_method($order);
        return !in_array($pm, self::NO_REFUND);
    }

    /**
     * Check if a payment method supports cancel
     *
     * @param WC_Order $order The order object related to the transaction.
     * @return boolean
     */
    public function can_payment_method_cancel($order)
    {
        $pm = $this->get_txn_payment_method($order);
        return !in_array($pm, self::NO_CANCEL);
    }

    /**
     * Check if a payment method supports capture
     *
     * @param WC_Order $order The order object related to the transaction.
     * @return boolean
     */
    public function can_payment_method_capture($order)
    {
        $pm = $this->get_txn_payment_method($order);
        return !in_array($pm, self::NO_CAPTURE);
    }

    /**
     * Return order payment method
     *
     * @param WC_Order $order The order object related to the transaction.
     * @return string
     */
    public function get_txn_payment_method($order)
    {
        return $order->get_meta('_payu_transaction_payment_method');
    }

    /**
     * Retrieve the order transaction id.
     *
     * @param WC_Order $order The order object related to the transaction.
     * @return string uid of a transaction
     */
    public function get_transaction_id($order)
    {
        return $order->get_meta('_payu_transaction_id', true);
    }

    /**
     * Get Invoice data of Order.
     *
     * @param WC_Order $order
     *
     * @return array
     * @throws Exception
     */
    public function get_invoice_data($order)
    {
        if (is_int($order)) {
            $order = wc_get_order($order);
        }

        $payment_method = explode('_', $order->get_payment_method());

        if (1 >= count($payment_method)) {
            throw new InvalidPaymentMethodException('Invalid payment method.');
        }

        if (!in_array($payment_method[1], array_keys($this->payment_methods))) {
            throw new InvalidPaymentMethodException('Unable to get invoice data.');
        }

        return [
            'authorized_amount' => $order->get_total(),
            'settled_amount' => $order->get_meta('_payu_transaction_captured_amount', true) ?: 0,
            'refunded_amount' => $order->get_meta('_payu_transaction_refunded_amount', true) ?: 0,
            'state' => $order->get_status()
        ];
    }

    /**
     * All payment icons that work with PayU.
     *
     * @since 2.0.1
     * @return array
     */
    public function payment_icons()
    {
        return apply_filters(
            'wc_payu_payment_icons',
            [
                WC_PayU_Payment_Methods::CARD => '<img src="' . WC_PayU::plugin_url() . '/assets/images/creditcard.png" class="payu-card-icon payu-icon" alt="Credit/Debit Card" />',
                WC_PayU_Payment_Methods::DISCOVERY_MILES => '<img src="' . WC_PayU::plugin_url() . '/assets/images/discoverymiles.png" class="payu-discoverymiles-icon payu-icon" alt="Discovery Miles" />'
            ]
        );
    }

    public function get_supported_features()
    {
        return $this->supports;
    }

    protected function process_capture()
    {
        global $woocommerce;

        $order_id = 0;
        $get_txn_data = [];
        $pay_u_reference = $_GET['PayUReference'];

        try {
            /** @var WC_Order $order */
            $order = new WC_Order($_GET['order_id']);

            //Discovery Miles separate login credentials prefix
            $payu_credentials_prefix = $order->get_meta('_payu_credentials_prefix', true);
            $config = $this->get_configuration($payu_credentials_prefix);

            $safekey = $this->settings['safekey'];

            if (!empty($payu_credentials_prefix) && !empty($this->settings[$payu_credentials_prefix . '_safekey'])) {
                $safekey = $this->settings[$payu_credentials_prefix . '_safekey'];
            }

            $get_txn_data['Safekey'] =  $safekey;
            $get_txn_data['AdditionalInformation']['payUReference'] = $pay_u_reference;

            $transaction = new PayU_Payment_Transaction($config);
            $this->txnData = $transaction->do_get_transaction($get_txn_data);

            if (!empty($this->get_merchant_reference())) {
                $order_id = $this->get_merchant_reference();
            }

            $order = new WC_Order($order_id);

            // Check order not already completed
            if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
                if ('yes' === $this->debug) {
                    $this->log->add('PayU', 'Aborting, Order #' . $order->get_id() . ' is already complete.');
                }

                wc_add_notice(__('Order is already completed', 'woocommerce-gateway-payu'), 'success');
                wp_redirect($this->get_return_url($order));
                exit;
            }

            if ($this->is_payment_successful()) {
                $transaction_notes = "PayU Reference: " . $this->get_payu_reference() . "<br /> ";

                $transaction_notes = $this->get_payment_method_details($transaction_notes);

                if ($this->get_recurring_details() != null && is_array($this->get_recurring_details())) {
                    $transaction_notes .= "<br /><br />Recurring Details:";

                    foreach ($this->get_recurring_details() as $key => $value) {
                        $transaction_notes .= "<br />&nbsp;&nbsp;- " . $key . ":" . $value . ", ";
                    }
                }

                $this->save_payu_transaction_data($order);

                // Payment completed
                $order->add_order_note(__("<strong>Payment completed: </strong><br />$transaction_notes", 'woocommerce-gateway-payu'));
                $order->payment_complete();
                $woocommerce->cart->empty_cart();

                if ('yes' === $this->debug) {
                    $this->log->add('PayU', 'Payment complete.');
                    wc_add_notice(__('Payment completed: <br />', 'woocommerce-gateway-payu') . $transaction_notes, 'success');
                }

                wp_redirect($this->get_return_url($order));
                exit;
            } else {
                $reason = $this->get_display_message();
                $transaction_notes = "PayU Reference: " . $this->get_payu_reference() . "<br />";
                $transaction_notes .= "Error: " . addslashes($reason) . "<br />";
                $transaction_notes .= "Point Of Failure: " . $this->get_point_of_failure() . "<br />";
                $transaction_notes .= "Result Code: " . $this->get_result_code();

                // Check for existence of new notification api (WooCommerce >= 2.1)
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Payment Failed:', 'woocommerce-gateway-payu') . $reason, 'error');
                } else {
                    $woocommerce->add_error(__('Payment Failed:', 'woocommerce-gateway-payu') . $reason);
                }

                $order->add_order_note(__("<strong>Payment unsuccessful: </strong><br />" . $transaction_notes, 'woocommerce-gateway-payu'));

                if ('yes' == $this->debug) {
                    $this->log->add('PayU', 'Payment Failed.');
                }

                wp_redirect($order->get_checkout_payment_url());
                exit;
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            wp_die($errorMessage);
        }
    }

    protected function process_ipn()
    {
        $postData  = file_get_contents("php://input");
        $sxe = simplexml_load_string($postData);

        if (empty($sxe)) {
            return;
        }

        $return_data = $this->xml_to_torray($sxe);

        if (empty($return_data)) {
            return;
        }

        $order_id = (int)$return_data['MerchantReference'];
        $pay_u_reference = $return_data['PayUReference'];

       
        $this->log('Received webhook xml: ' . PHP_EOL . print_r($return_data, true));

        // Validate amount
        if (!$this->validate_amount_paid()) {
            $this->log(
                '----------- Invalid amount webhook --------------' . PHP_EOL .
                    "Order No: " . $order_id . PHP_EOL .
                    "PayU Reference: " . $pay_u_reference . PHP_EOL .
                    '--------------------------------------------'
            );

            wp_die("PayU Notify Amount Failure");
        }

        if (isset($order_id) && !empty($order_id) && is_numeric($order_id)) {
            $order = new WC_Order($order_id);

            if (empty($order)) {
                $this->log(
                    '----------- Invalid order id --------------' . PHP_EOL .
                        "Order No: " . $order_id . PHP_EOL .
                        "PayU Reference: " . $pay_u_reference . PHP_EOL .
                        '--------------------------------------------'
                );

                wp_die("PayU Notify Order Id Invalid");
            }
        }

        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            if ('yes' === $this->debug) {
                $this->log->add('payu', 'Aborting, Order #' . $order->get_id() . ' is already completed.');
            }

            wp_die("PayU Notify Order alread completed");
        }

        try {
            //Creating get transaction soap data array
            $get_txn_data = [];
            $get_txn_data['Safekey'] = $this->settings['safekey'];
            $get_txn_data['AdditionalInformation']['payUReference'] = $pay_u_reference;

            //Discovery Miles separate login credentials prefix
            $payu_credentials_prefix = $order->get_meta('payu_credentials_prefix', true);
            $config = $this->get_configuration($payu_credentials_prefix);

            $transaction = new PayU_Payment_Transaction($config);
            $this->txnData = $transaction->do_get_transaction($get_txn_data);

            // Checking IPN is valid
            if (
                !in_array($this->get_result_code(), array('POO5', 'EFTPRO_003', '999', '305')) &&
                $this->is_payment_successful()
            ) {
                $amount_due = $this->get_total_due();
                $amount_paid = $this->get_total_paid();

                $transaction_notes = '';
                $transaction_notes .= "<strong>-----PAYU IPN RECIEVED---</strong><br />";
                $transaction_notes .= "Order Amount: " . $amount_due . "<br />";
                $transaction_notes .= "Amount Paid: " . $amount_paid . "<br />";
                $transaction_notes .= "Merchant Reference : " . $this->get_merchant_reference() . "<br />";
                $transaction_notes .= "PayU Reference: " . $this->get_payu_reference() . "<br />";
                $transaction_notes .= "PayU Payment Status: " . $this->get_transaction_state() . "<br /><br />";
                $transaction_notes = $this->get_payment_method_details($transaction_notes);

                $this->save_payu_transaction_data($order);

                // Payment completed
                $order->add_order_note(__($transaction_notes, 'woocommerce-gateway-payu'));
                $order->payment_complete();

                if ('yes' === $this->debug) {
                    $this->log->add('payu', 'Payment complete.');
                }
            } else {
                $transaction_notes = 'PayU Reference: ' . $this->get_payu_reference() . '<br />';
                $transaction_notes .= 'Point Of Failure: ' . $this->get_point_of_failure() . '<br />';
                $transaction_notes .= 'Result Code: ' . $this->get_result_code();

                $order->add_order_note(__('<strong>Payment unsuccessful: </strong><br />', 'woocommerce') . $transaction_notes);

                if ('yes' === $this->debug) {
                    $this->log->add('payu', 'Payment Failed.');
                }
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $this->log->add('payu', $errorMessage);
        }
    }

    /**
     * Capture transaction
     *
     * @param string $transaction_uid The transaction uid.
     * @param string $order_id The order id.
     * @param float $amount The amount to capture.
     * @throws CurrencyMismatchException When the configured currency cannot be used for the order.
     */
    public function capture_transaction($transaction_uid, $order_id, $amount)
    {
        $config = $this->get_configuration();
        $transaction = new PayU_Payment_Transaction($config);

        $this->txnData = $transaction->do_transaction([
            'Safekey' => $this->settings['safekey'],
            'TransactionType' => 'FINALIZE',
            'AdditionalInformation' => [
                'merchantReference' => $order_id,
                'payUReference' => $transaction_uid
            ],
            'Basket' => [
                'amountInCents' => $amount * 100,
                'currencyCode' => $this->get_currency_code(wc_get_order($order_id))
            ],
            'Creditcard' => [
                'amountInCents' => $amount * 100,
            ]
        ]);
    }

    /**
     * Refund transaction
     *
     * @param string $transaction_uid The transaction uid.
     * @param string $order_id The order id.
     * @param float $amount The amount to refund.
     * @throws CurrencyMismatchException When the configured currency cannot be used for the order.
     */
    public function refund_transaction($transaction_uid, $order_id, $amount)
    {
        $config = $this->get_configuration();
        $transaction = new PayU_Payment_Transaction($config);

        $this->txnData = $transaction->do_transaction([
            'Safekey' => $this->settings['safekey'],
            'TransactionType' => 'CREDIT',
            'AdditionalInformation' => [
                'merchantReference' => $order_id,
                'payUReference' => $transaction_uid
            ],
            'Basket' => [
                'amountInCents' => $amount * 100,
                'currencyCode' => $this->get_currency_code(wc_get_order($order_id))
            ]
        ]);
    }

    /**
     * Cancel/void an authorize/cancel transaction
     *
     * @param string $transaction_uid The transaction uid.
     * @param string $order_id The order id.
     * @param float $amount The amount to void.
     * @throws CurrencyMismatchException When the configured currency cannot be used for the order.
     */
    public function void_transaction($transaction_uid, $order_id, $amount)
    {
        $config = $this->get_configuration();
        $transaction = new PayU_Payment_Transaction($config);

        $this->txnData = $transaction->do_transaction([
            'Safekey' => $this->settings['safekey'],
            'TransactionType' => 'RESERVE_CANCEL',
            'AdditionalInformation' => [
                'merchantReference' => $order_id,
                'payUReference' => $transaction_uid
            ],
            'Basket' => [
                'amountInCents' => $amount * 100,
                'currencyCode' => $this->get_currency_code(wc_get_order($order_id))
            ],
            'Creditcard' => [
                'amountInCents' => $amount * 100,
            ]
        ]);
    }

    public function is_payment_new(): bool
    {
        return $this->txnData->successful
            && $this->get_transaction_state() === 'NEW';
    }

    public function is_payment_successful(): bool
    {
        return $this->txnData->successful
            && ($this->get_transaction_state() === 'SUCCESSFUL' || $this->get_result_code() === '00');
    }

    public function is_refund_successful(): bool
    {
        return $this->txnData->successful
            && $this->get_result_code() === '00';
    }

    public function is_void_successful(): bool
    {
        return $this->txnData->successful
            && $this->get_result_code() === '00';
    }

    /**
     * @return bool
     */
    public function is_payment_pending(): bool
    {
        return $this->txnData->successful
            && $this->get_transaction_state() === 'AWAITING_PAYMENT';
    }

    /**
     * @return bool
     */
    public function is_payment_processing(): bool
    {
        return ($this->txnData->successful === true || $this->txnData->successful === false)
            && $this->get_transaction_state() === 'PROCESSING';
    }

    /**
     * @return bool
     */
    public function is_payment_failed(): bool
    {
        return ($this->txnData->successful === true || $this->txnData->successful === false)
            && in_array(
                $this->get_transaction_state(),
                ['FAILED', 'EXPIRED', 'TIMEOUT']
            );
    }

    public function get_display_message(): string
    {
        return $this->txnData->displayMessage ?? '';
    }

    public function get_result_message(): string
    {
        return $this->txnData->resultMessage ?? '';
    }

    public function get_payu_reference(): string
    {
        return $this->txnData->payUReference ?? '';
    }

    /**
     * Store the transaction id.
     *
     * @param array    $transaction The transaction returned by the api wrapper.
     * @param WC_Order $order The order object related to the transaction.
     */
    public function save_transaction_id($order)
    {
        $order->update_meta_data('_transaction_id', $this->get_payu_reference());
        $order->update_meta_data('_payu_transaction_id', $this->get_payu_reference());
    }

    protected function process_cancel()
    {
        $order = new WC_Order($_GET['order_id']);
        $transactionNotes = "Payment cancelled by user";
        wc_add_notice(__('', 'woocommerce-gateway-payu') . $transactionNotes, 'error');
        $order->add_order_note(__($transactionNotes, 'woocommerce-gateway-payu'));

        if ('yes' == $this->debug) {
            $this->log->add('payu', 'Payment cancelled.');
        }

        wp_redirect($order->get_checkout_payment_url());
    }

    protected function xml_to_torray($xml)
    {
        return WC_PayU_XMLParser::xmlToArray($xml);
    }

    protected function get_configuration(?string $payu_credentials_prefix = null): array
    {
        $method = $_POST['payment_method'];
        $soap_username = $this->settings['username'];
        $soap_password = $this->settings['password'];

        if (!empty($payu_credentials_prefix)) {
            if (!empty($this->settings[$payu_credentials_prefix . '_username'])) {
                $soap_username = $this->settings[$payu_credentials_prefix . '_username'];
            }

            if (!empty($this->settings[$payu_credentials_prefix . '_password'])) {
                $soap_password = $this->settings[$payu_credentials_prefix . '_password'];
            }
        }

        /** If Discovery Miles selected */
        if ($method === WC_PayU_Payment_Methods::DISCOVERY_MILES ||
            (isset($_POST["payu_transaction_type"]) && ('recurring' === $_POST["payu_transaction_type"]))
        ) {
            if (!empty($this->settings['dm_username'])) {
                $soap_username = $this->settings['dm_username'];
            }

            if (!empty($this->settings['dm_password'])) {
                $soap_password = $this->settings['dm_password'];
            }
        }

        $config = [];
        $config['debug'] = $this->debug;
        $config['username'] = $soap_username;
        $config['password'] = $soap_password;
        $config['extended_debug'] = $this->extended_debug;

        if (strtolower($config['debug']) === 'yes') {
            $config['log_enable'] = true;
        } else {
            $config['log_enable'] = false;
        }

        if (strtolower($config['extended_debug']) === 'yes') {
            $config['extended_debug'] = true;
        } else {
            $config['extended_debug'] = false;
        }

        $config['production'] = false;

        if ($this->settings['testmode'] === 'no') {
            $config['production'] = true;
        }

        return $config;
    }

    protected function get_transaction_data(array $config, WC_Order $order)
    {
        $txnData = [];

        // Customer data
        $customer = [];

        if (empty($order->get_shipping_first_name())) {
            $customer['firstName'] = $order->get_billing_first_name();
        } else {
            $customer['firstName'] = $order->get_shipping_first_name();
        }

        if (empty($order->get_shipping_last_name())) {
            $customer['lastName'] = $order->get_billing_last_name();
        } else {
            $customer['lastName'] = $order->get_shipping_last_name();
        }

        $customer['mobile'] = $order->get_billing_phone();
        $customer['email'] = $order->get_billing_email();


        if (empty($order->get_shipping_country())) {
            $country_code = $order->get_billing_country();
        } else {
            $country_code = $order->get_shipping_country();
        }

        $customer['countryCode'] = WC()->countries->get_country_calling_code($country_code);
        $customer['countryCode'] = str_replace('+', '', $customer['countryCode']);
        $customer['regionalId'] = $customer['countryCode'];

        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $customer['merchantUserId'] = $current_user->ID;
        }

        //Add Customer
        $txnData = array_merge($txnData, ['Customer' => $customer]);
        unset($customer);

        $order_id = $order->get_id();

        //Cart data
        $basket = [];
        $woocommerce_format = $order->get_total();
        $float_amount = $woocommerce_format * 100;
        $basket['amountInCents'] = (int) $float_amount;
        $basket['description'] = 'Order No:' . (string)$order_id;
        $basket['currencyCode'] = $this->get_currency_code($order);

        //Add Basket
        $txnData = array_merge($txnData, ['Basket' => $basket]);
        unset($basket);

        // Additional Information
        $additional_info = [];
        $additional_info['supportedPaymentMethods'] = $this->payment_method;
        $additional_info['cancelUrl'] = $this->notify_url . '&order_id=' . $order_id . '&action=cancelled';
        $additional_info['notificationUrl'] = $this->notify_url;
        $additional_info['returnUrl'] = $this->notify_url . '&order_id=' . $order_id;
        $additional_info['merchantReference'] = (string)$order_id;

        if (!$config['production']) {
            $additional_info['demoMode'] = 'true';
        }

        if (!is_user_logged_in()) {
            $additional_info['callCenterRepId'] = 'Unknown';
        }

        // Add Additionnal Information
        $txnData = array_merge($txnData, ['AdditionalInformation' => $additional_info]);
        unset($additional_info);

        // Transaction record array
        if (
            $this->dm_enabled === 'yes' &&
            $this->dm_transaction_type !== '' &&
            $this->transaction_type !== 'PAYMENT' &&
            $this->transaction_type !== 'RESERVE'
        ) {
            $transaction_record = [];
            $transaction_record['statementDescription'] = $txnData['Basket']['description'];
            $transaction_record['managedBy'] = 'MERCHANT';

            if (is_user_logged_in()) {
                $transaction_record['anonymousUser'] = 'false';
            } else {
                $transaction_record['anonymousUser'] = 'true';
            }

            // Add Transaction Record
            $txnData = array_merge($txnData, ['TransactionRecord' => $transaction_record]);
            $transaction_record = null;
            unset($transaction_record);
        }

        return $txnData;
    }

    protected function get_redirect_url(): string
    {
        return $this->txnData->redirect_payment_url ?? '';
    }

    protected function get_merchant_reference(): string
    {
        return $this->txnData->merchantReference ?? '';
    }

    /**
     * @return bool
     */
    protected function has_payment_method(): bool
    {
        return property_exists($this->txnData, 'paymentMethodsUsed');
    }

    protected function get_payment_method(): stdClass|array
    {
        return $this->has_payment_method() ? $this->txnData->paymentMethodsUsed : null;
    }

    /**
     * @return bool
     */
    protected function is_payment_method_cc(): bool
    {
        return $this->has_payment_method() && $this->check_payment_method_cc();
    }

    protected function check_payment_method_cc(): bool
    {
        $payment_methods = $this->get_payment_method();

        if (is_array($payment_methods)) {
            foreach ($payment_methods as $method) {
                if (property_exists($method, 'gatewayReference')) {
                    return true;
                }
            }
        } else {
            if (property_exists($payment_methods, 'gatewayReference')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    protected function get_gateway_reference(): string
    {
        $gateway_reference = 'N/A';
        $payment_methods = $this->get_payment_method();

        if (is_array($payment_methods)) {
            foreach ($payment_methods as $method) {
                if (property_exists($method, 'gatewayReference')) {
                    $gateway_reference = $method->gatewayReference;
                }
            }
        } else {
            if (property_exists($payment_methods, 'gatewayReference')) {
                $gateway_reference = $payment_methods->gatewayReference;
            }
        }

        return $gateway_reference;
    }

    /**
     * @return string
     */
    protected function get_cc_number(): string
    {
        $card_number = 'N/A';
        $has_cc_number = $this->has_payment_method() && $this->is_payment_method_cc();

        if ($has_cc_number) {
            $payment_methods = $this->get_payment_method();

            if (is_array($payment_methods)) {
                foreach ($payment_methods as $method) {
                    if (property_exists($method, 'cardNumber')) {
                        $card_number = $method->cardNumber;
                    }
                }
            } else {
                if (property_exists($payment_methods, 'cardNumber')) {
                    $card_number = $payment_methods->cardNumber;
                }
            }
        }

        return $card_number;
    }

    /**
     * @return string
     */
    protected function get_cc_brand(): string
    {
        $card_brand = 'N/A';
        $has_cc_number = $this->has_payment_method() && $this->is_payment_method_cc();

        if ($has_cc_number) {
            $payment_methods = $this->get_payment_method();

            if (is_array($payment_methods)) {
                foreach ($payment_methods as $method) {
                    if (property_exists($method, 'information')) {
                        $card_brand = $method->information;
                    }
                }
            } else {
                if (property_exists($payment_methods, 'information')) {
                    $card_brand = $payment_methods->information;
                }
            }
        }

        return $card_brand;
    }

    protected function get_total_paid(): float|int
    {
        $total = 0;

        if ($this->is_payment_new()) {
            return $total;
        }

        $payment_methods = $this->get_payment_method();

        if (!$payment_methods) {
            return $total;
        }

        if (
            is_a($payment_methods, stdClass::class, true) &&
            !property_exists($payment_methods, 'amountInCents')
        ) {
            return $total;
        }

        if (
            is_a($payment_methods, stdClass::class, true) &&
            property_exists($payment_methods, 'amountInCents')
        ) {
            return $payment_methods->amountInCents / 100;
        }

        foreach ($payment_methods as $payment_method) {
            $total += $payment_method->amountInCents;
        }

        // Prevent division by zero
        return max($total, 1) / 100;
    }

    protected function get_transaction_state(): string
    {
        return isset($this->txnData->transactionState) ?
            $this->txnData->transactionState :
            '';
    }

    protected function get_point_of_failure(): string
    {
        return $this->txnData->pointOfFailure ?? '';
    }

    protected function get_recurring_details(): ?array
    {
        return isset($this->txnData->recurringDetails) ? $this->txnData->recurringDetails : null;
    }

    protected function get_total_due(): int
    {
        return isset($this->txnData->basket) ? (int)$this->txnData->basket->amountInCents : 0;
    }

    protected function get_result_code(): string
    {
        return $this->txnData->resultCode ?? '';
    }

    protected function get_payment_method_details(string $transaction_notes): string
    {
        if ($this->has_payment_method()) {
            $transaction_notes .= "<br /><br />Payment Method Details:";
            $payment_methods = $this->get_payment_method();

            if (!is_array($payment_methods)) {
                $payment_methods = [$payment_methods];
            }

            foreach ($payment_methods as $type => $payment_method) {
                $transaction_notes .= "<br />=== Method " . $type . "===";
                foreach ($payment_method as $key => $value) {
                    $transaction_notes .= "<br />&nbsp;&nbsp;=> " . $key . ": " . $value;
                }
                $transaction_notes .= '<br />';
            }
        }

        return $transaction_notes;
    }

    /**
     * Saves the card id
     * used for trials, and changing payment option
     *
     * @param int      $card_id The card reference.
     * @param WC_Order $order The order object related to the transaction.
     */
    protected function save_card_id($order)
    {
        $order->update_meta_data('_payu_card_id', $this->get_gateway_reference());
        $order->update_meta_data('_payu_card_last_4', $this->get_cc_number());
        $order->update_meta_data('_payu_card_brand', $this->get_cc_brand());
    }

    /**
     * @param $order
     *
     * @return mixed
     */
    protected function get_card_id($order)
    {
        $card_id = $order->get_meta('_payu_card_id', true);
        if ($card_id) {
            return $card_id;
        }
        return false;
    }

    protected function get_transaction_type($order)
    {
        return $order->get_meta('_payu_transaction_type', true);
    }

    private function validate_amount_paid(): bool
    {
        $amount_due = $this->get_total_due();
        $amount_paid = $this->get_total_paid();

        return sprintf('%.2F', $amount_due) == sprintf('%.2F', $amount_paid);
    }

    private function save_payu_transaction_data($order)
    {
        $this->save_transaction_id($order);

        if ($this->is_payment_method_cc()) {
            $this->save_card_id($order);
        }

        if ('RESERVE' == $this->transaction_type) {
            $order->update_meta_data('_payu_transaction_captured', 'no');
            $order->update_meta_data('_payu_transaction_captured_amount', 0);
        } else {
            $order->update_meta_data('_payu_transaction_captured', 'yes');
            $order->update_meta_data('_payu_transaction_captured_amount', $order->get_total());
        }

        $order->update_meta_data('_payu_transaction_refunded_amount', 0);
        $order->save();
    }
}
