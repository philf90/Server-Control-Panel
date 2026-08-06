<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Dns\Credentials;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Zugangsdaten eines DNS-Anbieters hinterlegen.
 *
 * **Der eine Weg, auf dem ein Token den Socket überquert — und der einzige.**
 * Danach kennt die Anwendung nur noch den Profilnamen; zurück kommt hier schon
 * nichts anderes. Wer ein Token sucht, findet im Panel den Namen des Profils
 * und sonst nichts (`docs/34 §5`).
 *
 * **Kein Vorgang in der Warteschlange.** Ein eingereihter Vorgang legt seine
 * Argumente in `operations.payload` ab — dieselbe Überlegung wie beim
 * Hochladen eines Zertifikats ({@see CertificateUpload}). Der Aufrufer ruft
 * unmittelbar.
 */
final class DnsCredentialStore implements Op
{
    public function __construct(private readonly Credentials $credentials = new Credentials) {}

    public static function name(): string
    {
        return 'dns.credential.store';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $config = $args['config'] ?? [];

        $this->credentials->store(
            $args['profile'] ?? null,
            $args['provider'] ?? null,
            is_array($config) ? $config : [],
        );

        $profile = Credentials::name($args['profile'] ?? null);

        // Zurück geht, was auch auf der Seite stehen darf: welcher Anbieter,
        // unter welchem Namen. Das Token bleibt hier.
        return [
            'profile' => $profile,
            'provider' => Providers::normalize($args['provider'] ?? null),
            'stored' => true,
        ];
    }
}
