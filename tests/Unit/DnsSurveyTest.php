<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dns\Measurement;
use App\Support\Dns\Survey;
use PHPUnit\Framework\TestCase;

/**
 * Die Reihenfolge des ganzen Merkmals — und der Fehler, den sie einmal hatte.
 *
 * **Dieser Durchgang gibt es, weil er gefehlt hat.** Bis zum 21. August stand
 * die Reihenfolge in `Dns`, hing dort an Eloquent und `now()` und war nur in
 * der CI zu fahren. Der einzige echte Fehler dieser Stufe steckte genau dort:
 * Alle Namen wurden unter der Zone **der Domain** gefragt — und ein Alias darf
 * jeden Namen tragen. Ein Alias `beispiel.at` an einer Domain `beispiel.de`
 * liess `dns.check` abweisen, die Ausnahme wurde gefangen, und **die ganze
 * Domain** stand als „nicht erreichbar" da.
 *
 * > **Der Fehler sitzt da, wo kein Test hinkommt — und das ist keine
 * > Beobachtung über den Zufall.**
 *
 * Der erste Fall unten ist genau dieser. Er wäre rot gewesen.
 */
final class DnsSurveyTest extends TestCase
{
    private const V4 = '203.0.113.10';

    // ------------------------------------------------------------------
    // Der Fehler, um dessentwillen es diesen Durchgang gibt
    // ------------------------------------------------------------------

    /**
     * Ein Name, dessen Messung scheitert, verdirbt die anderen nicht.
     *
     * **Der Alias liegt in einer fremden Zone**, seine Messung schlägt fehl —
     * und die Domain daneben steht trotzdem so da, wie sie gemessen wurde.
     */
    public function test_a_failing_name_does_not_spoil_the_others(): void
    {
        $survey = new Survey(new ScriptedMeasurement([
            'beispiel.de' => [
                'nameservers' => ['198.51.100.1'],
                'records' => [$this->record('beispiel.de', 'A', [self::V4])],
                'authorities' => [],
            ],
            // Der Alias: die Messung findet gar nicht statt.
            'beispiel.at' => null,
        ]));

        $findings = $survey->of(['beispiel.de', 'beispiel.at'], [self::V4], 'letsencrypt.org');

        $zustaende = [];

        foreach ($findings['records'] as $satz) {
            $zustaende[$satz['name']] = $satz['state'];
        }

        $this->assertSame('here', $zustaende['beispiel.de'] ?? null, 'Die Domain ist gemessen worden.');
        $this->assertSame('unreachable', $zustaende['beispiel.at'] ?? null, 'Der Alias nicht.');
    }

    /** Und je Name geht genau ein Aufruf hinaus. */
    public function test_one_call_per_name(): void
    {
        $messung = new ScriptedMeasurement([
            'beispiel.de' => $this->leer(),
            'www.beispiel.de' => $this->leer(),
        ]);

        (new Survey($messung))->of(['beispiel.de', 'www.beispiel.de'], [self::V4], null);

        $this->assertSame(['beispiel.de', 'www.beispiel.de'], $messung->gefragt);
    }

    /**
     * Und die Zone eines Aufrufs ist der Name selbst.
     *
     * `Resolver` sucht den NS-Satz von unten nach oben und landet bei der Zone
     * darüber, wenn der Name keinen eigenen hat. Wer stattdessen die Domain
     * als Zone nähme, fragte für einen fremden Alias die falschen Server.
     */
    public function test_the_zone_of_a_call_is_the_name_itself(): void
    {
        $messung = new ScriptedMeasurement(['www.beispiel.de' => $this->leer()]);

        (new Survey($messung))->of(['www.beispiel.de'], [self::V4], null);

        $this->assertSame(['www.beispiel.de'], $messung->gefragt);
    }

    // ------------------------------------------------------------------
    // Was gefragt wird
    // ------------------------------------------------------------------

