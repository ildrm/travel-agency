(() => {
    'use strict';
    if (!window.wc?.wcBlocksRegistry || !window.wc?.wcSettings || !window.wp?.element) return;
    const settings = window.wc.wcSettings.getSetting('atlas_zarinpal_data', {});
    const create = window.wp.element.createElement;
    const title = settings.title || 'ZarinPal';
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'atlas_zarinpal', label: create('span', null, title), ariaLabel: title,
        content: create('p', null, settings.description || ''), edit: create('p', null, settings.description || ''),
        canMakePayment: () => settings.enabled === true,
        supports: {features: settings.supports || ['products']}
    });
})();
