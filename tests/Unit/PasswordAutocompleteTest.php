<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutMarkupComments;

/**
 * Ein Feld, in das ein Passwort kommt, sagt dem Browser, welches.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Er ist beim Nachsehen der `3 issues` auf `/backups/<id>/restore` entstanden
 * (`docs/126`, 20. September 2026). Chromium meldet dort dreimal *„A form field
 * element should have an id or name attribute"* — die Ausfüllhilfe und nicht
 * die Zugänglichkeit, und schon am 23. August als **kein Fund** entschieden
 * (`docs/76`). Der Satz, der diese Entscheidung trägt, lautet dort:
 *
 * > Die eine Stelle, an der ein Browser ihn wirklich braucht, hat ihn.
 *
 * Gemessen stimmt das: **dreizehn von dreizehn** Feldern, die ein Passwort
 * aufnehmen können, führen ein `autocomplete` — zehnmal `new-password`,
 * dreimal `current-password`. Jedes einzeln hingeschrieben, und **nichts hält
 * es**.
 *
 * > **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
 * > stimmt, nur durch Glück getrennt.**
 *
 * ## Was daran hängt
 *
 * Ohne die Angabe rät der Passwortspeicher. Dieses Panel hat **acht** Felder
 * für fremde Geheimnisse — sieben in `DnsCredentials.vue` für Token, API-
 * Schlüssel und Kennwörter fremder Dienste, dazu das des Mailversands — und
 * **fünf** am eigenen Konto. Rät der Speicher falsch, bietet er das
 * Panelpasswort für einen DNS-Token an oder merkt sich einen API-Schlüssel als
 * Anmeldung. Beides fällt niemandem auf, und beides ist ein Geheimnis an der
 * falschen Stelle.
 *
 * Deshalb reicht `off` hier **nicht**: Für Passwortfelder übergehen die Browser
 * es seit Jahren. Verlangt wird das Wort, das die Absicht nennt — `new-password`
 * für ein Geheimnis, das entsteht, `current-password` für eines, das geprüft
 * wird.
 *
 * ## Warum der Leser Anführungszeichen achtet
 *
 * **Das ist an genau diesem Wächter bezahlt worden.** Die erste Messung dazu
 * las die Marke mit `<input[^>]*>` und meldete das Wiederholungsfeld in
 * {@see PasswordAutocompleteTest::PAAR} als Feld ohne
 * `autocomplete` — es trägt eines, nur steht davor
 * `:aria-invalid="… || (props.confirmation.length > 0 && !matches)"`. Der
 * Ausdruck hört am `>` im Attributwert auf, und alles dahinter fehlt.
 *
 * > **Ein Ausdruck, der ein Tag bis zum nächsten `>` liest, liest ein halbes
 * > Tag, sobald eines im Attribut steht.**
 *
 * Der Satz steht seit dem 5. September im Kopf von {@see NoticeChildrenTest} —
 * dort war es eine Meldung, hier ein Passwortfeld, und die Vermeidung war keine
 * Regel geworden. Gemeldet hätte dieser Wächter damit ausgerechnet das Feld,
 * für das es ihn gibt: ein **falsches Rot**, und die Behebung dagegen hätte ein
 * zweites `autocomplete` an eine Marke geschrieben, die schon eines trägt.
 *
 * ## Was er nicht hält
 *
 * Ob die Angabe **richtig** ist. `new-password` an einem Feld, das ein
 * bestehendes Passwort prüft, fällt hier nicht auf — das entscheidet, wofür das
 * Formular da ist, und keine Eigenschaft der Marke.
 *
 * > **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**
 */
