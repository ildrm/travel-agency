(function () {
    'use strict';
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) return;
    var settings = window.wc.wcSettings.getSetting('atlas_simulator_data', {});
    var el = window.wp.element.createElement;
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'atlas_simulator',
        label: el('span', null, settings.title || 'Simulated payment (local only)'),
        ariaLabel: settings.title || 'Simulated payment (local only)',
        content: el('p', null, settings.description || 'No funds are transferred.'),
        edit: el('p', null, settings.description || 'No funds are transferred.'),
        canMakePayment: function () { return true; },
        supports: { features: settings.supports || ['products'] }
    });
}());
