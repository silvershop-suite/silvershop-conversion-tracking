<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\Service\ConsentGate;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The hard consent gate. Covers the three states deterministically: cookieconsent absent + default-deny (nothing
 * emitted), cookieconsent absent + assume-consent (emitted live), and cookieconsent present (fail-closed before a
 * decision; every client tag prior-blocked as an inert `type="text/plain"` tag with the right category). The real
 * grant/deny with the cookieconsent JS runtime is covered by the live browser verify.
 */
class ConsentGateTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function forceAbsent(): void
    {
        Config::modify()->set(ConsentGate::class, 'consent_state_class', '\\NoConsentModule');
    }

    public function testDefaultDenyWhenCookieconsentAbsent(): void
    {
        $this->forceAbsent();
        Config::modify()->set(ConsentGate::class, 'assume_consent_without_module', false);
        $gate = ConsentGate::create();

        $this->assertFalse($gate->available());
        $this->assertFalse($gate->isActive('marketing'));
        $this->assertFalse($gate->isActive('statistics'));
        $this->assertSame([], $gate->grantedCategories());
        $this->assertSame('', trim((string) $gate->script('fbq("track","Purchase");', 'marketing')->getValue()));
        $this->assertSame('', trim((string) $gate->src('https://example.com/p.js', 'marketing')->getValue()));
    }

    public function testAssumeConsentWithoutModuleEmitsLive(): void
    {
        $this->forceAbsent();
        Config::modify()->set(ConsentGate::class, 'assume_consent_without_module', true);
        $gate = ConsentGate::create();

        $this->assertTrue($gate->isActive('marketing'));
        $this->assertSame(['marketing', 'statistics'], $gate->grantedCategories());

        $script = (string) $gate->script('fbq("track","Purchase");', 'marketing')->getValue();
        $this->assertStringContainsString('<script>', $script);
        $this->assertStringNotContainsString('text/plain', $script);
        $this->assertStringContainsString('fbq("track","Purchase");', $script);

        $src = (string) $gate->src('https://example.com/p.js', 'marketing', ['async' => true])->getValue();
        $this->assertStringContainsString('src="https://example.com/p.js"', $src);
        $this->assertStringContainsString('async', $src);
    }

    public function testWithCookieconsentInstalledTagsArePriorBlockedAndFailClosed(): void
    {
        if (!class_exists('SilverShop\\CookieConsent\\ConsentState')) {
            $this->markTestSkipped('silvershop/cookieconsent is not installed.');
        }
        // Default config → the real consent class is used.
        $gate = ConsentGate::create();
        $this->assertTrue($gate->available());

        // Before any decision, a non-required category is denied (fail-closed) → nothing fires.
        $this->assertFalse($gate->isActive('marketing'));
        $this->assertNotContains('marketing', $gate->grantedCategories());

        // The client tag is emitted but prior-blocked: an inert text/plain script carrying the category, which the
        // cookieconsent runtime activates only on grant.
        $script = (string) $gate->script('fbq("track","Purchase");', 'marketing')->getValue();
        $this->assertStringContainsString('type="text/plain"', $script);
        $this->assertStringContainsString('data-consent-category="marketing"', $script);
        $this->assertStringContainsString('fbq("track","Purchase");', $script);
    }
}
