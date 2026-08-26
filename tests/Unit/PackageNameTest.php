<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\SystemPackagesUpgrade;
use Tests\Support\WithoutHashComments;
use Tests\Support\WithoutPhpComments;

/**
 * Kein Freitext erreicht apt — geprüft wird gegen die vorige Antwort.
 *
 * ## Der gemessene Grund
 *
 * `docs/81 §5` verlangt es, und am 26. August 2026 ist nachgemessen worden,
 * warum ein Muster dafür nicht genügt (`docs/81 §2.3g`, U4):
 *
 *     apt-get -s install --only-upgrade -- '--reinstall'
 *     -> E: Unable to locate package --reinstall
 *
 *     apt-get -s install --only-upgrade '--reinstall'
 *     -> 0 upgraded, 0 newly installed, 0 to remove and 144 not upgraded.
 *     -> rc=0
 *
 * **Ein Paketname, der wie eine Option aussieht, wird von apt als Option
 * geschluckt** — wortlos, mit Rückgabewert 0. Ein Muster müsste jede dieser
 * Schreibweisen erraten; eine Positivliste muss nur vorhanden sein.
 *
 * > **Eine Positivliste aus der eigenen vorigen Antwort lässt nichts durch,
 * > was nicht schon dastand.**
 *
 * ## Was er nicht prüft
 *
 * Ob apt die Namen dann auch findet — das entscheidet apt, und dieser Wächter
 * hat keinen. Er prüft die **Grenze**: dass zwischen dem Formular und der
 * Kommandozeile eine Liste steht und kein Ausdruck.
 */
final class PackageNameTest extends TestCase
{
    use WithoutHashComments;
    use WithoutPhpComments;

    /** Was apt gerade gemeldet hat: Name => ist ein Sicherheitsupdate. */
    private const BEKANNT = [
        'tar' => true,
        'gzip' => false,
        'libssl3t64' => true,
    ];

    /**
     * Die gemessenen Angriffe — jeder wird abgewiesen.
     *
     * Die ersten beiden sind die aus U4: Namen, die apt als Option deutet. Die
     * übrigen sind die naheliegenden Nachbarn — ein Name mit einer angehängten
     * Option, eine lokale Datei, ein leerer Name.
     */
    public static function fremdeNamen(): \Generator
    {
        yield 'Option statt Name' => ['--reinstall'];
        yield 'kurze Option' => ['-y'];
        yield 'Name mit Option' => ['tar --reinstall'];
        yield 'lokale Datei' => ['./boese.deb'];
        yield 'leer' => [''];
        yield 'unbekannt, aber harmlos' => ['cowsay'];
    }

    #[DataProvider('fremdeNamen')]
    public function test_a_name_that_apt_did_not_offer_is_refused(string $name): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessageMatches('/stehen nicht in der Liste/');

