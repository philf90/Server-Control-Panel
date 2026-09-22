<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Wohin ein Menüpunkt gehört — und warum das hier prüfbar ist.
 *
 * ## Der Fehler, gegen den es diesen Wächter gibt
 *
 * Dieses Projekt hat den Ort eines Menüpunkts **dreimal** falsch gehabt —
 * Dateimanager, SFTP-Zugang, „Job anlegen" —, und jedes Mal hat es der
 * Betreiber gemeldet und kein Test. Der Grund steht in `CLAUDE.md`: „erreichbar"
 * kann ein Test nicht halten, weil es daran hängt, was ein Kunde *sucht*.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 *
 * Am 30. August 2026 ist die Gruppe „Server" geteilt worden — sie trug
 * dreizehn Punkte —, und die Trennlinie ist bewusst so gewählt, dass sie
 * **doch** zu halten ist: Was unter `/settings/…` liegt, ist eine Einstellung.
 * Damit hängt die Zuordnung an der Route und nicht an einem Urteil.
 *
 * > **Eine Gruppe, deren Grenze aus der Route folgt, kann ein Wächter halten;
 * > eine, die an einem Urteil hängt, nicht.**
 *
 * Das ersetzt die Frage nicht — wo jemand „Dienste" sucht, entscheidet weiter
 * ein Mensch. Es hält nur die eine Grenze, die sich ableiten lässt.
 */
final class NavGroupTest extends TestCase
{
    use WithoutMarkupComments;

    private const LAYOUT = 'resources/js/Layouts/PanelLayout.vue';

    /**
     * Die Gruppen, in die alles unter `/settings/…` gehört.
     *
     * **Bis zum 21. September 2026 stand hier eine einzige.** Die Zusage war
     * aber nie „eine Gruppe", sondern *„was unter `/settings/…` liegt, steht
     * in einer Einstellungsgruppe — und dort steht nichts anderes"*: eine
     * Grenze, die aus der Route folgt und die ein Wächter deshalb halten kann.
     * Zwei Gruppen ändern daran nichts, solange **beide** ausschliesslich von
     * `/settings/…` gefüllt werden.
     *
     * > **Eine Gruppe, deren Grenze aus der Route folgt, kann ein Wächter
     * > halten; eine, die an einem Urteil hängt, nicht.**
     *
     * Der Anlass war {@see self::test_no_group_grows_back_into_a_pot()}: Die
     * Gruppe stand bei acht, und „Benachrichtigungen" (B1) war der neunte
     * Punkt. Der naheliegende Ausweg — drei Einträge in {@see self::AUSNAHMEN}
     * — hätte genau die ableitbare Grenze in ein Urteil verwandelt, also das,
     * wogegen es diesen Wächter gibt.
     *
     * @var list<string>
     */
    private const EINSTELLUNGEN = ['Einstellungen', 'Nach draussen'];

    /**
     * Die eine Gruppe, die heute schon zu gross ist — benannt statt geduldet.
     *
     * Die Kundennavigation führt unter „Konto" **neun** Punkte: Abonnements,
     * Domains, Datenbanken, Dateien, SFTP-Zugang, Cronjobs, Vorgänge,
     * Protokoll und „Mein Konto". Das ist derselbe Topf, aus dem „Betrieb" und
     * „Einstellungen" am 30. August entstanden sind, eine Stufe kleiner — und
     * er ist an diesem Tag bewusst nicht mitgeteilt worden, weil der Betreiber
     * über die Kundenansicht nicht entschieden hat.
     *
     * > **Eine Ausnahme, die man aufschreibt, ist ein offener Punkt; eine
     * > Schwelle, die man höher setzt, ist keiner mehr.**
     *
     * Wer sie teilt, streicht diesen Eintrag.
     *
     * @var array<string,string>
     */
    private const ZU_GROSS = [
        'Kunde' => 'Konto',
    ];

    /**
     * Die einzige Adresse unter `/settings/…`, die woanders stehen darf.
     *
     * „Mein Konto" ist keine Einstellung **dieses Servers**, sondern die des
     * Betrachters: Wer sein Passwort ändern will, sucht sie bei sich und nicht
     * neben dem Mailversand. Sie steht deshalb in der Gruppe „Konto", und zwar
     * in beiden Navigationen.
     *
     * @var array<string,string>
     */
    private const AUSNAHMEN = [
        '/settings/profile' => 'Das eigene Konto des Betrachters, nicht eine Einstellung des Servers.',
    ];

