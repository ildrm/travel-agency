/* Academy forms use the dedicated audited API; DOM content never trusts API HTML. */
(() => {
  'use strict';
  const config = window.atlasAcademy;
  if (!config) return;
  const t = config.labels;
  const el = (tag, text, attributes = {}) => {
    const item = document.createElement(tag);
    if (text !== null) item.textContent = text;
    Object.entries(attributes).forEach(([key, value]) => { if (typeof value === 'boolean') item[key] = value; else item.setAttribute(key, String(value)); });
    return item;
  };
  const input = (value = '', attributes = {}) => el('input', null, { type: 'text', value, ...attributes });
  const label = (text, control) => { const item = el('label', text + ' '); item.append(control); return item; };
  const select = (choices, value) => {
    const item = el('select', null);
    choices.forEach(([key, text]) => { const option = el('option', text, { value: key }); option.selected = String(key) === String(value); item.append(option); });
    return item;
  };
  const localized = (en, fa) => config.lang === 'fa' ? fa : en;
  const call = async (path, body) => {
    const response = await fetch(config.base + path + (path.includes('?') ? '&' : '?') + 'lang=' + config.lang, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', ...(config.nonce ? { 'X-WP-Nonce': config.nonce } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || t.error);
    return result;
  };
  document.querySelectorAll('[data-atlas-academy]').forEach(section => {
    const status = section.querySelector('[data-academy-status]');
    const announce = message => { status.textContent = message; };
    const progressBox = section.querySelector('[data-academy-progress]');
    const saveForm = (form, buttonText, onSubmit) => {
      const reason = input('', { required: true, maxlength: 1000 });
      form.append(label(t.reason, reason));
      const button = el('button', buttonText, { type: 'submit' }); form.append(button);
      form.addEventListener('submit', async event => {
        event.preventDefault(); button.disabled = true; announce(t.loading);
        try { await onSubmit(reason.value); announce(t.saved); } catch (error) { announce(error.message || t.error); }
        finally { button.disabled = false; }
      });
      return form;
    };
    const verify = async reference => {
      const box = section.querySelector('[data-academy-verification]');
      box.replaceChildren(); announce(t.loading);
      try {
        const result = await call('verify/' + encodeURIComponent(reference));
        box.append(el('p', result.status === 'valid' ? t.valid : t.revoked));
        if (result.course) box.append(el('p', result.course + ' · ' + result.issue_date));
        announce('');
      } catch (error) { announce(error.message || t.error); }
    };
    const verifyForm = section.querySelector('[data-academy-verify]');
    verifyForm.addEventListener('submit', event => { event.preventDefault(); verify(verifyForm.elements.reference.value.trim()); });
    const reference = new URL(window.location.href).searchParams.get('academy_verify');
    if (reference && /^[a-f0-9]{64}$/.test(reference)) { verifyForm.elements.reference.value = reference; verify(reference); }
    if (!config.user || !progressBox) return;
    const authorizationBox = section.querySelector('[data-academy-authorizations]');
    let authorizationOffset = 0;
    const refreshAuthorizations = async () => {
      if (!authorizationBox) return;
      const result = await call('authorizations?offset=' + authorizationOffset); authorizationBox.replaceChildren();
      result.items.forEach(item => {
        const f = item.fields; const card = el('div', null);
        card.append(el('p', localized('Payer account ', 'حساب پرداخت‌کننده ') + f.payer_id + ' · ' + f.cohort_id + ' · ' + localized('Expires ', 'انقضا ') + f.expires_at + ' · ' + (item.state === 'accepted' ? localized('Authorized', 'مجاز') : localized('Revoked', 'لغوشده'))));
        if (item.state === 'accepted') card.append(saveForm(el('form', null), localized('Revoke future payer permission', 'لغو اجازه آینده پرداخت‌کننده'), async reason => { await call('authorizations/' + item.id + '/revoke', { version: item.version, reason }); await refreshAuthorizations(); }));
        authorizationBox.append(card);
      });
      if (authorizationOffset > 0) { const previous = el('button', localized('Previous authorizations', 'اجازه‌های قبلی'), { type: 'button' }); previous.addEventListener('click', async () => { authorizationOffset = Math.max(0, authorizationOffset - 100); try { await refreshAuthorizations(); } catch (error) { announce(error.message || t.error); } }); authorizationBox.append(previous); }
      if (result.has_more) { const next = el('button', localized('Older authorizations', 'اجازه‌های قدیمی‌تر'), { type: 'button' }); next.addEventListener('click', async () => { authorizationOffset += 100; try { await refreshAuthorizations(); } catch (error) { announce(error.message || t.error); } }); authorizationBox.append(next); }
    };
    const grantForm = section.querySelector('[data-academy-authorize]');
    if (grantForm) grantForm.addEventListener('submit', async event => {
      event.preventDefault(); const button = grantForm.querySelector('button'); button.disabled = true; announce(t.loading);
      try {
        await call('authorizations', { cohort_id: grantForm.elements.cohort_id.value, payer_id: Number(grantForm.elements.payer_id.value), eligibility_reference: grantForm.elements.eligibility_reference.value, expires_at: new Date(grantForm.elements.expires_at.value + 'Z').toISOString().replace('.000Z', 'Z'), learner_attested: grantForm.elements.learner_attested.checked, reason: grantForm.elements.reason.value });
        await refreshAuthorizations(); announce(t.saved);
      } catch (error) { announce(error.message || t.error); } finally { button.disabled = false; }
    });
    const grantRefresh = section.querySelector('[data-academy-authorizations-refresh]');
    if (grantRefresh) grantRefresh.addEventListener('click', async () => { announce(t.loading); try { await refreshAuthorizations(); announce(''); } catch (error) { announce(error.message || t.error); } });
    const showProgress = (data, enrollmentId) => {
      progressBox.replaceChildren();
      const f = data.fields; const plan = data.plan.fields; const own = Number(f.learner_id) === Number(config.user);
      progressBox.append(el('h3', localized('Learner completion record', 'پرونده تکمیل فراگیر')));
      const title = config.lang === 'fa' ? plan.course_snapshot.title_fa || plan.course_snapshot.title_en : plan.course_snapshot.title_en;
      progressBox.append(el('p', title + ' · ' + plan.course_snapshot.syllabus_version));
      progressBox.append(el('p', t.status + ': ' + (t[data.state] || data.state)));
      const attendance = el('ul', null);
      plan.sessions.forEach(session => {
        const entry = f.attendance[session.id];
        attendance.append(el('li', session.id + ' · ' + session.timezone + ' · ' + (entry ? (entry.attended ? t.present : t.absent) : '—')));
      });
      progressBox.append(attendance);
      const update = async (action, body) => { const next = await call('enrollments/' + enrollmentId + '/' + action, { version: data.version, ...body }); showProgress(next, enrollmentId); };
      if (!own) {
        const available = plan.sessions.filter(session => config.review || session.instructors.includes(Number(config.user)));
        if (available.length) {
          const form = el('form', null); const session = select(available.map(s => [s.id, s.id]), available[0].id); const attended = select([['true', t.present], ['false', t.absent]], 'true');
          form.append(label(t.session, session), label(t.attendance, attended));
          progressBox.append(saveForm(form, t.attendance, reason => update('attendance', { session: session.value, attended: attended.value === 'true', reason })));
        }
        if (config.review) {
          const form = el('form', null); const rubric = input(plan.policy.rubric_version, { required: true, maxlength: 100 }); const passed = select([['false', t.fail], ['true', t.pass]], 'false');
          form.append(label(t.rubric, rubric), label(t.assessment, passed));
          progressBox.append(saveForm(form, t.assessment, reason => update('assessment', { rubric_version: rubric.value, passed: passed.value === 'true', reason })));
        }
        if (config.issue) progressBox.append(saveForm(el('form', null), t.issue, reason => update('issue', { reason })));
      }
      if (f.assessments && f.assessments.length) {
        const latest = f.assessments[f.assessments.length - 1];
        progressBox.append(el('p', t.assessment + ': ' + (latest.passed ? t.pass : t.fail) + ' · ' + latest.rubric_version + ' · ' + localized('Attempt ', 'نوبت ') + latest.attempt));
      }
      (data.certificates || []).forEach(certificate => {
        const card = el('div', null); card.append(el('p', certificate.state === 'issued' ? t.valid : t.revoked));
        card.append(label(t.reference, input(certificate.fields.reference, { readonly: true })));
        if (config.issue && !own && certificate.state === 'issued') card.append(saveForm(el('form', null), t.revoke, async reason => {
          await call('certificates/' + certificate.id + '/revoke', { version: certificate.version, reason });
          showProgress(await call('enrollments/' + enrollmentId), enrollmentId);
        }));
        progressBox.append(card);
      });
      if (own) {
        const consent = input('', { type: 'checkbox', checked: !!f.disclosure_consent }); const form = el('form', null); form.append(label(t.consent, consent));
        progressBox.append(saveForm(form, t.save, reason => update('consent', { disclosure_consent: consent.checked, reason })));
        const appeal = el('form', null); const target = select([['assessment', t.assessmentTarget], ['revocation', t.revocationTarget]], 'assessment'); appeal.append(label(t.appeal, target));
        progressBox.append(saveForm(appeal, t.appeal, reason => update('appeal', { target: target.value, reason })));
      }
      (f.appeals || []).forEach(appeal => {
        progressBox.append(el('p', t.appeal + ' · ' + (appeal.target === 'assessment' ? t.assessmentTarget : t.revocationTarget) + ' · ' + (appeal.status === 'open' ? localized('Awaiting independent review', 'در انتظار بررسی مستقل') : (t[appeal.decision === 'reissue_allowed' ? 'reissue' : appeal.decision] || appeal.decision))));
        if (config.review && !own && appeal.status === 'open') {
          const form = el('form', null); const choices = [['upheld', t.upheld], appeal.target === 'assessment' ? ['retake', t.retake] : ['reissue_allowed', t.reissue]]; const decision = select(choices, 'upheld'); form.append(label(t.review, decision));
          progressBox.append(saveForm(form, t.review, reason => update('appeal-review', { appeal_id: appeal.id, decision: decision.value, reason })));
        }
      });
    };
    const loadProgress = async id => { announce(t.loading); try { showProgress(await call('enrollments/' + id), id); announce(''); } catch (error) { announce(error.message || t.error); } };
    section.querySelectorAll('[data-academy-enrollment]').forEach(button => button.addEventListener('click', () => loadProgress(button.dataset.academyEnrollment)));
    const scheduleBox = section.querySelector('[data-academy-schedule]');
    const rosterBox = section.querySelector('[data-academy-roster]');
    const cohortSelect = section.querySelector('[data-academy-cohort]');
    const renderPrerequisiteReview = cohortId => {
      if (!config.review) return;
      const details = el('details', null); details.append(el('summary', localized('Private learner prerequisite review', 'بررسی خصوصی پیش‌نیاز فراگیر')));
      const form = el('form', null); const learner = input('', { type: 'number', min: 1, required: true }); const approved = input('', { type: 'checkbox' }); const expiry = input('', { type: 'datetime-local', step: 1, required: true }); const reference = input('', { maxlength: 180, required: true }); let version = 0;
      const load = el('button', t.load, { type: 'button' });
      form.append(label(localized('Learner account reference', 'شناسه حساب فراگیر'), learner), load, label(localized('Prerequisites approved', 'پیش‌نیازها تأیید شده‌اند'), approved), label(localized('Review expiry (UTC)', 'انقضای بررسی (UTC)'), expiry), label(localized('Private reviewed evidence reference', 'شناسه خصوصی شواهد بازبینی‌شده'), reference));
      load.addEventListener('click', async () => {
        announce(t.loading);
        try { const result = await call('cohorts/' + cohortId + '/prerequisite-review?learner_id=' + encodeURIComponent(learner.value)); const review = result.review; version = review ? review.version : 0; approved.checked = !!review && review.state === 'approved'; expiry.value = review ? review.fields.valid_until.slice(0, 19) : ''; reference.value = review ? review.fields.evidence_reference : ''; announce(''); } catch (error) { announce(error.message || t.error); }
      });
      details.append(saveForm(form, localized('Record independent prerequisite decision', 'ثبت تصمیم مستقل پیش‌نیاز'), async reason => { const result = await call('cohorts/' + cohortId + '/prerequisite-review', { version, learner_id: Number(learner.value), approved: approved.checked, valid_until: new Date(expiry.value + 'Z').toISOString().replace('.000Z', 'Z'), evidence_reference: reference.value, reason }); version = result.version; }));
      scheduleBox.append(details);
    };
    const renderSchedule = (workspace, cohortId) => {
      scheduleBox.replaceChildren(); const plan = workspace.plan; const f = plan ? plan.fields : null;
      if (f) {
        const title = config.lang === 'fa' ? f.course_snapshot.title_fa || f.course_snapshot.title_en : f.course_snapshot.title_en;
        scheduleBox.append(el('h3', title), el('p', localized('Cohort: ', 'گروه: ') + f.cohort_id));
        f.sessions.forEach(session => {
          const options = { dateStyle: 'medium', timeStyle: 'short', timeZone: session.timezone };
          scheduleBox.append(el('p', session.id + ' · ' + new Intl.DateTimeFormat(config.lang, options).format(new Date(session.start)) + ' – ' + new Intl.DateTimeFormat(config.lang, options).format(new Date(session.end)) + ' · ' + session.timezone + ' · ' + session.room));
        });
      }
      if (!config.schedule) return;
      const form = el('form', null); const rows = el('div', null);
      const syllabus = input(f ? f.course_snapshot.syllabus_version : '', { required: true, maxlength: 100, readonly: !!f });
      const policy = input(f ? f.policy.reference : '', { required: true, maxlength: 180 });
      const rubric = input(f ? f.policy.rubric_version : '', { required: true, maxlength: 100 });
      const assessment = input('', { type: 'checkbox', checked: f ? f.policy.assessment_required : true });
      const publish = input('', { type: 'checkbox', checked: !!plan && plan.state === 'published' });
      form.append(label(localized('Agreed syllabus version', 'نسخه توافق‌شده سرفصل'), syllabus), label(localized('Approved completion-policy reference', 'شناسه سیاست تأییدشده تکمیل'), policy), label(t.rubric, rubric), label(localized('Assessment required', 'ارزیابی الزامی است'), assessment), rows);
      const addSession = session => {
        const row = el('fieldset', null); row.append(el('legend', t.session));
        const id = input(session ? session.id : 'session-' + (rows.children.length + 1), { required: true, pattern: '[a-zA-Z0-9_-]{1,64}', maxlength: 64 });
        const start = input(session ? session.start.slice(0, 19) : '', { type: 'datetime-local', step: 1, required: true });
        const end = input(session ? session.end.slice(0, 19) : '', { type: 'datetime-local', step: 1, required: true });
        const zone = input(session ? session.timezone : 'Asia/Tehran', { required: true }); const room = input(session ? session.room : '', { required: true, maxlength: 180 });
        const instructors = el('select', null, { multiple: true, required: true, size: Math.min(6, Math.max(2, workspace.instructors.length)) });
        workspace.instructors.forEach(person => { const option = el('option', person.name, { value: person.id }); option.selected = !!session && session.instructors.includes(person.id); instructors.append(option); });
        const required = input('', { type: 'checkbox', checked: session ? f.policy.required_sessions.includes(session.id) : true });
        row.append(label(localized('Session reference', 'شناسه جلسه'), id), label(t.start, start), label(t.end, end), label(t.zone, zone), label(t.room, room), label(t.instructors, instructors), label(t.required, required));
        const remove = el('button', t.remove, { type: 'button' }); remove.addEventListener('click', () => row.remove()); row.append(remove);
        row.readAcademySession = () => ({ id: id.value, start: new Date(start.value + 'Z').toISOString().replace('.000Z', 'Z'), end: new Date(end.value + 'Z').toISOString().replace('.000Z', 'Z'), timezone: zone.value, room: room.value, instructors: Array.from(instructors.selectedOptions).map(option => Number(option.value)), required: required.checked });
        rows.append(row);
      };
      if (f) f.sessions.forEach(addSession); else addSession(null);
      const add = el('button', localized('Add session', 'افزودن جلسه'), { type: 'button' }); add.addEventListener('click', () => addSession(null)); form.append(add, label(localized('Publish reviewed schedule', 'انتشار برنامه بازبینی‌شده'), publish));
      const exception = el('details', null); exception.append(el('summary', localized('Reviewed conflict exception', 'استثنای بازبینی‌شده هم‌پوشانی')));
      const exceptionReason = input('', { maxlength: 1000 }); const exceptionRef = input('', { maxlength: 180 }); exception.append(label(t.reason, exceptionReason), label(localized('Review evidence reference', 'شناسه شواهد بررسی'), exceptionRef)); form.append(exception);
      scheduleBox.append(saveForm(form, t.save, async reason => {
        const entries = Array.from(rows.children).map(row => row.readAcademySession());
        const required = entries.filter(entry => entry.required).map(entry => entry.id); entries.forEach(entry => { delete entry.required; });
        await call('cohorts/' + cohortId + '/plan', { version: plan ? plan.version : 0, sessions: entries, syllabus_version: syllabus.value, policy_reference: policy.value, required_sessions: required, assessment_required: assessment.checked, rubric_version: rubric.value, publish: publish.checked, reason, ...(exceptionReason.value || exceptionRef.value ? { exception_reason: exceptionReason.value, exception_reference: exceptionRef.value } : {}) });
        await loadWorkspace(cohortId);
      }));
    };
    const loadWorkspace = async cohortId => {
      scheduleBox.replaceChildren(); rosterBox.replaceChildren(); progressBox.replaceChildren();
      if (!cohortId) return;
      announce(t.loading);
      try {
        const workspace = await call('cohorts/' + cohortId); renderSchedule(workspace, cohortId); renderPrerequisiteReview(cohortId);
        if (workspace.plan) {
          const result = await call('cohorts/' + cohortId + '/roster');
          if (!result.items.length) rosterBox.append(el('p', t.empty));
          else { const learners = select([['', t.choose], ...result.items.map(item => [item.enrollment_id, item.name])], ''); learners.addEventListener('change', () => { if (learners.value) loadProgress(learners.value); }); rosterBox.append(label(t.choose, learners)); }
        }
        announce('');
      } catch (error) { announce(error.message || t.error); }
    };
    if (cohortSelect) cohortSelect.addEventListener('change', () => loadWorkspace(cohortSelect.value));
  });
})();
