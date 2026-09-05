<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Fehlermeldung nennt das Feld so, wie es auf der Seite heisst.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * `docs/66`, Befund 3. Auf `/subscriptions/140/cron` stand nach einem leeren
 * Formular:
 *
 * > Das Feld **Bezeichnung** ist erforderlich.
 *
 * Auf dieser Seite heisst das Feld **Beschriftung**. „Bezeichnung" kommt dort
 * nirgends vor — der Kunde sucht ein Feld, das er nicht sieht.
 *
 * Das war ein Fehler in der Behebung von Befund 15: Die 85 deutschen Namen
 * waren vollständig eingetragen, aber nie gegen die sichtbare Beschriftung
 * ihrer Seite gehalten. `AttributeNameTest` zählt, dass jedes Feld einen Namen
 * **hat**; über den Namen selbst sagt er nichts.
 *
 * > **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**
 *
 * ## Wie hier verglichen wird
 *
 * Erwartet ist nicht *ein* Wort, sondern eine **Menge**: der allgemeine Name
 * aus `lang/de/validation.php` und jeder Name, den die Steuerung dieser Seite
 * am Aufruf setzt. Ein Feld heisst nämlich nicht überall gleich — `path` heisst
 * im Dateimanager einmal „Name der Datei" und einmal „Name des Verzeichnisses",
 * und beide Formulare stehen in derselben Datei.
 *
 * **Die Grenze davon steht hier und nicht in einem Kommentar am Ende:** Die
 * Namen werden je **Datei** gesammelt und nicht je Methode. Trägt eine Steuerung
 * zwei Namen für dasselbe Feld, nimmt dieser Wächter beide an — auch an der
 * Stelle, an der nur einer richtig wäre. Er fängt damit den Fall „steht
 * nirgends" und nicht den Fall „steht an der falschen Stelle".
 *
 * > **Ein Wächter, der eine Menge vergleicht, findet das Fehlende und nicht das
 * > Vertauschte.**
 *
 * ## Zwei Siebe
 *
 * **Das Enthaltensein**, ohne Rücksicht auf Gross- und Kleinschreibung:
 * „Anzeigename" und „Name" meinen dasselbe Feld, und „E-Mail" und
 * „E-Mail-Adresse" auch. Ohne dieses Sieb meldete der Wächter zwölf Fälle, von
 * denen keiner einer ist.
 *
 * **Die begründeten Ausnahmen** ({@see self::KEIN_NAME}): Beschriftungen, die
 * gar kein Name sind, sondern ein Satz oder ein eingesetzter Wert.
 */
final class AttributeLabelTest extends TestCase
{
    /**
     * Beschriftungen, die kein Name sind — mit dem Grund.
     *
     * **Ein Eintrag hier ist eine Aussage und keine Abkürzung.** „Ausgeliefert
     * wird" ist ein Satzanfang, kein Feldname; „Das Feld Ausgeliefert wird ist
     * erforderlich" wäre schlechter als „Das Feld Zertifikat ist erforderlich",
     * obwohl es näher an der Seite steht.
     *
     * @var array<string,string>
     */
    private const KEIN_NAME = [
        'Domains/Show.vue:certificate' => 'Die Beschriftung ist ein Satzanfang („Ausgeliefert wird") '.
            'und kein Name. Der allgemeine Name ist hier der bessere.',
        'Databases/Show.vue:cidr' => 'Die Beschriftung trägt einen eingesetzten Wert '.
            '(„Erreichbar von — für {{ … }}") und steht damit nicht fest.',
        'Announcements/Index.vue:audiences' => 'Die Beschriftung gehört dem einzelnen Kästchen '.
            '(„Betreiber", „Administrator", „Kunde") und nicht dem Feld. Das Feld ist die Gruppe, '.
            'und ihr Name steht als eigener `span` darüber: „Publikum". Wer „Das Feld Publikum muss …" '.
            'liest, findet ihn dort.',
    ];

