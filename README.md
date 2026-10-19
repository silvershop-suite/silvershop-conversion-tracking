# SilverShop Conversion Tracking

Ad conversion / event tracking **core** for SilverShop (SilverStripe CMS 6). It mints a single, normalised
ecommerce event at each shop lifecycle seam — **ViewContent → AddToCart → InitiateCheckout → Purchase** — and fans
it out:

- an **always-on, GA4-shaped `dataLayer`** push (so a GTM-managed shop wires its own tags and the core stays out of
  the way), and
- each installed **connector**'s first-party tag (Meta Pixel / Google `gtag`) **plus** its server-to-server path
  (Meta Conversions API / Google Enhanced Conversions), through a retryable queued job.

Everything is **hard-gated on consent** (bound to `silvershop-suite/cookieconsent`), the browser event and the server
event share a **deterministic `event_id`** so Meta/Google de-duplicate the pair, and product ids are the **shared
feed id** (`silvershop-suite/catalog-data`), so the ad-platform catalogue match lines up with the product feed by
construction.

This module is the **core**. The per-platform clients ship separately:

- [`silvershop-suite/conversion-tracking-meta`](https://github.com/silvershop/silvershop-conversion-tracking-meta) — Meta
  Pixel + Conversions API.
- [`silvershop-suite/conversion-tracking-google`](https://github.com/silvershop/silvershop-conversion-tracking-google) —
  GA4 + Google Ads + Enhanced Conversions.

It mirrors the core+connector architecture of `silvershop-suite/newsletter` (+ its ESP adapters) and `silvershop-suite/search`
(+ `search-meilisearch`).

## Requirements

- PHP ^8.3
- `silverstripe/framework` ^6, `silverstripe/cms` ^6
- `silvershop/core` ^6.2
- `symbiote/silverstripe-queuedjobs` ^6 — the server-side dispatch runs as a retryable queued job

Strongly recommended / optional (all read behind `class_exists` guards):

- **`silvershop-suite/cookieconsent`** — the consent gate + Consent Mode v2 source every tag/event binds to. **Without a
  consent source the module default-denies** (fires nothing) unless you opt in (see *Consent* below).
- **`silvershop-suite/catalog-data`** — the shared product identity (`content_ids` / `item_id` = `feedId()`). A built-in
  identical-rule fallback is used when absent.
- **`silvershop-suite/order-confirmation`** — the unified, status-aware `checkout/complete/{ID}` success render the
  client-side Purchase fires on.

## Install

```bash
composer require silvershop-suite/conversion-tracking
```

Install at least one connector and supply its env ids/token, then switch it on in config (the connectors register
themselves — see their READMEs). With no connector, the core still emits the GA4 `dataLayer` for a GTM shop.

## How it works

### The normalised event

Every seam mints a platform-neutral `ConversionEvent` (`src/Model/`):

| Field | Notes |
|---|---|
| `name` | `EventName` enum → Meta `ViewContent`/… and GA4 `view_item`/… |
| `eventId` | the shared client↔server dedup id; **`purchase.{OrderID}`** for Purchase |
| `items[]` | `ConversionItem` — `id` = feed id, `itemGroupId`, `name`, `price`, `quantity`, `brand`, `gtin` |
| `value` / `currency` | order/line value + ISO-4217 currency (`get_site_currency()`) |
| `contentType` | `product` / `product_group` |
| `user` | raw `ConversionUser` matching data — hashing/forwarding is the **connector's** job; never on the `dataLayer` |
| `consentedCategories` | the cookieconsent categories granted at mint, frozen on for the queued server send |

### The lifecycle seams (no theme edits)

| Event | Seam |
|---|---|
| ViewContent / `view_item` | `ProductController::onAfterInit` (bare product page) |
| AddToCart / `add_to_cart` | `Order::afterAdd` — **deferred**: queued in session, drained on the next render (the add action redirects/AJAX-returns) |
| InitiateCheckout / `begin_checkout` | `CheckoutPageController::updateOrderForm` (checkout form render) |
| Purchase (server truth) | `Order::onPaid` — fires once on the paid transition (the webhook truth for async iDEAL/Mollie), enqueues the dispatch job |
| Purchase (client) | the confirmed-paid confirmation render (`checkout/complete/{ID}` / `order`), access-gated + `IsPaid()` only — never pending |

All tags are injected via a `ContentController` extension + `Requirements` (exactly how `silvershop-suite/cookieconsent`
injects) — **the module never edits a theme template**.

### Deduplication

The browser Pixel/gtag Purchase and the queued server Purchase both carry `event_id = purchase.{OrderID}` (and GA4
`transaction_id = {OrderID}`), so Meta and Google collapse the pair into one conversion. A webhook re-send of
`onPaid` reuses the same id. This is the single most important correctness detail — a mismatched id double-counts
Purchase.

### Product identity = the feed id

`content_ids` / GA4 `item_id` = `SilverShop\CatalogData\CatalogProductDataFactory::feedId()` when
`silvershop-suite/catalog-data` is installed (so the feed's `g:id` and the ad `content_ids` are the same string by
construction). When catalog-data is absent, a built-in fallback mints the id by the **identical rule** (SKU-first,
`product-{ID}` / `{parentFeedId}-{variationID}`, ≤50 chars), so the two never diverge.

## Consent (bound to cookieconsent — not reinvented)

Nothing fires before consent. The module is a **consumer** of `silvershop-suite/cookieconsent`:

- **PHP gate** — `ConsentState::has('marketing' | 'statistics')` decides whether a tag/event may fire and whether a
  server send runs.
- **Prior script-blocking** — client tags are emitted through `ConsentScript::inline()/src()` as inert
  `<script type="text/plain" data-consent-category="…">`, which the cookieconsent runtime activates on grant and on
  every `consentchange`. True ePrivacy prior opt-in: nothing fetches/fires pre-consent.
- **Consent Mode v2** — cookieconsent already emits `gtag('consent','default', …denied)` and the `update` on a
  decision, mapping `marketing → ad_storage/ad_user_data/ad_personalization` and `statistics → analytics_storage`.
  This module relies on that and does **not** reinvent it.

Category bindings: **Meta → `marketing`**, **GA4 → `statistics`**, **Google Ads → `marketing`**. The always-on
`dataLayer` push is gated on `statistics` by default (`ConversionEventService.datalayer_consent_category`).

**Default-deny when there is no consent source.** With `silvershop-suite/cookieconsent` absent, the module fires nothing
unless you explicitly accept responsibility for consent elsewhere:

```yaml
SilverShop\ConversionTracking\Service\ConsentGate:
  assume_consent_without_module: true   # default false (GDPR-safe)
```

> **Consent Mode SSR-default coordination note.** `silvershop-suite/cookieconsent` only emits the server-side
> `gtag('consent','default', …denied)` in `<head>` when the `GA_CODE` or `GTM_CODE` env var is set; otherwise the
> default-denied is emitted by its JS runtime (always, before any tag activates). For a belt-and-suspenders SSR
> Consent-Mode default without relying on the JS runtime, set one of those env vars. This module does **not** edit
> cookieconsent and does **not** emit a competing default.

## Writing a connector

Implement `SilverShop\ConversionTracking\Provider\ConversionProvider` (see the Meta/Google connectors) and register
it:

```yaml
SilverShop\ConversionTracking\Provider\ConversionProviderRegistry:
  providers:
    meta: '%$SilverShop\ConversionTracking\Meta\MetaProvider'
  active:
    - meta
```

A provider turns a `ConversionEvent` into that platform's consent-gated client snippet(s) + base tag + its
server-side send, and declares its `consentCategory()` and `mode()` (`datalayer_only` | `built_in_tags` | `both`,
default `both`). An active-but-unconfigured provider is skipped, so the module is inert until its env is supplied.

## Configuration highlights

```yaml
SilverShop\ConversionTracking\Service\ConversionEventService:
  datalayer_consent_category: statistics   # which category gates the always-on dataLayer push
  always_datalayer: true                   # emit the GA4 dataLayer regardless of connector mode
  server_dispatch_events: [purchase]       # which events get a server-side dispatch job (v1: Purchase)

SilverShop\ConversionTracking\Service\ConsentGate:
  assume_consent_without_module: false     # fire with no consent module? (GDPR-safe default: no)

SilverShop\ConversionTracking\Service\ConversionItemFactory:
  id_source: internal_item_id              # fallback-only; mirrors catalog-data (keep aligned)
```

## Privacy / GDPR (read before going live)

- **PII** (email/phone/name/address) is sent **only server-side** (Meta CAPI hashes it SHA-256; Google Enhanced
  Conversions receives it raw and hashes client-side), and **only with `marketing` consent**. It is **never** put on
  the browser `dataLayer`.
- Data is sent to **Meta (US)** and/or **Google (US)** — a processor relationship your privacy policy must declare
  (DPA + SCCs). State the data-sharing clearly.
- `_fbp` / `_fbc` are marketing cookies — declare them under cookieconsent's `marketing` category.
- cookieconsent already writes immutable consent-proof records; no extra consent logging is added here.

## Development

```bash
composer install
composer lint    # PHP_CodeSniffer (PSR-12)
composer stan    # PHPStan (silverstan, level 5)
composer test    # PHPUnit
```

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