    /**
     * Die Einträge beider Navigationen, jede für sich.
     *
     * **Getrennt, weil beide eine Gruppe „Konto" führen.** Der erste Wurf
     * dieses Lesers zählte über die ganze Datei und kam auf elf Punkte in
     * „Konto" — neun aus der Kundennavigation, einen aus der des Betreibers und
     * eine „Übersicht", die vor der ersten Gruppe steht und keiner gehört.
     *
     * > **Zwei Listen mit gleich benannten Gruppen sind eine Liste, sobald man
     * > nur die Namen liest.**
     *
     * Geschnitten wird an `if (account.value?.is_admin === false) {`, der einen
     * Stelle, an der die Datei die beiden auseinanderhält.
     *
     * @return array<string,list<array{gruppe:string,name:string,href:string}>>
     */
    private function navigationen(): array
    {
        $quelle = file_get_contents(dirname(__DIR__, 2).'/'.self::LAYOUT);

        $this->assertIsString($quelle);

        $ohne = $this->withoutMarkupComments($quelle);
        $schnitt = strpos($ohne, 'return [');

        $this->assertIsInt($schnitt, 'Die Kundennavigation fängt nicht mehr mit einem return an.');

        $zweiter = strpos($ohne, 'return [', $schnitt + 1);

        $this->assertIsInt($zweiter, 'Es gibt nur noch eine Navigation — dann prüft dieser Wächter die falsche.');

        return [
            'Kunde' => $this->lies(substr($ohne, $schnitt, $zweiter - $schnitt)),
            'Betreiber' => $this->lies(substr($ohne, $zweiter)),
        ];
    }

    /** @return list<array{gruppe:string,name:string,href:string}> */
    private function eintraege(): array
    {
        return array_merge(...array_values($this->navigationen()));
    }

    /** @return list<array{gruppe:string,name:string,href:string}> */
    private function lies(string $abschnitt): array
    {
        $zeilen = [];
        $gruppe = null;

        preg_match_all(
            "/\{\s*group:\s*'([^']+)'|\{\s*name:\s*'([^']+)',\s*href:\s*'([^']+)'/",
            $abschnitt,
            $treffer,
            PREG_SET_ORDER,
        );

        foreach ($treffer as $t) {
            if (($t[1] ?? '') !== '') {
                $gruppe = $t[1];

                continue;
            }

            // Ein Eintrag vor der ersten Gruppe ist „Übersicht" und gehört
            // keiner — er steht über allen und wird hier nicht geprüft.
            if ($gruppe === null) {
                continue;
            }

            $zeilen[] = ['gruppe' => $gruppe, 'name' => $t[2], 'href' => $t[3]];
        }

        return $zeilen;
    }

    /**
     * Jede Einstellung steht in „Einstellungen".
     */
    public function test_every_settings_route_stands_in_the_settings_group(): void
    {
        $falsch = [];

        foreach ($this->eintraege() as $eintrag) {
            if (! str_starts_with($eintrag['href'], '/settings/')) {
                continue;
            }

            if (array_key_exists($eintrag['href'], self::AUSNAHMEN)) {
                continue;
            }

            if (! in_array($eintrag['gruppe'], self::EINSTELLUNGEN, true)) {
                $falsch[] = sprintf('%s (%s) steht in „%s"', $eintrag['name'], $eintrag['href'], $eintrag['gruppe']);
            }
        }

        $this->assertSame([], $falsch, implode("\n  ", array_merge(
            ['Diese Punkte liegen unter /settings/ und stehen in keiner Einstellungsgruppe:'],
            $falsch,
        )));
    }

    /**
     * Und die Gegenrichtung: In „Einstellungen" steht nichts anderes.
     *
     * Ohne sie wüchse die Gruppe über Jahre zu einem zweiten Topf — genau dem,
     * aus dem sie entstanden ist.
     */
    public function test_the_settings_group_carries_nothing_else(): void
    {
        $fremd = [];

        foreach ($this->eintraege() as $eintrag) {
            if (! in_array($eintrag['gruppe'], self::EINSTELLUNGEN, true)) {
                continue;
            }

            if (! str_starts_with($eintrag['href'], '/settings/')) {
                $fremd[] = sprintf('%s (%s)', $eintrag['name'], $eintrag['href']);
            }
        }

        $this->assertSame([], $fremd, implode("\n  ", array_merge(
            ['Diese Punkte stehen in einer Einstellungsgruppe und sind keine Einstellung:'],
            $fremd,
        )));
    }

