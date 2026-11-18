(() => {
    'use strict';
    document.querySelectorAll('form[data-atlas-procurement]').forEach(form => {
        const requestKey = crypto.randomUUID();
        const artwork = form.querySelector('[data-artwork-file]');
        if (artwork) artwork.addEventListener('change', () => {
            form.elements.sha256.value = artwork.selectedOptions[0]?.dataset.digest || '';
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const status = form.querySelector('[role="status"]');
            const button = form.querySelector('button');
            const payload = {};
            try {
                form.querySelectorAll('[name]').forEach(input => {
                    const value = input.value.trim();
                    if (input.dataset.type === 'boolean') payload[input.name] = input.checked;
                    else if (input.dataset.type === 'integer') {
                        if (!/^(0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) throw new Error(atlasProcurement.failed);
                        payload[input.name] = Number(value);
                    } else if (input.dataset.type === 'integers') {
                        payload[input.name] = value.split(',').map(item => {
                            const normalized = item.trim();
                            if (!/^[1-9][0-9]*$/.test(normalized) || !Number.isSafeInteger(Number(normalized))) throw new Error(atlasProcurement.failed);
                            return Number(normalized);
                        });
                    } else payload[input.name] = value; // IRR remains an exact decimal string.
                });
                button.disabled = true;
                const response = await fetch(atlasProcurement.base + form.dataset.endpoint, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-WP-Nonce': atlasProcurement.nonce, 'Idempotency-Key': requestKey},
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || atlasProcurement.failed);
                status.textContent = atlasProcurement.saved;
                // Refresh is deliberate: every displayed action carries its observed revision.
                const refresh = document.createElement('button');
                refresh.type = 'button'; refresh.textContent = atlasProcurement.saved;
                refresh.addEventListener('click', () => window.location.reload()); status.append(' ', refresh);
            } catch (error) {
                status.textContent = error.message || atlasProcurement.failed;
                button.disabled = false; // Retry retains the original idempotency key.
            }
        });
    });
})();
