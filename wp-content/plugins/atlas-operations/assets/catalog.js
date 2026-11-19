(function () {
    'use strict';

    document.querySelectorAll('[data-catalog-filter]').forEach(function (form) {
        const status = form.querySelector('[data-catalog-loading]');
        if (!status) return;
        form.addEventListener('submit', function () {
            status.textContent = status.dataset.loadingText;
        });
        window.addEventListener('pageshow', function () {
            status.textContent = '';
        });
    });

    // A submitted filter or result link reloads the page. Announce the new
    // result and leave the browser's back/forward focus restoration intact.
    window.addEventListener('pageshow', function (event) {
        const navigation = performance.getEntriesByType('navigation')[0];
        if (event.persisted || navigation?.type === 'back_forward' || location.hash !== '#atlas-offers') return;
        const result = document.querySelector('#atlas-offers [data-catalog-result-focus]');
        if (result) result.focus();
    });

    document.querySelectorAll('.atlas-catalog-editor').forEach(function (editor) {
        const button = editor.querySelector('[data-add-destination]');
        const container = editor.querySelector('[data-catalog-destinations]');
        if (!button || !container) return;
        button.addEventListener('click', function () {
            const rows = container.querySelectorAll('[data-destination-row]');
            if (!rows.length || rows.length >= 12) return;
            const next = rows[0].cloneNode(true);
            next.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/\[destinations\]\[\d+\]/, '[destinations][' + rows.length + ']');
                input.value = input.name.endsWith('[type]') ? 'city' : '';
            });
            container.appendChild(next);
            next.querySelector('input').focus();
            if (rows.length + 1 >= 12) button.disabled = true;
        });
    });
}());
