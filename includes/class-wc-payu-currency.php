<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Validation helpers for the PayU currency setting.
 *
 * The configured currency is sent to PayU as the basket currencyCode while the
 * amount is taken from the order total, so a mismatch charges the wrong
 * denomination. These helpers let the mismatch be caught locally instead of
 * being rejected by PayU.
 */
class WC_PayU_Currency
{
    /**
     * Currency used when the store currency is not one PayU supports.
     */
    public const DEFAULT_CURRENCY = 'ZAR';

    /**
     * Normalise a currency code for comparison and storage.
     *
     * @param string|null $currency The raw currency code.
     * @return string The trimmed, upper-cased code.
     */
    public static function normalize($currency)
    {
        return strtoupper(trim((string) $currency));
    }

    /**
     * Returns the reason the configured currency cannot be used, if any.
     *
     * @param string|null $configured The currency configured on the gateway.
     * @param string|null $expected The currency the amount is denominated in.
     * @param string[] $supported The currencies supported by the gateway.
     * @return string The error message, or an empty string when the currency is valid.
     */
    public static function get_error($configured, $expected, array $supported)
    {
        $configured = self::normalize($configured);
        $expected = self::normalize($expected);

        if ('' === $configured) {
            return __('The PayU currency is not configured.', 'woocommerce-gateway-payu');
        }

        if (!empty($supported) && !in_array($configured, $supported, true)) {
            return sprintf(
                /* translators: 1: configured currency code, 2: comma separated list of supported currency codes */
                __('PayU does not support the currency %1$s. Supported currencies are: %2$s.', 'woocommerce-gateway-payu'),
                $configured,
                implode(', ', $supported)
            );
        }

        if ('' !== $expected && $configured !== $expected) {
            return sprintf(
                /* translators: 1: configured currency code, 2: order or store currency code */
                __('The PayU currency %1$s does not match the store currency: (%2$s).', 'woocommerce-gateway-payu'),
                $configured,
                $expected
            );
        }

        return '';
    }
}
