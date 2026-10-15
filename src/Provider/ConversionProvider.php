<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Provider;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;

/**
 * The published seam between the conversion-tracking core and one ad platform (Meta, Google, …) — the conversion
 * analogue of silvershop/newsletter's {@see \SilverShop\Newsletter\Provider\NewsletterProvider} and
 * silvershop/search's SearchBackend. The core owns the normalised {@see ConversionEvent}, the consent gate, the
 * lifecycle seams, the always-on `dataLayer` emit and the queued dispatch framework; a provider is the thin client
 * that turns an event into THAT platform's client tag(s) and server payload.
 *
 * Connectors live in their own module (silvershop/conversion-tracking-meta, …-google) so a shop pulls only the
 * platform clients it uses. Each registers in config against a stable key and is marked active; it only takes
 * effect once {@see self::isConfigured()} is true (its pixel/measurement id + token are present in the env).
 */
interface ConversionProvider
{
    /**
     * Emit the GA4-shaped `dataLayer` push only (a GTM-managed shop wires its own tags); inject no first-party tag.
     */
    public const MODE_DATALAYER_ONLY = 'datalayer_only';

    /**
     * Inject the platform's own first-party tag (Pixel / gtag) + run the server-side path; no extra `dataLayer`.
     */
    public const MODE_BUILT_IN_TAGS = 'built_in_tags';

    /**
     * Both: the always-on `dataLayer` push AND this platform's first-party tag + server-side path. The default.
     */
    public const MODE_BOTH = 'both';

    /**
     * Short, stable key used in config + the registry map (e.g. 'meta', 'google').
     */
    public function getKey(): string;

    /**
     * Human-readable name, e.g. 'Meta (Facebook / Instagram)'.
     */
    public function getName(): string;

    /**
     * Whether the provider has the ids / credentials it needs (pixel/measurement id + token in the env). An
     * unconfigured provider is skipped entirely, so the module is inert until a shop supplies its env.
     */
    public function isConfigured(): bool;

    /**
     * One of the MODE_* constants: whether this connector injects its first-party tag, emits only the dataLayer, or
     * both. Defaults to {@see self::MODE_BOTH}.
     */
    public function mode(): string;

    /**
     * The inert, consent-gated client `<script>` tag(s) for ONE event (Pixel `track` / `gtag` ecommerce call),
     * already wrapped by {@see \SilverShop\ConversionTracking\Service\ConsentGate} so they are prior-blocked until
     * the viewer grants {@see self::consentCategory()}. Empty when this connector emits nothing client-side for the
     * event (e.g. mode datalayer_only, or an event this platform does not track).
     *
     * @return array<int, \SilverStripe\ORM\FieldType\DBHTMLText>
     */
    public function clientSnippets(ConversionEvent $event): array;

    /**
     * The once-per-page bootstrap tag(s): the Pixel base init / the `gtag` loader, consent-gated. Empty in
     * mode datalayer_only or when unconfigured.
     *
     * @return array<int, \SilverStripe\ORM\FieldType\DBHTMLText>
     */
    public function baseTagSnippets(): array;

    /**
     * Whether this connector has any server-to-server path at all (Meta CAPI: true; Google v1 gtag-only: false).
     */
    public function supportsServerSide(): bool;

    /**
     * Perform the server-to-server send for ONE event (Meta Conversions API / Google Enhanced Conversions upload).
     * Runs inside the retryable {@see \SilverShop\ConversionTracking\Job\ConversionDispatchJob}. Return true when a
     * send was made (or correctly skipped), false to signal a failure the job should retry. A connector returns
     * true (a no-op) for events it does not send server-side, and must itself re-check
     * {@see ConversionEvent::hasConsent()} for its category before sending.
     */
    public function sendServerSide(ConversionEvent $event): bool;

    /**
     * The cookieconsent category that gates a given event for this platform: Meta → 'marketing', GA4 →
     * 'statistics', Google Ads → 'marketing'. Used both to prior-block the client tag and to decide the
     * server-side send.
     */
    public function consentCategory(EventName $name): string;
}
