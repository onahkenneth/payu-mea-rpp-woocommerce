
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';

const settings = getSetting('payu_discoverymiles_data', {});

const defaultLabel = __(
    'Discovery Miles',
    'woo-gutenberg-products-block'
);

const label = decodeEntities(settings.title) || defaultLabel;
/**
 * Content component
 */
const Content = () => {
    return decodeEntities(settings.description || '');
};

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = () => {
    return (
        <span style={{ width: '100%' }}>
            {label}
            <Icon />
        </span>
    )
}

const Icon = () => {
    return settings.icon
        ? <img alt={settings.title} src={settings.icon} style={{ float: 'right', marginRight: '20px' }} />
        : ''
}

/**
 * Payment method config object.
 */
const DiscoveryMiles = {
    name: settings.name,
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    }
};

registerPaymentMethod(DiscoveryMiles);