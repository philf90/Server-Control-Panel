<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * `srvpanel update` sagt, wie es ausgegangen ist.
 *
 * ## Der Befund, gegen den es diesen Wächter gibt
 *
 * Der Befehl setzte ab und gab `SUCCESS` zurück — auch für einen Lauf, der
 * danach scheiterte. Ein `srvpanel update && …` bekam für ein misslungenes
 * Update ein `ok`. Gemeldet vom Betreiber am 31. August 2026 (`docs/94 §6`).
 *
 * > **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den
 * > Ausgang dessen, was er abgesetzt hat, nichts.**
 *
 * ## Was er nicht hält
 *
 * Dass der Prozess den Neustart wirklich überlebt. Das ist eine Eigenschaft des
 * Servers und keine des Quelltextes — gemessen als M1 in `docs/94 §6`, und dort
 * steht sie mit ihren Zahlen. Ein Test kann sie nicht halten.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */
final class UpdateWaitTest extends TestCase
{
    use WithoutPhpComments;

    private const BEFEHL = 'app/Console/Commands/Update.php';

    private function quelle(string $pfad = self::BEFEHL): string
    {
        $inhalt = file_get_contents(dirname(__DIR__, 2).'/'.$pfad);

        self::assertIsString($inhalt, $pfad.' ist nicht lesbar.');

        return $this->withoutComments($inhalt);
    }

    /**
     * Der Rückgabewert kommt aus dem Urteil und nicht aus dem Absetzen.
     *
     * Beide Richtungen: Ein Fehlschlag muss `FAILURE` ergeben, und es muss
     * überhaupt einen Weg zu `SUCCESS` geben — ein Befehl, der immer scheitert,
     * bestünde die erste Hälfte genauso.
     */
    public function test_the_exit_code_comes_from_the_verdict(): void
    {
        $quelle = $this->quelle();

        $this->assertMatchesRegularExpression(
            '/if \(Outcome::failed\(\$urteil\)\) \{.*?return self::FAILURE;/s',
            $quelle,
            'Ein gescheiterter Lauf ergibt keinen Fehlschlag — dann meldet `srvpanel update && …` weiter „ok".',
        );

        $this->assertStringContainsString(
            'return self::SUCCESS;',
            $quelle,
            'Es gibt keinen Weg zu einem Erfolg — dann prüft die Hälfte darüber nichts.',
        );
    }

    /**
     * Geladen wird **vor** dem Absetzen.
     *
     * **Die Bauvorschrift aus M1** (`docs/94 §6`): Nach dem Umschalten ist das
     * Fassungsverzeichnis fort, und `agent/` liegt darin. Ein `class_exists()`
     * danach scheitert lautlos, und der Befehl stürbe mitten im Update.
     *
     * Geprüft wird die **Reihenfolge** und nicht das Wort: Stünde das Vorladen
     * danach, wäre der Aufruf trotzdem da, und ein Wächter, der nur nach ihm
     * sucht, bliebe still.
     */
    public function test_everything_the_wait_needs_is_loaded_before_dispatch(): void
    {
        $quelle = $this->quelle();

        $laden = strpos($quelle, '$this->vorladen();');
        $absetzen = strpos($quelle, "\$agent->call('panel.update'");

        $this->assertIsInt($laden, 'Der Befehl lädt nichts vor.');
        $this->assertIsInt($absetzen, 'Der Befehl setzt gar nichts mehr ab.');
        $this->assertLessThan(
            $absetzen,
            $laden,
            'Vorgeladen wird nach dem Absetzen — dann ist das Fassungsverzeichnis unter Umständen schon fort.',
        );

        $this->assertMatchesRegularExpression(
            '/class_exists\(Outcome::class\)/',
            $quelle,
            'Der Leser des Urteils wird nicht vorgeladen.',
        );
    }

