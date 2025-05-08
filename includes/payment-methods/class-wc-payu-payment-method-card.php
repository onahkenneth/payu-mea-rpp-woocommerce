<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_PayU_Payment_Method_Card extends WC_PayU_Payment_Method
{
    /**
     * PayU's internal identifier for Card payment.
     */
    const PAYU_ID = WC_PayU_Payment_Methods::CARD;

    public function __construct()
    {
        parent::__construct();
        
        $this->payu_id = self::PAYU_ID;
        $this->title = __('Credit/Debit Card', 'woocommerce-gateway-payu');
        $this->supported_currencies = ['ZAR','NGN'];
        $this->supported_countries = ['ZA', 'NG'];
        $this->label = __('Credit/Debit Card', 'woocommerce-gateway-payu');
        $this->description = __('Pay with Credit/Debit Card.', 'woocommerce-gateway-payu');
    }
}
