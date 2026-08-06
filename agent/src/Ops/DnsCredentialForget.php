<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Dns\Credentials;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Ein DNS-Profil wieder entfernen.
 *
 * **Es gibt einen Weg hinein, also gehört einer hinaus dazu.** Ein Token, das
 * sich nur überschreiben und nie löschen lässt, bleibt auf dem Server, wenn ein
 * Kunde geht — und niemand denkt daran, weil es keine Stelle gibt, an der es
 * auffiele.
 */
final class DnsCredentialForget implements Op
{
    public function __construct(private readonly Credentials $credentials = new Credentials) {}

    public static function name(): string
    {
        return 'dns.credential.forget';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $profile = Credentials::name($args['profile'] ?? null);

        return ['profile' => $profile, 'removed' => $this->credentials->forget($profile)];
    }
}