final class PasswordAutocompleteTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Die Wörter, die die Absicht nennen.
     *
     * `off` steht bewusst nicht dabei — siehe den Kopf.
     *
     * @var list<string>
     */
    private const ERLAUBT = ['new-password', 'current-password'];

    /**
     * Das Wiederholungsfeld aus `PasswordFields.vue`, wörtlich.
     *
     * Es ist der Prüfkörper für den Leser und nicht für die Regel: Es trägt
     * sein `autocomplete` **hinter** einem Attributwert mit einem `>` darin.
     */
    private const PAAR = '<input :value="props.confirmation" :type="visible ? \'text\' : \'password\'" '
        .':aria-invalid="Boolean(props.confirmationError) || (props.confirmation.length > 0 && !matches)" '
        .'autocomplete="new-password" spellcheck="false" required>';

    public function test_every_password_field_names_its_purpose(): void
    {
        $gelesen = 0;
        $ohne = [];

        foreach ($this->vorlagen() as $pfad => $quelle) {
            foreach ($this->passwortfelder($quelle) as [$zeile, $marke]) {
                $gelesen++;

                if ($this->absicht($marke) !== null) {
                    continue;
                }

                $ohne[] = sprintf('%s:%d', $pfad, $zeile);
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf*. Ohne sie meldet
        // dieser Wächter Grün, sobald sein Ausdruck ins Leere greift — und ein
        // `:type`-Ausdruck ist genau die Form, die ein Umbau umschreibt.
        $this->assertGreaterThanOrEqual(10, $gelesen,
            'Es werden kaum Passwortfelder gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $ohne, sprintf(
            "Diese Felder nehmen ein Passwort auf und sagen dem Browser nicht, welches:\n  %s\n\n".
            'Erlaubt ist `new-password` oder `current-password`. `off` nicht: Für Passwortfelder '.
            'übergehen die Browser es, und der Speicher rät dann zwischen dem Panelpasswort und '.
            'einem fremden Geheimnis.',
            implode("\n  ", $ohne),
        ));
    }

    /**
     * Der Leser sieht über ein `>` im Attributwert hinweg — beide Richtungen.
     *
     * Die Gegenprobe zum Abtaster, und sie steht hier statt in
     * `waechter-brechen.sh`: Sie braucht keine Datei im Baum, nur die Regel.
     * Ohne sie bliebe offen, ob der Wächter das Feld findet oder es bloss nicht
     * meldet, weil er es gar nicht sieht.
     */
    public function test_the_reader_sees_past_an_angle_bracket_in_an_attribute(): void
    {
        $gefunden = $this->passwortfelder('<template>'."\n".self::PAAR."\n".'</template>');

        $this->assertCount(1, $gefunden, 'Das Wiederholungsfeld wird gar nicht als Passwortfeld erkannt.');
        $this->assertSame('new-password', $this->absicht($gefunden[0][1]),
            'Der Leser hört am `>` im Attributwert auf und übersieht das `autocomplete` dahinter.');

        // Und die andere Richtung: dieselbe Marke ohne die Angabe wird gemeldet.
        $ohne = str_replace(' autocomplete="new-password"', '', self::PAAR);

        $this->assertNull($this->absicht($this->passwortfelder('<template>'.$ohne.'</template>')[0][1]),
            'Ein Feld ohne `autocomplete` gilt als beschriftet — dann meldet die Regel nie etwas.');
    }

    /**
     * Ein `off` ist keine Absicht.
     *
     * Der Fall steht hier und nicht im Bruchskript, weil er die *Wertliste*
     * prüft und nicht die Anwesenheit des Attributs — zwei verschiedene Fehler,
     * und der zweite sieht im Baum wie der erste aus.
     */
    public function test_off_is_not_an_intention(): void
    {
        $aus = str_replace('autocomplete="new-password"', 'autocomplete="off"', self::PAAR);

        $this->assertNull($this->absicht($this->passwortfelder('<template>'.$aus.'</template>')[0][1]),
            '`autocomplete="off"` zählt als Absicht — Browser übergehen es an Passwortfeldern.');
    }

    /** Das Wort, das die Absicht nennt — oder `null`. */
    private function absicht(string $marke): ?string
    {
        if (preg_match('/\bautocomplete\s*=\s*"([^"]*)"/', $marke, $treffer) !== 1) {
            return null;
        }

        return in_array($treffer[1], self::ERLAUBT, true) ? $treffer[1] : null;
    }

    /**
     * Jede `<input>`-Marke, die ein Passwort aufnehmen kann.
     *
     * Gesucht wird `type="password"` **und** die gebundene Form: Zehn der
     * dreizehn Felder dieses Panels schalten zwischen `text` und `password` um,
     * weil sie einen Knopf zum Anzeigen tragen ({@see RevealTest}). Ein Wächter,
     * der nur die wörtliche Form kennt, sähe drei.
     *
     * > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
     * > Gewohnheit und nicht die Regel.**
     *
     * @return list<array{int, string}>
     */
    private function passwortfelder(string $quelle): array
    {
        $ohneKommentare = $this->withoutMarkupComments($quelle);
        $funde = [];
        $stelle = 0;

        while (preg_match('/<input\b/', $ohneKommentare, $treffer, PREG_OFFSET_CAPTURE, $stelle) === 1) {
            $von = (int) $treffer[0][1];
            $bis = $this->markenende($ohneKommentare, $von + 6);
            $marke = substr($ohneKommentare, $von, $bis - $von + 1);
            $stelle = $bis + 1;

            if (preg_match('/\b(:|v-bind:)?type\s*=\s*"[^"]*\bpassword\b[^"]*"/', $marke) !== 1) {
                continue;
            }

            $funde[] = [substr_count(substr($ohneKommentare, 0, $von), "\n") + 1, $marke];
        }

        return $funde;
    }

    /**
     * Die Stelle des `>`, das die Marke wirklich schliesst.
     *
     * Gezählt wird der Anführungszustand und nicht bis zum nächsten `>` gelesen
     * — siehe den Kopf dieser Klasse.
     */
    private function markenende(string $quelle, int $von): int
    {
        $laenge = strlen($quelle);
        $quote = null;

        for ($i = $von; $i < $laenge; $i++) {
            $c = $quelle[$i];

            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;

                continue;
            }

            if ($c === '>') {
                return $i;
            }
        }

        return $laenge - 1;
    }

    /**
     * Jede Seite und jede Komponente.
     *
     * @return array<string, string>
     */
    private function vorlagen(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $dateien['resources/js'.substr($datei->getPathname(), strlen($wurzel))] =
                    (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }
}
