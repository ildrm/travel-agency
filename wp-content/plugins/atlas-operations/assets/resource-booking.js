(() => {
  'use strict';
  const config = window.atlasResources;
  if (!config) return;
  const t = (en, fa) => config.lang === 'fa' ? fa : en;
  const node = (tag, text, attrs = {}) => { const item = document.createElement(tag); if (text !== null) item.textContent = text; Object.entries(attrs).forEach(([key, value]) => item.setAttribute(key, value)); return item; };
  const label = (text, control) => { const item = node('label', text + ' '); item.append(control); return item; };
  const call = async (path, body) => {
    const response = await fetch(config.base + path + (path.includes('?') ? '&' : '?') + 'lang=' + config.lang, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...(config.nonce ? { 'X-WP-Nonce': config.nonce } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
    const result = await response.json(); if (!response.ok) throw new Error(result.message || config.error); return result;
  };
  document.querySelectorAll('[data-resource-unavailable]').forEach(message => { const form = message.closest('[data-atlas-booking]'); if (form) { const reserve = form.querySelector('[data-atlas-reserve]'); if (reserve) reserve.disabled = true; } });
  document.querySelectorAll('[data-resource-booking]').forEach(fields => {
    const form = fields.closest('[data-atlas-booking]'); const output = fields.querySelector('[data-resource-estimate]');
    if (!form || !output) return;
    const quantity = form.querySelector('[name="quantity"]');
    if (quantity && quantity.parentElement.tagName === 'LABEL') {
      Array.from(quantity.parentElement.childNodes).filter(child => child.nodeType === Node.TEXT_NODE).forEach(child => child.remove());
      quantity.parentElement.prepend(document.createTextNode(fields.querySelector('[name="check_in"]') ? t('Rooms / units ', 'اتاق / واحد ') : t('Vehicles ', 'وسیله ')));
      if (fields.querySelector('[name="pickup_local"]')) { quantity.value = '1'; quantity.max = '1'; quantity.readOnly = true; }
    }
    const plan = fields.querySelector('[name="rate_plan_id"]'); const version = fields.querySelector('[name="rate_version"]'); const terms = fields.querySelector('[name="terms_accepted"]');
    const reserve = form.querySelector('[data-atlas-reserve]'); const protection = fields.querySelector('[name="protection"]'); let planGeneration = 0; let loadingPlan = false;
    form.addEventListener('submit', event => { if (loadingPlan || !plan.value || Number(version.value) < 1) { event.preventDefault(); event.stopImmediatePropagation(); output.textContent = t('Choose and review a current rate plan first.', 'ابتدا طرح قیمت جاری را انتخاب و بررسی کنید.'); } }, true);
    let timer; let generation = 0;
    const refresh = async () => {
      const current = ++generation; const parameters = new URLSearchParams();
      if (loadingPlan || !plan.value || Number(version.value) < 1) return;
      ['rate_plan_id', 'guests_per_unit', 'check_in', 'check_out', 'pickup_local', 'return_local', 'pickup_offset', 'return_offset'].forEach(name => { const control = fields.querySelector('[name="' + name + '"]'); if (control && control.value) parameters.set(name, control.value); });
      if (!(parameters.has('check_in') && parameters.has('check_out')) && !(parameters.has('pickup_local') && parameters.has('return_local'))) return;
      output.textContent = config.loading;
      try {
        const result = await call('resource-offers/' + form.dataset.offer + '/availability?' + parameters.toString());
        if (current !== generation) return;
        if (result.status === 'open') output.textContent = t('Local units currently available: ', 'واحد محلی در دسترس فعلی: ') + result.remaining + (result.amount_per_unit_irr !== undefined ? ' · ' + t('Base dated quote per unit, before optional protection: ', 'قیمت پایه تاریخ‌دار هر واحد، پیش از پوشش اختیاری: ') + new Intl.NumberFormat(config.lang).format(result.amount_per_unit_irr) + ' IRR' : '') + ' · ' + t('Rechecked at reservation; supplier acceptance is still required.', 'هنگام رزرو دوباره بررسی می‌شود؛ پذیرش تأمین‌کننده همچنان لازم است.');
        else output.textContent = result.status === 'sold_out' ? t('No local allocation is available for these dates. Request alternatives.', 'برای این تاریخ سهمیه محلی موجود نیست. گزینه جایگزین درخواست کنید.') : t('Request a reviewed dated quote from the agency.', 'از آژانس قیمت تاریخ‌دار بازبینی‌شده بخواهید.');
      } catch (error) { if (current === generation) output.textContent = error.message || config.error; }
    };
    const scheduleQuote = () => {
      terms.checked = false;
      generation++;
      output.textContent = '';
      clearTimeout(timer);
      timer = setTimeout(refresh, 350);
    };
    fields.addEventListener('input', event => {
      if (event.target !== terms && event.target !== plan) scheduleQuote();
    });
    if (quantity) quantity.addEventListener('input', scheduleQuote);
    if (plan.tagName === 'SELECT') {
      reserve.disabled = true;
      plan.addEventListener('change', async () => {
        const request = ++planGeneration; generation++; clearTimeout(timer); version.value = '0'; terms.checked = false; if (protection) protection.checked = false;
        loadingPlan = true; reserve.disabled = true; output.textContent = config.loading; fields.querySelector('[data-resource-plan-terms]').textContent = '';
        if (!plan.value) { loadingPlan = false; output.textContent = t('Choose a rate plan to review its terms.', 'برای بررسی شرایط، طرح قیمت را انتخاب کنید.'); return; }
        try {
          const result = await call('resource-offers/' + form.dataset.offer + '?rate_plan_id=' + encodeURIComponent(plan.value));
          if (request !== planGeneration) return;
          version.value = String(result.version); fields.querySelector('[data-resource-plan-terms]').textContent = config.lang === 'fa' ? result.terms_fa : result.terms_en;
          fields.querySelector('[data-resource-zone]').textContent = t('Named time zone: ', 'منطقه زمانی: ') + result.timezone;
          const guests = fields.querySelector('[name="guests_per_unit"]'); if (guests) guests.max = String(result.capacity_per_unit);
          const amount = fields.querySelector('[data-resource-protection-amount]'); if (amount) amount.textContent = new Intl.NumberFormat(config.lang).format(result.protection_irr);
          loadingPlan = false; reserve.disabled = false; output.textContent = ''; await refresh();
        } catch (error) { if (request === planGeneration) { loadingPlan = false; output.textContent = error.message || config.error; } }
      });
    }
  });
  document.querySelectorAll('[data-resource-workspace]').forEach(section => {
    const status = section.querySelector('[data-resource-status]'); const box = section.querySelector('[data-resource-queue]');
    let offset = 0;
    const run = async action => { status.textContent = config.loading; try { await action(); status.textContent = config.saved; } catch (error) { status.textContent = error.message || config.error; } };
    const refresh = async () => {
      const result = await call('resource-bookings?offset=' + offset); box.replaceChildren();
      if (!result.items.length) box.append(node('p', t('No resource reservations yet.', 'هنوز رزرو منبعی وجود ندارد.')));
      result.items.forEach(item => {
        const f = item.fields; const quote = f.quote; const card = node('article', null);
        card.append(node('h3', quote.kind === 'stay' ? t('Stay reservation', 'رزرو اقامت') : t('Vehicle reservation', 'رزرو وسیله')));
        card.append(node('p', quote.start + ' → ' + quote.end + ' · ' + quote.timezone));
        if (quote.rate_name_en || quote.rate_name_fa) card.append(node('p', config.lang === 'fa' ? quote.rate_name_fa : quote.rate_name_en));
        const supplier = { pending: t('Awaiting supplier review', 'در انتظار بررسی تأمین‌کننده'), accepted: t('Supplier accepted; agency confirmation is separate', 'تأمین‌کننده پذیرفت؛ تأیید آژانس جداست'), rejected: t('Supplier rejected; agency exception/refund review required', 'تأمین‌کننده نپذیرفت؛ بررسی استثنا و بازپرداخت آژانس لازم است') };
        const states = { held: t('Temporary allocation held', 'تخصیص موقت نگه داشته شده'), consumed: t('Booking allocation retained', 'تخصیص رزرو حفظ شده'), released: t('Allocation released', 'تخصیص آزاد شده') };
        card.append(node('p', states[item.state] || item.state), node('p', supplier[f.supplier_status] || f.supplier_status));
        const bookingStates = { held: t('Awaiting checkout', 'در انتظار ثبت سفارش'), pending_payment: t('Awaiting checkout', 'در انتظار ثبت سفارش'), paid_pending_confirmation: t('Awaiting agency confirmation', 'در انتظار تأیید آژانس'), confirmed: t('Agency confirmed', 'آژانس تأیید کرد'), exception: t('Agency exception/refund review required', 'بررسی استثنا و بازپرداخت آژانس لازم است'), expired: t('Checkout deadline elapsed', 'مهلت ثبت سفارش پایان یافته است'), fulfilled: t('Completed', 'تکمیل شده'), canceled: t('Canceled; money/refund status is separate', 'لغو شد؛ وضعیت وجه و بازپرداخت جداست') };
        if (item.booking_status) card.append(node('p', bookingStates[item.booking_status] || item.booking_status));
        card.append(node('p', config.lang === 'fa' ? quote.terms_fa : quote.terms_en));
        card.append(node('p', t('No security-deposit instrument has been secured by this booking.', 'هیچ ابزار ودیعه‌ای از طریق این رزرو تضمین نشده است.')));
        if (item.can_supplier_review) {
          const reference = node('input', null, { readonly: '', value: f.allocation_id }); card.append(label(t('Inventory allocation reference for operational pickup/return', 'شناسه تخصیص موجودی برای تحویل و بازگشت عملیاتی'), reference));
          const form = node('form', null); const decision = node('select', null); decision.append(node('option', t('Accept local allotment', 'پذیرش سهمیه محلی'), { value: 'true' }), node('option', t('Reject / request agency review', 'رد / درخواست بررسی آژانس'), { value: 'false' }));
          if (['confirmed', 'fulfilled'].includes(item.booking_status)) { decision.options[0].disabled = true; decision.value = 'false'; }
          const evidence = node('input', null, { required: '', minlength: '8', maxlength: '128', pattern: '[A-Za-z0-9._:-]{8,128}' }); const reason = node('input', null, { required: '', maxlength: '190' }); const button = node('button', t('Record supplier decision', 'ثبت تصمیم تأمین‌کننده'));
          form.append(label(t('Supplier decision', 'تصمیم تأمین‌کننده'), decision), label(t('Private evidence reference', 'شناسه خصوصی شواهد'), evidence), label(t('Reason', 'دلیل'), reason), button);
          form.addEventListener('submit', event => { event.preventDefault(); button.disabled = true; run(async () => { await call('resource-bookings/' + item.id + '/supplier-review', { version: item.version, accepted: decision.value === 'true', evidence_reference: evidence.value, reason: reason.value }); await refresh(); }).finally(() => { button.disabled = false; }); }); card.append(form);
        }
        const cancellation = item.cancellation;
        if (cancellation) {
          const labels = { requested: t('Cancellation requested; allocation remains until reviewed', 'درخواست لغو ثبت شد؛ تخصیص تا زمان بررسی حفظ می‌شود'), withdrawn: t('Cancellation request withdrawn', 'درخواست لغو پس گرفته شد'), approved: t('Cancellation approved; allocation released', 'لغو تأیید و تخصیص آزاد شد'), rejected: t('Cancellation request not approved', 'درخواست لغو تأیید نشد') };
          card.append(node('p', labels[cancellation.state] || cancellation.state));
          if (cancellation.fields && cancellation.fields.customer_reason && item.can_cancel_request) card.append(node('p', t('My request: ', 'درخواست من: ') + cancellation.fields.customer_reason));
          if (cancellation.fields && cancellation.fields.decision_reason) card.append(node('p', cancellation.fields.decision_reason));
          if (cancellation.fields && cancellation.fields.refund_review_required === true) card.append(node('p', t('Verified payment remains recorded. A manual refund review is required; no refund has been asserted or processed by cancellation.', 'پرداخت تأییدشده همچنان ثبت است. بررسی دستی بازپرداخت لازم است؛ لغو به معنی ثبت یا انجام بازپرداخت نیست.')));
        }
        if (item.can_cancel_request) {
          const form = node('form', null); const reason = node('textarea', null, { required: '', maxlength: '1000' }); const button = node('button', cancellation && cancellation.state === 'requested' ? t('Update cancellation request', 'به‌روزرسانی درخواست لغو') : t('Request cancellation', 'درخواست لغو'));
          form.append(label(t('My cancellation reason (private to me and the agency)', 'دلیل لغو من (خصوصی برای من و آژانس)'), reason), button);
          form.addEventListener('submit', event => { event.preventDefault(); button.disabled = true; run(async () => { await call('resource-bookings/' + item.id + '/cancel-request', { version: cancellation ? cancellation.version : 0, reason: reason.value }); await refresh(); }).finally(() => { button.disabled = false; }); }); card.append(form);
          if (cancellation && cancellation.state === 'requested') {
            const withdraw = node('form', null); const explanation = node('input', null, { required: '', maxlength: '1000' }); const button = node('button', t('Withdraw my cancellation request', 'پس گرفتن درخواست لغو من'));
            withdraw.append(label(t('Withdrawal reason', 'دلیل پس گرفتن درخواست'), explanation), button);
            withdraw.addEventListener('submit', event => { event.preventDefault(); button.disabled = true; run(async () => { await call('resource-bookings/' + item.id + '/cancel-request', { version: cancellation.version, reason: explanation.value, withdraw: true }); await refresh(); }).finally(() => { button.disabled = false; }); }); card.append(withdraw);
          }
        }
        if (item.can_cancel_review) {
          const form = node('form', null); const approved = node('select', null); approved.append(node('option', t('Do not approve cancellation', 'لغو تأیید نشود'), { value: 'false' }), node('option', t('Approve cancellation and release unstarted allocation', 'تأیید لغو و آزادسازی تخصیص شروع‌نشده'), { value: 'true' }));
          const terms = node('input', null, { type: 'checkbox', required: '' }); const reference = node('input', null, { required: '', minlength: '8', maxlength: '128', pattern: '[A-Za-z0-9._:-]{8,128}' }); const reason = node('input', null, { required: '', maxlength: '190' }); const button = node('button', t('Record agency cancellation decision', 'ثبت تصمیم آژانس درباره لغو'));
          if (cancellation.fields && cancellation.fields.customer_reason) form.append(node('p', t('Customer request: ', 'درخواست مشتری: ') + cancellation.fields.customer_reason));
          form.append(label(t('Cancellation decision', 'تصمیم لغو'), approved), label(t('I reviewed the accepted cancellation terms and customer request; payment/refund handling remains separate.', 'شرایط پذیرفته‌شده لغو و درخواست مشتری را بررسی کردم؛ رسیدگی به وجه و بازپرداخت جداست.'), terms), label(t('Private review evidence reference', 'شناسه خصوصی شواهد بررسی'), reference), label(t('Decision reason', 'دلیل تصمیم'), reason), button);
          form.addEventListener('submit', event => { event.preventDefault(); button.disabled = true; run(async () => { await call('resource-bookings/' + item.id + '/cancel-review', { version: cancellation.version, approved: approved.value === 'true', terms_reviewed: terms.checked, policy_reference: quote.policy_reference, evidence_reference: reference.value, reason: reason.value }); await refresh(); }).finally(() => { button.disabled = false; }); }); card.append(form);
        }
        box.append(card);
      });
      if (offset > 0) { const previous = node('button', t('Previous records', 'پرونده‌های قبلی'), { type: 'button' }); previous.addEventListener('click', () => { offset = Math.max(0, offset - 100); run(refresh); }); box.append(previous); }
      if (result.has_more) { const next = node('button', t('Next records', 'پرونده‌های بعدی'), { type: 'button' }); next.addEventListener('click', () => { offset += 100; run(refresh); }); box.append(next); }
    };
    section.querySelector('[data-resource-refresh]').addEventListener('click', () => run(refresh));
    const rateForm = section.querySelector('[data-resource-rate]'); const activate = section.querySelector('[data-resource-activate]');
    if (!rateForm) return;
    const loadReviewers = async (selected = []) => {
      const control = rateForm.elements.supplier_users; control.replaceChildren();
      if (!rateForm.elements.resource_id.value) return;
      const result = await call('resource-resources/' + rateForm.elements.resource_id.value + '/reviewers');
      result.items.forEach(person => { const option = node('option', person.name, { value: person.id }); option.selected = selected.includes(person.id); control.append(option); });
    };
    const planEditor = rateForm.querySelector('[data-resource-plan-editor]'); let newPlanId = '';
    const choose = async (selected = '') => {
      const id = rateForm.elements.offer_id.value; if (!id) return;
      const result = await call('resource-offers/' + id + '/review' + (selected ? '?rate_plan_id=' + encodeURIComponent(selected) : '')); newPlanId = result.new_plan_id;
      planEditor.replaceChildren(); (result.plans || []).forEach(item => planEditor.append(node('option', (config.lang === 'fa' ? item.name_fa : item.name_en) + ' · v' + item.version, { value: item.id })));
      const values = result.rate ? result.rate.fields : { min_duration: 1, max_duration: 365, max_guests_per_unit: 1, unit_amount_irr: 0, tax_per_unit_irr: 0, protection_irr: 0, timezone: result.offer.timezone, published: false };
      Array.from(rateForm.elements).forEach(control => { if (!control.name || control.name === 'offer_id' || control.name === 'supplier_users') return; const value = control.name === 'version' ? (result.rate ? result.rate.version : 0) : control.name === 'published' ? result.rate && result.rate.state === 'published' : values[control.name]; if (control.type === 'checkbox') control.checked = !!value; else control.value = value === undefined ? '' : String(value); });
      rateForm.elements.plan_id.value = result.rate ? result.rate.id : newPlanId; planEditor.value = result.rate ? result.rate.id : '';
      if (result.rate) { rateForm.elements.name_en.value = values.name_en || 'Reviewed standard rate'; rateForm.elements.name_fa.value = values.name_fa || 'قیمت استاندارد بازبینی‌شده'; rateForm.elements.max_guests_per_unit.value = values.max_guests_per_unit || 1; }
      await loadReviewers(values.supplier_users || []);
      activate.elements.offer_id.value = id; activate.elements.version.value = result.offer.version;
      activate.elements.product_id.value = result.offer.product_id || '';
      section.querySelector('[data-resource-activation-offer]').textContent = result.offer.title_en + ' · ' + t('independent agency review required', 'بررسی مستقل آژانس لازم است');
    };
    rateForm.elements.offer_id.addEventListener('change', () => run(() => choose()));
    planEditor.addEventListener('change', () => run(() => choose(planEditor.value)));
    rateForm.querySelector('[data-resource-new-plan]').addEventListener('click', () => {
      if (!newPlanId || !rateForm.elements.offer_id.value) return;
      rateForm.elements.plan_id.value = newPlanId; planEditor.value = ''; rateForm.elements.version.value = '0'; rateForm.elements.published.checked = false;
      ['name_en', 'name_fa', 'reason', 'unit_amount_irr'].forEach(name => { rateForm.elements[name].value = ''; });
      status.textContent = t('New plan: keep the shared resource, review all prices and terms, then save while the offer is suspended.', 'طرح جدید: منبع مشترک را حفظ کنید، قیمت و شرایط را بررسی کنید و هنگام تعلیق پیشنهاد ذخیره کنید.');
    });
    rateForm.elements.resource_id.addEventListener('change', () => run(() => loadReviewers([])));
    rateForm.addEventListener('submit', event => {
      event.preventDefault(); const button = rateForm.querySelector('button:not([type]),button[type="submit"]'); button.disabled = true;
      run(async () => {
        const data = {}; Array.from(rateForm.elements).forEach(control => { if (!control.name || control.name === 'offer_id' || control.name === 'supplier_users') return; data[control.name] = control.type === 'checkbox' ? control.checked : control.type === 'number' ? Number(control.value) : control.value; });
        data.supplier_users = Array.from(rateForm.elements.supplier_users.selectedOptions).map(option => Number(option.value));
        const result = await call('resource-offers/' + rateForm.elements.offer_id.value, data); await choose(result.id);
      }).finally(() => { button.disabled = false; });
    });
    activate.addEventListener('submit', event => {
      event.preventDefault(); if (!activate.elements.offer_id.value) { status.textContent = t('Choose an offer first.', 'ابتدا پیشنهاد را انتخاب کنید.'); return; }
      const button = activate.querySelector('button'); button.disabled = true;
      run(async () => { const result = await call('resource-offers/' + activate.elements.offer_id.value + '/activate', { version: Number(activate.elements.version.value), product_id: Number(activate.elements.product_id.value) }); activate.elements.version.value = result.version; }).finally(() => { button.disabled = false; });
    });
  });
})();
