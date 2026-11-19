# Performance evidence

The 19 November 2026 run recorded one loopback homepage navigation per language in headless Chrome. These values describe the sampled desktop pages. [Browser build](evidence/browser-build.json)

| Measure | English | Persian |
| --- | ---: | ---: |
| Navigation interval | 133.8 ms | 175.6 ms |
| Largest contentful paint | 140 ms | 200 ms |
| Cumulative layout shift | 0 | 0 |
| Resource timing entries | 20 | 20 |
| Encoded bytes represented in those entries | 336,134 | 336,134 |

Raw samples report their own measurement scope. [English](evidence/performance-en.json) · [Persian](evidence/performance-fa.json)

The current evidence is a local baseline. Production acceptance requires representative content, repeated cache and network conditions, real mobile devices, LCP, CLS and INP distributions and authorized capacity testing. Peak-load testing remains outside this active workstation's low-impact verification scope.
