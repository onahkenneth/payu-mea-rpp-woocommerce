<?php

use Automattic\WooCommerce\Enums\OrderStatus;

class WC_PayU_Payment_Method extends WC_Payment_Gateway
{
    /**
     * PayU key name
     *
     * @var string
     */
    protected $payu_id;

    /**
     * Display title
     *
     * @var string
     */
    public $title;

    /**
     * Method label
     *
     * @var string
     */
    protected $label;

    /**
     * Method description
     *
     * @var string
     */
    public $description;

    /**
     * Array of currencies supported by this method
     *
     * @var array
     */
    protected $supported_currencies;

    /**
     * Supported customer locations for which charges for a payment method can be processed.
     * Empty if all customer locations are supported.
     *
     * @var string[]
     */
    protected $supported_countries = [];

    /**
     * Wether this method is enabled
     *
     * @var bool
     */
    public $enabled;

    /**
     * Wether this method is in testmode.
     *
     * @var bool
     */
    public $testmode;

    /**
     * Create instance of payment method
     */
    public function __construct()
    {
        $main_settings = WC_PayU_Helper::get_payu_settings();
        $this->enabled = ! empty($main_settings['enabled']) && 'yes' === $main_settings['enabled'] ? 'yes' : 'no';

        // Load the settings.
        $this->init_settings();

        $this->id = WC_Gateway_PayU::ID . '_' . static::PAYU_ID; // @phpstan-ignore-line (STRIPE_ID is defined in classes using this class)
        $this->has_fields = true;
        $this->testmode = WC_PayU_Mode::is_test();
        $this->supports = ['products', 'refunds'];
    }

    /**
     * Magic method to call methods from the main PayU gateway.
     *
     * Calling methods on the method instance should forward the call to the main PayU gateway.
     * Because the payment methods are not actual gateways, they don't have the methods to handle payments, so we need to forward the calls to
     * the main PayU gateway.
     *
     * That would suggest we should use a class inheritance structure, however, we don't want to extend the PayU gateway class
     * because we don't want the payment method instance of the gateway to process those calls, we want the actual main instance of the
     * gateway to process them.
     *
     * @param string $method The method name.
     * @param array  $arguments The method arguments.
     */
    public function __call($method, $arguments)
    {
        $gateway_instance = WC_PayU::get_instance()->get_main_gateway();

        if (in_array($method, get_class_methods($gateway_instance))) {
            return call_user_func_array([$gateway_instance, $method], $arguments);
        } else {
            $message = method_exists($gateway_instance, $method) ? 'Call to private method ' : 'Call to undefined method ';
            throw new \Error($message . get_class($this) . '::' . $method);
        }
    }

    /**
     * Returns payment method ID
     *
     * @return string
     */
    public function get_id()
    {
        return $this->payu_id;
    }

    /**
     * Returns true if the method is enabled.
     *
     * @return bool
     */
    public function is_enabled()
    {
        return 'yes' === $this->enabled;
    }

    /**
     * Returns true if the method is available.
     *
     * @return bool
     */
    public function is_available()
    {
        if (is_add_payment_method_page()) {
            return false;
        }

        return $this->is_enabled_at_checkout() && parent::is_available();
    }

    /**
     * Returns payment method title
     *
     * @param stdClass|array|bool $payment_details Optional payment details from charge object.
     *
     * @return string
     */
    public function get_title($payment_details = false)
    {
        $payment_method_settings = get_option('woocommerce_' . $this->payu_id . '_settings', []);
        return ! empty($payment_method_settings['title']) ? $payment_method_settings['title'] : $this->title;
    }

    /**
     * Returns payment method label
     *
     * @return string
     */
    public function get_label()
    {
        return $this->label;
    }

    /**
     * Returns payment method description
     *
     * @return string
     */
    public function get_description()
    {
        $payment_method_settings = get_option('woocommerce_' . $this->payu_id . '_settings', []);
        return ! empty($payment_method_settings['description']) ? $payment_method_settings['description'] : '';
    }

    /**
     * Gets the payment method's icon.
     *
     * @return string The icon HTML.
     */
    public function get_icon()
    {
        $icons = WC_PayU::get_instance()->get_main_gateway()->payment_icons();
        return apply_filters('woocommerce_gateway_icon', isset($icons[$this->get_id()]) ? $icons[$this->get_id()] : '', $this->id);
    }

