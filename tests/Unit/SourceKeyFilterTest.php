<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\UpdatesController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SrvPanel\Agent\Ops\SystemSourcesList;
use Tests\Feature\InspectOnlyTest;
use Tests\Support\WithoutPhpComments;

/**
 * Die Schlüssel fallen aus dem Payload — und nur sie.
 *
 * ## Warum das hier steht und nicht in {@see InspectOnlyTest}
 *
 * Die Quellenliste kommt vom Agenten. In der CI läuft keiner, `sources` ist
 * dann `null`, und ein `assertNull` darauf wäre grün, ganz gleich, ob der
 * Filter etwas tut.
 *
 * > **Eine Behauptung, die auch dann hält, wenn der Gegenstand fehlt, misst
 * > nicht ihn, sondern sein Fehlen.**
 *
 * Der Prüfkörper wird deshalb hier gebaut — in der Form, die
 * {@see SystemSourcesList} wirklich liefert.
 *
 * ## Gemessen wird die Methode und nicht ihr Aufrufer
 *
 * `withoutKeys()` ist privat, und das gehört so: Sie hat ausserhalb des
 * Controllers nichts zu suchen. Der Griff über Reflexion misst trotzdem den
 * echten Code — eine zweite Fassung des Filters im Test wäre die, die
 * veraltet, und sie prüfte sich selbst.
 */
