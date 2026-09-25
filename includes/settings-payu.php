<?php

if(!defined('ABSPATH')) {
    exit;
}

$order_tx_type = [
    'PAYMENT' => __('PAYMENT', 'woocommerce-gateway-payu'),
    'RESERVE' => __('RESERVE', 'woocommerce-gateway-payu'),
];

/**
 * Only offer currencies the payment methods can actually settle.
 *
 * This file is included from WC_Gateway_PayU::init_form_fields(), so $this is the
 * gateway and its payment methods are already built.
 */
$wc_currencies = get_woocommerce_currencies();
$currency_options = [];

foreach ($this->get_supported_currencies() as $currency_code) {
    $currency_options[$currency_code] = isset($wc_currencies[$currency_code])
        ? sprintf('%s (%s)', $currency_code, $wc_currencies[$currency_code])
        : $currency_code;
}

$store_currency = WC_PayU_Currency::normalize(get_woocommerce_currency());
$default_currency = isset($currency_options[$store_currency]) ? $store_currency : WC_PayU_Currency::DEFAULT_CURRENCY;

$settings = [
    'enabled' => [
        'title' => __('Enable payment gateway.', 'woocommerce-gateway-payu'),
        'type' => 'checkbox',
        'label' => __('', 'woocommerce-gateway-payu'),
        'default' => 'no',
    ],
    'testmode' => [
        'title' => __('Test mode', 'woocommerce-gateway-payu'),
        'type' => 'checkbox',
        'label' => __('(checked = sandbox, unchecked = production)', 'woocommerce-gateway-payu'),
        'default' => 'yes',
        'description' => __('PayU environment for transactions.', 'woocommerce-gateway-payu'),
    ],
    'title' => [
        'title' => __('Title:', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Payment method name visible during checkout.', 'woocommerce-gateway-payu'),
        'default' => __('PayU Gateway', 'woocommerce-gateway-payu'),
        'desc_tip' => true,
    ],
    'card_title' => [
        'title' => __('Default Method Title:', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Title for default payment method during checkout. Only shortcode checkout', 'woocommerce-gateway-payu'),
        'default' => __('Credit/Debit Card, eBucks and EFT Pro', 'woocommerce-gateway-payu'),
        'desc_tip' => true,
    ],
    'description' => [
        'title' => __('Description:', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Description of payment method(s) visible during checkout.', 'woocommerce-gateway-payu'),
        'default' => __('Payment method(s)', 'woocommerce-gateway-payu'),
    ],
    'safekey' => [
        'title' => __('SafeKey', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Given to Merchant by PayU', 'woocommerce-gateway-payu'),
    ],
    'username' => [
        'title' => __('SOAP Username', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Given to Merchant by PayU', 'woocommerce-gateway-payu'),
    ],
    'password' => [
        'title' => __('SOAP Password', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Given to Merchant by PayU', 'woocommerce-gateway-payu'),
    ],
    'currency' => [
        'title' => __('Currency', 'woocommerce-gateway-payu'),
        'type' => 'select',
        'description' =>  __('The currency PayU transacts in. Only currencies the payment methods support are listed, and the selection must match your store currency.', 'woocommerce-gateway-payu'),
        'default' => $default_currency,
        'options' => $currency_options,
    ],
    'payment_method' => [
        'title' => __('Payment Method', 'woocommerce-gateway-payu'),
        'type' => 'textarea',
        'description' =>  __('Supported Payment Methods', 'woocommerce-gateway-payu'),
        'default' => __('CREDITCARD', 'woocommerce-gateway-payu'),
    ],
    'transaction_type' => [
        'title' => __('Transaction Type', 'woocommerce-gateway-payu'),
        'type' => 'select',
        'description' =>  __('Supported Transaction Types', 'woocommerce-gateway-payu'),
        'options' => $order_tx_type,
    ],
    'dm_enabled' => [
        'title' => __('Discovery Miles', 'woocommerce-gateway-payu'),
        'type' => 'checkbox',
        'label' => __('Enable separate configuration for Discovery Miles.', 'woocommerce-gateway-payu'),
        'default' => 'no',
    ],
    'dm_transaction_type' => [
        'title' => __('Transaction Type', 'woocommerce-gateway-payu'),
        'type' => 'select',
        'description' =>  __('Supported Transaction Types', 'woocommerce-gateway-payu'),
        'options' => $order_tx_type,
    ],
    'dm_title' => [
        'title' => __('Discovery Miles Title:', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Title which the user sees during checkout.', 'woocommerce-gateway-payu'),
        'default' => __('Pay with Discovery Miles', 'woocommerce-gateway-payu'),
        'desc_tip' => true,
    ],
    'dm_safekey' => [
        'title' => __('Discovery Miles SafeKey', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Given to Merchant by PayU', 'woocommerce-gateway-payu'),
    ],
    'dm_username' => [
        'title' => __('Discovery Miles Username', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Given to Merchant by PayU', 'woocommerce-gateway-payu'),
    ],
    'dm_password' => [
        'title' => __('Discovery Miles Password', 'woocommerce-gateway-payu'),
        'type' => 'text',
        'description' => __('Given to Merchant by PayU', 'woocommerce-gateway-payu'),
    ],
    'debug' => [
        'title' => __('Debug Log', 'woocommerce-gateway-payu'),
        'type' => 'checkbox',
        'label' => __('Enable logging', 'woocommerce-gateway-payu'),
        'default' => 'no',
        'description' => __('Default logging stored inside <code>wp-content/uploads/wc-logs/payu-%</code>', 'woocommerce-gateway-payu')
    ],
    'extended_debug' => [
        'title' => __('Extended Debug Enable', 'woocommerce-gateway-payu'),
        'type' => 'checkbox',
        'label' => __('Enable PayU API extended logging', 'woocommerce-gateway-payu'),
        'default' => 'no',
        'description' => __('Log PayU API request, response and headers', 'woocommerce-gateway-payu')
    ]
];

return apply_filters('wc_payu_settings', $settings);