    /**
     * Eine abgelaufene Frist ist kein Erfolg.
     *
     * Ein Rückgabewert kennt kein „ich weiss es nicht". Er fällt zur Seite, die
     * den Aufrufer anhalten lässt.
     *
     * Gemessen wird im Rumpf von `mitlesen()` **nach** der Schleife: Ein
     * `return self::FAILURE` irgendwo in der Datei sagt nichts darüber, was am
     * Ende der Warteschleife steht.
     */
    public function test_an_expired_deadline_is_not_a_success(): void
    {
        $quelle = $this->quelle();

        $anfang = strpos($quelle, 'private function mitlesen(');

        $this->assertIsInt($anfang, 'Es gibt keine Warteschleife mehr.');

        $ende = strpos($quelle, "\n    }\n", $anfang);

        $this->assertIsInt($ende, 'Der Rumpf der Warteschleife hört nirgends auf.');

        $rumpf = substr($quelle, $anfang, $ende - $anfang);
        $nachDerSchleife = substr($rumpf, (int) strrpos($rumpf, 'sleep('));

        $this->assertStringContainsString(
            'return self::FAILURE;',
            $nachDerSchleife,
            'Nach der Frist steht kein Fehlschlag — dann macht ein Skript weiter, obwohl nichts belegt ist.',
        );

        $this->assertStringNotContainsString(
            'return self::SUCCESS;',
            $nachDerSchleife,
            'Nach der Frist steht ein Erfolg — „noch kein Urteil" ist aber keines.',
        );
    }

    /**
     * Die Warteschleife kehrt nicht zurück — sie beendet den Prozess.
     *
     * ## Der Befund, gegen den es diese Hälfte gibt
     *
     * Am 1. September 2026 auf `cloudsrv24` (`docs/96 §1`): Der Befehl hat den
     * Symlink-Wechsel überlebt, sein Urteil grün gedruckt — und ist danach an
     * zwei Fatals gestorben. `rc=255` für ein gelungenes Update.
     *
     * Der Grund liegt nicht im eigenen Code: Laravel lädt nach `handle()`
     * weiter nach, und der Autolader dieses Prozesses zeigt in das abgeräumte
     * Fassungsverzeichnis. `Update::vorladen()` deckt die Warteschleife und kann
     * den Abbau nicht decken.
     *
     * Der Verweis steht als Fliesstext und nicht als `{@see}` mit vollem Namen:
     * Pint macht daraus einen `use`-Eintrag, und dieser Wächter läuft ohne
     * Framework — er läge damit still, wofür es ihn gibt.
     *
     * > **Eine Positivliste über das, was ein fremdes Framework nach dem
     * > eigenen Code nachlädt, wächst, während man sie füllt.** Im Nachbau
     * > fehlte zuerst `ConsoleTerminateEvent`; mit ihm vorgeladen traten vier
     * > neue an seine Stelle.
     *
     * ## Was hier gemessen wird
     *
     * Dass der Ausgang der Warteschleife den Prozess **selbst** beendet, und
     * dass es keinen zweiten Weg an `exit` vorbei gibt. Die Kommentare sind
     * abgestreift — sonst hielte die Zeile, die den alten Zustand zitiert, ihn
     * für diesen Wächter am Leben.
     *
     * ## Was er nicht hält
     *
     * Dass der Prozess damit wirklich sauber endet. Das ist eine Eigenschaft
     * von PHP und Laravel und keine des Quelltextes — gemessen im Container
     * gegen eine Fassung, die sich mitten im Lauf selbst abräumt: mit `return`
     * `rc=255`, mit `exit` `rc=0`, bei gleicher Ausgabe.
     */
    public function test_the_wait_ends_the_process_itself(): void
    {
        $quelle = $this->quelle();

        $this->assertSame(
            1,
            substr_count($quelle, '$this->mitlesen('),
            'Die Warteschleife wird nicht genau einmal gerufen — ein zweiter Aufruf käme an dieser Prüfung vorbei.',
        );

        $this->assertStringContainsString(
            'exit($this->mitlesen(',
            $quelle,
            'Die Warteschleife kehrt zurück, statt zu beenden — dann läuft Laravels Abbau in ein Verzeichnis, das es nicht mehr gibt.',
        );

        $this->assertStringNotContainsString(
            'return $this->mitlesen(',
            $quelle,
            'Es gibt weiter einen Rückweg aus der Warteschleife — und der ist genau der Weg in die Kaskade.',
        );
    }

