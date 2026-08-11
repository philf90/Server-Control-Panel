<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Jede Zeit, die die Oberfläche erreicht, kommt aus `Clock`.
 *
 * **Der Anlass steht in `docs/40`**: Ein Eintrag um `12:31:26` war UTC, und der
 * Betreiber las ihn auf einer Uhr, die zwei Stunden weiter ist.
 *
 * > **Ein Zeitstempel, den man falsch liest, ist schlimmer als keiner — er
 * > sieht aus wie eine Auskunft.**
 *
 * Achtzehn Stellen gaben eine Zeit heraus, alle über `toDateTimeString()`. Sie
 * umzustellen ist die eine Hälfte; **die andere ist, dass die neunzehnte nicht
 * wieder danebengeht.** `Names::fqdn()` ist viermal neu erfunden worden, bevor
 * es dafür einen Wächter gab — hier steht er von Anfang an.
 *
 * ## Die Ausnahmen stehen im Wert und nicht in einem Kommentar
 *
 * Dieselbe Form wie `Pg\Shielding::EXEMPT` und `RemovalPathTest::WITHOUT_REMOVAL`:
 * Eine Liste ohne Begründung je Eintrag wächst, bis sie alles enthält.
 */
final class TimeDisplayTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Wo `toDateTimeString()` stehen darf — und warum.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        'app/Http/Controllers/AuditController.php' => 'Das CSV bleibt UTC (`docs/40 §3.3`). Der Export '
            .'ist ein Beleg, den jemand aufhebt — er wird gelesen, wenn der Server längst umgezogen '
            .'und die Einstellung eine andere ist. Die Zone steht in der Kopfzeile, die Werte bleiben, '
            .'wie sie gespeichert sind.',

        'app/Support/Settings/Settings.php' => 'Hier wird geschrieben und nicht angezeigt: `checked_at` '
            .'und `changed_at` gehen als Text in `settings` und liegen dort in UTC. Wer sie später '
            .'zeigt, dreht sie an der Lesestelle mit `Clock::displayText()` — eine Zeit in der '
            .'Anzeigezone zu speichern hiesse, den Bestand von einer Einstellung abhängig zu machen, '
            .'die sich ändern darf.',
    ];

    /** @return list<string> */
    private function sources(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_no_page_formats_a_time_of_its_own(): void
    {
        $root = dirname(__DIR__, 2).'/';
        $seen = 0;
        $stray = [];

        foreach ($this->sources() as $file) {
            $relative = str_replace($root, '', $file);

            // Ohne Kommentare: In `Clock` selbst steht der Name im Fliesstext,
            // und ein Wächter, der ihn mitliest, prüft die Erklärung statt des
            // Codes. In dieser Woche viermal passiert.
            $code = $this->withoutComments((string) file_get_contents($file));

            if (! str_contains($code, 'toDateTimeString()')) {
                continue;
            }

            $seen++;

            if (! array_key_exists($relative, self::EXEMPT)) {
                $stray[] = $relative;
            }
        }

        $this->assertSame([], $stray, sprintf(
            "Diese Stellen formatieren eine Zeit selbst:\n  %s\n\n"
            .'Was auf eine Seite geht, kommt aus `Clock::display()` — sonst steht dort UTC und '
            ."sieht aus wie Ortszeit.\n\n"
            .'Gehört die Stelle wirklich nicht dazu, kommt sie mit einer Begründung in '
            .'`TimeDisplayTest::EXEMPT` — im Wert und nicht in einem Kommentar daneben.',
            implode("\n  ", $stray),
        ));

        /*
         * **Die Untergrenze zählt, wo die Regel stehen darf.** Läuft der
         * Ausdruck ins Leere — weil jemand den Aufruf umbenennt —, meldete er
         * „alles in Ordnung" für eine Fläche, die er nicht mehr liest.
         */
        $this->assertGreaterThanOrEqual(2, $seen, 'Der Ausdruck findet keine Zeitformatierung mehr.');
    }

    /** Und jeder Eintrag der Liste sagt, warum er dasteht. */
    public function test_every_exemption_carries_its_reason(): void
    {
        foreach (self::EXEMPT as $file => $why) {
            $this->assertFileExists(dirname(__DIR__, 2).'/'.$file, sprintf(
                'Die Ausnahme %s zeigt auf eine Datei, die es nicht gibt.',
                $file,
            ));

            $this->assertGreaterThan(120, strlen($why), sprintf(
                '%s steht ohne Begründung in der Liste. Was fehlt, ist nicht der Satz, sondern der '
                .'Grund — ohne ihn weiss der Nächste nicht, ob der Eintrag noch gilt.',
                $file,
            ));
        }
    }
}
