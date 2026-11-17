# DOC-17 — English, Persian and RTL localization

**Status:** DRAFT specification; review pending. **Purpose:** define equivalent bilingual behavior and sample copy for customer and operator journeys. **Prepared from perspectives:** native Persian editing, i18n engineering, UX writing, accessibility and support. **Accountable reviewer:** localization lead. **Dependencies:** [IA](DOC-10_INFORMATION-ARCHITECTURE.md), [design system](DOC-12_DESIGN-SYSTEM-UI-KIT.md), [data and booking](DOC-24_BOOKING-INVENTORY-REGISTRATION.md). **Approval gate:** native Persian reviewer and product owner approve actual copy; counsel and finance review terms and money labels.

## 1. Architecture and data

English uses `lang="en" dir="ltr"`; Persian uses `lang="fa" dir="rtl"`. Use WordPress translation mechanisms for interface strings and an evaluated content-localization solution for records; plugin choice and compatibility are OPEN DECISION. The same stable content/offer ID connects translations, each with draft/review/published status and editor attribution. Approved source content is not automatically approved in translation. When a translation is missing, show a clearly labeled fallback in its own language and direction or hide the unavailable localized page according to editorial decision; do not fabricate translation. Language switch maps the current record if available and preserves safe query context, never cross-user state.

Use logical CSS properties; do not reverse actual chronological data or mirror logos, photographic images or numbers merely because layout is RTL. Isolate email, URLs, order IDs, prices and English place names with appropriate bidi isolation (`bdi` or equivalent); do not insert invisible controls into the stored identifier. Dates store UTC instant and named IANA zone; display a localized calendar representation with zone and offset as appropriate. Jalali display/input is an optional approved feature; convert at the boundary with tested date library and preserve canonical instant. DST ambiguous/nonexistent local times require explicit handling and tests. No host or browser clock determines admission.

Money uses integer IRR internally. If displayed as toman, label it clearly and convert exactly at 10 IRR per toman, with an explicit rounding policy for non-divisible display amounts; never call a rial amount toman without conversion. Provider units/configuration need documented verification. Numeral style is a presentation preference; machine IDs retain canonical digits. Persist normalized telephone/email values only where policy allows. Persian search compares normalized Arabic/Persian yeh and kaf, whitespace and diacritics according to tested editorial rules while preserving originals.

## 2. Fictional EN/FA copy pairs

All copy is proposed and requires native editing and legal/finance review for production. References and amounts below are illustrative only.

| Context | English | فارسی |
|---|---|---|
| Menu | Tours · Events · Academy · Shop | تورها · رویدادها · آموزشگاه · فروشگاه |
| CTA | View itinerary | مشاهده برنامه سفر |
| CTA | Request a quote | درخواست پیش‌فاکتور |
| Sign-in | Sign in to continue your booking. | برای ادامه رزرو وارد حساب کاربری شوید. |
| Before window | Registration opens on 1 June at 09:00 (Asia/Tehran). | ثبت‌نام در ۱ ژوئن ساعت ۰۹:۰۰ (به وقت تهران) آغاز می‌شود. |
| Closed | Registration has closed. | مهلت ثبت‌نام به پایان رسیده است. |
| Sold out | No seats are currently available. | در حال حاضر ظرفیت خالی وجود ندارد. |
| Deadline | Your payment deadline is 18:00 (Asia/Tehran). | مهلت پرداخت شما ساعت ۱۸:۰۰ به وقت تهران پایان می‌یابد. |
| Payment pending | Payment is being verified; your booking is not yet confirmed. | پرداخت در حال بررسی است؛ رزرو شما هنوز تأیید نشده است. |
| Paid exception | Your payment needs review. Contact support with reference A-123. | پرداخت شما نیاز به بررسی دارد. با شماره پیگیری A-123 با پشتیبانی تماس بگیرید. |
| RFQ | Request received. Price and availability are not confirmed. | درخواست دریافت شد. قیمت و موجودی هنوز تأیید نشده‌اند. |
| Price | Total: 1,000,000 IRR (100,000 toman). | مبلغ کل: ۱٬۰۰۰٬۰۰۰ ریال (۱۰۰٬۰۰۰ تومان). |
| Exclusion | Airfare is not included. | هزینه بلیت هواپیما شامل نمی‌شود. |
| Error | Enter a valid email address. | یک نشانی ایمیل معتبر وارد کنید. |
| Error | Choose at least one attendee. | دست‌کم یک شرکت‌کننده انتخاب کنید. |
| Refund summary | Refund eligibility depends on the stated cancellation policy. | امکان بازپرداخت به شرایط لغو اعلام‌شده بستگی دارد. |
| Confirmation subject | Booking pending confirmation — A-123 | رزرو در انتظار تأیید — A-123 |
| Confirmation body | We received your payment. The agency will review your booking. | پرداخت شما دریافت شد. مجموعه درخواست رزرو شما را بررسی می‌کند. |

Use the last payment-received body only after independently verified payment, never merely on redirect. Show accessible absolute deadline alongside a visual countdown; translate all system, email, SMS, invoice and voucher states, including errors and exception messages. Payer, purchaser, traveler and attendee must not be conflated in translation.

## 3. QA and ownership

Native editor owns terminology, grammar, tone and a glossary covering booking/reservation, confirmed/pending and rial/toman; product validates meaning and legal reviewer signs contractual copy. Test 320 px/zoom and long strings, date and digit preference, mixed-script reference copied intact, locale switch before/after sign-in, missing translation, email subject encoding and notifications. Verify search for both Arabic/Persian yeh/kaf, punctuation and whitespace, and detect misleading homographs without destructive source changes. Test dates on zone transitions, registration at exact cutoff and Jalali conversion if enabled. Screen reader should announce content under correct language; keyboard order follows logical flow.

**Fictional example:** a course has two cohorts: one open and one closed. The Persian page labels each independently; switching language returns to the same cohort, subject to translation availability. This example is not evidence of a working implementation. **OPEN DECISIONS:** translation provider/plugin, language URLs, fallback rules, supported calendar, font licensing and ownership for supplier-authored translations.

**Self-assessment (0–5):** completeness 4 (core bilingual scenarios); specificity 4 (copy and rules); consistency 4 (time/money rules); user value 4 (equivalent tasks); WP feasibility 4 (plugin choice deferred); security/privacy 4 (safe identifiers); compliance 2 (legal copy and native review pending, blocker); accessibility/localization 4 (QA matrix, not audit); testability 4 (cases); traceability 4 (FR-I18N-001/NFR-LOC-001). Persian examples have not been independently reviewed.
