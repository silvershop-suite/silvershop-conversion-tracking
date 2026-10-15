<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Job;

use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Provider\ConversionProviderRegistry;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Performs the server-to-server send of one {@see ConversionEvent} (Meta Conversions API / Google
 * Enhanced-Conversions upload) as a retryable background job, visible and auditable in QueuedJobAdmin — the reason
 * symbiote/silverstripe-queuedjobs is a hard requirement of this module. One job per server-dispatched event;
 * {@see \SilverShop\ConversionTracking\Service\ConversionEventService::dispatchServerSide()} enqueues it (v1: for
 * Purchase, when a server-capable connector is active).
 *
 * The event rides as a plain array ({@see ConversionEvent::toArray()}) so the job payload serialises cleanly and a
 * webhook re-send reuses the same deterministic `event_id` — the pixel and every server send therefore de-duplicate.
 * The consent granted at mint is frozen onto the event, and each connector re-checks its category before sending,
 * so a request-less (webhook) dispatch without a known consent state is a graceful no-op (default-deny).
 *
 * @property array<string, mixed> $EventData
 */
class ConversionDispatchJob extends AbstractQueuedJob
{
    // retry_max_attempts / retry_initial_delay / retry_falloff_multiplier are set in
    // _config/conversion-tracking.yml so a transient CAPI/network failure is retried with a growing delay.

    public function __construct(?ConversionEvent $event = null)
    {
        if ($event instanceof ConversionEvent) {
            $this->EventData = $event->toArray();
        }
    }

    public function getTitle(): string
    {
        $data = is_array($this->EventData) ? $this->EventData : [];

        return _t(
            self::class . '.TITLE',
            'Dispatch conversion event "{event}" ({id}) to ad platforms',
            [
                'event' => (string) ($data['name'] ?? 'unknown'),
                'id' => (string) ($data['eventId'] ?? ''),
            ]
        );
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $providers = ConversionProviderRegistry::create()->activeServerSide();
        if ($providers === [] || !is_array($this->EventData)) {
            // Nothing to send (no server-capable connector, or an empty payload) — successful no-op.
            $this->isComplete = true;
            return;
        }

        $event = ConversionEvent::fromArray($this->EventData);

        $failures = [];
        foreach ($providers as $provider) {
            if (!$provider->sendServerSide($event)) {
                $failures[] = $provider->getKey();
            }
        }

        if ($failures !== []) {
            // Let queuedjobs mark the job broken + retry per the retry_* config.
            throw new \RuntimeException(sprintf(
                'Server-side conversion dispatch failed for: %s (event %s)',
                implode(', ', $failures),
                (string) ($this->EventData['eventId'] ?? '')
            ));
        }

        $this->addMessage(sprintf(
            'Dispatched conversion event %s to %d connector(s)',
            (string) ($this->EventData['eventId'] ?? ''),
            count($providers)
        ));
        $this->isComplete = true;
    }
}
