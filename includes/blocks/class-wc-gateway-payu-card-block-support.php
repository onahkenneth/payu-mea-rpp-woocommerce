<?php


final class WC_PayU_Card_Block_Support extends WC_PayU_Block_Support
{
    /**
     * Name of the payment method.
     *
     * @var string
     */
    protected $name = WC_PayU_Payment_Methods::CARD;

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
        $this->settings = get_option('woocommerce_payu_settings', []);
    }

    public function get_name()
    {
        return WC_Gateway_PayU::ID . '_' . $this->name;
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active()
    {
        $payment_gateways_class = WC()->payment_gateways();
        $payment_gateways = $payment_gateways_class->payment_gateways();

        return isset($payment_gateways[$this->get_name()]) ?
            $payment_gateways[$this->get_name()]->is_available() :
            $payment_gateways[WC_Gateway_PayU::ID]->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles()
    {
        $script_path = '/assets/js/frontend/blocks.js';
        $script_asset_path = WC_PayU::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : [
                'dependencies' => [],
                'version' => WC_PayU::plugin_version()
            ];
        $script_url = WC_PayU::plugin_url() . $script_path;

        wp_register_script(
            'wc-payu-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-payu-payments-blocks', 'woocommerce-gateway-payu', WC_PayU::plugin_abspath() . 'languages/');
        }

        return ['wc-payu-payments-blocks'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return [
            'name' => $this->get_name(),
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports' => $this->get_supported_features(),
            'icon' => WC_PayU::plugin_url() . '/assets/images/creditcard.png'
        ];
    }

    /**
     * Returns an array of supported features.
     *
     * @return string[]
     */
    public function get_supported_features()
    {
        $payment_gateways = WC()->payment_gateways->payment_gateways();

        return isset($payment_gateways[$this->get_name()]) ?
            $payment_gateways[$this->get_name()]->get_supported_features() :
            $payment_gateways[WC_Gateway_PayU::ID]->get_supported_features();
    }
}
