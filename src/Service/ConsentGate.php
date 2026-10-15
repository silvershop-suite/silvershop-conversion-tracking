<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Service;

use SilverShop\CookieConsent\ConsentState;
use SilverShop\CookieConsent\View\ConsentScript;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * The single binding point to silvershop/cookieconsent — conversion-tracking is a CONSUMER of its consent API, it
 * does not reinvent any of it. Everything the module emits (the `dataLayer` push, each connector's base + per-event
 * tags) and every server-side send is gated here on a cookieconsent category: Meta → `marketing`, GA4 →
 * `statistics`, Google Ads → `marketing`.
 *
 * Three states:
 *  - cookieconsent INSTALLED → read {@see ConsentState::has()} (server) and prior-block every client tag through
 *    {@see ConsentScript::inline()} / {@see ConsentScript::src()} (the inert `<script type="text/plain"
 *    data-consent-category="…">` the cookieconsent runtime activates on grant + each `consentchange`). Consent
 *    Mode v2 is cookieconsent's job; we only bind to it.
 *  - cookieconsent ABSENT + {@see self::$assume_consent_without_module} = false (the GDPR-safe default) →
 *    DEFAULT-DENY: nothing is emitted and no server send runs.
 *  - cookieconsent ABSENT + assume_consent_without_module = true → the shop has taken responsibility for consent
 *    elsewhere, so tags are emitted live.
 *
 * Because an inert `type="text/plain"` tag simply never activates when the cookieconsent runtime is absent, the
 * default-deny state needs no special client handling — it falls out of the same mechanism.
 */
class ConsentGate
{
    use Injectable;
    use Configurable;

    /**
     * Only when cookieconsent is NOT installed: assume consent and emit tags live. Default false (no consent
     * source → nothing fires). Leave this off unless the shop guarantees consent some other way.
     */
    private static bool $assume_consent_without_module = false;

    /**
     * The categories the module gates on and stamps onto events for the server path.
     *
     * @var array<int, string>
     */
    private static array $tracked_categories = ['marketing', 'statistics'];

    /**
     * The cookieconsent class that signals the consent API is present. Overridable in config (to a non-existent
     * class) so the default-deny / assume-consent fallback can be exercised deterministically in a test even where
     * cookieconsent is installed.
     */
    private static string $consent_state_class = ConsentState::class;

    /**
     * Whether silvershop/cookieconsent is installed (so the real consent API is available).
     */
    public function available(): bool
    {
        return class_exists((string) static::config()->get('consent_state_class'));
    }

    /**
     * The server-side gate: whether a given category may fire right now. With cookieconsent installed this is the
     * real {@see ConsentState::has()} (fail-closed before a decision); without it, the configured assume-consent
     * fallback (default false).
     */
    public function isActive(string $category): bool
    {
        if ($this->available()) {
            return ConsentState::has($category);
        }

        return (bool) static::config()->get('assume_consent_without_module');
    }

    /**
     * The tracked categories granted right now — frozen onto an event at mint so the later queued server dispatch
     * stays correct even without a request.
     *
     * @return array<int, string>
     */
    public function grantedCategories(): array
    {
        $granted = [];
        foreach ((array) static::config()->get('tracked_categories') as $category) {
            if ($this->isActive((string) $category)) {
                $granted[] = (string) $category;
            }
        }

        return $granted;
    }

    /**
     * Wrap an inline script so it is prior-blocked until the category is granted. Returns an empty fragment in the
     * default-deny state (cookieconsent absent + assume_consent off), so callers can emit the result unconditionally.
     */
    public function script(string $js, string $category): DBHTMLText
    {
        if ($this->available()) {
            return ConsentScript::inline($js, $category);
        }

        if ((bool) static::config()->get('assume_consent_without_module')) {
            return DBHTMLText::create()->setValue('<script>' . $js . '</script>');
        }

        return DBHTMLText::create()->setValue('');
    }

    /**
     * Wrap an external script URL so it is prior-blocked until the category is granted.
     *
     * @param array<string, mixed> $attrs e.g. ['async' => true]
     */
    public function src(string $url, string $category, array $attrs = []): DBHTMLText
    {
        if ($this->available()) {
            return ConsentScript::src($url, $category, $attrs);
        }

        if ((bool) static::config()->get('assume_consent_without_module')) {
            $rendered = '';
            foreach ($attrs as $name => $value) {
                $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $name);
                if ($value === true) {
                    $rendered .= ' ' . $cleanName;
                } elseif ($value !== false && $value !== null) {
                    $rendered .= ' ' . $cleanName . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
                }
            }

            return DBHTMLText::create()->setValue(
                '<script src="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $rendered . '></script>'
            );
        }

        return DBHTMLText::create()->setValue('');
    }
}