    /**
     * Ohne diese Zahlen wären beide Richtungen auch dann grün, wenn der
     * Ausdruck oben nichts fände.
     */
    public function test_the_comparison_has_something_to_compare(): void
    {
        $eintraege = $this->eintraege();

        $einstellungen = array_filter($eintraege, static fn (array $e): bool => $e['gruppe'] === 'Einstellungen');
        $draussen = array_filter($eintraege, static fn (array $e): bool => $e['gruppe'] === 'Nach draussen');
        $betrieb = array_filter($eintraege, static fn (array $e): bool => $e['gruppe'] === 'Betrieb');
        $verlauf = array_filter($eintraege, static fn (array $e): bool => $e['gruppe'] === 'Verlauf');

        /*
         * **Sechs und drei seit dem 21. September 2026** — und die Teilung ist
         * genau die, die der Satz darunter seit dem 16. September angekündigt
         * hat: Die Gruppe stand bei acht, „Benachrichtigungen" (B1) war der
         * neunte Punkt, und die Obergrenze hat die Entscheidung fällig
         * gemacht.
         *
         * > **Eine Gruppe ist zu gross, wenn sie zwei Fragen beantwortet — und
         * > nicht, wenn sie viele Punkte hat.**
         *
         * Die zweite Frage stand schon länger da: Mailversand, DNS-Zugang und
         * Meldeziel sind die drei Stellen, an denen dieser Server
         * **Zugangsdaten eines Fremden** hält. Die sechs daneben sagen, wie er
         * eingestellt ist.
         *
         * **Und die Zahl ist wieder ein Halt und kein Befund.** Wer hier den
         * siebten Punkt einträgt, hat noch Luft; wer den neunten einträgt,
         * entscheidet wieder.
         */
        $this->assertCount(6, $einstellungen, 'Die Gruppe „Einstellungen" trägt sechs Punkte — wie dieser Server eingestellt ist.');
        $this->assertCount(3, $draussen, 'Die Gruppe „Nach draussen" trägt drei Punkte — wo Zugangsdaten eines Fremden liegen.');

        /*
         * **Sechs und drei seit dem 5. September 2026.** „Betrieb" trug acht und
         * wäre mit „Ankündigungen" auf neun gewachsen; herausgelöst ist, was
         * sagt, **was war** — Vorgänge, Protokoll, Logs. Die Trennlinie stand
         * schon im Kommentar an „Logs", die Zahl hat sie nur fällig gemacht.
         *
         * **Sieben seit dem 8. September 2026** — „Zeitpläne" (A6) steht neben
         * „Dienste". Diese Zahl ist die bekannte Aufräumfalle in ihrer
         * nützlichen Richtung: Sie ist kein Befund, sondern ein Halt, an dem
         * jemand einmal entscheiden muss, ob die Gruppe noch eine Gruppe ist.
         * Bei neun war sie es nicht mehr.
         */
        $this->assertCount(7, $betrieb, 'Die Gruppe „Betrieb" trägt sieben Punkte — was jetzt ist und was ansteht.');
        $this->assertCount(3, $verlauf, 'Die Gruppe „Verlauf" trägt drei Punkte — was war.');
    }

    /**
     * Keine Gruppe wächst wieder zu dem Topf, aus dem diese entstanden sind.
     *
     * Die alte Gruppe „Server" hatte dreizehn Punkte, und niemand hat es
     * bemerkt, bis der Betreiber es sagte. Acht ist keine gemessene Grenze,
     * sondern eine gesetzte — sie liegt über den sieben, die die grösste heute
     * hat, und unter den dreizehn, die zu viel waren.
     */
    public function test_no_group_grows_back_into_a_pot(): void
    {
        foreach ($this->navigationen() as $wer => $eintraege) {
            $je = [];

            foreach ($eintraege as $eintrag) {
                $je[$eintrag['gruppe']] = ($je[$eintrag['gruppe']] ?? 0) + 1;
            }

            foreach ($je as $gruppe => $anzahl) {
                if ((self::ZU_GROSS[$wer] ?? null) === $gruppe) {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    8,
                    $anzahl,
                    sprintf('%s: Die Gruppe „%s" trägt %d Punkte — sie gehört geteilt.', $wer, $gruppe, $anzahl),
                );
            }
        }
    }