    /**
     * Der Vorbehalt steht nur unter einem Lauf, der etwas eingespielt hat.
     *
     * ## Der Befund, gegen den es diesen Fall gibt
     *
     * **Gemessen am 1. September 2026 auf `cloudsrv24`** (`docs/96 §2`): Unter
     * dem grünen `Es stand nichts an — Fassung unverändert: 0.7.3~rc.9.` stand
     * der Satz über die Bereitschaftsprüfung und den Rückweg. Es hat aber nichts
     * entpackt — keine Kopie unter `/opt/srvpanel/rollback`, keine Prüfung.
     *
     * > **Zwei Sätze über denselben Lauf, von denen einer eine Installation
     * > voraussetzt, die der andere ausschliesst.**
     *
     * ## Warum im Rumpf von `urteilen()` und nicht in der Datei
     *
     * Denselben Satz gibt es ein zweites Mal, im Zweig für `--no-wait`. Dort
     * gehört er hin: Der Lauf ist abgesetzt und sein Ausgang unbekannt, der
     * Vorbehalt gilt also. Ein Wächter über die ganze Datei fände ihn und wäre
     * zufrieden.
     *
     * > **Ein Wächter, der eine Zeichenkette in der Datei sucht, sagt nichts
     * > über die Funktion, in der sie wirken soll.**
     */
    public function test_the_caveat_hangs_on_the_verdict(): void
    {
        $quelle = $this->quelle();

        $anfang = strpos($quelle, 'private function urteilen(');

        $this->assertIsInt($anfang, 'Es gibt keine Stelle mehr, die das Urteil ausgibt.');

        $rumpf = substr($quelle, $anfang);

        $wache = strpos($rumpf, 'if (! Outcome::unchanged($urteil)) {');
        $satz = strpos($rumpf, 'Antwortet die Bereitschaftsprüfung');

        $this->assertIsInt($satz, 'Der Vorbehalt steht nicht mehr in `urteilen()`.');
        $this->assertIsInt(
            $wache,
            'Der Vorbehalt hängt an keiner Frage — dann verspricht er einen Rückweg auch dort, wo nichts eingespielt wurde.',
        );
        $this->assertLessThan(
            $satz,
            $wache,
            'Die Frage steht nach dem Vorbehalt — dann ist der Satz schon gedruckt.',
        );
    }

    /**
     * Wer nicht warten will, sagt es — und die Vorgabe ist das Warten.
     *
     * **Die Vorgabe ist die Umkehrung des alten Verhaltens**, und das ist
     * Absicht: Der Fall, der stillschweigend das Falsche tat, war der ohne
     * Fahne.
     */
    public function test_waiting_is_the_default_and_can_be_turned_off(): void
    {
        $quelle = $this->quelle();

        $this->assertStringContainsString(
            '{--no-wait :',
            $quelle,
            'Es gibt keinen Weg mehr, nur abzusetzen.',
        );

        $this->assertStringContainsString(
            "\$warten = \$this->option('no-wait') !== true;",
            $quelle,
            'Die Vorgabe ist nicht das Warten — dann bleibt der Befund bestehen, nur mit einer Fahne daneben.',
        );
    }

    /**
     * Und das Log wird ab seinem Anfang gelesen.
     *
     * `PanelUpdate` leert es mit `@unlink()` **im Agenten, vor `systemd-run`** —
     * synchron beim Absetzen. Damit gehört alles, was danach dort steht, diesem
     * Lauf. Diese Zusage hängt an zwei Dateien, und der Test hält beide
     * zusammen: Zöge das Leeren in die Unit, läse dieser Befehl beim ersten
     * Blick das Urteil des **vorigen** Laufs.
     *
     * > **Ein Urteil in einer Datei, die mehrere Läufe sammelt, gehört an die
     * > Stelle gebunden, an der der eigene Lauf begonnen hat.**
     */
    public function test_the_log_is_emptied_before_the_run_is_dispatched(): void
    {
        $agent = $this->quelle('agent/src/Ops/PanelUpdate.php');

        $leeren = strpos($agent, '@unlink(self::LOG);');
        $absetzen = strpos($agent, "run('systemd-run'");

        $this->assertIsInt($leeren, 'Das Log wird nicht mehr geleert — dann steht dort noch der vorige Lauf.');
        $this->assertIsInt($absetzen, 'Der Lauf wird nicht mehr über systemd-run abgesetzt.');
        $this->assertLessThan(
            $absetzen,
            $leeren,
            'Geleert wird nach dem Absetzen — dann liest der Befehl beim ersten Blick ein fremdes Urteil.',
        );

        $this->assertStringContainsString(
            'Outcome::lines($log, 0)',
            $this->quelle(),
            'Der Befehl liest nicht ab dem Anfang.',
        );
    }
}
