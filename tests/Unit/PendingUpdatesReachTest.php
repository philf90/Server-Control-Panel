<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Was die Ablage der offenen Aktualisierungen hergibt, kommt auf einer Seite an.
 *
 * **Warum es diesen Wächter gibt.** `Settings::savePendingUpdates()` legt seit
 * dem 11. September 2026 **zwei** Felder ab — die Zahl und den Zeitpunkt — und
 * bietet für beide einen Leser an. Gelesen wurde am selben Tag nur die Zahl:
 * `pendingUpdatesCheckedAt()` stand da und wurde von **niemandem** gerufen.
 *
 * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
 * > einem zu unterscheiden, das es nicht gibt.**
 *
 * Das wiegt hier mehr als anderswo, weil der Zeitpunkt genau die Frage
 * beantwortet, die das Abzeichen nicht beantworten kann: Die Navigation zeigt
 * eine **Abschrift** — `system.packages.list` kostet gemessen 3033 ms und wird
 * deshalb nicht bei jedem Seitenaufbau gefragt (`docs/907 §1.1`). Ohne den
 * Zeitpunkt sieht eine Zahl von gestern aus wie eine von dieser Minute.
 *
 * **Die Leser kommen aus der Klasse und nicht aus einer Liste hier.** Eine
 * Liste im Test wäre die zweite Fassung derselben Auskunft, und die zweite ist
 * die, die veraltet — genau der Fehler, der `ValidationLanguageTest` am
 * 10. September blind gemacht hat. Gesucht wird deshalb nach den öffentlichen
 * Methoden, deren Rumpf die Marke der Ablage nennt.
 *
 * **Der Name ist der Faden.** Eine Eigenschaft, unter der ein solcher Wert zur
 * Seite reist, heisst hier wie ihr Leser — `pendingUpdates` und
 * `pendingUpdatesCheckedAt`, zwei von zwei. Das ist eine Gewohnheit dieses
 * Panels und keine Regel des Frameworks; sie steht hier, weil sie den Faden
 * zwischen Ablage und Vorlage prüfbar macht, und wer sie bricht, macht diesen
 * Wächter zu Recht rot.
 *
 * **Gesucht wird im Vorlagenblock und nicht in der ganzen Datei**, und das ist
 * gemessen: Der erste Wurf las die `.vue` im Ganzen und blieb **grün**, als der
 * gerenderte Satz entfernt wurde — die Prop-Deklaration im `<script setup>`
 * trägt denselben Namen.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht — und eine Deklaration ist keine Anzeige.**
 *
 * **Was er nicht kann:** Er sagt nicht, ob der Satz auf der Seite *stimmt* oder
 * ob er an der richtigen Stelle steht. Dass er über dem dreiwertigen Zweig
 * steht — sein nützlichster Augenblick ist der Platzhalter — ist eine
 * Entscheidung und keine Eigenschaft des Quelltextes.
 */
