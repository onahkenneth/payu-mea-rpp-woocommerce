<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_PayU_Payment_Method_Dm extends WC_PayU_Payment_Method
{
    /**
     * PayU's internal identifier for Discovery Miles.
     */
    const PAYU_ID = WC_PayU_Payment_Methods::DISCOVERY_MILES;

    public function __construct()
    {
        parent::__construct();

        $main_settings = WC_PayU_Helper::get_payu_settings();
        $this->enabled = 'yes' === $this->enabled && ! empty($main_settings['dm_enabled']) && 'yes' === $main_settings['dm_enabled'] ? 'yes' : 'no';
        
        $this->payu_id = self::PAYU_ID;
        $this->title = __('Discovery Miles', 'woocommerce-gateway-payu');
        $this->supported_currencies = ['ZAR', 'NGN'];
        $this->supported_countries = ['ZA', 'NG'];
        $this->label = __('Discovery Miles', 'woocommerce-gateway-payu');
        $this->description = __('Pay with Discovery Miles.', 'woocommerce-gateway-payu');
    }
}
