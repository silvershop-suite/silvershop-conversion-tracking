<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Model;

use SilverStripe\Control\Cookie;
use SilverStripe\Control\HTTPRequest;

/**
 * The customer-matching data a connector needs to raise match quality — captured RAW here; hashing is the
 * connector's job (Meta hashes em/ph/fn/ln/… with SHA-256 for Advanced Matching; Google's Enhanced Conversions
 * wants the raw fields and hashes them client-side). This object therefore MUST NOT be pushed to the browser
 * `dataLayer`; it only ever travels the server-side path and, for Google, the strictly consent-gated
 * `gtag('set','user_data',…)` call.
 *
 * {@see self::$fbp} / {@see self::$fbc} (Meta's `_fbp` / `_fbc` cookies) and {@see self::$clientIp} /
 * {@see self::$userAgent} are forwarded UNHASHED and must be captured from the live request at mint time, because
 * the server-side dispatch runs later in a queued job with no originating request.
 */
final class ConversionUser
{
    public function __construct(
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $postcode = null,
        public readonly ?string $country = null,
        public readonly ?string $externalId = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
    ) {
    }

    /**
     * Capture the request-bound matching signals (client IP, user-agent, Meta `_fbp` / `_fbc` cookies, and an
     * `fbc` synthesised from an `fbclid` query param when the cookie is not yet set) onto a copy of this user, so
     * they survive into the queued server-side dispatch.
     */
    public function withRequest(?HTTPRequest $request): self
    {
        if (!$request instanceof HTTPRequest) {
            return $this;
        }

        $fbp = $this->fbp ?? (Cookie::get('_fbp') ?: null);
        $fbc = $this->fbc ?? (Cookie::get('_fbc') ?: null);
        if ($fbc === null) {
            $fbclid = (string) $request->getVar('fbclid');
            if ($fbclid !== '') {
                $fbc = 'fb.1.' . time() . '.' . $fbclid;
            }
        }

        return new self(
            $this->email,
            $this->phone,
            $this->firstName,
            $this->lastName,
            $this->city,
            $this->state,
            $this->postcode,
            $this->country,
            $this->externalId,
            $this->clientIp ?? ($request->getIP() ?: null),
            $this->userAgent ?? ($request->getHeader('User-Agent') ?: null),
            $fbp,
            $fbc,
        );
    }

    /**
     * Whether there is anything worth sending for matching at all.
     */
    public function isEmpty(): bool
    {
        return $this->email === null
            && $this->phone === null
            && $this->firstName === null
            && $this->lastName === null
            && $this->externalId === null
            && $this->fbp === null
            && $this->fbc === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'phone' => $this->phone,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'externalId' => $this->externalId,
            'clientIp' => $this->clientIp,
            'userAgent' => $this->userAgent,
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $value = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== ''
            ? (string) $data[$key]
            : null;

        return new self(
            $value('email'),
            $value('phone'),
            $value('firstName'),
            $value('lastName'),
            $value('city'),
            $value('state'),
            $value('postcode'),
            $value('country'),
            $value('externalId'),
            $value('clientIp'),
            $value('userAgent'),
            $value('fbp'),
            $value('fbc'),
        );
    }
}
