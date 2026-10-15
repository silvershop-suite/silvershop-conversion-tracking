<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Job\ConversionDispatchJob;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Provider\ConversionProviderRegistry;
use SilverShop\ConversionTracking\Service\ConsentGate;
use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * The orchestrator: it enqueues a server dispatch only for configured events when a server-capable connector is
 * active, and it freezes the consent state onto an event at mint.
 */
class ConversionEventServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function activateFakeServer(): FakeConversionProvider
    {
        ConversionProviderRegistry::config()->set('providers', ['fake' => FakeConversionProvider::class]);
        ConversionProviderRegistry::config()->set('active', ['fake']);
        /** @var FakeConversionProvider $fake */
        $fake = Injector::inst()->get(FakeConversionProvider::class);
        $fake->reset();
        $fake->configured = true;
        $fake->serverSide = true;

        return $fake;
    }

    private function event(EventName $name): ConversionEvent
    {
        return new ConversionEvent(
            name: $name,
            eventId: $name->value . '.1',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
            orderID: 1,
            consentedCategories: ['marketing'],
        );
    }

    private function jobCount(): int
    {
        return QueuedJobDescriptor::get()->filter('Implementation', ConversionDispatchJob::class)->count();
    }

    public function testPurchaseEnqueuesDispatchJobWhenServerConnectorActive(): void
    {
        $this->activateFakeServer();
        ConversionEventService::create()->dispatchServerSide($this->event(EventName::PURCHASE));
        $this->assertSame(1, $this->jobCount());
    }

    public function testNonConfiguredEventDoesNotEnqueue(): void
    {
        $this->activateFakeServer();
        // Default server_dispatch_events is ['purchase'] → a view does not enqueue.
        ConversionEventService::create()->dispatchServerSide($this->event(EventName::VIEW_CONTENT));
        $this->assertSame(0, $this->jobCount());
    }

    public function testNoEnqueueWithoutServerConnector(): void
    {
        ConversionProviderRegistry::config()->set('providers', ['fake' => FakeConversionProvider::class]);
        ConversionProviderRegistry::config()->set('active', []);
        ConversionEventService::create()->dispatchServerSide($this->event(EventName::PURCHASE));
        $this->assertSame(0, $this->jobCount());
    }

    public function testStampConsentFreezesGrantedCategories(): void
    {
        // Force the assume-consent path so granted categories are deterministic without cookieconsent state.
        Config::modify()->set(ConsentGate::class, 'consent_state_class', '\\NoConsentModule');
        Config::modify()->set(ConsentGate::class, 'assume_consent_without_module', true);

        $stamped = ConversionEventService::create()->stampConsent(
            new ConversionEvent(EventName::PURCHASE, 'purchase.1', orderID: 1)
        );

        $this->assertTrue($stamped->hasConsent('marketing'));
        $this->assertTrue($stamped->hasConsent('statistics'));
    }
}
