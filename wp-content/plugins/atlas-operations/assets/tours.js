(function () {
    'use strict';
    var fa = document.documentElement.lang.indexOf('fa') === 0;
    function text(en, persian) { return fa ? persian : en; }
    function value(field) {
        if (field.dataset.tourType === 'bool') return field.checked;
        if (field.dataset.tourType === 'empty-list') return [];
        if (field.dataset.tourType === 'int' || ['expected_latest_version', 'expected_revision'].includes(field.dataset.tourField)) {
            if (!/^\d{1,15}$/.test(field.value)) throw Error(text('Use a bounded integer.', 'عدد صحیح محدود وارد کنید.'));
            return Number(field.value);
        }
        if (field.dataset.tourType === 'lines') {
            var lines = field.value.split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean);
            if (field.dataset.tourField === 'staff') return lines.map(function (line) { if (!/^\d{1,15}$/.test(line)) throw Error(text('Staff must be account numbers.', 'کارکنان باید شماره حساب باشند.')); return Number(line); });
            return lines;
        }
        return field.value.trim();
    }
    function collect(container, nested) {
        var body = {};
        container.querySelectorAll('[data-tour-field]').forEach(function (field) {
            if (field.closest('template') || (!nested && field.closest('[data-tour-repeat]'))) return;
            body[field.dataset.tourField] = value(field);
        });
        return body;
    }
    document.querySelectorAll('form:has(input[name=action][value=atlas_tour])').forEach(function (form) {
        var status = form.querySelector('[data-tour-status]');
        form.querySelectorAll('[data-tour-repeat]').forEach(function (repeater) {
            var rows = repeater.querySelector('[data-tour-rows]'), template = repeater.querySelector('template');
            function announce(message) { if (status) status.textContent = message + ' · ' + rows.children.length; }
            repeater.querySelector('[data-tour-add]').addEventListener('click', function () {
                if (rows.children.length >= 200) return;
                var row = template.content.firstElementChild.cloneNode(true);
                row.querySelector('[data-tour-remove]').addEventListener('click', function () { row.remove(); announce(text('Row removed', 'ردیف حذف شد')); });
                row.querySelector('[data-tour-up]').addEventListener('click', function () { if (row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling); announce(text('Row moved. Update adjacent stop references after reordering.', 'ردیف جابه‌جا شد. پس از مرتب‌سازی مرجع توقف‌های مجاور را به‌روز کنید.')); });
                row.querySelector('[data-tour-down]').addEventListener('click', function () { if (row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row); announce(text('Row moved. Update adjacent stop references after reordering.', 'ردیف جابه‌جا شد. پس از مرتب‌سازی مرجع توقف‌های مجاور را به‌روز کنید.')); });
                rows.append(row); row.querySelector('input,textarea,select').focus(); announce(text('Row added', 'ردیف اضافه شد'));
            });
        });
        form.addEventListener('submit', function (event) {
            try {
                var body = collect(form, false);
                form.querySelectorAll('[data-tour-repeat]').forEach(function (repeat) { body[repeat.dataset.tourRepeat] = Array.from(repeat.querySelector('[data-tour-rows]').children).map(function (row) { return collect(row, true); }); });
                var field = form.querySelector('input[name=structured_payload]') || document.createElement('input'); field.type = 'hidden'; field.name = 'structured_payload'; field.value = JSON.stringify(body); form.append(field);
                // Repeater controls have duplicate temporary names; only the typed payload is submitted.
                form.querySelectorAll('[data-tour-field]').forEach(function (input) { input.removeAttribute('name'); });
            } catch (error) { event.preventDefault(); if (status) status.textContent = error.message; }
        });
    });
    document.querySelectorAll('[data-tour-manifest]').forEach(function (panel) {
        var page = 1, limit = 25, load = panel.querySelector('[data-tour-load-manifest]'), prev = panel.querySelector('[data-tour-manifest-prev]'), next = panel.querySelector('[data-tour-manifest-next]'), status = panel.querySelector('[data-tour-manifest-status]'), target = panel.querySelector('[data-tour-manifest-rows]');
        async function refresh() {
            load.disabled = prev.disabled = next.disabled = true;
            try {
                var response = await fetch(panel.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': panel.dataset.nonce }, body: JSON.stringify({ page: page, limit: limit, purpose: 'tour_operations' }) }), result = await response.json();
                if (!response.ok) throw Error(result.message || text('Manifest unavailable.', 'فهرست در دسترس نیست.'));
                var table = document.createElement('table'), caption = document.createElement('caption'); caption.textContent = text('Private assigned tour manifest; self-declared names', 'فهرست خصوصی تور تعیین‌شده؛ نام‌های خوداظهاری'); table.append(caption);
                var head = document.createElement('tr'); [text('Booking / traveler', 'رزرو / مسافر'), text('Booking status', 'وضعیت رزرو'), text('Admission / documents', 'پذیرش / مدارک'), text('Rooming', 'اتاق‌بندی')].forEach(function (label) { var cell = document.createElement('th'); cell.scope = 'col'; cell.textContent = label; head.append(cell); }); table.append(head);
                result.items.forEach(function (item) { var row = document.createElement('tr'); [item.booking_id + ' · ' + item.attendee_index + ' · ' + item.name, item.booking_status, (item.admission_eligible ? text('Eligible', 'مجاز') : text('Do not admit', 'پذیرش نکنید')) + ' · ' + item.document_state, item.rooms.map(function (room) { return room.lodging_index + ': ' + room.room_code; }).join(' · ')].forEach(function (value) { var cell = document.createElement('td'); cell.textContent = value; row.append(cell); }); table.append(row); });
                target.replaceChildren(table); status.textContent = text('Manifest page ', 'صفحه فهرست ') + page; prev.disabled = page === 1; next.disabled = result.items.length < limit;
            } catch (error) { target.replaceChildren(); status.textContent = error.message; prev.disabled = page === 1; }
            finally { load.disabled = false; }
        }
        load.addEventListener('click', function () { page = 1; refresh(); }); prev.addEventListener('click', function () { if (page > 1) { page--; refresh(); } }); next.addEventListener('click', function () { page++; refresh(); });
    });
}());
