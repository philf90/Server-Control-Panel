<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

/**
 * Ein gesperrtes Abonnement wieder freigeben.
 *
 * Die Umkehrung von {@see SubscriptionSuspend}, Wert für Wert. Sie steht
 * bewusst als eigene Klasse daneben und nicht als Verzweigung darin: Wer
 * wissen will, ob eine Sperre vollständig zurückgenommen wird, liest zwei
 * kurze Dateien nebeneinander statt eines `if` mit zwei Zweigen.
 */
final class SubscriptionResume extends SubscriptionState
{
    public static function name(): string
    {
        return 'subscription.resume';
    }

    /** Zurück auf den Wert aus §4.5. */
    protected function rootMode(): int
    {
        return 0755;
    }

    /** @return list<string> */
    protected function accountArgs(string $user): array
    {
        // Ein leeres `--expiredate` nimmt das Ablaufdatum zurück — nicht `0`,
        // das wäre der 1. Januar 1970 und damit weiterhin abgelaufen.
        return ['--unlock', '--expiredate', '', $user];
    }

    /**
     * Beim Freigeben gibt es nichts zu beenden: Was läuft, gehört zu einem
     * Abonnement, das gerade wieder arbeiten darf.
     */
    protected function stopsProcesses(): bool
    {
        return false;
    }
}
