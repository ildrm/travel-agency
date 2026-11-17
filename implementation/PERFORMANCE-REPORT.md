# Performance report

Evidence reviewed: 17 November 2026. The available measurements are one local headless desktop navigation per language. They are development observations, not field Core Web Vitals, a production benchmark, or a capacity test. The execution record and any superseding run are maintained in [TEST-EVIDENCE.md](TEST-EVIDENCE.md).

## Recorded navigation samples

| Recorded value | English homepage | Persian homepage |
| --- | ---: | ---: |
| Navigation interval | 184.7 ms | 79.2 ms |
| Recorded largest contentful paint | 196 ms | 200 ms |
| Recorded cumulative layout shift | 0 | 0 |
| Resource Timing entries counted | 17 | 17 |
| Encoded resource bytes recorded | 323,515 bytes | 323,515 bytes |
| Samples | 1 | 1 |

Sources: [performance-en.json](evidence/performance-en.json) and [performance-fa.json](evidence/performance-fa.json). The values are rounded for presentation; the JSON retains the recorded precision. Each file explicitly limits its scope to one local headless desktop navigation and disclaims field p75, load capacity, INP, and production benchmarking.

These are the refreshed samples associated with the final 12:59 UTC browser run on 17 November 2026. [browser-build.json](evidence/browser-build.json) records unchanged source, headless operation, one worker, and exit code 0. [browser-results.json](evidence/browser-results.json) records eight expected tests with no unexpected, skipped, or flaky results in 19.1 seconds. That suite duration is not a page-load or capacity metric.

The related browser evidence reports `HeadlessChrome/157.0.0.0` at 1440 × 1000. See [axe-en.json](evidence/axe-en.json), [axe-fa.json](evidence/axe-fa.json), and the run configuration/status in [TEST-EVIDENCE.md](TEST-EVIDENCE.md). The performance JSON does not independently record a browser version, timestamp, hardware profile, or cache/network conditions. No broader browser or device support result follows from these samples.

## Interpretation and limits

The recorded pages completed quickly on the local test setup and showed no layout shift during the sampled observation. That is the extent of the supported conclusion. There is no sample distribution, percentile, repeated cold/warm comparison, representative production network, or geographically distributed measurement. Local loopback results cannot predict a customer's experience under mobile network latency, hosting contention, real content volume, or third-party service delays.

`navigation_ms` is the recorded navigation interval, not a separately measured server TTFB or an established complete-interactivity metric. The LCP and CLS values describe only the observed page and capture window. There is no measured Interaction to Next Paint (INP), sustained interaction latency, or field p75 result. The difference between the English and Persian single samples is insufficient to rank their performance.

The byte and resource counts are the entries represented by the recorded Resource Timing collection. They should not be treated as the complete application download size, installed package size, browser memory use, or a full accounting of HTML, cached resources, external assets, and later requests. No checkout, account, dashboard, partner portal, or booking journey performance result is established by homepage measurements.

The browser checks also include homepage reflow at 320 × 800 and request reduced motion, as scoped in [ACCESSIBILITY-REPORT.md](ACCESSIBILITY-REPORT.md). Those checks are not mobile performance measurements or evidence of a particular mobile performance budget.

## Work needed for performance acceptance

- Measure repeated runs with declared device, browser, cache, network, hosting, dataset, and observation-window conditions; preserve raw samples and report their distribution.
- Exercise representative discovery, booking, cart/checkout, account, and agency workflows with production-like content and integrations.
- Evaluate LCP, CLS, and interaction responsiveness across representative devices and networks; obtain field p75 evidence before making field Core Web Vitals claims.
- Run a separately authorized capacity test on an isolated environment with an explicit workload and acceptance criteria. No requests-per-second, concurrent-user capacity, database scalability, or resilience result is claimed here.

These measurements provide a local baseline for later comparisons. They do not close production performance or load-testing gates; consult [TEST-EVIDENCE.md](TEST-EVIDENCE.md) for the actual executed scope.