    /**
     * Returns the currencies the method supports.
     *
     * @return array|null
     */
    public function get_supported_currencies()
    {
        return apply_filters(
            'wc_' . $this->payu_id . '_supported_currencies', // @phpstan-ignore-line (STRIPE_ID is defined in classes using this class)
            $this->supported_currencies
        );
    }

    /**
     * Wrapper function for get_woocommerce_currency global function
     */
    public function get_woocommerce_currency()
    {
        return get_woocommerce_currency();
    }

    /**
     * Wrapper function returning supported features.
     */
    public function get_supported_features()
    {
        return $this->supports;
    }

    /**
     * Processes an order payment.
     *
     * Payment methods use the WC_Gateway_PayU::process_payment() function.
     *
     * @param int $order_id The order ID to process.
     * @return array The payment result.
     */
    public function process_payment($order_id)
    {
        return WC_PayU::get_instance()->get_main_gateway()->process_payment($order_id);
    }

    /**
     * Process the add payment method request.
     *
     * Payment methods use the WC_Gateway_PayU::process_payment() function.
     *
     * @return array The add payment method result.
     */
    public function add_payment_method()
    {
        $gateway_instance = WC_PayU::get_instance()->get_main_gateway();
        return $gateway_instance->add_payment_method();
    }

    /**
     * Returns the payment method settings option.
     *
     * @return string
     */
    public function get_option_key()
    {
        return 'woocommerce_' . WC_Gateway_PayU::ID . '_settings';
    }

    /**
     * Get option from the main PayU gateway if it exists.
     *
     * @param string $key Option key.
     * @param mixed  $empty_value Value when empty.
     * @return string The value specified for the option or a default value for the option.
     */
    public function get_option($key, $empty_value = null)
    {
        $main_settings = WC_PayU_Helper::get_payu_settings();

        if (empty($main_settings)) {
            return $empty_value;
        }

        if (! is_null($empty_value) && '' === $main_settings[$key]) {
            return $empty_value;
        }

        return $main_settings[$key] ?? $empty_value;
    }

