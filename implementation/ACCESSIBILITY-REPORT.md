# Accessibility report

Reviewed 19 November 2026. The English and Persian home and service checkout scans recorded zero axe violations and zero incomplete findings in the tested snapshots. The tests use the locally installed WooCommerce 11.3.0 and a headless Chrome worker.

| Page | English | Persian |
| --- | --- | --- |
| Home | [axe-en.json](evidence/axe-en.json) | [axe-fa.json](evidence/axe-fa.json) |
| Service checkout | [axe-checkout-en.json](evidence/axe-checkout-en.json) | [axe-checkout-fa.json](evidence/axe-checkout-fa.json) |

The previous build's checkout email field referred to a missing description in the signed-in WooCommerce 11.3.0 checkout. The companion adds a visible localized description when that exact WooCommerce version omits it. The browser now checks that the field points to one existing description. The succeeding axe scans contain no result for the prior missing reference. [Compatibility code](../wp-content/plugins/atlas-operations/checkout-accessibility.php) · [Browser evidence](evidence/browser-results.json)

A 1,440px Persian checkout previously produced a 20,972px-wide capture. The offscreen secondary-address input extended the RTL scroll area. The theme now clips that individual hidden input within its container, keeps the visible address controls usable, and supplies a checkout H1 and skip-link target. Regression checks cover both checkout languages at 320px and 1,440px. The agency queue has a keyboard-focusable scroll region for its table on phones. The mobile header exposes navigation with a menu button and returns focus to that button when Escape closes the menu. [Checkout template](../wp-content/themes/atlas-leisure/templates/page-checkout.html) · [Persian checkout capture](evidence/checkout-fa.png)

Headless interaction coverage includes the keyboard skip link, 320-pixel page reflow, reduced-motion configuration, Farsi catalog filtering, guide language navigation, both service reservations, the separate printable voucher, physical offline commerce, a dated stay and QR file scanning. The camera test uses a mocked stream. Desktop axe snapshots and mobile reflow checks cover different aspects of accessibility.

Release acceptance still requires native Persian review, representative manual keyboard and screen-reader journeys, real mobile devices and wider browser coverage. Test focus restoration, errors, text resizing, reduced motion and reading order across dynamic account and partner workflows. These scoped results support a local release review. [Test scope](TEST-EVIDENCE.md)