    /** Jede sichtbare Beschriftung stimmt mit dem Namen überein, den der Server benutzt. */
    public function test_every_label_matches_the_name_the_server_uses(): void
    {
        $namen = $this->attributes();
        $paare = $this->pairs($namen);

        /*
         * **Zwei Untergrenzen.** Die erste sagt, dass Beschriftungen gefunden
         * wurden; die zweite, dass darunter welche mit einem am Aufruf
         * gesetzten Namen sind. Ohne die zweite wäre der Wächter auch dann
         * grün, wenn die Auslese der Aufrufe ins Leere liefe — und genau die
         * ist die Behebung dieses Befundes.
         */
        $this->assertGreaterThanOrEqual(40, count($paare), sprintf(
            'Nur %d Beschriftungen mit einem bekannten Feld gefunden — dann prüft dieser Wächter '.
            'nichts.',
            count($paare),
        ));

        $amAufruf = count(array_filter($paare, static fn (array $p): bool => $p['am_aufruf']));

        $this->assertGreaterThanOrEqual(8, $amAufruf, sprintf(
            'Nur %d Beschriftungen werden von einem Namen am Aufruf gedeckt. Dann liest dieser '.
            'Wächter die dritten Werte der validate()-Aufrufe nicht mehr.',
            $amAufruf,
        ));

        $abweichungen = [];

        foreach ($paare as $paar) {
            if ($this->fits($paar['text'], $paar['erwartet'])) {
                continue;
            }

            $schluessel = $paar['seite'].':'.$paar['feld'];

            if (isset(self::KEIN_NAME[$schluessel])) {
                continue;
            }

            $abweichungen[] = sprintf(
                '%s — Feld `%s`: auf der Seite „%s", der Server sagt „%s"',
                $paar['seite'],
                $paar['feld'],
                $paar['text'],
                implode('" oder „', $paar['erwartet']),
            );
        }

        $this->assertSame([], $abweichungen, sprintf(
            "Hier heisst ein Feld auf der Seite anders als in der Fehlermeldung:\n\n  %s\n\n".
            'Der Kunde liest dann „Das Feld X ist erforderlich" und sucht ein Feld, das es auf '.
            'dieser Seite nicht gibt. Zu beheben ist es am Aufruf: dritter Wert von '.
            '`validate()`. Ist die Beschriftung gar kein Name, gehört sie mit Begründung nach '.
            'self::KEIN_NAME.',
            implode("\n  ", $abweichungen),
        ));
    }

    /**
     * Jede Ausnahme trägt einen Grund und zeigt auf eine Stelle, die es gibt.
     *
     * **Dieselbe Fehlerklasse wie überall hier:** eine Zeichenkette, die auf
     * etwas verweist, ohne dass der Bezug geprüft wird. Eine Ausnahme für eine
     * Seite, die umbenannt wurde, ist ein Loch mit einer Begründung daneben.
     */
    public function test_every_exception_still_points_somewhere(): void
    {
        $paare = $this->pairs($this->attributes());
        $vorhanden = array_map(static fn (array $p): string => $p['seite'].':'.$p['feld'], $paare);

        foreach (self::KEIN_NAME as $schluessel => $grund) {
            $this->assertNotSame('', trim($grund), sprintf('Die Ausnahme „%s" trägt keinen Grund.', $schluessel));

            $this->assertContains($schluessel, $vorhanden, sprintf(
                'Die Ausnahme „%s" zeigt auf keine Beschriftung mehr. Entweder ist die Seite '.
                'umbenannt — dann zeigt die Ausnahme auf ihren neuen Ort — oder das Feld ist '.
                'fort, und die Ausnahme geht mit ihm.',
                $schluessel,
            ));
        }
    }