final class SourceKeyFilterTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Felder eines Eintrags, so wie der Agent sie schickt.
     *
     * **Diese Liste ist der eigentliche Wächter.** Nicht, dass `key` fällt —
     * das ist eine Zeile —, sondern dass niemand ein neues Feld hinzufügt,
     * ohne zu entscheiden, ob es der Administrator sehen darf. Kommt eines
     * dazu, wird dieser Test rot, und die Entscheidung fällt beim Bauen statt
     * bei einem Abnahmelauf.
     *
     * > **Ein Filter über eine Liste, die wächst, ist eine Zusage über den
     * > Stand von heute.**
     *
     * @var list<string>
     */
    private const ENTRY_FIELDS = [
        'stanza', 'enabled', 'targets', 'types', 'uris',
        'suites', 'components', 'key', 'owned',
    ];

    /**
     * Ein Eintrag mit einem Schlüssel, der etwas verrät.
     *
     * @return array<string, mixed>
     */
    private function entry(int $stanza): array
    {
        return [
            'stanza' => $stanza,
            'enabled' => true,
            'targets' => 4,
            'types' => 'deb',
            'uris' => 'https://deb.debian.org/debian',
            'suites' => 'bookworm',
            'components' => 'main',
            'key' => [
                'kind' => 'path',
                'path' => '/usr/share/keyrings/debian-archive-keyring.gpg',
                'readable' => true,
                'keys' => [[
                    'fingerprint' => '0123456789ABCDEF0123456789ABCDEF01234567',
                    'uid' => 'Debian Archive Automatic Signing Key',
                    'expires' => 1893456000,
                    'state' => 'ok',
                ]],
            ],
            'owned' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function sources(): array
    {
        return [
            'files' => [
                ['path' => '/etc/apt/sources.list.d/ubuntu.sources', 'entries' => [$this->entry(1), $this->entry(2)]],
                ['path' => '/etc/apt/sources.list.d/zz-docker.list', 'entries' => [$this->entry(1)]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sources
     * @return array<string, mixed>
     */
    private function filter(array $sources): array
    {
        $methode = new ReflectionMethod(UpdatesController::class, 'withoutKeys');

        return $methode->invoke(null, $sources);
    }

    public function test_every_key_of_every_entry_of_every_file_is_gone(): void
    {
        $gefiltert = $this->filter($this->sources());
        $gesehen = 0;

        foreach ($gefiltert['files'] as $datei) {
            foreach ($datei['entries'] as $eintrag) {
                $gesehen++;

                $this->assertNull($eintrag['key'], sprintf(
                    'In %s steht der Schlüssel von Eintrag %d noch da.',
                    $datei['path'],
                    $eintrag['stanza'],
                ));
            }
        }

        $this->assertSame(3, $gesehen,
            'Der Filter hat Einträge verloren — dann sind die Schlüssel weg, weil die Zeilen weg sind.');
    }

    /**
     * Und die Gegenprobe: Ungefiltert steht er da.
     *
     * Ohne sie bestünde der Test oben auch für einen Prüfkörper, der von
     * vornherein keinen Schlüssel trägt.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_the_probe_carries_a_key_to_begin_with(): void
    {
        $this->assertNotNull($this->sources()['files'][0]['entries'][0]['key']);
        $this->assertSame(
            '/usr/share/keyrings/debian-archive-keyring.gpg',
            $this->sources()['files'][0]['entries'][0]['key']['path'],
        );
    }

    /**
     * Alles ausser dem Schlüssel bleibt unangetastet.
     *
     * Das ist die Hälfte, die still bricht: Ein Filter, der zu viel nimmt,
     * lässt die Seite laufen und zeigt eine Tabelle ohne Adressen.
     */
    public function test_nothing_but_the_key_changes(): void
    {
        $vorher = $this->sources();
        $nachher = $this->filter($vorher);

        foreach (self::ENTRY_FIELDS as $feld) {
            if ($feld === 'key') {
                continue;
            }

            $this->assertSame(
                $vorher['files'][0]['entries'][0][$feld],
                $nachher['files'][0]['entries'][0][$feld],
                sprintf('Der Filter hat `%s` angefasst, und das ist nicht sein Gegenstand.', $feld),
            );
        }

        $this->assertSame(
            $vorher['files'][0]['path'],
            $nachher['files'][0]['path'],
        );
    }

    /**
     * Der Agent schickt genau diese Felder — kommt eines dazu, fällt hier die
     * Entscheidung.
     *
     * **Gelesen wird der Agent und nicht der Prüfkörper.** Eine Liste, die
     * sich selbst bestätigt, ist keine.
     */
    public function test_the_agent_sends_no_field_this_test_does_not_know(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/SystemSourcesList.php',
        ));

        $von = strpos($quelle, '$eintraege[] = [');
        $this->assertNotFalse($von, 'Der Eintrag wird anders gebaut als erwartet — dieser Test misst nichts mehr.');

        $bis = strpos($quelle, '];', $von);
        $this->assertNotFalse($bis);

        preg_match_all("/'([a-z_]+)'\s*=>/", substr($quelle, $von, $bis - $von), $treffer);

        $this->assertSame(
            self::ENTRY_FIELDS,
            $treffer[1],
            'Der Agent schickt andere Felder als die, über die hier entschieden wurde. '
            .'Für jedes neue gilt: Darf der Administrator es sehen? '
            .'Steht die Antwort fest, gehört das Feld in ENTRY_FIELDS.',
        );
    }

    /**
     * Und der Filter wird auch gerufen.
     *
     * **Ohne diese Hälfte ist alles darüber eine Rechnung über toten Code.**
     * Gemessen am 27. August 2026: Wird der Aufruf in `read()` gestrichen,
     * bleiben die vier Behauptungen oben grün — sie messen die Methode und
     * nicht ihre Erreichbarkeit.
     *
     * > **Ein Wächter über eine Methode sagt nichts darüber, dass jemand sie
     * > ruft.**
     *
     * Dass das hier als Textprüfung steht und nicht als Wirkung, hat einen
     * Grund und ist kein Versehen: Die Wirkung hinge am Agenten, und in der CI
     * läuft keiner ({@see InspectOnlyTest}). Ein
     * Abnahmelauf auf einem echten Server misst sie; hier wird gehalten, was
     * ohne ihn zu halten ist.
     */
    public function test_the_filter_is_reached_from_the_payload(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/UpdatesController.php',
        ));

        $von = strpos($quelle, 'private function read(');
        $this->assertNotFalse($von, 'Der Payload wird anders gebaut als erwartet — dieser Test misst nichts mehr.');

        /*
         * Nur der Rumpf und nicht die ganze Datei: Eine fehlgeschlagene
         * Behauptung über vierhundert Zeilen ist keine Meldung, sondern ein
         * Abdruck — und wer sie liest, sucht den Unterschied selbst.
         */
        $rumpf = substr($quelle, $von);

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$operator\s*&&.*?\{\s*\$sources\s*=\s*self::withoutKeys\(\$sources\);/s',
            $rumpf,
            'Der Payload läuft nicht mehr durch den Filter — oder er läuft für jeden hindurch. '
            .'Beides sieht von aussen aus wie eine Seite, die funktioniert.',
        );

        $this->assertStringContainsString(
            '$operator ? ServerController::prompt() : null',
            $rumpf,
            'Der Anteil für den Neustart geht nicht mehr an der Rolle vorbei.',
        );
    }
}
