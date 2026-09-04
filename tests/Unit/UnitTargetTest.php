<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `srvpanel.target` — der eine Griff für das ganze Panel.
 *
 * ## Warum es das Ziel gibt
 *
 * Gefunden hat es die Bestandsdiagnose selbst (A10-Nachlauf, Punkt 7): Nach
 * einem `systemctl stop srvpanel-agentd` standen `srvpanel-worker` und
 * `srvpanel-metrics` still, und ein `start` des Agenten holte sie **nicht**
 * zurück. Beide tragen `Requires=srvpanel-agentd.service`, und diese Angabe
 * überträgt das Anhalten, nicht das Starten.
 *
 * > **Eine Abhängigkeit, die das Anhalten überträgt und das Starten nicht,
 * > hinterlässt einen Zustand, den nur der herstellt, der ihn auch beheben
 * > müsste.**
 *
 * ## Woher der Sollzustand kommt
 *
 * **Aus den Unit-Dateien und nicht aus einer Liste hier.** Eine Aufzählung im
 * Wächter wäre die zweite Fassung derselben Regel, und die zweite ist die, die
 * veraltet — dieselbe Begründung wie bei {@see UnitCatalogTest}. Gefragt wird
 * je Datei: Ein `.service` gehört ins Ziel, wenn er **kein** `Type=oneshot`
 * ist; jeder `.timer` gehört hinein.
 *
 * `Type=oneshot` ist dabei nicht irgendein Merkmal, sondern genau das, was
 * einen von einem Timer gestarteten Dienst ausmacht. Stünde `srvpanel-usage`
 * in der Liste, liefe die Messung bei jedem `systemctl start srvpanel.target`
 * sofort los — fünf Läufe auf einen Schlag, von denen keiner fällig war.
 *
 * ## Beide Richtungen, und warum
 *
 * `Wants=` sagt, was das Ziel startet; `PartOf=` sagt, was es anhält. Fehlte
 * das zweite, wäre das Ziel ein Griff, der nur anschaltet. Fehlte das erste,
 * wäre es einer, der nur ausschaltet. Beides ist ein halber Griff, und ein
 * Wächter über nur eine der beiden Angaben sähe die fehlende Hälfte nicht.
 *
 * ## Was er nicht halten kann
 *
 * Ob systemd die Datei annimmt, sagt `systemd-analyze verify` und nicht dieser
 * Wächter — gemessen wurde das am 3. September 2026 gegen systemd 255 in einer
 * eigenen Namespace, mit `rc=0`, neun `Wants` und `WantedBy=multi-user.target`
 * nach dem `enable`.
 */
final class UnitTargetTest extends TestCase
{
    /** Der Name des Ziels, wie ihn `Wants=` und `PartOf=` schreiben. */
    private const ZIEL = 'srvpanel.target';

    /**
     * Die Units, die das Ziel führen **soll** — aus den Dateien gelesen.
     *
     * @return list<string>
     */
    private static function erwartet(): array
    {
        $units = [];

        foreach (self::dateien() as $name => $inhalt) {
            if (str_ends_with($name, '.timer')) {
                $units[] = $name;

                continue;
            }

            if (! str_ends_with($name, '.service')) {
                continue;
            }

            // Ein `Type=oneshot` wird von seinem Timer gestartet und nicht vom
            // Ziel. Gefragt wird die Angabe und nicht der Name: Ein Dienst, der
            // seinen Timer verliert, soll dadurch nicht ins Ziel rutschen.
            if (preg_match('/^\s*Type\s*=\s*oneshot\s*$/mD', $inhalt) === 1) {
                continue;
            }

            $units[] = $name;
        }

        sort($units);

        return $units;
    }

    /**
     * Jede Unit-Datei der Paketierung samt Inhalt.
     *
     * @return array<string, string>
     */
    private static function dateien(): array
    {
        $verzeichnis = dirname(__DIR__, 2).'/packaging/systemd';
        $namen = scandir($verzeichnis);

        self::assertIsArray($namen);

        $dateien = [];

        foreach ($namen as $name) {
            if (! str_ends_with($name, '.service') && ! str_ends_with($name, '.timer')) {
                continue;
            }

            $inhalt = file_get_contents($verzeichnis.'/'.$name);

            self::assertIsString($inhalt, $name.' liess sich nicht lesen.');

            $dateien[$name] = $inhalt;
        }

        self::assertNotSame([], $dateien, 'Keine einzige Unit-Datei gelesen — dann misst dieser Wächter nichts.');

        return $dateien;
    }

