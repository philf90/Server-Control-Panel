<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Dns\Credentials;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Welche DNS-Profile hinterlegt sind.
 *
 * **Nicht verändernd, also ohne Vorgang** — dieselbe Aufteilung wie bei
 * {@see AcmeCertificateInfo}: Nachsehen ändert nichts, und eine Seite, die bei
 * jedem Aufruf eine Zeile ins Protokoll schreibt, öffnet man nicht gern.
 *
 * **Und ohne jeden Ausschnitt des Tokens.** Auch nicht die letzten vier
 * Zeichen: Bei einem kurzen Token ist das ein spürbarer Teil davon, und der
 * Gewinn wäre eine Bequemlichkeit beim Wiedererkennen. Wer wissen will, ob das
 * richtige hinterlegt ist, hinterlegt es neu.
 */
final class DnsCredentialList implements Op
{
    public function __construct(private readonly Credentials $credentials = new Credentials) {}

    public static function name(): string
    {
        return 'dns.credential.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $profiles = [];

        foreach ($this->credentials->known() as $name) {
            $described = $this->credentials->describe($name);

            if ($described === null) {
                continue;
            }

            $profiles[] = $described + ['provider_label' => Providers::label($described['provider'])];
        }

        // **Beide Listen.** Die Oberfläche soll zeigen können, dass es die
        // anderen vier gibt und dass sie noch nicht gehen — sonst fragt sich
        // jemand, warum sein Anbieter fehlt, und trägt ihn beim falschen ein.
        return [
            'profiles' => $profiles,
            'providers' => Providers::keys(),
            'available' => Providers::available(),
        ];
    }
}
