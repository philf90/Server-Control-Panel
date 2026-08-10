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

    /**
     * Wo das Passwort dieses Kontos steht.
     *
     * Als Konstante, damit der Wächter sie nennen kann — und damit ein Test
     * ihn nicht auf die echte Datei loslassen muss.
     */
    public const SHADOW = '/etc/shadow';

    /** @return list<string> */
    protected function accountArgs(string $user): array
    {
        // Ein leeres `--expiredate` nimmt das Ablaufdatum zurück — nicht `0`,
        // das wäre der 1. Januar 1970 und damit weiterhin abgelaufen. **Das ist
        // die Schranke, die SSH und SFTP tatsächlich prüfen**, und sie steht
        // hier ohne Bedingung.
        $args = ['--expiredate', '', $user];

        /*
         * **`--unlock` nur, wenn es etwas zu entsperren gibt.**
         *
         * Der Systembenutzer eines Abonnements hat kein Passwort — er wird ohne
         * eines angelegt, seine Shell ist `nologin`, und der Zugang läuft über
         * SFTP mit Schlüssel (`SubscriptionProvision`). `usermod --unlock`
         * weigert sich dann, weil das Feld danach **leer** wäre, und schreibt:
         *
         *     usermod: unlocking the user's password would result in a
         *     passwordless account.
         *
         * Die Weigerung ist richtig, die Meldung auch — nur erschien sie bei
         * **jeder Freigabe jedes Abonnements**, weil kein Systembenutzer je ein
         * Passwort hat. Gemeldet vom Betreiber am 10. August 2026 aus Vorgang
         * 492 (`docs/39`, Punkt 6).
         *
         * > **Ein Hinweis, der immer erscheint, erzieht dazu, die Ausgabe nicht
         * > zu lesen.**
         *
         * Und die Ausgabe eines Vorgangs ist die Stelle, an der ein echter
         * Fehler auffallen soll. Die Sperre wird dadurch kein Stück schwächer:
         * `--lock` beim Sperren bleibt, und wo ein Passwort steht, wird es
         * weiterhin entsperrt.
         */
        return self::unlocks(@file_get_contents(self::SHADOW), $user)
            ? array_merge(['--unlock'], $args)
            : $args;
    }

    /**
     * Steht in `/etc/shadow` ein Passwort, das entsperrt werden will?
     *
     * **Als reine Funktion über den Inhalt**, damit sie sich ohne `/etc/shadow`
     * prüfen lässt — dieselbe Bauart wie
     * {@see PgDatabaseCreate::statement()}.
     *
     * Die Regel: Vorangestellte `!` weg, und was übrig bleibt, muss ein echtes
     * Passwort sein. Nicht leer (`!` — genau der Fall auf `cloudsrv24`), nicht
     * `!!` (angelegt ohne Passwort) und nicht `!*` (ausdrücklich keines). In
     * allen dreien gäbe es nichts zu entsperren, und `usermod` sagte es uns.
     *
     * **Ohne lesbare Datei wird nicht entsperrt.** `null` heisst „nicht
     * nachgesehen", und dann bleibt es beim Ablaufdatum — der Schranke, auf die
     * es ankommt. Nichts behaupten ist auch hier billiger als raten.
     */
    public static function unlocks(string|false|null $shadow, string $user): bool
    {
        if (! is_string($shadow)) {
            return false;
        }

        foreach (explode("\n", $shadow) as $line) {
            $fields = explode(':', $line);

            if (($fields[0] ?? '') !== $user) {
                continue;
            }

            $secret = ltrim($fields[1] ?? '', '!');

            return $secret !== '' && $secret !== '*';
        }

        return false;
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
