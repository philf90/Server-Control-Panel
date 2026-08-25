<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Logs;
use SrvPanel\Agent\Ops\SystemLogsTail;
use SrvPanel\Agent\Result;

/**
 * Kein Pfad kommt von aussen — und keine Unit.
 *
 * ## Was hier gehalten wird
 *
 * Die Seite „Logs" zeigt Protokolle des Servers, und die Auswahl kommt aus
 * einem Formular. Zwischen dem Formular und `fopen()` steht genau eine Sache:
 * die Positivliste in {@see Logs}. Eine Operation „lies diese Datei" mit einem
 * Pfad als Argument wäre der kürzeste Weg von einem angemeldeten Konto zu
 * `/etc/shadow` — und jede nachträgliche Prüfung des Pfades hätte irgendwann
 * eine Lücke.
 *
 * Dasselbe gilt für das Journal: `journalctl -u <was der Benutzer schickt>`
 * gäbe die Ausgabe jedes Dienstes dieses Servers heraus, und darunter sind
 * welche, die Zugangsdaten protokollieren.
 *
 * ## Und der Fund, der den Leser geprägt hat
 *
 * Gemessen am 24. August 2026 gegen systemd 255, in einem Container ohne
 * Journal:
 *
 *     journalctl -u srvpanel-web       rc=0 · stdout „-- No entries --"
 *                                      stderr „No journal files were found."
 *     journalctl -u gibt-es-nicht      dasselbe, Zeichen für Zeichen
 *
 * Der Rückgabewert unterscheidet also nicht — und die Markierung steht auf
 * **stdout**, also dort, wo der Leser die Zeilen erwartet.
 *
 * > **Ein Leser, der `-- No entries --` als Zeile nimmt, zeigt eine Meldung
 * > des Werkzeugs als Inhalt des Protokolls.**
 */
final class LogSourceTest extends TestCase
{
    /**
     * Wo ein Protokoll liegen darf.
     *
     * **Eine Grenze und keine Aufzählung.** Sie fängt den Fall, den die
     * Positivliste selbst nicht fangen kann: einen neuen Eintrag, der auf
     * `/etc` oder ein Kundenverzeichnis zeigt. Ersteres wäre ein Geheimnis,
     * letzteres ein Bruch der Mandantenklammer.
     *
     * @var list<string>
     */
    private const ALLOWED_ROOTS = ['/var/log/', '/var/lib/srvpanel/'];

    /** Ein Schlüssel, den die Liste nicht kennt, kommt nicht durch. */
    public function test_a_key_outside_the_list_is_refused(): void
    {
        foreach ([
            '/etc/shadow',
            '../../etc/passwd',
            '/var/log/auth.log',          // der Pfad einer erlaubten Quelle — trotzdem kein Schlüssel
            'panel/../../etc/shadow',
            '',
        ] as $attempt) {
            try {
                Logs::source($attempt);
                $this->fail(sprintf('%s ist durchgekommen.', var_export($attempt, true)));
            } catch (AgentException $expected) {
                $this->assertStringContainsString('source', $expected->getMessage());
            }
        }
    }

    /**
     * Und die Gegenprobe: Ein Schlüssel aus der Liste kommt durch.
     *
     * Ohne sie bestünde der Test oben auch für eine Liste, die **alles**
     * abweist — und die wäre genauso falsch, nur andersherum.
     */
    public function test_a_key_from_the_list_is_accepted(): void
    {
        $keys = Logs::keys();

        $this->assertGreaterThan(5, count($keys), 'Es gibt kaum Quellen — dann prüft dieser Test nichts.');

        foreach ($keys as $key) {
            $source = Logs::source($key);

            $this->assertContains($source['kind'], [Logs::FILE, Logs::JOURNAL]);
            $this->assertNotSame('', $source['label'], $key.' hat keine Beschriftung.');
        }
    }

