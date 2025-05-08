<?php
/** @var WC_Gateway_BeGateway $gateway */
/** @var WC_Order $order */
/** @var int $order_id */
/** @var array $order_data */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly
if ($gateway->get_transaction_id($order)):
?>
    <ul class="order_action">
        <?php if ($order->get_type() == 'shop_order'): ?>
            <?php $order_is_cancelled = (
                $order->get_meta( '_payu_transaction_refunded', true ) === 'yes' ) ||
                $order->get_meta( '_payu_transaction_voided', true ) === 'yes';
            ?>
            <?php if ($order_is_cancelled && 'cancelled' != $order_data['state']): ?>
                <li class="payu-admin-section-li-small">
                    <?php _e( 'Order is cancelled', 'woocommerce-gateway-payu' ); ?>
                </li>
            <?php endif; ?>

            <li class="payu-admin-section-li">
                <span class="payu-balance__label">
                    <?php _e( 'Remaining balance', 'woocommerce-gateway-payu' ); ?>:
                </span>
                <span class="payu-balance__amount">
                    <span class='payu-balance__currency'>
                        &nbsp;
                    </span>
                    <?php _e(wc_price( $order_data['authorized_amount'] - $order_data['settled_amount'] ) ); ?>
                </span>
            </li>
            <li class="payu-admin-section-li">
                <span class="payu-balance__label">
                    <?php _e( 'Total authorized', 'woocommerce-gateway-payu' ); ?>:
                </span>
                <span class="payu-balance__amount">
                    <span class='payu-balance__currency'>
                        &nbsp;
                    </span>
                    <?= wc_price( $order_data['authorized_amount'] ); ?>
                </span>
            </li>
            <li class="payu-admin-section-li">
                <span class="payu-balance__label">
                    <?php _e( 'Total captured', 'woocommerce-gateway-payu' ); ?>:
                </span>
                <span class="payu-balance__amount">
                    <span class='payu-balance__currency'>
                        &nbsp;
                    </span>
                    <?= wc_price( $order_data['settled_amount'] ); ?>
                </span>
            </li>
            <li class="payu-admin-section-li">
                <span class="payu-balance__label">
                    <?php _e( 'Total refunded', 'woocommerce-gateway-payu' ); ?>:
                </span>
                <span class="payu-balance__amount">
                    <span class='payu-balance__currency'>
                        &nbsp;
                    </span>
                    <?= wc_price( $order_data['refunded_amount'] ); ?>
                </span>
            </li>
            <li style='font-size: xx-small'>&nbsp;</li>
            <?php $can_capture = $gateway->can_payment_method_capture( $order ); ?>
                <?php if ($order_data['settled_amount'] == 0 &&
                        ! in_array( $order_data['state'], array( 'cancelled', 'created') ) &&
                        ! $order_is_cancelled && $can_capture): ?>
                    <li class="payu-full-width">
                        <a class="button button-primary" data-action="payu_gateway_capture" id="payu_gateway_capture"
                        aria-label="<?php _e( 'Capture full amount', 'woocommerce-gateway-payu' ); ?>"
                        data-nonce="<?php echo wp_create_nonce( 'payu' ); ?>"
                        data-order-id="<?php esc_attr_e( $order_id ); ?>"
                        data-confirm="<?php _e( 'You are about to CAPTURE this payment', 'woocommerce-gateway-payu' ); ?>">
                            <?php _e( sprintf( __( 'Capture full amount (%s)', 'woocommerce-gateway-payu' ), wc_price( $order_data['authorized_amount'] ) ) ); ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php $can_cancel = $gateway->can_payment_method_cancel( $order ); ?>
                <?php if ($order_data['settled_amount'] == 0 &&
                    ! in_array( $order_data['state'], array( 'cancelled', 'created') ) &&
                    !$order_is_cancelled && $can_cancel): ?>
                    <li class="payu-full-width">
                        <a class="button" data-action="payu_gateway_cancel"
                        id="payu_gateway_cancel"
                        data-confirm="<?php _e( 'You are about to CANCEL this payment', 'woocommerce-gateway-payu' ); ?>"
                        data-nonce="<?php echo wp_create_nonce( 'payu' ); ?>"
                        data-order-id="<?php esc_attr_e( $order_id ); ?>">
                        <?php _e( 'Cancel transaction', 'woocommerce-gateway-payu' ); ?>
                        </a>
                    </li>
                    <li style='font-size: xx-small'>&nbsp;</li>
                <?php endif; ?>

            <?php $can_capture = $gateway->can_payment_method_capture( $order ); ?>
            <?php $order_is_captured = $order->get_meta( '_payu_transaction_captured', true) == 'yes'; ?>
                <?php if ($order_data['authorized_amount'] > $order_data['settled_amount'] && ! in_array( $order_data['state'], array( 'cancelled', 'created') ) && !$order_is_cancelled && !$order_is_captured && $can_capture): ?>
                    <li class="payu-admin-section-li-header">
                        <?php _e( 'Partly capture', 'woocommerce-gateway-payu' ); ?>
                    </li>
                    <li class="payu-balance last">
                        <span class="payu-balance__label" style="margin-right: 0;">
                            <?php _e( 'Capture amount', 'woocommerce-gateway-payu' ); ?>:
                        </span>
                        <span class="payu-partly_capture_amount">
                            <input id="payu-capture_partly_amount-field" class="payu-capture_partly_amount-field" type="text" autocomplete="off" size="6" value="<?php esc_attr_e( $order_data['authorized_amount'] - $order_data['settled_amount'] ); ?>" />
                        </span>
                    </li>
                    <li class="payu-full-width">
                        <a class="button" id="payu_gateway_capture_partly"
                        data-nonce="<?php echo wp_create_nonce( 'payu' ); ?>"
                        data-order-id="<?php esc_attr_e( $order_id ); ?>">
                        <?php _e( 'Capture specified amount', 'woocommerce-gateway-payu' ); ?>
                        </a>
                    </li>
                    <li style='font-size: xx-small'>&nbsp;</li>
                <?php endif; ?>

            <?php $can_refund = $gateway->can_payment_method_capture( $order ); ?>
            <?php if ( $order_data['settled_amount'] > $order_data['refunded_amount'] && ! in_array( $order_data['state'], array( 'cancelled', 'created') ) && !$order_is_cancelled && $can_refund): ?>
                <li class="payu-admin-section-li-header">
                    <?php _e( 'Partly refund', 'wwoocommerce-gateway-payu' ); ?>
                </li>
                <li class="payu-balance last">
                    <span class="payu-balance__label" style='margin-right: 0;'>
                        <?php _e( 'Refund amount', 'woocommerce-gateway-payu' ); ?>:
                    </span>
                    <span class="payu-partly_refund_amount">
                        <input id="payu-refund_partly_amount-field"
                               class="payu-refund_partly_amount-field" type="text"
                               size="6" autocomplete="off"
                               value="<?php esc_attr_e( $order_data['settled_amount'] - $order_data['refunded_amount'] ) ?>" />
                    </span>
                </li>
                <li class="payu-full-width">
                    <a class="button" id="payu_gateway_refund_partly"
                       data-nonce="<?php echo wp_create_nonce( 'payu' ); ?>"
                       data-order-id="<?php esc_attr_e( $order_id ); ?>">
                       <?php _e( 'Refund specified amount', 'woocommerce-gateway-payu' ); ?>
                    </a>
                </li>
                <li style='font-size: xx-small'>&nbsp;</li>
            <?php endif; ?>
        <?php endif; ?>

        <li class="payu-admin-section-li-header-small">
            <?php _e( 'Payment method', 'woocommerce-gateway-payu' ) ?>
        </li>
        <li class="payu-admin-section-li-small">
            <?php esc_html_e( ucfirst( $order->get_meta( '_payu_transaction_payment_method', true ) ) ); ?>
        </li>
        <li class="payu-admin-section-li-header-small">
            <?php _e( 'Transaction UID', 'woocommerce-gateway-payu' ) ?>
        </li>
        <li class="payu-admin-section-li-small">
            <?php esc_html_e( $order->get_meta( '_payu_transaction_id', true ) ); ?>
        </li>
        <?php if ( null != $order->get_meta( '_payu_card_last_4', true ) ): ?>
            <li class="payu-admin-section-li-header-small">
                <?php _e( 'Card number', 'woocommerce-gateway-payu' ); ?>
            </li>
            <li class="payu-admin-section-li-small">
                <?php esc_html_e( 'xxxx ' . $order->get_meta( '_payu_card_last_4', true ) ); ?>
            </li>
            <li class="payu-admin-section-li-header-small">
                <?php _e( 'Card brand', 'woocommerce-gateway-payu' ); ?>
            </li>
            <li class="payu-admin-section-li-small">
                <?php esc_html_e( ucfirst( $order->get_meta( '_payu_card_brand', true ) ) ); ?>
            </li>
        <?php endif ?>
    </ul>
<?php endif ?>
