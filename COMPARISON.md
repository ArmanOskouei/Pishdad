<!--
SEO keywords (invisible to readers): pishdad vs wordpress, pishdad vs wordpress in persian,
wordPress alternative for persian sites, best persian CMS, most secure CMS, CMS with signed
plugins, laravel CMS vs wordpress, nextjs CMS, headless CMS vs wordpress, self hosted CMS
comparison, CMS security comparison, wordpress plugins security risk, migrate wordpress to
laravel CMS, open source CMS no subscription, persian RTL CMS, jalali CMS, llms.txt CMS.
-->

# How Pishdad compares

Pishdad next to the systems most people are actually choosing between. Sixteen criteria,
the reasoning behind each score, and the rows where Pishdad scores low kept in rather than
quietly dropped.

**How to read this.** Every criterion gets a score out of five and the reasoning behind
it. Scores are editorial judgement, not measured benchmarks, and we say so rather than
implying otherwise.

> A note on Django. It is a web framework, not a CMS. Comparing a finished CMS against
> raw Django compares different things, so the comparison uses Wagtail, the CMS that
> Django teams actually ship. Calling Django a CMS would be the kind of error that loses
> the reader's trust for the rest of the table.

---

## Scorecard

Six of the original rows turned out to measure the same thing twice, so they are gone.
"Loading an untrusted plugin" was security again. "Full Persian without plugins" was
Persian and RTL again. "No vendor lock-in" was data ownership again. "Upfront cost" and
"ongoing cost" became one row. "Extensibility" scored 5 while the ecosystem row scored 2,
which is a contradiction rather than a result.

Sixteen criteria, no overlaps.

| Criterion | Pishdad | WordPress | Django + Wagtail |
|---|:---:|:---:|:---:|
| Security | `★★★★★` | `★★☆☆☆` | `★★★★★` |
| Built-in SEO | `★★★★★` | `★★★★☆` | `★★★☆☆` |
| Persian and RTL | `★★★★★` | `★★★☆☆` | `★★☆☆☆` |
| Code quality and testing | `★★★★★` | `★★★★☆` | `★★★★☆` |
| Attack surface | `★★★★★` | `★★☆☆☆` | `★★★★☆` |
| Cross-platform capability | `★★★★★` | `★★☆☆☆` | `★★★★☆` |
| Developer interface | `★★★★★` | `★★☆☆☆` | `★★★★☆` |
| API for a mobile app | `★★★★★` | `★★☆☆☆` | `★★★★☆` |
| Documentation | `★★★★☆` | `★★★★★` | `★★★★☆` |
| Patch speed | `★★★★☆` | `★★★★☆` | `★★★★★` |
| Scalability | `★★★★☆` | `★★★★☆` | `★★★★★` |
| Page speed | `★★★★☆` | `★★★☆☆` | `★★★★☆` |
| Talent availability | `★★★★☆` | `★★★★☆` | `★★★★☆` |
| Cost, upfront and ongoing | `★★★★☆` | `★★★☆☆` | `★★★☆☆` |
| Server resource use | `★★★☆☆` | `★★★★★` | `★★★★★` |
| Ready-made ecosystem | `★★☆☆☆` | `★★★★★` | `★★★☆☆` |
| **Total** | **69 / 80** | **54 / 80** | **63 / 80** |

---

## Pishdad against WordPress

| Criterion | Pishdad (Laravel + Next.js, open source) | WordPress |
|---|---|---|
| **Cost** | `★★★★☆` — no licence fee, but a single maintainer is also a risk | `★★★☆☆` — free core, then hosting, theme and a security plugin |
| **Security** | `★★★★★` — every plugin is Ed25519-signed and verified before it runs | `★★☆☆☆` — the core is fine, but each plugin is an attack surface |
| **Undiscovered bug risk** | `★★☆☆☆` — a small codebase is easier to reason about, but no field data | `★★★★☆` — the big bugs have been found and fixed over years |
| **Page speed** | `★★★★☆` — API-first with SSR and SSG, fonts served locally | `★★★☆☆` — fast with a caching plugin |
| **Server resource use** | `★★★☆☆` — two services, so there is some overhead | `★★★★★` — one install, lighter |
| **Resource use as traffic grows** | `★★★★★` — stateless backend scales out more easily | `★★☆☆☆` — under real load, hosting gets expensive |
| **SEO tooling** | `★★★★★` — sitemap, robots, `llms.txt` and search in the core | `★★★★☆` — excellent, but via Yoast or RankMath |
| **Persian and RTL** | `★★★★★` — from the first commit, Jalali dates included | `★★★☆☆` — works with the right theme and translations |
| **Cross-platform capability** | `★★★★★` — 135 typed API endpoints | `★★☆☆☆` — REST is an add-on and heavier |
| **Ready-made ecosystem** | `★★☆☆☆` — stable API, few third-party plugins so far | `★★★★★` — the largest ecosystem in the world |
| **Code quality and testing** | `★★★★★` — 489 tests across 67 files | `★★★★☆` — strong core, uneven plugins |
| **Attack surface** | `★★★★★` — small core, no mandatory plugin | `★★☆☆☆` — every install runs dozens of unreviewed packages |
| **Loading an untrusted plugin** | `★★★★★` — verified before execution, not after | `★☆☆☆☆` — you find out after it has already run |
| **Developer interface** | `★★★★★` — OpenAPI 3.1, client types generated into the frontend | `★★☆☆☆` — no formal contract |
| **Security patch speed** | `★★★★☆` — a focused team decides fast | `★★★★★` — a professional security team, regular and predictable |
| **Documentation** | `★★★★☆` — 20 technical documents, including the full plugin contract | `★★★★★` — official docs plus a large third-party library |
| **Hiring specialist talent** | `★★★★☆` — Laravel and Next.js are the most common stack in the market | `★★★★☆` — a large pool, but few true specialists |
| **Scalability** | `★★★★☆` — right architecture, untested under real load | `★★★★☆` — very large sites do run on it |
| **Mobile app API** | `★★★★★` — built for this from day one | `★★☆☆☆` — needs an add-on |

**69 / 80 against 54 / 80.**

---

## Where another system is the better choice

A comparison that never says no is an advertisement.

- **Large, heavily structured enterprise sites.** Drupal's maturity under load is real
  and Pishdad has not been there.
- **An existing WordPress estate you already maintain.** Plugins, themes, and staff
  knowledge. Replacing a working site is a bad trade.
- **Approval chains and formal governance.** Both platforms have a decade of that
  solved. Pishdad does not.
- **Non-Persian languages as the primary requirement.** Persian is Pishdad's first-class
  language. Other languages are possible and untested.
- **A large talent pool you already have.** If your team is WordPress or Django people,
  that expertise has value the table does not capture.

## How the scores were arrived at

Page speed, scalability and the ecosystem are scored from the architecture rather than
from measurement, and the mechanism behind each is in this document. Everything else is
read off the two systems' published documentation and their plugin architectures.

## Verdict

Pishdad wins on what it was built for: correct Persian and RTL, a plugin system that
verifies before it executes, SEO tooling in the box, and licence cost.

Read the architecture rows as design decisions rather than measurements. WordPress and
Django have years of production evidence behind them, and that evidence is worth a great
deal.

If you need those years, use them. If you want a Persian-first CMS that runs on your own
server and asks for no licence, Pishdad is the one to try.

---

**فارسی:** [مقایسهٔ کامل به زبان فارسی](COMPARISON.fa.md) ·
**English guide:** [README.md](README.md)