    /**
     * Passt die Beschriftung zu einem der erwarteten Namen?
     *
     * @param  list<string>  $erwartet
     */
    private function fits(string $text, array $erwartet): bool
    {
        foreach ($erwartet as $name) {
            if ($text === $name) {
                return true;
            }

            // „Anzeigename" und „Name" meinen dasselbe Feld — und die Gross-
            // schreibung entscheidet es nicht: „Name" steckt in „Anzeigename",
            // aber nur klein geschrieben.
            if (mb_stripos($text, $name) !== false || mb_stripos($name, $text) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jede Beschriftung mit ihrem Feld und den Namen, die dafür in Frage kommen.
     *
     * @param  array<string,string>  $namen
     * @return list<array{seite: string, feld: string, text: string, erwartet: list<string>, am_aufruf: bool}>
     */
    private function pairs(array $namen): array
    {
        [$komponenten, $amAufruf] = $this->controllers();
        $paare = [];

        foreach ($this->pages() as $pfad) {
            $quelle = (string) file_get_contents($pfad);
            $seite = substr($pfad, strlen($this->root().'/resources/js/Pages/'));
            $steuerung = $komponenten[substr($seite, 0, -4)] ?? null;

            foreach ($this->labels($quelle) as [$feld, $text]) {
                if (! isset($namen[$feld])) {
                    continue;
                }

                $eigene = $steuerung === null ? [] : ($amAufruf[$steuerung][$feld] ?? []);

                $paare[] = [
                    'seite' => $seite,
                    'feld' => $feld,
                    'text' => $text,
                    'erwartet' => [$namen[$feld], ...$eigene],
                    'am_aufruf' => $eigene !== [],
                ];
            }
        }

        return $paare;
    }

    /**
     * Die Beschriftungen einer Seite als Paare aus Feldname und Text.
     *
     * **Zwei Bauformen, weil beide vorkommen:** das `<label>`, das seine
     * Eingabe umschliesst (dann steht das Feld im `v-model`), und das
     * `<label for="…">`, das daneben steht. Ein Ausdruck, der nur eine kennt,
     * meldet für die andere „alles in Ordnung".
     *
     * @return list<array{string, string}>
     */
    private function labels(string $quelle): array
    {
        $gefunden = [];

        preg_match_all('/<label\b[^>]*>(.*?)<\/label>/s', $quelle, $treffer, PREG_SET_ORDER);

        foreach ($treffer as $t) {
            $feld = null;

            if (preg_match('/v-model="\w+\.([\w.]+)"/', $t[1], $v) === 1) {
                $teile = explode('.', $v[1]);
                $feld = end($teile);
            } elseif (preg_match('/<label\b[^>]*\bfor="([\w-]+)"/', $t[0], $f) === 1) {
                $feld = $f[1];
            }

            if ($feld === null) {
                continue;
            }

            $text = preg_match('/<span[^>]*>(.*?)<\/span>/s', $t[1], $s) === 1
                ? $s[1]
                : (string) strstr($t[1].'<', '<', true);

            $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', '', $text)));

            if ($text !== '') {
                $gefunden[] = [$feld, $text];
            }
        }

        return $gefunden;
    }

    /**
     * Welche Steuerung welche Seite rendert, und welche Namen sie am Aufruf setzt.
     *
     * @return array{array<string,string>, array<string,array<string,list<string>>>}
     */
    private function controllers(): array
    {
        $komponenten = [];
        $namen = [];

        foreach ($this->phpFiles($this->root().'/app') as $datei) {
            $quelle = (string) file_get_contents($datei);

            preg_match_all("/Inertia::render\('([^']+)'/", $quelle, $t);

            foreach ($t[1] as $komponente) {
                $komponenten[$komponente] = $datei;
            }

            /*
             * Beide Schreibweisen des dritten Wertes: `], [], [ … ]);` für
             * einen Aufruf, der seine Regeln als Blockliteral trägt, und
             * `[], [ … ],` für einen, dessen Argumente je eine Zeile haben.
             * Dazu eine Konstante `NAMEN`, wo derselbe Satz zwei Wege bedient.
             */
            foreach (['/\],\s*\[\],\s*\[(.*?)\]\s*\)/s', '/\[\],\s*\[(.*?)\],\s*\)/s', '/NAMEN = \[(.*?)\]/s'] as $muster) {
                preg_match_all($muster, $quelle, $bloecke);

                foreach ($bloecke[1] as $block) {
                    preg_match_all("/'(\w+)' => '([^']*)'/", $block, $paare, PREG_SET_ORDER);

                    foreach ($paare as $paar) {
                        $namen[$datei][$paar[1]][] = $paar[2];
                    }
                }
            }
        }

        return [$komponenten, $namen];
    }

    /**
     * Die deutschen Namen aus `lang/de/validation.php`.
     *
     * @return array<string,string>
     */
    private function attributes(): array
    {
        /** @var array{attributes?: array<string,string>} $lang */
        $lang = require $this->root().'/lang/de/validation.php';

        return $lang['attributes'] ?? [];
    }

    /** @return list<string> */
    private function pages(): array
    {
        return $this->files($this->root().'/resources/js/Pages', 'vue');
    }

    /** @return list<string> */
    private function phpFiles(string $wurzel): array
    {
        return $this->files($wurzel, 'php');
    }

    /** @return list<string> */
    private function files(string $wurzel, string $endung): array
    {
        $treffer = [];
        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel));

        foreach ($lauf as $datei) {
            if ($datei->isFile() && $datei->getExtension() === $endung) {
                $treffer[] = $datei->getPathname();
            }
        }

        sort($treffer);

        return $treffer;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
