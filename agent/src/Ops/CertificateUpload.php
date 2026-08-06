<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Bundle;
use SrvPanel\Agent\Acme\CertificateName;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Ein eigenes Zertifikat ablegen — geprüft, bevor es irgendwo liegt.
 *
 * **Der private Schlüssel überquert den Socket genau einmal und geht nie
 * zurück.** Zurück kommt, was auch jeder Browser sieht: Namen, Aussteller,
 * Laufzeit, Seriennummer und die beiden Pfade, unter denen nginx sie findet.
 * Dieselbe Aufteilung wie bei {@see AcmeCertificate}, nur dass der Schlüssel
 * dort entsteht und hier ankommt.
 *
 * **Geprüft wird vollständig, bevor geschrieben wird** ({@see Bundle}). Eine
 * halb abgelegte Kette wäre schlimmer als eine abgewiesene: Ein Zertifikat, das
 * nginx nicht laden kann, nimmt beim nächsten Reload alle Websites dieses
 * Servers mit — auch die, mit denen es nichts zu tun hat.
 *
 * **Es liegt getrennt vom bestellten.** Der Ablageort trägt `_uploaded.` als
 * Kennzeichnung, weil beide denselben ersten Namen haben können und sonst
 * eines das andere überschriebe. Welches davon ein Server-Block ausliefert,
 * entscheidet danach das Panel — es nennt den Schlüssel.
 */
final class CertificateUpload implements Op
{
    public function __construct(private readonly Store $store = new Store) {}

    public static function name(): string
    {
        return 'tls.certificate.upload';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $context->progress(10, 'Prüfen');

        $bundle = Bundle::from($args['certificate'] ?? null, $args['private_key'] ?? null);

        $context->progress(60, 'Ablegen');

        // Der erste Name ist der, unter dem abgelegt wird — dieselbe Regel wie
        // bei einer Bestellung, nur mit der Kennzeichnung der Quelle davor.
        $paths = $this->store->write(
            CertificateName::uploaded($bundle->names[0]),
            $bundle->chain,
            $bundle->privateKey,
        );

        $context->progress(100, 'fertig');

        return $paths + [
            'names' => $bundle->names,
            'issuer' => $bundle->issuer,
            'serial' => $bundle->serial,
            'not_before' => $bundle->notBefore,
            'not_after' => $bundle->notAfter,
        ];
    }
}
