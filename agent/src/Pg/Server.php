<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Server as DbServer;

/**
 * Läuft hier ein PostgreSQL — und dürfen wir darauf arbeiten?
 *
 * Das Gegenstück zu {@see DbServer}, mit einem Unterschied, den P5 nicht kennt:
 * **Es gibt einen Zustand zwischen „läuft nicht" und „nutzbar".** Der Server
 * kann laufen und der Agent trotzdem nicht hineinkommen, weil die Rolle `root`
 * noch fehlt (`docs/38 §6.1`). Das ist kein Fehler, sondern eine Übergabe, die
 * noch aussteht — und es gehört unterschieden, weil die Antwort für den
 * Betreiber eine andere ist: einmal „starte den Dienst", einmal „führe diesen
 * Befehl aus".
 *
 * **Nichts davon ist ein Fehlschlag der Operation.** Wortgleich die
 * Entscheidung aus {@see DbServer}: „PostgreSQL läuft nicht" ist eine Auskunft.
 * Ein rot gemeldeter Vorgang stünde alle fünfzehn Minuten neben allem, was
 * tatsächlich kaputt ist.
 */
final class Server
{
    /**
     * Wo der Socket liegt.
     *
     * Debian und Ubuntu legen ihn nach `/var/run/postgresql`, unabhängig von
     * der Fassung und vom Cluster. Ein Pfad und kein Rechnername: `peer` gibt
     * es nur lokal (`docs/38 §6`).
     */
    public const SOCKET_DIRECTORY = '/var/run/postgresql';

    /**
     * Die kleinste Fassung, auf der dieses Panel arbeitet.
     *
     * **PostgreSQL 14, und die Zahl kommt nicht aus einer Wunschliste, sondern
     * von den Zielplattformen:** Ubuntu 22.04 liefert 14, Debian 12 liefert 15,
     * Ubuntu 24.04 liefert 16, Debian 13 liefert 17.
     *
     * Was an 14 anders ist, steht in {@see Shielding}: Bis dahin darf `PUBLIC`
     * im Schema `public` jeder Datenbank anlegen. Die Absperrung nimmt das Recht
     * ausdrücklich weg, statt sich auf die Vorgabe zu verlassen — deshalb ist 14
     * benutzbar und nicht bloss geduldet.
     */
    public const MIN_VERSION = 14;

    /**
     * Der Befehl, den der Betreiber einmal ausführt.
     *
     * Er steht hier als Konstante und nicht in der Oberfläche, weil die
     * Oberfläche ihn **anzeigt** und nicht kennt: Ein abgedruckter Befehl, den
     * es so nicht gibt, ist genau der Fehler, den `docs/36 §22.3v` teuer
     * bezahlt hat.
     */
    public const HANDOVER = 'sudo -u postgres psql -c "CREATE ROLE root SUPERUSER LOGIN"';

    /**
     * Was hier steht — in einem Lauf.
     *
     * @return array{
     *     available: bool,
     *     handed_over: bool,
     *     version: string,
     *     major: int|null,
     *     usable: bool,
     *     reason: string|null,
     *     handover: string,
     * }
     */
    public function describe(Context $context, Session $session): array
    {
        $blank = [
            'available' => false,
            'handed_over' => false,
            'version' => '',
            'major' => null,
            'usable' => false,
            'reason' => null,
            'handover' => self::HANDOVER,
        ];

        if (! is_dir(self::SOCKET_DIRECTORY)) {
            return array_merge($blank, [
                'reason' => 'PostgreSQL ist auf diesem Server nicht eingerichtet — es gibt kein Socketverzeichnis.',
            ]);
        }

        try {
            $rows = $session->query($context, 'SELECT current_setting(\'server_version_num\'), version()');
        } catch (AgentException $error) {
            /*
             * **Hier wird die Meldung gelesen und nicht nur weitergereicht.**
             * „Der Dienst läuft nicht" und „die Rolle root fehlt" sehen für den
             * Agenten gleich aus — beides ist ein `psql`, das mit einem
             * Rückgabewert ungleich null endet. Für den Betreiber sind es zwei
             * verschiedene Handgriffe, und ein Panel, das ihm beide Male
             * dasselbe sagt, hilft bei keinem.
             */
            return array_merge($blank, [
                'available' => ! self::saysServerIsDown($error->getMessage()),
                'reason' => $error->getMessage(),
            ]);
        }

        $row = $rows[0] ?? [];
        $number = (int) ($row[0] ?? 0);
        $version = (string) ($row[1] ?? '');
        $major = intdiv($number, 10000);

        [$usable, $reason] = self::usable($major);

        return [
            'available' => true,
            'handed_over' => true,
            'version' => $version,
            'major' => $major,
            'usable' => $usable,
            'reason' => $reason,
            'handover' => self::HANDOVER,
        ];
    }

    /**
     * Die Vorbedingung, hart.
     *
     * Für jede Operation, die etwas anlegt.
     */
    public function require(Context $context, Session $session): void
    {
        $info = $this->describe($context, $session);

        if (! $info['usable']) {
            throw AgentException::denied(
                'Auf diesem PostgreSQL arbeitet srvpanel nicht: '.($info['reason'] ?? 'unbekannter Grund'),
            );
        }
    }

    /**
     * Sagt diese Meldung, dass der Dienst gar nicht läuft?
     *
     * `psql` unterscheidet die beiden Fälle im Text und nirgends sonst: Ein
     * Server, der nicht antwortet, liefert „could not connect to server" oder
     * „No such file or directory" auf den Socket; ein Server, der antwortet und
     * die Rolle nicht kennt, liefert „role … does not exist" oder
     * „Peer authentication failed".
     *
     * **Gesucht wird nach dem Nichtlaufen und nicht nach der fehlenden Rolle.**
     * Der Rückfall ist damit „der Server läuft" — und das ist die Richtung, in
     * der ein Irrtum billiger ist: Das Panel zeigt dann den Übergabebefehl an,
     * statt zu behaupten, PostgreSQL sei nicht installiert.
     */
    private static function saysServerIsDown(string $message): bool
    {
        foreach (['could not connect to server', 'No such file or directory', 'Connection refused'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: bool, 1: string|null} */
    private static function usable(int $major): array
    {
        if ($major < self::MIN_VERSION) {
            return [false, sprintf(
                'PostgreSQL %d ist älter als %d. Bis PostgreSQL 14 darf PUBLIC im Schema public jeder '
                .'Datenbank Objekte anlegen; darunter liegen Fassungen, gegen die dieses Panel die '
                .'Abschottung nie gemessen hat.',
                $major,
                self::MIN_VERSION,
            )];
        }

        return [true, null];
    }
}
