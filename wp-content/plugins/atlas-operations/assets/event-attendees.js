(function () {
    'use strict';
    var fa = document.documentElement.lang.indexOf('fa') === 0;
    function t(en, persian) { return fa ? persian : en; }
    var labels = {
        attendance_language: t('Attendance language', 'زبان حضور'), experience_level: t('Self-declared experience', 'تجربه خوداظهاری'), gear_attested: t('I have the required gear', 'وسایل لازم را دارم'),
        dietary_preference: t('Optional dietary preference', 'ترجیح غذایی اختیاری'), allergen_codes: t('Restricted allergen self-declaration', 'خوداظهاری محدود حساسیت غذایی'), assistance_contact: t('Request a private assistance contact', 'درخواست تماس خصوصی برای کمک'),
        beginner: t('Beginner', 'مبتدی'), intermediate: t('Intermediate', 'متوسط'), advanced: t('Advanced', 'پیشرفته'), prefer_not_to_say: t('Prefer not to say', 'ترجیح می‌دهم نگویم'), none: t('No dietary preference', 'بدون ترجیح غذایی'), none_declared: t('No allergens declared', 'حساسیت غذایی اعلام نشده'),
        vegetarian: t('Vegetarian', 'گیاه‌خواری'), vegan: t('Vegan', 'وگان'), halal: t('Halal preference', 'ترجیح حلال'), contact_operations: t('Contact operations privately', 'تماس خصوصی با عملیات'), peanut: t('Peanut', 'بادام‌زمینی'), tree_nut: t('Tree nuts', 'مغزهای درختی'), milk: t('Milk', 'شیر'), egg: t('Egg', 'تخم‌مرغ'), wheat: t('Wheat', 'گندم'), soy: t('Soy', 'سویا'), fish: t('Fish', 'ماهی'), shellfish: t('Shellfish', 'صدف و سخت‌پوستان'), sesame: t('Sesame', 'کنجد')
    };
    document.querySelectorAll('[data-event-attendee-form]').forEach(function (panel) {
        var form = panel.closest('[data-atlas-booking]'), schema;
        try { schema = JSON.parse(panel.dataset.schema); } catch (error) { return; }
        if (!form || !window.atlasBooking) return;
        var status = panel.querySelector('[data-event-attendee-status]'), container = panel.querySelector('[data-event-attendees]'), hidden = panel.querySelector('[name=event_attendee_submission]');
        var key, signature, submission, ready = false;
        function quantity() { return Number(form.elements.quantity.value); }
        function references() { return Array.from(form.querySelectorAll('input[name="traveler"]:checked')).map(function (input) { return input.value; }); }
        function invalidate() { hidden.value = ''; ready = false; submission = undefined; status.textContent = t('Attendee changes require saving the private form again.', 'پس از تغییر شرکت‌کنندگان، فرم خصوصی را دوباره ذخیره کنید.'); }
        form.addEventListener('submit', function (event) { if (!ready || !hidden.value) { event.preventDefault(); event.stopImmediatePropagation(); status.textContent = t('Save the private attendee form and complete any prerequisite review first.', 'ابتدا فرم خصوصی را ذخیره و بازبینی پیش‌نیاز را کامل کنید.'); } }, true);
        form.addEventListener('change', function (event) { if (panel.contains(event.target) || event.target.name === 'quantity' || event.target.name === 'traveler') invalidate(); });
        panel.addEventListener('input', invalidate);
        if (schema.require_traveler && form.dataset.needsTravelers !== '1') {
            fetch(atlasBooking.travelersUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': atlasBooking.restNonce } }).then(function (response) { if (!response.ok) throw Error('unavailable'); return response.json(); }).then(function (people) {
                var target = panel.querySelector('[data-event-private-travelers]'), legend = document.createElement('p'); legend.textContent = t('Select one distinct owned traveler per attendee. Add travelers in My account if none appear.', 'برای هر شرکت‌کننده یک مسافر متمایز متعلق به خود را انتخاب کنید. اگر موردی نیست در حساب من مسافر اضافه کنید.'); target.appendChild(legend);
                people.forEach(function (person) { var label = document.createElement('label'), checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.name = 'traveler'; checkbox.value = person.id; label.append(checkbox, document.createTextNode(' ' + person.fields.name)); target.appendChild(label); });
            }).catch(function () { status.textContent = t('Owned traveler records are unavailable. Retry after signing in.', 'سوابق مسافران متعلق به شما در دسترس نیست؛ پس از ورود دوباره تلاش کنید.'); });
        }
        function control(row, field) {
            var required = schema.required_fields.includes(field), label = document.createElement('label');
            label.appendChild(document.createTextNode((labels[field] || field) + (required ? ' *' : '')));
            if (field === 'gear_attested' || field === 'assistance_contact') { var box = document.createElement('input'); box.type = 'checkbox'; box.dataset.eventField = field; label.prepend(box); row.appendChild(label); return; }
            var choices = field === 'attendance_language' ? schema.language_choices : field === 'experience_level' ? ['beginner', 'intermediate', 'advanced', 'prefer_not_to_say'] : field === 'dietary_preference' ? schema.dietary_choices : schema.allergen_choices;
            if (field === 'allergen_codes') { var group = document.createElement('fieldset'), legend = document.createElement('legend'); legend.textContent = labels[field]; group.appendChild(legend); choices.forEach(function (value) { var choice = document.createElement('label'), input = document.createElement('input'); input.type = 'checkbox'; input.dataset.eventField = field; input.value = value; choice.append(input, document.createTextNode(labels[value] || value)); group.appendChild(choice); }); row.appendChild(group); return; }
            var select = document.createElement('select'), empty = document.createElement('option'); empty.value = ''; empty.textContent = '—'; select.appendChild(empty); select.dataset.eventField = field;
            choices.forEach(function (value) { var option = document.createElement('option'); option.value = value; option.textContent = labels[value] || value.toUpperCase(); select.appendChild(option); }); label.appendChild(select); row.appendChild(label);
        }
        function build() {
            invalidate(); var count = quantity(), refs = references();
            if (!Number.isSafeInteger(count) || count < 1 || count > 50 || ((schema.require_traveler || refs.length) && refs.length !== count)) { status.textContent = t('Choose a valid quantity and one distinct traveler per attendee.', 'تعداد معتبر و یک مسافر متمایز برای هر شرکت‌کننده انتخاب کنید.'); return false; }
            container.replaceChildren();
            for (var index = 0; index < count; index++) { var row = document.createElement('fieldset'), legend = document.createElement('legend'); legend.textContent = t('Attendee ', 'شرکت‌کننده ') + (index + 1); row.dataset.eventAttendeeRow = String(index); row.dataset.traveler = refs[index] || ''; row.appendChild(legend); schema.fields.forEach(function (field) { control(row, field); });
                if (schema.prerequisite_required) { var label = document.createElement('label'), input = document.createElement('input'); label.textContent = t('Opaque prerequisite reference; actual evidence needs independent review', 'مرجع غیرمعنادار پیش‌نیاز؛ شواهد واقعی به بازبینی مستقل نیاز دارد'); input.dataset.eventPrerequisite = '1'; input.maxLength = 120; input.autocomplete = 'off'; label.appendChild(input); row.appendChild(label); }
                container.appendChild(row);
            }
            if (schema.policy_id) { var notice = document.createElement('p'), consentLabel = document.createElement('label'), consent = document.createElement('input'); notice.textContent = fa ? schema.restricted_notice_fa : schema.restricted_notice_en; consent.type = 'checkbox'; consent.dataset.eventConsent = '1'; consentLabel.append(consent, document.createTextNode(t('I explicitly consent to the stated restricted-purpose processing and retention.', 'به پردازش و نگهداری با هدف محدود اعلام‌شده صریحاً رضایت می‌دهم.'))); container.append(notice, consentLabel); }
            status.textContent = t('Complete the allowlisted rows, then save. Medical free text is not accepted.', 'ردیف‌های مجاز را کامل و ذخیره کنید. متن آزاد پزشکی پذیرفته نمی‌شود.'); return true;
        }
        panel.querySelector('[data-event-build-attendees]').addEventListener('click', build);
        panel.querySelector('[data-event-save-attendees]').addEventListener('click', async function () {
            var button = this; button.disabled = true;
            try { var rows = Array.from(container.querySelectorAll('[data-event-attendee-row]')); if (!rows.length || rows.length !== quantity()) { build(); throw Error(t('Complete the prepared rows.', 'ردیف‌های آماده‌شده را کامل کنید.')); }
                var currentRefs = references(); if ((schema.require_traveler || currentRefs.length) && rows.some(function (row, index) { return row.dataset.traveler !== currentRefs[index]; })) throw Error(t('Traveler selection changed. Prepare rows again.', 'مسافران انتخاب‌شده تغییر کرده‌اند؛ ردیف‌ها را دوباره آماده کنید.'));
                var body = { form_id: schema.id, attendees: rows.map(function (row) { var answers = {}; schema.fields.forEach(function (field) { var controls = Array.from(row.querySelectorAll('[data-event-field="' + field + '"]')); if (field === 'allergen_codes') { var values = controls.filter(function (input) { return input.checked; }).map(function (input) { return input.value; }); if (values.length) answers[field] = values; } else if (controls[0].type === 'checkbox') answers[field] = controls[0].checked; else if (controls[0].value) answers[field] = controls[0].value; }); var prerequisite = row.querySelector('[data-event-prerequisite]'); return { traveler_ref: row.dataset.traveler, answers: answers, prerequisite_reference: prerequisite ? prerequisite.value.trim() : '' }; }) };
                if (schema.policy_id) { body.consent_version = schema.consent_version; body.consent_accepted = !!container.querySelector('[data-event-consent]').checked; }
                var next = JSON.stringify(body); if (!key || signature !== next) { if (!window.crypto || !crypto.randomUUID) throw Error(t('Secure request keys are unavailable.', 'کلید امن درخواست در دسترس نیست.')); key = crypto.randomUUID(); signature = next; }
                var response = await fetch(panel.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': atlasBooking.restNonce, 'Idempotency-Key': key }, body: next }), data = await response.json();
                if (!response.ok) throw Error(data.message || t('Private form could not be saved.', 'فرم خصوصی ذخیره نشد.')); submission = data.id; hidden.value = submission; ready = ['ready', 'accepted'].includes(data.state);
                status.textContent = ready ? t('Private form saved. Reservation can now be checked by the server.', 'فرم خصوصی ذخیره شد. اکنون سرور می‌تواند رزرو را بررسی کند.') : t('Saved; awaiting independent prerequisite review. Keep the submission reference: ', 'ذخیره شد؛ در انتظار بازبینی مستقل پیش‌نیاز. مرجع اطلاعات را نگه دارید: ') + submission;
            } catch (error) { ready = false; hidden.value = ''; status.textContent = error.message || t('Connection interrupted; retry the same form.', 'ارتباط قطع شد؛ همان فرم را دوباره تلاش کنید.'); }
            finally { button.disabled = false; }
        });
        panel.querySelector('[data-event-refresh-attendees]').addEventListener('click', async function () { if (!submission) { status.textContent = t('Save the private form first.', 'ابتدا فرم خصوصی را ذخیره کنید.'); return; } try { var url = new URL(panel.dataset.readBase + submission); url.searchParams.set('lang', fa ? 'fa' : 'en'); url.searchParams.set('state_only','1'); var response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': atlasBooking.restNonce } }), data = await response.json(); if (!response.ok) throw Error(data.message); ready = ['ready', 'accepted'].includes(data.state); hidden.value = ready ? submission : ''; status.textContent = ready ? t('Prerequisite review accepted; proceed with reservation.', 'بازبینی پیش‌نیاز پذیرفته شد؛ رزرو را ادامه دهید.') : data.state === 'bound' ? t('This form is already linked to a reservation. Use the existing cart or contact support.', 'این فرم قبلاً به رزرو متصل شده است. از سبد موجود استفاده کنید یا با پشتیبانی تماس بگیرید.') : t('Review is pending or the form is unavailable.', 'بازبینی در انتظار است یا فرم در دسترس نیست.'); } catch (error) { status.textContent = error.message || t('Review status is unavailable.', 'وضعیت بازبینی در دسترس نیست.'); } });
    });
}());
