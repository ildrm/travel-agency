(() => {
    'use strict';
    document.querySelectorAll('form[data-atlas-dispatch]').forEach(form => {
        const requestSelect = form.querySelector('[data-request-select]');
        const resourceSelect = form.querySelector('[data-resource-select]');
        const driverSelect = form.querySelector('[data-driver-select]');
        if (requestSelect) {
            const sync = () => { form.elements.expected_request_version.value = requestSelect.selectedOptions[0]?.dataset.version || ''; };
            requestSelect.addEventListener('change', sync); sync();
        }
        if (resourceSelect && driverSelect) {
            const sync = () => {
                const org = resourceSelect.selectedOptions[0]?.dataset.org || '';
                form.elements.org_id.value = org;
                [...driverSelect.options].forEach(option => { option.hidden = option.dataset.org !== org; option.disabled = option.hidden; });
                driverSelect.value = [...driverSelect.options].find(option => !option.disabled)?.value || '';
            };
            resourceSelect.addEventListener('change', sync); sync();
        }
        const requestKey = crypto.randomUUID();
        form.addEventListener('submit', async event => {
            event.preventDefault(); const payload = {}; const button = form.querySelector('button'); const status = form.querySelector('[role="status"]');
            try {
                form.querySelectorAll('[name]').forEach(input => {
                    let value = input.value.trim();
                    if (input.dataset.type === 'boolean') value = input.checked;
                    else if (input.dataset.type === 'integer') {
                        if (!/^(0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) throw new Error(atlasDispatch.failed);
                        value = Number(value);
                    }
                    const parts = input.name.split('.');
                    if (parts.length === 2 && parts[0] === 'policy') { payload.policy ||= {}; payload.policy[parts[1]] = value; }
                    else payload[input.name] = value;
                });
                button.disabled = true;
                const response = await fetch(atlasDispatch.base + form.dataset.endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': atlasDispatch.nonce, 'Idempotency-Key': requestKey}, body: JSON.stringify(payload)});
                const data = await response.json(); if (!response.ok) throw new Error(data.message || atlasDispatch.failed);
                status.textContent = atlasDispatch.saved; const refresh = document.createElement('button'); refresh.type = 'button'; refresh.textContent = atlasDispatch.refresh; refresh.addEventListener('click', () => window.location.reload()); status.append(' ', refresh);
            } catch (error) { status.textContent = error.message || atlasDispatch.failed; button.disabled = false; }
        });
    });
})();