    /**
     * Nach CAA wird für jeden Namen gefragt — auch ohne Sollzustand.
     *
     * **Führt der Server keine öffentliche Adresse**, gibt es keinen `A`-Satz
     * zu erwarten. Ein CAA, das die Bestellung verbietet, gibt es trotzdem, und
     * es kostete dann Fehlversuche ohne jede Anzeige.
     */
    public function test_caa_is_asked_even_without_a_desired_state(): void
    {
        $messung = new ScriptedMeasurement(['beispiel.de' => $this->leer()]);

        (new Survey($messung))->of(['beispiel.de'], [], null);

        $this->assertSame(
            [['name' => 'beispiel.de', 'type' => 'CAA']],
            $messung->fragen['beispiel.de'] ?? [],
        );
    }

    /** Mit Sollzustand kommen die Sätze dazu — CAA bleibt vorn. */
    public function test_with_a_desired_state_the_records_come_along(): void
    {
        $messung = new ScriptedMeasurement(['beispiel.de' => $this->leer()]);

        (new Survey($messung))->of(['beispiel.de'], [self::V4, '2001:db8::1'], null);

        $this->assertSame(
            ['CAA', 'A', 'AAAA'],
            array_column($messung->fragen['beispiel.de'] ?? [], 'type'),
        );
    }

    // ------------------------------------------------------------------
    // Was zurückkommt
    // ------------------------------------------------------------------

    /** Die Nameserver mehrerer Namen stehen zusammen und jeder einmal. */
    public function test_the_nameservers_are_merged_without_repetition(): void
    {
        $survey = new Survey(new ScriptedMeasurement([
            'beispiel.de' => ['nameservers' => ['198.51.100.1', '198.51.100.2'], 'records' => [], 'authorities' => []],
            'shop.beispiel.de' => ['nameservers' => ['198.51.100.2'], 'records' => [], 'authorities' => []],
        ]));

        $findings = $survey->of(['beispiel.de', 'shop.beispiel.de'], [self::V4], null);

        $this->assertSame(['198.51.100.1', '198.51.100.2'], $findings['nameservers']);
    }

    /** Das CAA-Urteil landet unter `authorities` und nicht unter den Sätzen. */
    public function test_the_caa_judgement_lands_in_its_own_place(): void
    {
        $survey = new Survey(new ScriptedMeasurement([
            'beispiel.de' => [
                'nameservers' => ['198.51.100.1'],
                'records' => [],
                'authorities' => [[
                    'name' => 'beispiel.de',
                    'answered' => 1,
                    'values' => [['flags' => 0, 'tag' => 'issue', 'value' => 'digicert.com']],
                ]],
            ],
        ]));

        $findings = $survey->of(['beispiel.de'], [], 'letsencrypt.org');

        $this->assertCount(1, $findings['authorities']);
        $this->assertSame('refused', $findings['authorities'][0]['state']);
        $this->assertStringContainsString('digicert.com', (string) $findings['authorities'][0]['reason']);
    }

    /**
     * Ein Name, dessen Nameserver schweigen, bekommt **kein** CAA-Urteil.
     *
     * **`answered = 0` heisst „nicht erreichbar".** Daraus ein „kein CAA
     * gefunden" zu machen wäre eine Entwarnung, die niemand gemessen hat — und
     * sie stünde ausgerechnet für die Zone, über die nichts bekannt ist.
     */
    public function test_a_silent_zone_gets_no_caa_verdict(): void
    {
        $survey = new Survey(new ScriptedMeasurement([
            'beispiel.de' => [
                'nameservers' => [],
                'records' => [],
                'authorities' => [['name' => 'beispiel.de', 'answered' => 0, 'values' => []]],
            ],
        ]));

        $findings = $survey->of(['beispiel.de'], [], 'letsencrypt.org');

        $this->assertSame('unknown', $findings['authorities'][0]['state']);
        $this->assertNull($findings['authorities'][0]['reason']);
    }

