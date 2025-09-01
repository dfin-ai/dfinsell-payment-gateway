const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const settings = window.wc.wcSettings.getSetting('woocommerce_dfinsell_settings', {});
const label = window.wp.element.createElement('span', null, settings.title);

registerPaymentMethod({
    name: 'dfinsell',
    label: label,
    ariaLabel: settings.title,
    canMakePayment: () => true,
    content: window.wp.element.createElement('p', null, settings.description),
    edit: window.wp.element.createElement('p', null, settings.description),
    supports: {
        features: settings.supports || [ 'products' ]
    }
});
