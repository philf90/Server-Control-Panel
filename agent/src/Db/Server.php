<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;

/**
 * Was für ein Datenbankserver hier läuft — und ob das Panel darauf arbeiten darf.
 *
 * **Erst prüfen, dann übernehmen** (Plan §2, Leitbild 3). Der Punkt, an dem das
 * hier zählt, ist die Sperre: `docs/36 §6` verlangt, dass die Sperre eines
 * Abonnements seine Datenbankbenutzer erreicht, und das Mittel dafür ist
 * `ALTER USER … ACCOUNT LOCK`. Die Anweisung gibt es in MariaDB erst ab 10.4.2.
 *
 * Auf einem älteren Server bietet das Panel Datenbanken deshalb **gar nicht
 * erst an** — statt eine Sperre anzubieten, die nicht sperrt. Ein gesperrtes
 * Abonnement, dessen Datenbank jede Anwendung weiterbedient, die die
 * Zugangsdaten hat, ist keine Sperre, sondern eine abgeschaltete Webseite.
 *
 * Alle vier Zielplattformen liegen darüber: Ubuntu 22.04 liefert MariaDB 10.6,
 * Debian 12 und Ubuntu 24.04 liefern 10.11, Debian 13 liefert 11.x. Die Prüfung
 * steht trotzdem da — für den Server, auf dem jemand etwas anderes installiert
 * hat, und weil eine Zusage ohne Prüfung eine Behauptung ist.
 */
final class Server
{
    /** Ab hier gibt es `ACCOUNT LOCK`. */
    public const MIN_MARIADB = '10.4.2';

    /** MySQL kennt es seit 5.7.6. */
    public const MIN_MYSQL = '5.7.6';

    /**
     * Version, Geschmacksrichtung und Horchadresse — in einem Lauf.
     *
     * @return array{
     *     available: bool,
     *     flavour: string,
     *     version: string,
     *     usable: bool,
     *     reason: string|null,
     *     bind_address: string|null,
     *     remote: bool,
     * }
     */
    public function describe(Context $context, Session $session): array
    {
        try {
            $rows = $session->query($context, 'SELECT @@version, @@version_comment, @@bind_address');
        } catch (AgentException $error) {
            // **Kein Fehlschlag der Operation.** „MariaDB läuft nicht" ist eine
            // Auskunft und kein Fehler des Agenten — das Panel zeigt sie an und
            // schaltet die Datenbankfläche ab. Ein rot gemeldeter Vorgang stünde
            // dagegen alle fünfzehn Minuten neben allem, was tatsächlich kaputt
            // ist. Dieselbe Entscheidung wie bei `srvpanel usage` ohne
            // `usrquota`.
            return [
                'available' => false,
                'flavour' => 'unknown',
                'version' => '',
                'usable' => false,
                'reason' => $error->getMessage(),
                'bind_address' => null,
                'remote' => false,
            ];
        }

        $row = $rows[0] ?? [];
        $version = (string) ($row[0] ?? '');
        $comment = (string) ($row[1] ?? '');
        $bind = isset($row[2]) && $row[2] !== 'NULL' ? (string) $row[2] : null;

        $flavour = self::flavour($version, $comment);
        [$usable, $reason] = self::usable($flavour, $version);

        return [
            'available' => true,
            'flavour' => $flavour,
            'version' => $version,
            'usable' => $usable,
            'reason' => $reason,
            'bind_address' => $bind,

            // **Horcht der Server auf einer erreichbaren Adresse?** Die Antwort
            // steuert, ob das Panel den Fernzugriff überhaupt anbietet
            // (`docs/36 §12`) — und sie wird gelesen und nicht gesetzt: Eine
            // serverweite Horchadresse zu ändern, weil ein Kunde ein Häkchen
            // gesetzt hat, wäre der Bruch von Leitbild 1.
            'remote' => $bind !== null && $bind !== '' && $bind !== '127.0.0.1' && $bind !== '::1' && $bind !== 'localhost',
        ];
    }

    /**
     * Die Vorbedingung, hart.
     *
     * Für jede Operation, die etwas anlegt: Sie läuft nicht auf einem Server,
     * auf dem sich das Angelegte später nicht sperren liesse.
     */
    public function require(Context $context, Session $session): void
    {
        $info = $this->describe($context, $session);

        if (! $info['usable']) {
            throw AgentException::denied(
                'Auf diesem Datenbankserver arbeitet srvpanel nicht: '.($info['reason'] ?? 'unbekannter Grund'),
            );
        }
    }

    /**
     * MariaDB oder MySQL?
     *
     * MariaDB schreibt sich seit jeher in `@@version` selbst hinein
     * (`10.11.6-MariaDB-0+deb12u1`), MySQL nennt sich nur in
     * `@@version_comment`. Beide werden gelesen: Ein Build ohne die Marke in
     * der Version — es gibt sie — fiele sonst in den falschen Zweig, und der
     * falsche Zweig wäre hier der mit der niedrigeren Mindestversion.
     */
    private static function flavour(string $version, string $comment): string
    {
        $haystack = strtolower($version.' '.$comment);

        if (str_contains($haystack, 'mariadb')) {
            return 'mariadb';
        }

        if (str_contains($haystack, 'mysql')) {
            return 'mysql';
        }

        return 'unknown';
    }

    /** @return array{0: bool, 1: string|null} */
    private static function usable(string $flavour, string $version): array
    {
        // `10.11.6-MariaDB-0+deb12u1` → `10.11.6`. `version_compare` käme mit
        // dem Rest zurecht, aber nicht verlässlich: `-0+deb12u1` liest es als
        // Vorabversion und stuft es damit *unter* `10.11.6` ein.
        $number = preg_match('/^([0-9]+(?:\.[0-9]+)*)/', $version, $m) === 1 ? $m[1] : '0';

        $minimum = match ($flavour) {
            'mariadb' => self::MIN_MARIADB,
            'mysql' => self::MIN_MYSQL,
            default => null,
        };

        if ($minimum === null) {
            return [false, sprintf('Unbekannter Datenbankserver (%s) — erwartet werden MariaDB oder MySQL.', $version)];
        }

        if (version_compare($number, $minimum, '<')) {
            return [false, sprintf(
                '%s %s kennt ALTER USER … ACCOUNT LOCK nicht (nötig ab %s). Ohne die Anweisung erreicht die Sperre '
                .'eines Abonnements seine Datenbanken nicht, und „gesperrt" wäre nur eine Beschriftung.',
                $flavour === 'mariadb' ? 'MariaDB' : 'MySQL',
                $number,
                $minimum,
            )];
        }

        return [true, null];
    }
}
