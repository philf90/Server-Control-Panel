<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DnsHealth;
use App\Enums\DnsRecordState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Zusammenzug des DNS-Abgleichs auf eine Marke.
 *
 * ## Was hier gehalten wird
 *
 * `DnsHealth::of()` ist die **eine** Stelle, an der aus einem Befund eine
 * Marke wird. Sie steht zwischen zwei Listen und der Domainseite; geht sie
 * auseinander, sagt eine Liste „in Ordnung" über eine Domain, deren Seite drei
 * gelbe Meldungen trägt.
 *
 * ## Der Fall, der diesem Wächter seine Form gibt
 *
 * **Ein unbekannter Zustand darf nicht als „in Ordnung" durchgehen.** Kommt
 * ein sechster {@see DnsRecordState} dazu — es sind schon einmal zwei
 * nachträglich dazugekommen, siehe dessen Kopf —, und niemand denkt an diese
 * Stelle, dann ist die richtige Antwort „nachsehen".
 *
 * > **Ein Zusammenzug, der Unbekanntes für gut hält, wird beim nächsten
 * > Zustand still falsch.**
 *
 * Deshalb zählt {@see self::states()} die Zustände **auf**, statt sie
 * aufzuschreiben: Ein neuer Fall landet von selbst im Prüfstand, und der
 * Wächter meldet ihn, statt ihn zu übersehen.
 *
 * ## Was er nicht prüft
 *
 * Ob die Wörter richtig gewählt sind. „in Ordnung", „nachsehen" und
 * „ungeprüft" sind eine Setzung des Betreibers vom 22. August 2026 und keine
 * Eigenschaft, die sich messen lässt.
 */
final class DnsHealthTest extends TestCase
{
    /**
     * Ein Befund, in dem alles steht, wie es soll.
     *
     * @return array<string, mixed>
     */
    private function healthy(): array
    {
        return [
            'records' => [
                ['name' => 'a.invalid', 'type' => 'A', 'state' => 'here'],
                ['name' => 'a.invalid', 'type' => 'AAAA', 'state' => 'here'],
            ],
            'nameservers' => ['ns1.invalid'],
            'unasked' => [],
            'authorities' => [['name' => 'a.invalid', 'state' => 'none']],
        ];
    }

    public function test_a_clean_finding_is_fine(): void
    {
        $this->assertSame(DnsHealth::Fine, DnsHealth::of($this->healthy()));
    }

    /**
     * **`null` ist nicht „nichts gefunden".**
     *
     * Jede frisch angelegte Domain ist dieser Fall. Sie als „in Ordnung" zu
     * führen wäre eine Entwarnung ohne Messung.
     */
    public function test_never_checked_is_its_own_state(): void
    {
        $this->assertSame(DnsHealth::Unchecked, DnsHealth::of(null));

        $this->assertNotSame(
            DnsHealth::of(null)->badge(),
            DnsHealth::Fine->badge(),
            'Ungeprüft und „in Ordnung" tragen denselben Rang — dann sind es keine zwei Zustände.',
        );
    }

    /**
     * Alle Satzzustände ausser {@see DnsRecordState::Here} verlangen
     * Aufmerksamkeit.
     *
     * **Aufgezählt und nicht aufgeschrieben.** Ein sechster Zustand steht von
     * selbst hier — genau die Stelle, an der ein Zusammenzug sonst still
     * falsch wird.
     *
     * @return array<string, array{DnsRecordState}>
     */
    public static function states(): array
    {
        $saetze = [];

        foreach (DnsRecordState::cases() as $state) {
            $saetze[$state->value] = [$state];
        }

        return $saetze;
    }

    #[DataProvider('states')]
    public function test_only_here_counts_as_fine(DnsRecordState $state): void
    {
        $befund = $this->healthy();
        $befund['records'][1]['state'] = $state->value;

        $erwartet = $state === DnsRecordState::Here ? DnsHealth::Fine : DnsHealth::Attention;

        $this->assertSame($erwartet, DnsHealth::of($befund), sprintf(
            'Ein Satz im Zustand „%s" ergibt %s statt %s.',
            $state->label(),
            DnsHealth::of($befund)->value,
            $erwartet->value,
        ));
    }