    /**
     * Returns boolean dependent on whether payment method
     * can be used at checkout
     *
     * @param int|null    $order_id
     * @param string|null $account_domestic_currency The account's default currency.
     * @return bool
     */
    public function is_enabled_at_checkout($order_id = null, $account_domestic_currency = null)
    {
        // Check currency compatibility. On the pay for order page, check the order currency.
        // Otherwise, check the store currency.
        $current_store_currency = $this->get_woocommerce_currency();
        $currencies = $this->get_supported_currencies();

        if (!empty($currencies)) {
            if (is_wc_endpoint_url('order-pay') && isset($_GET['key'])) {
                $order = wc_get_order($order_id ? $order_id : absint(get_query_var('order-pay')));
                $order_currency = $order->get_currency();
                if (!in_array($order_currency, $currencies, true)) {
                    return false;
                }
            } elseif (! in_array($current_store_currency, $currencies, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Capture payment when the order is changed from on-hold to complete or processing
     *
     * @param $order_id int
     */
    public function capture_payment($order_id, $amount)
    {
        $order = wc_get_order($order_id);
        if ($this->id != $order->get_payment_method()) {
            return new WP_Error('payu_error', __('Invalid payment method', 'woocommerce-gateway-payu'));
        }
        $transaction_uid = $this->get_transaction_id($order);
        $captured = $order->get_meta('_payu_transaction_captured', true);

        if (!$transaction_uid) {
            return new WP_Error('payu_error', __('No transaction reference to capture', 'woocommerce-gateway-payu'));
        }

        if ('yes' == $captured) {
            return new WP_Error('payu_error', __('Transaction is already captured', 'woocommerce-gateway-payu'));
        }

        $this->log("Info: Starting to capture {$transaction_uid} of {$order_id}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

        $this->capture_transaction($transaction_uid, $order_id, $amount);

        if ($this->is_payment_successful()) {
            $note = __('Capture completed', 'woocommerce-gateway-payu') . PHP_EOL .
                __('Transaction ID: ', 'woocommerce-gateway-payu') . $this->get_payu_reference();

            $order->add_order_note($note);

            $this->log('Info: Capture was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);
            $this->save_transaction_id($order);

            $order->update_meta_data('_payu_transaction_captured', 'yes');
            $order->update_meta_data('_payu_transaction_captured_amount', $amount);
            $order->save();
            $order->payment_complete($this->get_payu_reference());

            return true;
        } else {
            $order->add_order_note(
                __('Error to capture transaction', 'woocommerce-gateway-payu') . PHP_EOL .
                    __('Error: ', 'woocommerce-gateway-payu') . $this->get_result_message() . PHP_EOL
            );
            $this->log('Issue: Capture has failed there has been an issue with the transaction.' . $this->get_display_message() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);
            return new WP_Error('payu_error', __('Error to capture transaction', 'woocommerce-gateway-payu'));
        }
    }

    /**
     * Cancel pre-auth on refund/cancellation
     *
     * @param int $order_id
     */
    public function cancel_payment($order_id, $amount)
    {
        $order = wc_get_order($order_id);
        if ($this->id != $order->get_payment_method()) {
            return new WP_Error('payu_error', __('Invalid payment method', 'woocommerce-gateway-payu'));
        }

        $transaction_uid = $this->get_transaction_id($order);

        if (!$transaction_uid) {
            return new WP_Error('payu_error', __('No transaction reference to cancel', 'woocommerce-gateway-payu'));
        }

        $this->log("Info: Starting to void {$transaction_uid} of {$order_id}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

        $this->void_transaction($transaction_uid, $order_id, $amount);

        if ($this->is_void_successful()) {
            $note = __('Void complete', 'woocommerce-gateway-payu') . PHP_EOL .
                __('Transaction UID: ', 'woocommerce-gateway-payu') . $this->get_payu_reference();

            $order->add_order_note($note);

            $this->log('Info: Void was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

            $order->update_meta_data('_payu_transaction_voided', 'yes');
            $order->update_status(OrderStatus::CANCELLED, __('Order cancelled by merchant.', 'woocommerce-gateway-payu'));
            $order->save();

            return true;
        } else {
            $order->add_order_note(
                __('Error to void transaction', 'woocommerce-gateway-payu') . PHP_EOL .
                    __('Error: ', 'woocommerce-gateway-payu') . $this->get_result_message()
            );
            $this->log("Issue: Void has failed there has been an issue with the transaction." . $this->get_display_message() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

            return new WP_Error('payu_error', __('Error to void transaction', 'woocommerce-gateway-payu'));
        }
    }

    /**
     * Refund payment
     *
     * @param int $order_id
     */
    public function refund_payment($order_id, $amount)
    {
        $order = wc_get_order($order_id);

        if ($this->id != $order->get_payment_method()) {
            return new WP_Error('payu_error', __('Invalid payment method', 'woocommerce-gateway-payu'));
        }

        $transaction_uid = $this->get_transaction_id($order);
        $captured = $order->get_meta('_payu_transaction_captured', true);

        if (!$transaction_uid) {
            return new WP_Error('payu_error', __('No transaction reference to refund', 'woocommerce-gateway-payu'));
        }

        if (!$captured) {
            return new WP_Error('payu_error', __('Transaction has not been captured. Refund failed!', 'woocommerce-gateway-payu'));
        }

        $this->log("Info: Starting to refund {$transaction_uid} of {$order_id}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

        $this->refund_transaction($transaction_uid, $order_id, $amount, __('Refunded from Woocommerce', 'woocommerce-gateway-payu'));

        if ($this->is_refund_successful()) {
            $note = __('Refund completed', 'woocommerce-gateway-payu') . PHP_EOL .
                __('Transaction ID: ', 'woocommerce-gateway-payu') . $this->get_payu_reference();

            $order->add_order_note($note);

            $this->log('Info: Refund was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

            $refund_amount = $order->get_meta('_payu_transaction_refunded_amount', true) ?: 0;
            $refund_amount += $amount;
            $order->update_meta_data('_payu_transaction_refunded_amount', $refund_amount);

            if ($refund_amount >= $order->get_total()) {
                $order->update_meta_data('_payu_transaction_refunded', 'yes');
            }

            $order->save();
            return true;
        } else {
            $order->add_order_note(
                __('Error to refund transaction', 'woocommerce-gateway-payu') . PHP_EOL .
                    __('Error: ', 'woocommerce-gateway-payu') . $this->get_result_message()
            );
            $this->log("Issue: Refund has failed there has been an issue with the transaction." . $this->get_display_message() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__);

            return new WP_Error('payu_error', __('Error to refund transaction', 'woocommerce-gateway-payu'));
        }
    }
}
