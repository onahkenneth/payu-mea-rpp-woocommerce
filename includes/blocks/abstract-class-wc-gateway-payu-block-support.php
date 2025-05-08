<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;

abstract class WC_PayU_Block_Support extends AbstractPaymentMethodType
{
    /**
     * Payment method name defined by payment methods extending this class.
     *
     * @var string
     */
    protected $name = 'payu';

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('woocommerce_rest_checkout_process_payment_with_context', [$this, 'add_payment_request_order_meta'], 8, 1);
    }

    /**
     * Add payment request data to the order meta as hooked on the
     * woocommerce_rest_checkout_process_payment_with_context action.
     *
     * @param PaymentContext $context Holds context for the payment.
     * @param PaymentResult $result  Result object for the payment.
     */
    public function add_payment_request_order_meta(PaymentContext $context)
    {
        $is_payment_method = false;
        $data = $context->payment_data;

        $main_gateway = WC_PayU::get_instance()->get_main_gateway();
        $isMain = $main_gateway instanceof WC_Gateway_PayU;

        // Check if the payment method is a block payment method. Block methods start with `payu_`.
        if ($isMain && 0 === strpos($context->payment_method, "{$main_gateway->id}_")) {
            // Strip "payu_" from the payment method name to get the payment method type.
            $payment_method_type = substr($context->payment_method, strlen($main_gateway->id) + 1);
            $is_payment_method = isset($main_gateway->payment_methods[$payment_method_type]);
        }

        if (! $is_payment_method) {
            return;
        }

        
        $context->set_payment_data(array_merge($data, ['payment_method' => $payment_method_type]));
    }
}
