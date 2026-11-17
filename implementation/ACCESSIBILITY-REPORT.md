# Accessibility report

Evidence reviewed: 17 November 2026. This is a scoped development assessment, not a WCAG conformance statement or an accessibility certification. The checkout has an unresolved critical manual-review finding despite zero automatically classified axe violations.

The execution record and any superseding browser run are maintained in [TEST-EVIDENCE.md](TEST-EVIDENCE.md). The JSON files below are the direct evidence for the automated results; a passing browser assertion does not resolve an axe `incomplete` result.

## Recorded automated results

| Page and language | Axe violations | Axe incomplete rules | Evidence |
| --- | ---: | ---: | --- |
| Homepage, English | 0 | 0 | [axe-en.json](evidence/axe-en.json) |
| Homepage, Persian | 0 | 0 | [axe-fa.json](evidence/axe-fa.json) |
| WooCommerce checkout, English | 0 | 1, critical | [axe-checkout-en.json](evidence/axe-checkout-en.json) |
| WooCommerce checkout, Persian | 0 | 1, critical | [axe-checkout-fa.json](evidence/axe-checkout-fa.json) |

These records identify axe-core 4.14.1 and a `HeadlessChrome/157.0.0.0` user agent, with a 1440 × 1000 viewport. The homepage scans were recorded at 12:59:18 and 12:59:20 UTC, and the checkout scans at 12:59:25 and 12:59:30 UTC on 17 November 2026. The browser name and version are limited to what the recorded user agent reports; this is not evidence for Safari, Firefox, real mobile browsers, or other Chrome versions.

## Open checkout finding

Both checkout scans contain `aria-valid-attr-value` in `incomplete`, with `impact: critical`. The affected element is `#email`. Its `aria-describedby="wc-guest-checkout-notice"` references an element ID that axe could not find on the page.

This is an open review item, not a clean checkout accessibility result. The missing description may prevent the intended guest-checkout guidance from being associated with the email field; the actual assistive-technology behavior has not been tested. Review the rendered WooCommerce checkout state, determine whether the referenced notice should exist, and ensure any description reference resolves to the intended content throughout checkout updates. Re-run the English and Persian scans and check the field with assistive technology before closing the item. This report does not modify vendor code or claim the underlying cause has been isolated to a particular component.

## Browser and interaction scope

The browser checks are scoped to the local English and Persian homepages at desktop 1440 × 1000 and narrow mobile-emulation 320 × 800 viewports, including a keyboard skip-link check, right-to-left presentation, and horizontal reflow. Reduced motion is requested in the headless browser configuration. The final 12:59 UTC run recorded eight expected tests, zero unexpected results, zero skips, and zero flaky results in 19.1 seconds. [browser-build.json](evidence/browser-build.json) records a headless, single-worker run with exit code 0 and unchanged source during execution. See [TEST-EVIDENCE.md](TEST-EVIDENCE.md) and [browser-results.json](evidence/browser-results.json) for the full functional scope. These browser outcomes do not close the checkout axe manual-review finding.

A skip-link assertion covers that interaction only. It does not establish complete keyboard access, visible focus behavior, focus order, focus restoration, error recovery, or successful use of every booking, authentication, account, partner, and checkout control. Requesting reduced motion does not by itself establish that every animation respects the preference.

The automated axe scans above cover desktop snapshots. The 320 × 800 reflow checks are not separate mobile axe scans, real-device tests, or a complete zoom/text-resizing assessment. Screenshots and DOM direction assertions can support layout review, but cannot establish language quality or reading order for an assistive-technology user.

## Unmeasured coverage and release follow-up

- Native Persian language and right-to-left usability review has not been performed.
- Screen readers, speech input, switch input, and other assistive technologies have not been tested.
- Real iOS/Android devices, Safari, Firefox, forced-colors/high-contrast modes, and the full range of zoom and text-spacing settings have not been assessed.
- Dynamic validation errors, notices, payment feedback, modal/focus behavior, and complete keyboard journeys need manual review in representative states.
- The missing checkout ARIA description must receive an explicit resolution and repeatable evidence; zero `violations` must not be used to hide the critical `incomplete` result.

Use this report with the functional evidence in [TEST-EVIDENCE.md](TEST-EVIDENCE.md). These local observations support development review and do not constitute production accessibility approval.
