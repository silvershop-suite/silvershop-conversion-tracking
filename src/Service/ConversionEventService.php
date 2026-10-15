<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Service;

use SilverShop\ConversionTracking\Job\ConversionDispatchJob;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Provider\ConversionProvider;
use SilverShop\ConversionTracking\Provider\ConversionProviderRegistry;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\Requirements;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * The core orchestrator. Every lifecycle seam mints a {@see ConversionEvent} and hands it here; the service
 *
 *  1. STAMPS the consent state granted right now onto the event ({@see ConsentGate::grantedCategories()}), so the
 *     later queued server send stays GDPR-correct without a request;
 *  2. EMITS the client side — the always-on GA4 `dataLayer` push plus each active connector's consent-gated
 *     tag(s) — either inline this request, or onto a session queue drained on the next render (the AddToCart
 *     deferred-emit, because the add action redirects/AJAX-returns before anything can render);
 *  3. ENQUEUES a retryable {@see ConversionDispatchJob} for the server-to-server path when a server-capable
 *     connector is active and the event is one of the configured {@see self::$server_dispatch_events}.
 *
 * It owns no platform knowledge — it only drives the registry's active {@see ConversionProvider}s.
 */
class ConversionEventService
{
    use Injectable;
    use Configurable;

    /**
     * The cookieconsent category that gates the always-on `dataLayer` push (GA4 ecommerce is analytics data).
     * Connector first-party tags gate on their own {@see ConversionProvider::consentCategory()}.
     */
    private static string $datalayer_consent_category = 'statistics';

    /**
     * Whether the core always pushes the GA4-shaped `dataLayer` (even with no connector, or a connector in
     * built_in_tags mode). Recommended on — a GTM-managed shop relies on it.
     */
    private static bool $always_datalayer = true;

    /**
     * The events for which a server-side dispatch job is enqueued (when a server-capable connector is active).
     * v1 = Purchase only (Meta CAPI for Purchase); widen in v2.
     *
     * @var array<int, string>
     */
    private static array $server_dispatch_events = ['purchase'];

    /**
     * Session key holding the deferred (next-render) client event queue.
     */
    private static string $session_queue_key = 'SilverShop.ConversionEvents';

    private ConsentGate $consent;

    private DataLayerEmitter $dataLayer;

    public function __construct(?ConsentGate $consent = null, ?DataLayerEmitter $dataLayer = null)
    {
        $this->consent = $consent ?? ConsentGate::create();
        $this->dataLayer = $dataLayer ?? DataLayerEmitter::create();
    }

    /**
     * The common path for the same-request / deferred client seams (ViewContent, AddToCart, InitiateCheckout):
     * stamp consent, emit the client side, and enqueue any server dispatch.
     */
    public function track(ConversionEvent $event, bool $deferred = false): void
    {
        $event = $this->stampConsent($event);
        $this->clientEmit($event, $deferred);
        $this->dispatchServerSide($event);
    }

    /**
     * Freeze the currently-granted consent categories onto the event.
     */
    public function stampConsent(ConversionEvent $event): ConversionEvent
    {
        return new ConversionEvent(
            $event->name,
            $event->eventId,
            $event->items,
            $event->value,
            $event->currency,
            $event->contentType,
            $event->user,
            $event->sourceUrl,
            $event->occurredAt,
            $event->orderID,
            $this->consent->grantedCategories(),
        );
    }

    /**
     * Emit the client side of an event: inline this request, or (deferred) onto the session queue for the next
     * render. The dataLayer push carries no PII and the connector tags are consent-gated inert scripts.
     */
    public function clientEmit(ConversionEvent $event, bool $deferred = false): void
    {
        if ($deferred) {
            $this->enqueueDeferred($event);
            return;
        }

        $this->inject($event);
    }

    /**
     * Inject the once-per-page connector bootstrap tags (Pixel base / gtag loader), consent-gated. Called from the
     * ContentController seam on every page.
     */
    public function emitBaseTags(): void
    {
        foreach ($this->registry()->active() as $provider) {
            if ($provider->mode() === ConversionProvider::MODE_DATALAYER_ONLY) {
                continue;
            }
            $html = $this->joinSnippets($provider->baseTagSnippets());
            if ($html !== '') {
                Requirements::insertHeadTags($html, 'SilverShopCT-base-' . $provider->getKey());
            }
        }
    }

    /**
     * Drain the deferred client queue (events stashed on a previous request, e.g. AddToCart) into the page.
     */
    public function drainQueue(): void
    {
        $request = $this->currentRequest();
        if (!$request instanceof HTTPRequest) {
            return;
        }
        $session = $request->getSession();
        $key = (string) static::config()->get('session_queue_key');
        $queued = $session->get($key);
        if (!is_array($queued) || $queued === []) {
            return;
        }
        $session->clear($key);

        foreach ($queued as $data) {
            if (is_array($data)) {
                $this->inject(ConversionEvent::fromArray($data));
            }
        }
    }

    /**
     * Enqueue the retryable server-side dispatch job when a server-capable connector is active and this event is
     * configured for server dispatch. A no-op otherwise (keeps the queue clean on client-only installs).
     */
    public function dispatchServerSide(ConversionEvent $event): void
    {
        if (!in_array($event->name->value, (array) static::config()->get('server_dispatch_events'), true)) {
            return;
        }
        if ($this->registry()->activeServerSide() === []) {
            return;
        }

        QueuedJobService::singleton()->queueJob(new ConversionDispatchJob($event));
    }

    private function inject(ConversionEvent $event): void
    {
        $fragments = [];

        if ((bool) static::config()->get('always_datalayer') && $event->name->isEcommerce()) {
            $fragments[] = $this->consent->script(
                $this->dataLayer->pushScript($event),
                (string) static::config()->get('datalayer_consent_category')
            );
        }

        foreach ($this->registry()->active() as $provider) {
            if ($provider->mode() === ConversionProvider::MODE_DATALAYER_ONLY) {
                continue;
            }
            foreach ($provider->clientSnippets($event) as $snippet) {
                $fragments[] = $snippet;
            }
        }

        $html = $this->joinSnippets($fragments);
        if ($html !== '') {
            Requirements::insertHeadTags(
                $html,
                'SilverShopCT-' . $event->name->value . '-' . md5($event->eventId)
            );
        }
    }

    private function enqueueDeferred(ConversionEvent $event): void
    {
        $request = $this->currentRequest();
        if (!$request instanceof HTTPRequest) {
            return;
        }
        $session = $request->getSession();
        $key = (string) static::config()->get('session_queue_key');
        $queue = $session->get($key);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $event->toArray();
        $session->set($key, $queue);
    }

    /**
     * @param array<int, DBHTMLText> $snippets
     */
    private function joinSnippets(array $snippets): string
    {
        $html = '';
        foreach ($snippets as $snippet) {
            $value = trim((string) $snippet->getValue());
            if ($value !== '') {
                $html .= $value;
            }
        }

        return $html;
    }

    private function registry(): ConversionProviderRegistry
    {
        return ConversionProviderRegistry::create();
    }

    private function currentRequest(): ?HTTPRequest
    {
        if (Director::is_cli()) {
            return null;
        }
        $controller = Controller::curr();

        return $controller ? $controller->getRequest() : null;
    }
}
