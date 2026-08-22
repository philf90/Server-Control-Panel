<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wer etwas in die Einstellungen schreiben kann, wird auch von irgendwo
 * gerufen.
 *
 * ## Der Anlass
 *
 * `Settings::saveDnsAddresses()` gab es seit P7 Schritt 4, und **nichts hat es
 * aufgerufen** — kein Formular, keine Route, kein Schalter am Kommando. Die
 * Gegenseite `dnsAddresses()` wurde gelesen, die Domainseite hielt ihr Ergebnis
 * sogar gegen die abgeleiteten Adressen und wollte warnen, wenn beide
 * auseinandergehen. Sie konnten nie auseinandergehen.
 *
 * > **Eine Einstellung, die sich lesen, aber nirgends setzen lässt, ist keine
 * > Einstellung — sie ist ein Vorsatz.**
 *
 * Gefunden hat es die Zwischenabnahme von P7 (`docs/74`, Befund 2), und zwar
 * beim Ausschreiben des Laufs — nicht im Betrieb. Aufgefallen wäre es sonst
 * erst dem ersten Betreiber hinter NAT, dem der Abgleich jede Domain als
 * „zeigt woandershin" meldet und der keinen Weg findet, das zu berichtigen.
 *
 * ## Warum es diesen Wächter vorher nicht gab
 *
 * Für dieselbe Form gibt es einen seit P3: `AgentOperationReachTest` fand zwei
 * fertig gebaute Agent-Operationen, die von nichts aufgerufen wurden. Die Regel
 * war damit für den Agenten aufgeschrieben — und für `Settings` nicht.
 *
 * > **Eine Regel, die für einen Gegenstand gilt, gilt nicht für den nächsten,
 * > bloss weil sie dieselbe ist.**
 *
 * ## Was er nicht prüft
 *
 * Ob der Aufrufer **erreichbar** ist. Eine Route hinter einer Freigabe, die
 * niemand hat, oder ein Knopf, den keine Seite zeigt, gilt hier als Aufrufer.
 * Das kann kein Test halten — es hängt daran, was ein Betreiber sucht.
 */
final class SettingsWriterReachTest extends TestCase
{
    /**
     * Die Bäume, in denen ein Aufrufer stehen darf.
     *
     * `tests/` fehlt mit Absicht: Ein Testfall, der eine Schreibmethode ruft,
     * belegt nicht, dass sie im Betrieb erreichbar ist — er belegt, dass sie
     * sich rufen lässt. Genau diesen Unterschied soll dieser Wächter machen.
     *
     * @var list<string>
     */
    private const CALLERS = ['app', 'agent/src', 'routes', 'config'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Schreibmethoden von `Settings`.
     *
     * **Am Namen und nicht an einer Liste.** Eine Liste nennt die, an die
     * jemand beim Schreiben gedacht hat; die nächste Methode stünde nicht darin
     * und dürfte die Regel brechen.
     *
     * @return list<string>
     */
    private function writers(): array
    {
        $quelle = (string) file_get_contents($this->root().'/app/Support/Settings/Settings.php');

        preg_match_all('/^\s*public function (save\w+|store\w*)\s*\(/m', $quelle, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet der Ausdruck nichts, prüft der Fall darunter null Methoden und ist
     * grün, ohne etwas gesehen zu haben. Fünf sind es beim Bau dieses Wächters.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_writers_to_check(): void
    {
        $this->assertGreaterThanOrEqual(
            4,
            count($this->writers()),
            'In Settings sind fast keine Schreibmethoden zu finden — der Ausdruck trifft nicht mehr.',
        );
    }

    /**
     * **Und die Gegenprobe zur anderen Hälfte.**
     *
     * Der Fall darüber sichert den Ausdruck über die Methodennamen. Er sagt
     * nichts über den Scanner, der die Aufrufer sucht — und genau der war im
     * ersten Wurf kaputt: `glob('**\/*.php')` rekursiert in PHP nicht, also
     * fand er nichts und meldete alle fünf als tot.
     *
     * Diese Zahl steht dagegen. Sie ist bewusst niedrig: Sie soll einen
     * Scanner fangen, der ins Leere läuft, und nicht jede Umbenennung melden.
     */
    public function test_the_scan_for_callers_finds_anything(): void
    {
        $summe = 0;

        foreach ($this->writers() as $name) {
            $summe += $this->callers($name);
        }

        $this->assertGreaterThanOrEqual(
            4,
            $summe,
            'Der Scanner findet fast keine Aufrufe — dann sagt der Fall darunter nichts.',
        );
    }

    /**
     * Jede von ihnen wird ausserhalb von `Settings` gerufen.
     */
    public function test_every_writer_is_called_from_somewhere(): void
    {
        $tot = [];

        foreach ($this->writers() as $name) {
            if ($this->callers($name) === 0) {
                $tot[] = $name.'()';
            }
        }

        $this->assertSame([], $tot, implode("\n", [
            'Diese Schreibmethoden von Settings ruft niemand:',
            ...$tot,
            '',
            'Damit laesst sich der Wert nur lesen und nirgends setzen. Alles, was',
            'auf ihn hin gebaut ist — eine Warnung, ein Vergleich, ein Sonderfall —,',
            'ist toter Code, und niemand merkt es, weil der Lesepfad funktioniert.',
            '',
            'Entweder bekommt die Methode ihren Weg hinein, oder sie faellt mit',
            'ihrer Gegenseite weg.',
        ]));
    }

    /**
     * Wie oft dieser Name ausserhalb von `Settings.php` gerufen wird.
     */
    private function callers(string $name): int
    {
        $gefunden = 0;

        foreach (self::CALLERS as $baum) {
            $verzeichnis = $this->root().'/'.$baum;

            if (! is_dir($verzeichnis)) {
                continue;
            }

            /*
             * **Über den Iterator und nicht über `glob('**\/*.php')`.** PHPs
             * `glob` kennt kein rekursives `**`; der erste Wurf dieses
             * Wächters fand damit fast keine Datei und meldete alle fünf
             * Schreibmethoden als tot. Vier davon haben ihren Aufrufer seit
             * Monaten.
             *
             * > **Ein Wächter, der beim ersten Lauf den halben Bestand meldet,
             * > hat den Bestand nicht überführt — er hat sich selbst
             * > widerlegt.**
             */
            /** @var SplFileInfo $datei */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($verzeichnis, FilesystemIterator::SKIP_DOTS),
            ) as $datei) {
                if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                    continue;
                }

                if (str_ends_with($datei->getPathname(), '/Settings/Settings.php')) {
                    continue;
                }

                $gefunden += substr_count((string) file_get_contents($datei->getPathname()), '->'.$name.'(');
            }
        }

        return $gefunden;
    }
}