        SystemPackagesUpgrade::names('packages', [$name], self::BEKANNT);
    }

    /** Und ein Name, der dastand, kommt durch. */
    public function test_a_name_from_the_list_passes(): void
    {
        $this->assertSame(
            ['tar', 'gzip'],
            SystemPackagesUpgrade::names('packages', ['tar', 'gzip'], self::BEKANNT),
        );
    }

    /**
     * **Die Gegenprobe zum Prüfkörper.** Wiese diese Methode alles ab, wären
     * die sechs Fälle oben grün, ohne etwas zu belegen.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_the_refusal_names_what_it_refused(): void
    {
        try {
            SystemPackagesUpgrade::names('packages', ['tar', '--reinstall'], self::BEKANNT);
        } catch (AgentException $error) {
            $this->assertStringContainsString('--reinstall', $error->getMessage());
            $this->assertStringNotContainsString('tar', $error->getMessage());

            return;
        }

        $this->fail('Ein fremder Name neben einem bekannten kommt durch.');
    }

    /** Ein leerer Name wird als solcher benannt und nicht als leere Stelle. */
    public function test_an_empty_name_is_named(): void
    {
        try {
            SystemPackagesUpgrade::names('packages', [''], self::BEKANNT);
        } catch (AgentException $error) {
            $this->assertStringContainsString('(leer)', $error->getMessage());

            return;
        }

        $this->fail('Ein leerer Name kommt durch.');
    }

    /**
     * `security` stellt seine Liste selbst zusammen — und ignoriert, was
     * mitgeschickt wurde.
     *
     * **Das ist die Stelle, an der ein Muster besonders teuer wäre.** Hier
     * reist gar kein Name aus dem Browser mit; wer die Liste trotzdem aus der
     * Anfrage nähme, gäbe dem Aufrufer eine freie Auswahl unter dem Namen
     * „nur Sicherheit".
     */
    public function test_security_builds_its_own_list(): void
    {
        $this->assertSame(
            ['tar', 'libssl3t64'],
            SystemPackagesUpgrade::names('security', ['gzip'], self::BEKANNT),
        );
    }

    /** `all` braucht keine Namen — und schickt auch keine mit. */
    public function test_all_sends_no_names(): void
    {
        $this->assertSame([], SystemPackagesUpgrade::names('all', ['tar'], self::BEKANNT));
    }

    /** Eine leere Auswahl ist kein Lauf über alles. */
    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessageMatches('/kein Paket ausgewählt/');

        SystemPackagesUpgrade::names('packages', [], self::BEKANNT);
    }

    /** Zweimal derselbe Name ist einmal derselbe Name. */
    public function test_a_name_is_sent_once(): void
    {
        $this->assertSame(['tar'], SystemPackagesUpgrade::names('packages', ['tar', 'tar'], self::BEKANNT));
    }

    /**
     * Und im Panel steht kein Muster über einem Paketnamen.
     *
     * **Die Grenze sitzt im Agenten, und eine zweite im Panel wäre die
     * schwächere davor.** Sie stünde ausserdem an der Stelle, an der niemand
     * die Liste kennt, gegen die sie prüfen müsste.
     *
     * > **Eine Grenze, die zweimal gezogen ist, gilt an der schwächeren
     * > Stelle.**
     */
    public function test_the_panel_puts_no_pattern_on_a_package_name(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/UpdatesController.php',
        ));

        $this->assertStringContainsString("'packages.*' => ['string']", $quelle,
            'Die Regel für einen Paketnamen ist nicht mehr `string` allein — dann prüft dieser Wächter nichts.');

        foreach (['regex:', 'alpha_dash', 'preg_match'] as $muster) {
            $this->assertStringNotContainsString($muster, $quelle, sprintf(
                'In UpdatesController steht `%s`. Ein Muster über einem Paketnamen ist die zweite '
                .'Fassung einer Grenze, die im Agenten gegen die gelesene Liste geht.',
                $muster,
            ));
        }
    }

    /**
     * Und `--only-upgrade` steht nicht im Modus, der Namen bekommt.
     *
     * **Gemessen (U9):** Auf ein Paket, das noch nicht installiert ist, tut
     * `--only-upgrade` wortlos nichts und endet mit 0. In der Liste der
     * Aktualisierungen stehen aber auch **Neuinstallationen** — Zeilen ohne
     * alte Fassung —, und die fielen damit still unter den Tisch.
     *
     * Im Modus `panel` ist die Fahne richtig: Dort geht es um genau ein Paket,
     * das mit Sicherheit installiert ist.
     */
    public function test_the_named_run_does_not_use_only_upgrade(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/apt-run');

        $zweig = $this->branch($skript, 'packages');

        $this->assertNotSame('', $zweig, 'Im Skript gibt es keinen Zweig `packages)` mehr.');
        $this->assertStringContainsString('set -- install', $zweig,
            'Der Zweig `packages)` ruft kein `install` mehr — dann misst dieser Wächter nichts.');
        $this->assertStringNotContainsString('--only-upgrade', $zweig,
            'Der Zweig `packages)` benutzt --only-upgrade. Eine Neuinstallation aus der Liste fiele '
            .'damit wortlos unter den Tisch (gemessen, docs/81 §2.3g U9).');

        // Die Gegenprobe: Im Zweig `panel` steht sie, und dort gehört sie hin.
        $this->assertStringContainsString('--only-upgrade', $this->branch($skript, 'panel'),
            'Auch `panel)` benutzt sie nicht mehr — dann sucht der Ausdruck oben ins Leere.');
    }

    /**
     * Der Rumpf eines `case`-Zweiges des Skripts — **ohne seine Kommentare.**
     *
     * **Ohne diesen Schnitt meldete dieser Wächter seinen eigenen Text als
     * Verstoss.** Im Zweig `packages)` steht die Erklärung „Kein
     * `--only-upgrade`" — und eine Zeichenkettensuche findet sie. Beim ersten
     * Lauf war der Wächter genau deshalb rot, und die naheliegende Reaktion
     * wäre gewesen, die Erklärung zu streichen.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, findet sie auch dort, wo
     * > jemand erklärt, warum sie nicht dasteht.**
     */
    private function branch(string $skript, string $name): string
    {
        $skript = $this->withoutHashComments($skript);
        $von = strpos($skript, "\n    ".$name.')');

        if ($von === false) {
            return '';
        }

        $bis = strpos($skript, "\n        ;;", $von);

        return $bis === false ? '' : substr($skript, $von, $bis - $von);
    }
}
