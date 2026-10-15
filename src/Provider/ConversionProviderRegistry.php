<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Provider;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Registry of conversion-tracking connectors. Connectors declare themselves in config (key => Injector service)
 * and are switched on by listing their key under `active` — and unlike the newsletter's single-active registry a
 * shop can run SEVERAL at once (Meta AND Google), so `active` is a list. This mirrors
 * {@see \SilverShop\Newsletter\Provider\NewsletterProviderRegistry} otherwise.
 *
 *   SilverShop\ConversionTracking\Provider\ConversionProviderRegistry:
 *     providers:
 *       meta: '%$SilverShop\ConversionTracking\Meta\MetaProvider'
 *       google: '%$SilverShop\ConversionTracking\Google\GoogleProvider'
 *     active:
 *       - meta
 *       - google
 *
 * A listed-active connector still only takes effect once {@see ConversionProvider::isConfigured()} is true, so the
 * module is inert until a shop supplies that platform's env ids/token.
 */
class ConversionProviderRegistry
{
    use Configurable;
    use Injectable;

    /**
     * @var array<string, string> key => Injector service name / class. Connectors add their entry via config.
     */
    private static array $providers = [];

    /**
     * @var array<int, string> keys (getKey()) of the connectors a shop has switched on.
     */
    private static array $active = [];

    /**
     * Every registered connector, keyed by its {@see ConversionProvider::getKey()}.
     *
     * @return array<string, ConversionProvider>
     */
    public function all(): array
    {
        $providers = [];
        foreach ((array) self::config()->get('providers') as $service) {
            if (!$service) {
                continue;
            }
            /** @var ConversionProvider $provider */
            $provider = Injector::inst()->get($service);
            $providers[$provider->getKey()] = $provider;
        }

        return $providers;
    }

    public function get(string $key): ?ConversionProvider
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The connectors that are both listed active AND configured — the ones the core actually emits/dispatches for.
     * Order follows the `active` list.
     *
     * @return array<int, ConversionProvider>
     */
    public function active(): array
    {
        $all = $this->all();
        $active = [];
        foreach ((array) self::config()->get('active') as $key) {
            $provider = $all[(string) $key] ?? null;
            if ($provider && $provider->isConfigured()) {
                $active[] = $provider;
            }
        }

        return $active;
    }

    /**
     * The active + configured connectors that have a server-to-server path (so the core should enqueue a
     * {@see \SilverShop\ConversionTracking\Job\ConversionDispatchJob}).
     *
     * @return array<int, ConversionProvider>
     */
    public function activeServerSide(): array
    {
        return array_values(array_filter(
            $this->active(),
            static fn (ConversionProvider $provider): bool => $provider->supportsServerSide()
        ));
    }
}
