<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Feld, das ein Datum oder eine Uhrzeit verlangt, hat den Eingabetyp dazu.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * Am 4. September 2026 hat der Betreiber den Wartungsmodus auf dem Telefon
 * einschalten wollen. Das Feld „Voraussichtlich bis" war ein `type="text"` mit
 * `inputmode="numeric"` für die Form `Y-m-d H:i` — und die Zifferntastatur von
 * iOS gibt weder Bindestrich noch Doppelpunkt noch Leerzeichen her. Auf dem
 * Bildschirmfoto steht `20260904`, und weiter kam er nicht.
 *
 * > **Ein Format, das kein Eingabetyp hergibt, ist auf dem Telefon nicht
 * > tippbar — und `inputmode` gibt weniger Zeichen her, als das Format
 * > verlangt.**
 *
 * **Auf `/audit` stand das Paar seit P2 richtig da**: `date_format:Y-m-d` und
 * daneben ein `type="date"`. Die Vermeidung war nur nie die Regel geworden.
 *
 * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
 * > wieder da, wenn die Vermeidung nicht die Regel wurde.**
 *
 * ## Warum es keinen Typ für „Datum und Uhrzeit in einem" gibt
 *
 * `datetime-local` sieht danach aus und ist es nicht: Sein Wert trägt zwischen
 * Datum und Uhrzeit ein `T` und nicht das Leerzeichen, das `Y-m-d H:i`
 * verlangt. Ein zusammengesetztes Format gehört deshalb auf **zwei** Felder,
 * und dieser Wächter sagt das mit dem Grund, statt es zu dulden.
 *
 * ## Was er nicht kann
 *
 * Er liest den Quelltext und nicht den Browser: Dass ein `type="date"`
 * tatsächlich einen Auswähler öffnet, ist eine Zusage der Geräte und keine des
 * Repositorys. Und er findet ein Feld nur, wenn dessen `v-model` auf den Namen
 * endet, unter dem die Steuerung es prüft — heissen die beiden verschieden,
 * meldet er es als fehlend und nicht als falsch.
 */
