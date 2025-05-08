<?php

class WC_PayU_Helper
{
    public const SETTINGS_OPTION = 'woocommerce_payu_settings';

    /**
     * Get the main PayU settings option.
     *
     * @param string $method (Optional) The payment method to get the settings from.
     * @return array $settings The PayU settings.
     */
    public static function get_payu_settings($method = null)
    {
        $settings = null === $method ? get_option(self::SETTINGS_OPTION, []) : get_option('woocommerce_' . $method . '_settings', []);
        if (! is_array($settings)) {
            $settings = [];
        }
        return $settings;
    }
}