final class PendingUpdatesReachTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const SETTINGS = __DIR__.'/../../app/Support/Settings/Settings.php';

    private const MARKE = 'self::PENDING_UPDATES';

    /**
     * Jeder Leser der Ablage wird gerufen — und der Wert erreicht eine Vorlage.
     *
     * Beide Richtungen in einem Fall, weil eine allein nichts hält: Ein Leser,
     * den ein Controller ruft und keine Seite rendert, ist derselbe tote Wert
     * wie einer, den niemand ruft — er reist nur weiter, bevor er verfällt.
     */
    public function test_every_reader_of_the_store_reaches_a_page(): void
    {
        $leser = $this->readers();

        $this->assertGreaterThanOrEqual(2, count($leser), implode("\n", [
            sprintf('Es wurden nur %d Leser der Ablage gefunden.', count($leser)),
            '',
            'Erwartet sind mindestens zwei — die Zahl und der Zeitpunkt. Findet der',
            'Ausdruck weniger, misst dieser Wächter nichts: Entweder heisst die Marke',
            sprintf('nicht mehr `%s`, oder die Leser sind umgezogen.', self::MARKE),
        ]));

        $ruft = $this->sourceOf('app', 'php', self::SETTINGS);
        $vorlagen = $this->templates();

        foreach ($leser as $name) {
            $this->assertStringContainsString('->'.$name.'(', $ruft, implode("\n", [
                sprintf('`Settings::%s()` wird von niemandem gerufen.', $name),
                '',
                'Ein Leser ohne Aufrufer ist von einem Feld, das es nicht gibt, von',
                'aussen nicht zu unterscheiden — und genau das war dieser Zeitpunkt am',
                '11. September 2026, an dem Tag, an dem er entstand.',
            ]));

            $this->assertStringContainsString($name, $vorlagen, implode("\n", [
                sprintf('Der Wert aus `Settings::%s()` kommt auf keiner Seite an.', $name),
                '',
                'Er reist unter dem Namen seines Lesers — das ist die Gewohnheit dieses',
                'Panels, und sie ist hier der Faden zwischen Ablage und Vorlage. Wer die',
                'Eigenschaft anders nennt, trägt den neuen Namen im Kopf dieses Wächters',
                'nach; wer den Wert fallen lässt, behebt den Befund.',
            ]));
        }
    }

    /**
     * Der Schreiber legt genau die Felder ab, für die es Leser gibt.
     *
     * Die Gegenrichtung: Ein drittes Feld in `savePendingUpdates()`, das
     * niemand liest, ist derselbe tote Wert — nur eine Ebene früher, und ohne
     * Leser fiele er dem Fall darüber gar nicht auf.
     */
    public function test_the_writer_stores_nothing_that_nobody_reads(): void
    {
        $quelle = $this->withoutComments(file_get_contents(self::SETTINGS));

        $geschrieben = $this->storedKeys($quelle);
        $gelesen = $this->readKeys($quelle);

        $this->assertNotEmpty($geschrieben, implode("\n", [
            'In `savePendingUpdates()` wurden keine abgelegten Felder gefunden.',
            '',
            'Ohne sie misst dieser Fall nichts — der Ausdruck greift ins Leere.',
        ]));

        $this->assertSame([], array_values(array_diff($geschrieben, $gelesen)), sprintf(
            "Diese Felder werden abgelegt und von keinem Leser geholt:\n  %s\n\n".
            "Entweder bekommen sie einen Leser, oder sie gehören aus dem Schreiber\n".
            'heraus — abgelegt und nie gelesen ist der teuerste der beiden Zustände.',
            implode(', ', array_diff($geschrieben, $gelesen)),
        ));
    }

    /**
     * Die öffentlichen Leser der Ablage, aus der Klasse gelesen.
     *
     * Der Schreiber fällt heraus: Er nennt dieselbe Marke und ist kein Leser.
     * Unterschieden wird an der **Wirkung** und nicht am Namen — ein Leser gibt
     * etwas zurück, der Schreiber gibt `void`.
     *
     * @return list<string>
     */
    private function readers(): array
    {
        $quelle = $this->withoutComments(file_get_contents(self::SETTINGS));

        preg_match_all(
            '/public function (\w+)\(\s*\)\s*:\s*\??\w+\s*\{(.*?)\n    \}/sD',
            $quelle,
            $treffer,
            PREG_SET_ORDER,
        );

        $leser = [];

        foreach ($treffer as [, $name, $rumpf]) {
            if (str_contains($rumpf, self::MARKE) && str_contains($rumpf, 'return')) {
                $leser[] = $name;
            }
        }

        return $leser;
    }

    /**
     * Die Felder, die `savePendingUpdates()` in den Wert schreibt.
     *
     * @return list<string>
     */
    private function storedKeys(string $quelle): array
    {
        $von = strpos($quelle, 'function savePendingUpdates');
        $this->assertNotFalse($von, 'Es gibt kein `savePendingUpdates()` mehr.');

        $bis = strpos($quelle, "\n    }", $von);
        $rumpf = substr($quelle, $von, $bis - $von);

        preg_match_all("/'(\w+)'\s*=>/", $rumpf, $treffer);

        return array_values(array_diff($treffer[1], ['key', 'value']));
    }

    /**
     * Die Felder, die irgendein Leser aus dem Wert holt.
     *
     * @return list<string>
     */
    private function readKeys(string $quelle): array
    {
        preg_match_all(
            '/'.preg_quote(self::MARKE, '/')."\)\['(\w+)'\]/",
            $quelle,
            $treffer,
        );

        return array_values(array_unique($treffer[1]));
    }

    /**
     * Die Vorlagenblöcke aller Seiten als **ein** Text, ohne ihre Kommentare.
     *
     * Der `<script setup>`-Teil bleibt draussen: Dort steht die Deklaration,
     * und die ist keine Anzeige. Die Kommentare fallen vorsorglich weg — wer
     * eine Behebung erklärt, schreibt in diesem Repo den Vorzustand wörtlich
     * hin, und dieser Absatz hier ist selbst ein Beispiel dafür.
     */
    private function templates(): string
    {
        $aus = '';

        foreach ($this->filesOf('resources/js', 'vue') as $datei) {
            $quelle = $this->withoutMarkupComments(file_get_contents($datei));

            $von = strpos($quelle, '<template>');

            if ($von === false) {
                continue;
            }

            $bis = strrpos($quelle, '</template>');
            $aus .= substr($quelle, $von, $bis === false ? null : $bis - $von)."\n";
        }

        return $aus;
    }

    /**
     * Der Quelltext eines Baums als **ein** Text.
     *
     * Gesucht wird darin nach Namen, und dafür genügt die Vereinigung: Wo genau
     * ein Name steht, entscheidet dieser Wächter nicht — nur, dass er irgendwo
     * ankommt. Die ausgenommene Datei ist die Ablage selbst; sonst fände jeder
     * Leser sich in seiner eigenen Deklaration.
     */
    private function sourceOf(string $unter, string $endung, ?string $ohne = null): string
    {
        $aus = '';

        foreach ($this->filesOf($unter, $endung) as $datei) {
            if ($ohne !== null && realpath($datei) === realpath($ohne)) {
                continue;
            }

            $aus .= file_get_contents($datei)."\n";
        }

        return $aus;
    }

    /**
     * Die Dateien einer Endung unter einem Verzeichnis.
     *
     * @return list<string>
     */
    private function filesOf(string $unter, string $endung): array
    {
        $wurzel = realpath(__DIR__.'/../../'.$unter);
        $this->assertNotFalse($wurzel, sprintf('Das Verzeichnis `%s` gibt es nicht.', $unter));

        $aus = [];
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS));

        foreach ($dateien as $datei) {
            if ($datei->getExtension() === $endung) {
                $aus[] = $datei->getPathname();
            }
        }

        return $aus;
    }
}