    /** Und die Gegenprobe: hat jemand geantwortet, ist dasselbe „kein CAA". */
    public function test_the_same_empty_set_is_none_when_someone_answered(): void
    {
        $survey = new Survey(new ScriptedMeasurement([
            'beispiel.de' => [
                'nameservers' => ['198.51.100.1'],
                'records' => [],
                'authorities' => [['name' => 'beispiel.de', 'answered' => 2, 'values' => []]],
            ],
        ]));

        $findings = $survey->of(['beispiel.de'], [], 'letsencrypt.org');

        $this->assertSame('none', $findings['authorities'][0]['state']);
    }

    /** Ohne öffentliche Adresse gibt es keinen Sollzustand — und keinen Befund. */
    public function test_without_a_public_address_there_is_nothing_to_compare(): void
    {
        $findings = (new Survey(new ScriptedMeasurement(['beispiel.de' => $this->leer()])))
            ->of(['beispiel.de'], [], null);

        $this->assertSame([], $findings['records']);
    }

    /** Der Zustand kommt als Kennung heraus und nicht als Aufzählungsfall. */
    public function test_the_state_comes_out_as_a_string(): void
    {
        $survey = new Survey(new ScriptedMeasurement([
            'beispiel.de' => [
                'nameservers' => ['198.51.100.1'],
                'records' => [$this->record('beispiel.de', 'A', ['198.51.100.9'])],
                'authorities' => [],
            ],
        ]));

        $satz = $survey->of(['beispiel.de'], [self::V4], null)['records'][0];

        $this->assertSame('elsewhere', $satz['state']);
        $this->assertSame([self::V4], $satz['expected']);
        $this->assertSame(['198.51.100.9'], $satz['found']);
    }

    /** @param list<string> $values */
    private function record(string $name, string $type, array $values): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'asked' => 1,
            'answered' => 1,
            'values' => $values,
            'consistent' => true,
        ];
    }

    /** @return array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>} */
    private function leer(): array
    {
        return ['nameservers' => ['198.51.100.1'], 'records' => [], 'authorities' => []];
    }
}

/**
 * Eine Messung, die antwortet, was der Durchgang bestimmt — und mitschreibt,
 * wonach gefragt wurde.
 *
 * `null` als Antwort heisst „die Messung hat nicht stattgefunden": der Fall,
 * den ein echter Agent nicht auf Bestellung liefert.
 */
final class ScriptedMeasurement implements Measurement
{
    /** @var list<string> */
    public array $gefragt = [];

    /** @var array<string, list<array{name: string, type: string}>> */
    public array $fragen = [];

    /** @param array<string, array<string, mixed>|null> $antworten */
    public function __construct(private readonly array $antworten) {}

    /**
     * @param  list<array{name: string, type: string}>  $queries
     * @return array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}|null
     */
    public function of(string $zone, array $queries): ?array
    {
        $this->gefragt[] = $zone;
        $this->fragen[$zone] = $queries;

        /*
         * **Das Doppel weist ab, was `dns.check` abweist.** Die Operation
         * verlangt, dass jeder Name in der angegebenen Zone liegt, und wirft
         * sonst — {@see \App\Support\Dns\AgentMeasurement} macht daraus
         * `null`.
         *
         * Ohne diese Zeilen war das Doppel nachsichtiger als das Original: Es
         * beantwortete auch eine Frage nach einem fremden Namen, und der
         * Durchgang blieb grün für eine Fassung, die im Betrieb die ganze
         * Domain verdorben hat.
         *
         * > **Eine Attrappe, die weniger verbietet als das Original, sagt Ja
         * > zu Code, den das Original ablehnt.**
         */
        foreach ($queries as $frage) {
            $name = strtolower(trim($frage['name'], '. '));
            $in = strtolower(trim($zone, '. '));

            if ($name !== $in && ! str_ends_with($name, '.'.$in)) {
                return null;
            }
        }

        /** @var array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}|null $antwort */
        $antwort = $this->antworten[$zone] ?? null;

        return $antwort;
    }
}