    /** Jede Datei liegt dort, wo ein Protokoll liegen darf. */
    public function test_every_file_stays_inside_the_allowed_roots(): void
    {
        $checked = 0;

        foreach (Logs::sources() as $key => $source) {
            if ($source['kind'] !== Logs::FILE) {
                continue;
            }

            $checked++;
            $path = (string) $source['path'];

            $inside = false;

            foreach (self::ALLOWED_ROOTS as $root) {
                if (str_starts_with($path, $root)) {
                    $inside = true;
                }
            }

            $this->assertTrue($inside, sprintf(
                '%s zeigt auf %s — das liegt ausserhalb von %s. Ein Protokoll dort wäre entweder '
                .'ein Geheimnis des Systems oder das eines Kunden.',
                $key,
                $path,
                implode(' und ', self::ALLOWED_ROOTS),
            ));

            $this->assertStringNotContainsString('..', $path, $key.' enthält einen Rückschritt im Pfad.');
        }

        $this->assertGreaterThan(4, $checked, 'Es werden kaum Dateien gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Jede Journalquelle nennt eine Unit, die das Paket ausliefert.
     *
     * **Der Verweis, den sonst nichts prüft.** Ein Unitname ist eine
     * Zeichenkette; benennt die Paketierung eine Unit um, zeigt die Quelle ins
     * Leere — und `journalctl` meldet das mit **Rückgabe 0** und
     * `-- No entries --`. Der Kunde sähe „für diese Unit steht nichts im
     * Journal" und hätte keinen Anlass, an einen Fehler zu denken.
     */
    public function test_every_journal_source_names_a_unit_the_package_ships(): void
    {
        $shipped = [];

        foreach (glob(dirname(__DIR__, 2).'/packaging/systemd/*.service') ?: [] as $path) {
            $shipped[] = basename($path, '.service');
        }

        $this->assertGreaterThan(4, count($shipped), 'Es werden kaum Units gefunden — dann prüft dieser Test nichts.');

        $checked = 0;

        foreach (Logs::sources() as $key => $source) {
            if ($source['kind'] !== Logs::JOURNAL) {
                continue;
            }

            $checked++;

            $this->assertContains((string) $source['unit'], $shipped, sprintf(
                '%s nennt die Unit %s, und packaging/systemd/ liefert sie nicht aus.',
                $key,
                (string) $source['unit'],
            ));
        }

        $this->assertGreaterThan(4, $checked, 'Es werden kaum Journalquellen gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Das Protokoll des Panels liegt im Schreibbereich, den die Paketierung
     * anlegt.
     *
     * `storage` ist ein Verweis nach `/var/lib/srvpanel/storage` (nfpm.yaml) —
     * und genau das ist der Grund: Läge es unter der Fassung, wäre es nach
     * jedem Update fort.
     */
    public function test_the_panel_log_lives_in_the_writable_area(): void
    {
        $packaging = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/nfpm.yaml');
        $path = (string) Logs::sources()['panel']['path'];

        $this->assertStringContainsString('/var/lib/srvpanel/storage', $packaging,
            'Die Paketierung legt den Schreibbereich woanders an — dann zeigt die Quelle daneben.');
        $this->assertStringStartsWith('/var/lib/srvpanel/storage/', $path);
    }

    /**
     * **Die Wirkung**: `-- No entries --` ist keine Zeile.
     *
     * Die Ausgabe ist Zeichen für Zeichen die gemessene.
     */
    public function test_the_empty_marker_is_not_a_line(): void
    {
        $read = SystemLogsTail::readJournal(new Result(
            0,
            SystemLogsTail::JOURNAL_EMPTY."\n",
            "No journal files were found.\n",
        ));

        $this->assertSame([], $read['lines'], 'Die Markierung darf nicht als Zeile durchkommen.');
        $this->assertFalse($read['exists']);

        // Und der Hinweis geht nicht verloren: „kein Journal auf diesem Server"
        // ist eine Auskunft über die Einrichtung und keine über den Dienst.
        $this->assertSame('No journal files were found.', $read['note']);
    }

    /**
     * Und die Gegenprobe: Echte Zeilen kommen durch, und der Hinweis fehlt.
     *
     * Ohne sie bestünde der Test oben auch für einen Leser, der **jede** Zeile
     * verwirft.
     */
    public function test_real_lines_survive(): void
    {
        $read = SystemLogsTail::readJournal(new Result(
            0,
            "2026-08-24T17:15:09+0000 host srvpanel-web[1]: bereit\n"
            ."2026-08-24T17:15:10+0000 host srvpanel-web[1]: erste Anfrage\n",
            '',
        ));

        $this->assertCount(2, $read['lines']);
        $this->assertTrue($read['exists']);
        $this->assertNull($read['note']);
    }
}