final class DateInputTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    /**
     * Was ein Eingabetyp hergibt — Format zu `type`.
     *
     * Die Liste ist kurz, weil sie vollständig ist: Mehr Formate erzeugt kein
     * Browser von sich aus. Ein Format, das hier fehlt, ist deshalb kein
     * fehlender Eintrag, sondern ein Feld, das niemand tippen kann.
     */
    private const TYPE_FOR_FORMAT = [
        'Y-m-d' => 'date',
        'H:i' => 'time',
    ];

    /**
     * Die Felder, die es geben **muss** — die Untergrenze.
     *
     * Ohne sie stünde eine Null da, wo „nichts gefunden" und „nichts zu finden"
     * gleich aussehen.
     *
     * @var list<string>
     */
    private const KNOWN = ['from', 'to', 'until_date', 'until_time'];

    /**
     * Jedes Feld mit `date_format`, gelesen aus den Steuerungen.
     *
     * @return array<string, array{format: string, file: string}>
     */
    private function fields(): array
    {
        $gefunden = [];

        foreach ($this->phpFiles(__DIR__.'/../../app/Http') as $datei) {
            $quelle = $this->withoutComments((string) file_get_contents($datei));

            preg_match_all(
                "/'([A-Za-z0-9_.*]+)'\s*=>\s*\[(?:\s*'[^']*',)*\s*'date_format:([^']+)'/",
                $quelle,
                $treffer,
                PREG_SET_ORDER,
            );

            foreach ($treffer as $satz) {
                $gefunden[$satz[1]] = ['format' => $satz[2], 'file' => basename($datei)];
            }
        }

        return $gefunden;
    }

    /** @return list<string> */
    private function phpFiles(string $verzeichnis): array
    {
        $dateien = [];

        /** @var \SplFileInfo $eintrag */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($verzeichnis)) as $eintrag) {
            if ($eintrag->isFile() && $eintrag->getExtension() === 'php') {
                $dateien[] = $eintrag->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    /**
     * Jedes `<input>` der Oberfläche, samt seinem `v-model` und seinem `type`.
     *
     * @return list<array{model: string, type: ?string, file: string}>
     */
    private function inputs(): array
    {
        $felder = [];

        /** @var \SplFileInfo $eintrag */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../resources/js')) as $eintrag) {
            if (! $eintrag->isFile() || $eintrag->getExtension() !== 'vue') {
                continue;
            }

            $quelle = $this->withoutMarkupComments((string) file_get_contents($eintrag->getPathname()));

            preg_match_all('/<input\b[^>]*>/', $quelle, $treffer);

            foreach ($treffer[0] as $tag) {
                if (preg_match('/v-model="([^"]+)"/', $tag, $model) !== 1) {
                    continue;
                }

                $felder[] = [
                    'model' => $model[1],
                    'type' => preg_match('/\btype="([^"]+)"/', $tag, $typ) === 1 ? $typ[1] : null,
                    'file' => basename($eintrag->getPathname()),
                ];
            }
        }

        return $felder;
    }

    public function test_no_field_asks_for_a_date_and_a_time_at_once(): void
    {
        foreach ($this->fields() as $name => $regel) {
            self::assertArrayHasKey(
                $regel['format'],
                self::TYPE_FOR_FORMAT,
                sprintf(
                    '%s prüft „%s" gegen `date_format:%s`. Diese Form gibt kein Eingabetyp her — '.
                    '`datetime-local` trägt ein `T` statt des Leerzeichens. Auf zwei Felder aufteilen.',
                    $regel['file'],
                    $name,
                    $regel['format'],
                ),
            );
        }
    }

    public function test_every_date_format_rule_has_an_input_that_can_produce_it(): void
    {
        $inputs = $this->inputs();

        foreach ($this->fields() as $name => $regel) {
            $erwartet = self::TYPE_FOR_FORMAT[$regel['format']] ?? null;

            if ($erwartet === null) {
                continue; // Der andere Fall, und er hat seine eigene Prüfung.
            }

            $passend = array_values(array_filter(
                $inputs,
                static fn (array $feld): bool => $feld['model'] === $name
                    || str_ends_with($feld['model'], '.'.$name),
            ));

            self::assertNotSame([], $passend, sprintf(
                '%s prüft „%s" gegen `date_format:%s`, aber keine Seite hat ein `<input>` darauf.',
                $regel['file'],
                $name,
                $regel['format'],
            ));

            foreach ($passend as $feld) {
                self::assertSame($erwartet, $feld['type'], sprintf(
                    '%s bindet „%s" an ein `type="%s"`. `date_format:%s` verlangt `type="%s"` — '.
                    'sonst gibt die Tastatur des Telefons die nötigen Zeichen nicht her.',
                    $feld['file'],
                    $name,
                    $feld['type'] ?? 'text',
                    $regel['format'],
                    $erwartet,
                ));
            }
        }
    }

    /**
     * Die Untergrenze — an beiden Ausdrücken.
     *
     * Läuft einer von beiden ins Leere, sind die Prüfungen darüber grün, ohne
     * etwas gemessen zu haben.
     */
    public function test_both_expressions_find_the_known_fields(): void
    {
        $felder = $this->fields();
        $models = array_column($this->inputs(), 'model');

        foreach (self::KNOWN as $name) {
            self::assertArrayHasKey($name, $felder, 'Kein `date_format` mehr für „'.$name.'" gefunden.');

            $gebunden = array_filter(
                $models,
                static fn (string $model): bool => $model === $name || str_ends_with($model, '.'.$name),
            );

            self::assertNotSame([], $gebunden, 'Kein `<input>` mehr für „'.$name.'" gefunden.');
        }
    }
}
