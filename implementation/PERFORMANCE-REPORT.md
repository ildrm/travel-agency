# Performance evidence

The 18 November 2026 run recorded one loopback homepage navigation per language in headless Chrome. These values describe the sampled desktop pages. [Browser build](evidence/browser-build.json)

| Measure | English | Persian |
| --- | ---: | ---: |
| Navigation interval | 119.3 ms | 245.5 ms |
| Largest contentful paint | 128 ms | 272 ms |
| Cumulative layout shift | 0 | 0 |
| Resource timing entries | 19 | 19 |
| Encoded bytes represented in those entries | 325,826 | 325,826 |

Raw samples report their own measurement scope. [English](evidence/performance-en.json) · [Persian](evidence/performance-fa.json)

The current evidence is a local baseline. Production acceptance requires representative content, repeated cache and network conditions, real mobile devices, LCP, CLS and INP distributions and authorized capacity testing. Peak-load testing remains outside this active workstation's low-impact verification scope.
