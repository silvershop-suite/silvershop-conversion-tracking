<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Job\ConversionDispatchJob;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Provider\ConversionProviderRegistry;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The server-side dispatch job: it carries the event payload intact, sends it through the active server-capable
 * connectors, and honours the consent frozen onto the event (a connector no-ops its category when it was not
 * granted at mint).
 */
class ConversionDispatchJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function useFake(): FakeConversionProvider
    {
        ConversionProviderRegistry::config()->set('providers', ['fake' => FakeConversionProvider::class]);
        ConversionProviderRegistry::config()->set('active', ['fake']);

        /** @var FakeConversionProvider $fake */
        $fake = Injector::inst()->get(FakeConversionProvider::class);
        $fake->reset();
        $fake->configured = true;
        $fake->serverSide = true;
        $fake->serverResult = true;

        return $fake;
    }

    private function purchaseEvent(array $consented = ['marketing']): ConversionEvent
    {
        return new ConversionEvent(
            name: EventName::PURCHASE,
            eventId: 'purchase.42',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
            orderID: 42,
            consentedCategories: $consented,
        );
    }

    public function testPayloadSurvivesSerialisation(): void
    {
        $job = new ConversionDispatchJob($this->purchaseEvent());
        $this->assertSame('purchase.42', $job->EventData['eventId']);
        $this->assertSame('purchase', $job->EventData['name']);
        $this->assertSame('RJ-001', $job->EventData['items'][0]['id']);
        $this->assertStringContainsString('purchase.42', $job->getTitle());
    }

    public function testProcessSendsThroughActiveServerConnector(): void
    {
        $fake = $this->useFake();

        $job = new ConversionDispatchJob($this->purchaseEvent(['marketing']));
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertCount(1, $fake->serverSends);
        $sent = $fake->serverSends[0];
        $this->assertSame('purchase.42', $sent->eventId);
        $this->assertSame(12.95, $sent->value);
        $this->assertSame('RJ-001', $sent->contentIds()[0]);
    }

    public function testConsentGateStopsServerSendWhenCategoryNotGranted(): void
    {
        $fake = $this->useFake();

        // Marketing was NOT granted at mint (e.g. a webhook with no cookie) → the connector must send nothing.
        $job = new ConversionDispatchJob($this->purchaseEvent([]));
        $job->process();

        $this->assertTrue($job->jobFinished(), 'A consent-denied dispatch is a graceful no-op');
        $this->assertCount(0, $fake->serverSends);
    }

    public function testProcessNoOpsWithoutServerConnector(): void
    {
        ConversionProviderRegistry::config()->set('providers', ['fake' => FakeConversionProvider::class]);
        ConversionProviderRegistry::config()->set('active', []);

        $job = new ConversionDispatchJob($this->purchaseEvent());
        $job->process();

        $this->assertTrue($job->jobFinished(), 'No active server connector → successful no-op');
    }

    public function testFailedSendThrowsForRetry(): void
    {
        $fake = $this->useFake();
        $fake->serverResult = false;

        $job = new ConversionDispatchJob($this->purchaseEvent(['marketing']));
        $this->expectException(\RuntimeException::class);
        $job->process();
    }
}