    /**
     * **Die Gegenprobe zur Aufzählung.**
     *
     * Läuft `DnsRecordState::cases()` leer oder trifft der Lieferant nicht
     * mehr, prüft der Fall darüber null Zustände und ist grün, ohne etwas
     * gesehen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_states_to_check(): void
    {
        $this->assertGreaterThanOrEqual(5, count(self::states()));
    }

    /**
     * Ein unbekannter Zustand ist „nachsehen" und nicht „in Ordnung".
     *
     * Das ist derselbe Fall wie oben, nur von der anderen Seite: Der
     * Datenlieferant deckt ab, was die Aufzählung heute kennt; dieser Fall
     * deckt ab, was morgen aus der Datenbank kommt, ohne dass jemand die
     * Aufzählung erweitert hat.
     */
    public function test_an_unknown_state_is_not_fine(): void
    {
        $befund = $this->healthy();
        $befund['records'][0]['state'] = 'gibtesnicht';

        $this->assertSame(DnsHealth::Attention, DnsHealth::of($befund));
    }

    /**
     * Ein Befund ohne einen einzigen Satz ist keine Entwarnung.
     *
     * Er entsteht, wenn dem Server jede öffentliche Adresse fehlt — die
     * Domainseite schreibt dort „Für diese Domain ist kein Sollzustand
     * bekannt". Ohne diesen Fall stünde in der Liste „in Ordnung".
     */
    public function test_a_finding_without_records_is_not_fine(): void
    {
        $befund = $this->healthy();
        $befund['records'] = [];

        $this->assertSame(DnsHealth::Attention, DnsHealth::of($befund));
    }

    /**
     * Ein CAA, das die eigene Stelle nicht nennt, zählt.
     *
     * **Auch wenn jeder Satz richtig steht.** Genau das ist der Fall, den der
     * Bereich an der Domain meldet, bevor eine Bestellung daran scheitert
     * (`docs/72 §2.4`).
     */
    public function test_a_refused_authority_asks_for_attention(): void
    {
        $befund = $this->healthy();
        $befund['authorities'] = [['name' => 'a.invalid', 'state' => 'refused']];

        $this->assertSame(DnsHealth::Attention, DnsHealth::of($befund));
    }

    /**
     * Ein Name, der gar nicht gefragt werden konnte, zählt ebenfalls.
     */
    public function test_an_unasked_name_asks_for_attention(): void
    {
        $befund = $this->healthy();
        $befund['unasked'] = ['b.invalid'];

        $this->assertSame(DnsHealth::Attention, DnsHealth::of($befund));
    }

    /**
     * Und eine Zone, deren Nameserver niemand erreicht hat.
     */
    public function test_a_zone_without_nameservers_asks_for_attention(): void
    {
        $befund = $this->healthy();
        $befund['nameservers'] = [];

        $this->assertSame(DnsHealth::Attention, DnsHealth::of($befund));
    }

    /**
     * Ein Befund, dem Felder fehlen, stürzt nicht ab.
     *
     * **Er kommt aus einer JSON-Spalte** und nicht aus einem Typ. Eine ältere
     * Zeile — geschrieben, bevor es `unasked` gab — hat dieses Feld nicht, und
     * ein Zusammenzug, der darüber stolpert, nähme die ganze Liste mit.
     */
    public function test_a_finding_with_missing_fields_survives(): void
    {
        $this->assertSame(DnsHealth::Attention, DnsHealth::of([]));

        $this->assertSame(DnsHealth::Fine, DnsHealth::of([
            'records' => [['state' => 'here']],
            'nameservers' => ['ns1.invalid'],
        ]));
    }

    /**
     * Jeder Rang, den diese Aufzählung nennt, ist einer, den `Badge` kennt.
     *
     * **Der Fehler, den dieses Projekt sechsmal gemacht hat**: eine
     * Zeichenkette, die auf etwas verweist, ohne dass etwas den Bezug prüft.
     * Ein vertippter Rang wäre eine Marke ohne Farbe — sichtbar erst im
     * Browser, und dann nur für den, der genau diesen Zustand vor sich hat.
     */
    public function test_every_badge_rank_is_one_the_component_knows(): void
    {
        $bekannt = ['ok', 'warn', 'critical', 'neutral'];

        foreach (DnsHealth::cases() as $health) {
            $this->assertContains($health->badge(), $bekannt, sprintf(
                '%s trägt den Rang „%s", und den kennt Badge nicht.',
                $health->value,
                $health->badge(),
            ));
        }
    }

    /**
     * Und jeder Zustand trägt ein Wort.
     *
     * Farbe ist nie der einzige Träger — `Badge` besteht darauf, und rund acht
     * Prozent der männlichen Nutzer lesen eine Fläche nicht als Signal.
     */
    public function test_every_state_carries_a_word(): void
    {
        foreach (DnsHealth::cases() as $health) {
            $this->assertNotSame('', trim($health->label()), $health->value.' hat keine Beschriftung.');
        }
    }
}