    /**
     * **Ein Name zeigt auf genau eine Adresse.**
     *
     * Befund 7 des P8-Abnahmelaufs hat zwei Hälften; das hier ist die zweite.
     * „Sicherungen" stand als `/backups` im Kundenzweig und als
     * `/settings/backups` im Betreiberzweig — und als der fehlende Eintrag
     * nachgetragen wurde, hätte der Betreiber zweimal „Sicherungen" mit
     * demselben Zeichen in derselben Leiste gesehen.
     *
     * > **Zwei Einträge mit demselben Namen zwingen den Leser, die
     * > Gruppenüberschrift mitzulesen — und auf dem Telefon steht sie nicht
     * > neben dem Punkt, sondern darüber.**
     *
     * Dieselbe Frage haben „Datenbanken" und „Datenbankserver" schon einmal
     * beantwortet; die Antwort war bis heute eine Gewohnheit und keine Regel.
     *
     * **Gefragt wird über beide Zweige zusammen und nicht je Zweig.** Derselbe
     * Name in beiden ist kein Fehler, solange er dieselbe Adresse meint — „Mein
     * Konto", „Vorgänge" und „Protokoll" stehen in beiden. Ein Fehler ist er,
     * sobald zwei **verschiedene** Adressen ihn tragen.
     */
    public function test_a_name_points_at_exactly_one_address(): void
    {
        $adressen = [];

        foreach ($this->eintraege() as $eintrag) {
            $adressen[$eintrag['name']][$eintrag['href']] = true;
        }

        foreach ($adressen as $name => $ziele) {
            $this->assertCount(
                1,
                $ziele,
                sprintf('„%s" führt an %d verschiedene Orte: %s.', $name, count($ziele), implode(', ', array_keys($ziele))),
            );
        }

        // **Die Untergrenze.** Findet der Leser keinen Eintrag, ist die
        // Schleife leer und dieser Fall grün, ohne etwas angesehen zu haben.
        $this->assertGreaterThanOrEqual(
            20,
            count($adressen),
            'Der Leser findet weniger als zwanzig Namen — dann greift er ins Leere und meldet es nicht.',
        );
    }

    /**
     * **Der Betreiber erreicht die Sicherungen ohne Abonnement.**
     *
     * Befund 7, erste Hälfte, gemeldet vom Betreiber: `Sicherungen → /backups`
     * stand nur im Kundenzweig. Der Bereich „Ohne Abonnement" auf dieser Seite
     * ist nach `docs/117` allein seiner und der einzige Weg, eine Sicherung
     * ohne Abonnement zurückzuspielen oder zu entfernen — `BackupController`
     * fragt dafür an zwei Stellen `manageOrphanedBackups`.
     *
     * **Die eine Handlung, die nur der Betreiber ausführen kann, lag auf der
     * einen Seite, zu der nur der Kunde einen Menüpunkt hatte.**
     *
     * ## Was dieser Fall nicht kann, und das ist der grössere Teil
     *
     * Er hält **diese** Adresse und nicht die Frage dahinter. Ob ein Kunde eine
     * Handlung dort sucht, wo sie steht, hängt an einer Erwartung und nicht an
     * einer Eigenschaft des Quelltextes — es ist die vierte Wiederholung
     * derselben Familie (Dateimanager `docs/55`, SFTP-Zugang `docs/59`, „Job
     * anlegen" `docs/64`), und jedes Mal hat sie der Betreiber gemeldet und
     * kein Wächter.
     *
     * > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht
     * > sie dort?** Nicht „ist sie erreichbar" — erreichbar ist alles, was man
     * > findet, wenn man lange genug rollt.
     *
     * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
     * > nicht als Zusage.**
     */
    public function test_the_operator_reaches_the_orphaned_backups(): void
    {
        $ziele = array_column($this->navigationen()['Betreiber'] ?? [], 'href');

        $this->assertContains(
            '/backups',
            $ziele,
            'Der Betreiber hat keinen Menüpunkt zu den Sicherungen — und der Bereich „Ohne Abonnement" darauf ist der einzige Weg zu einer Sicherung, deren Abonnement es nicht mehr gibt.',
        );
    }
}