    /** Der Inhalt der Zieldatei. */
    private static function ziel(): string
    {
        $pfad = dirname(__DIR__, 2).'/packaging/systemd/'.self::ZIEL;

        self::assertFileExists($pfad, 'Ohne die Zieldatei gibt es den einen Griff nicht.');

        $inhalt = file_get_contents($pfad);

        self::assertIsString($inhalt);

        return $inhalt;
    }

    /**
     * Die Werte einer Angabe, über alle ihre Zeilen hinweg.
     *
     * `Wants=` darf mehrfach stehen und mehrere Namen je Zeile tragen; systemd
     * fügt sie zusammen. Ein Leser, der nur die erste Zeile nimmt, meldete eine
     * Lücke, die es nicht gibt.
     *
     * @return list<string>
     */
    private static function angabe(string $inhalt, string $schluessel): array
    {
        $ohneKommentare = preg_replace('/^\s*#.*$/mD', '', $inhalt);

        self::assertIsString($ohneKommentare);

        $treffer = [];
        preg_match_all('/^\s*'.$schluessel.'\s*=\s*(?<werte>.+)$/mD', $ohneKommentare, $treffer);

        $namen = [];

        foreach ($treffer['werte'] as $zeile) {
            foreach (preg_split('/\s+/', trim($zeile)) ?: [] as $name) {
                if ($name !== '') {
                    $namen[] = $name;
                }
            }
        }

        sort($namen);

        return array_values(array_unique($namen));
    }

    public function test_the_target_wants_every_long_running_unit(): void
    {
        $this->assertSame(
            self::erwartet(),
            self::angabe(self::ziel(), 'Wants'),
            'Das Ziel führt nicht genau die Dauerdienste und Timer der Paketierung.',
        );
    }

    public function test_the_target_wants_no_service_a_timer_starts(): void
    {
        $gewollt = array_fill_keys(self::angabe(self::ziel(), 'Wants'), true);
        $gezaehlt = 0;

        foreach (self::dateien() as $name => $inhalt) {
            if (preg_match('/^\s*Type\s*=\s*oneshot\s*$/mD', $inhalt) !== 1) {
                continue;
            }

            $gezaehlt++;

            $this->assertArrayNotHasKey(
                $name,
                $gewollt,
                $name.' wird von seinem Timer gestartet — im Ziel liefe er bei jedem `start` sofort los.',
            );
        }

        // Ohne diese Untergrenze wäre der Wächter grün, sobald jemand
        // `Type=oneshot` anders schreibt: Er prüfte dann null Dienste.
        $this->assertGreaterThanOrEqual(5, $gezaehlt, 'Kein einziger `Type=oneshot` gefunden — dann misst dieser Fall nichts.');
    }

    public function test_every_unit_of_the_target_belongs_to_it(): void
    {
        $traegt = [];

        foreach (self::dateien() as $name => $inhalt) {
            if (in_array(self::ZIEL, self::angabe($inhalt, 'PartOf'), true)) {
                $traegt[] = $name;
            }
        }

        sort($traegt);

        $this->assertSame(
            self::erwartet(),
            $traegt,
            'Ohne `PartOf=` nimmt ein `stop` des Ziels die Unit nicht mit — der Griff schaltete nur an.',
        );
    }

    public function test_the_target_is_packaged(): void
    {
        $nfpm = file_get_contents(dirname(__DIR__, 2).'/packaging/nfpm.yaml');

        self::assertIsString($nfpm);

        $this->assertStringContainsString(
            'dst: /lib/systemd/system/'.self::ZIEL,
            $nfpm,
            'Eine Unit, die kein Paket ablegt, gibt es auf dem Server nicht.',
        );
    }

    public function test_the_installation_enables_the_target(): void
    {
        $postinst = file_get_contents(dirname(__DIR__, 2).'/packaging/scripts/postinstall.sh');

        self::assertIsString($postinst);

        $ohneKommentare = preg_replace('/^\s*#.*$/mD', '', $postinst);

        self::assertIsString($ohneKommentare);

        // `--now` ist nicht beiläufig: Ohne ihn meldete das Ziel `inactive`,
        // während jede seiner Units läuft.
        $this->assertMatchesRegularExpression(
            '/systemctl\s+enable\s+--now\s+'.preg_quote(self::ZIEL, '/').'/',
            $ohneKommentare,
            'Ein Ziel, das die Installation nicht anschaltet, kommt nach einem Neustart nicht wieder.',
        );
    }
}
