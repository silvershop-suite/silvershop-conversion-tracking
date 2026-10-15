<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Provider\ConversionProvider;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * In-memory stand-in for a platform connector — records the events it is asked to emit/send so the registry,
 * service and dispatch job can be tested without any real HTTP or pixel. Mirrors the newsletter module's
 * FakeRemoteProvider. It replicates a real connector's consent discipline: {@see self::sendServerSide()} only
 * records a send when the event carries consent for its category, so the server-side consent gate is testable.
 */
class FakeConversionProvider implements ConversionProvider, TestOnly
{
    use Injectable;

    /** @var array<int, ConversionEvent> events actually sent server-side */
    public array $serverSends = [];

    /** @var array<int, ConversionEvent> events offered to clientSnippets() */
    public array $clientEvents = [];

    public bool $configured = true;

    public string $modeValue = ConversionProvider::MODE_BOTH;

    public bool $serverSide = true;

    public bool $serverResult = true;

    public string $category = 'marketing';

    public string $keyValue = 'fake';

    public function reset(): void
    {
        $this->serverSends = [];
        $this->clientEvents = [];
    }

    public function getKey(): string
    {
        return $this->keyValue;
    }

    public function getName(): string
    {
        return 'Fake connector';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function mode(): string
    {
        return $this->modeValue;
    }

    public function clientSnippets(ConversionEvent $event): array
    {
        $this->clientEvents[] = $event;
        $html = '<script type="text/plain" data-consent-category="' . $this->category . '">'
            . '/* fake ' . $event->name->value . ' */</script>';

        return [DBHTMLText::create()->setValue($html)];
    }

    public function baseTagSnippets(): array
    {
        return [DBHTMLText::create()->setValue(
            '<script type="text/plain" data-consent-category="' . $this->category . '">/* fake base */</script>'
        )];
    }

    public function supportsServerSide(): bool
    {
        return $this->serverSide;
    }

    public function sendServerSide(ConversionEvent $event): bool
    {
        // A real connector re-checks consent for its category before sending; a denied event is a silent no-op.
        if (!$event->hasConsent($this->consentCategory($event->name))) {
            return true;
        }
        $this->serverSends[] = $event;

        return $this->serverResult;
    }

    public function consentCategory(EventName $name): string
    {
        return $this->category;
    }
}
