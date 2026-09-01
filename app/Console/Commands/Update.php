<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\PanelUpdate;
use SrvPanel\Agent\Outcome;

/**
 * `srvpanel update` — stösst das Update an und liest sein Urteil nach.
 *
 * ## Der Befund, gegen den es die Warteschleife gibt
 *
 * **Bis zum 1. September 2026 trat dieser Befehl nach dem Absetzen zur Seite
 * und gab `SUCCESS` zurück — auch wenn der Lauf danach scheiterte.** Ein
 * `srvpanel update && …` in einem Skript bekam für ein misslungenes Update ein
 * `ok`.
 *
 * Das ist Form A aus `docs/86 §5`, an der einen Stelle, die die Behebung nie
 * bekommen hat: `AwaitDispatchedRun` liest das Urteil seit dem
 * 28. August für die Vorgänge des Panels nach, die Kommandozeile blieb aussen
 * vor.
 *
 * > **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den
 * > Ausgang dessen, was er abgesetzt hat, nichts.**
 *
 * ## Warum das lange als unmöglich galt
 *
 * Im Kopf dieser Klasse stand: „Es wartet bewusst nicht auf das Ergebnis: Der
 * Lauf beendet den Prozess, der ihn angestoßen hat." Dasselbe sagte die Meldung
 * an den Betreiber. **Gemessen hatte das niemand** — der Befehl hat nie
 * gewartet, also konnte es nie auffallen.
 *
 * Am 1. September auf `cloudsrv24` gemessen (`docs/94 §6`, M1), quer über den
 * Wechsel von `0.7.3-rc.4` auf `-rc.5`:
 *
 *     05:41:37  pid=934334  cwd=/opt/srvpanel/releases/0.7.3-rc.4  artisan=1
 *     05:41:42  pid=934334  cwd=FORT                               artisan=0
 *     05:42:17  pid=934334  cwd=FORT                               artisan=0
 *
 * **Der Prozess überlebt.** Dieselbe PID vor und nach dem Umschalten, fünfzig
 * Sekunden weiter noch am Leben. Der Satz war falsch.
 *
 * > **Ein Satz, den die Oberfläche behauptet und den niemand gemessen hat, ist
 * > eine Vermutung mit Fussnote.**
 *
 * ## Die Bauvorschrift, die daraus folgt
 *
 * **Was das Fassungsverzeichnis abräumt, ist danach nicht mehr nachladbar.**
 * `cwd=FORT` und `artisan=0` sagen es: Das Paket entfernt die alte Fassung, und
 * `agent/` liegt darin. Alles, was die Warteschleife braucht, muss **vor** dem
 * Absetzen geladen sein — ein `class_exists()` danach scheitert lautlos, und
 * der Befehl stürbe mitten im Update.
 *
 * Deshalb steht {@see self::vorladen()} vor dem Aufruf des Agenten und nicht
 * daneben.
 *
 * ## Und das reicht nur bis zum eigenen Code
 *
 * **Die Bauvorschrift oben deckt die Warteschleife und nicht den Abbau.** Am
 * 1. September 2026 hat der erste Sprung mit beiden behobenen Hälften
 * (`0.7.3-rc.7` → `-rc.8`, `docs/96 §1`) das Urteil gedruckt und ist danach
 * gestorben: Laravel lädt nach `handle()` weiter nach, und jede dieser Dateien
 * liegt im abgeräumten Verzeichnis.
 *
 * Der Befehl gab damit `255` für ein Update zurück, das gelungen ist — die
 * Umkehrung des Befundes, gegen den es die Warteschleife überhaupt gibt.
 *
 * Die Antwort ist nicht, die Liste in `vorladen()` zu verlängern: Sie ist
 * gemessen gewachsen, während sie gefüllt wurde. Die Antwort steht am Ende von
 * {@see self::handle()} — der Prozess beendet sich selbst, bevor das Framework
 * dazu kommt.
 *
 * ## Warum Versatz 0 genügt
 *
 * {@see PanelUpdate} leert sein Log mit `@unlink()` **im Agenten, vor
 * `systemd-run`** — synchron beim Absetzen. Wenn dieser Befehl zu lesen
 * anfängt, ist die Datei bereits fort und der neue Lauf schreibt sie frisch.
 * Ein Urteil eines früheren Laufs kann hier also nicht erwischt werden; die
 * Falle aus `docs/86` Beobachtung 17 greift für `panel.update` nicht.
 *
 * ## Kein Fortschrittsbalken
 *
 * `apt` nennt keinen Anteil, und dieser Befehl kennt auch keinen: Wie lange ein
 * Update dauert, hängt an der Zahl der Pakete und der Leitung.
 *
 * > **Ein Balken, der keinen Anteil kennt, behauptet einen.**
 *
 * Gezeigt werden stattdessen die Zeilen selbst, sobald sie im Log stehen. Das
 * ist der Fortschritt, den es wirklich gibt.
 */
final class Update extends Command
{
    /**
     * Wie lange auf ein Urteil gewartet wird.
     *
     * Fünfzehn Minuten sind reichlich für ein Paket dieser Grösse und knapp
     * genug, dass ein Skript nicht ewig hängt. Der Lauf selbst hört davon
     * nichts — er steckt in einer transienten Unit und läuft weiter.
     */
    private const FRIST = 900;

    /** Wie oft nachgesehen wird. */
    private const TAKT = 3;

    protected $signature = 'srvpanel:update {--no-wait : Nur absetzen und sofort zurückkehren}';

    protected $description = 'Installiert eine neue Version des Panels aus der Paketquelle';

    public function handle(Client $agent): int
    {
        $warten = $this->option('no-wait') !== true;

        if ($warten) {
            $this->vorladen();
        }

        try {
            $result = $agent->call('panel.update', [], ['source' => 'cli', 'command' => 'srvpanel:update']);
        } catch (AgentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $unit = is_string($result['unit'] ?? null) ? $result['unit'] : '?';
        $log = is_string($result['log'] ?? null) ? $result['log'] : PanelUpdate::LOG;

        $this->newLine();
        $this->line(sprintf('  Das Update läuft als <options=bold>%s</>.', $unit));

        if (! $warten) {
            $this->line('  Der Lauf geht weiter; dieser Befehl wartet nicht auf ihn.');
            $this->newLine();
            $this->line(sprintf('  Zusehen:   tail -f %s', $log));
            $this->line(sprintf('  Zustand:   systemctl status %s', $unit));
            $this->newLine();
            $this->comment('  Antwortet die Bereitschaftsprüfung danach nicht, setzt das Paket selbst auf die vorige Version zurück.');

            return self::SUCCESS;
        }

        $this->line('  Das Panel startet dabei neu. Dieser Befehl liest mit und nennt am Ende den Ausgang.');
        $this->newLine();

        /*
         * **Hier endet der Prozess, statt zurückzukehren.**
         *
         * Nach dem Umschalten ist das Fassungsverzeichnis fort, und der
         * Autolader dieses Prozesses zeigt hinein. Alles, was Laravel *nach*
         * `handle()` noch nachlädt, findet seine Datei nicht mehr — und der
         * Befehl stirbt an einer Kaskade, nachdem er sein Urteil schon gedruckt
         * hat. Gemessen am 1. September 2026 auf `cloudsrv24` beim Sprung
         * `rc.7` → `rc.8`: grünes Urteil, dann zwei Fatals, `rc=255` für ein
         * gelungenes Update.
         *
         * > **Ein Rückgabewert, der einen gelungenen Lauf als Fehlschlag
         * > meldet, ist derselbe Fehler wie einer, der einen misslungenen als
         * > Erfolg meldet — nur in die andere Richtung.**
         *
         * {@see self::vorladen()} kann das nicht decken: Es lädt, was die
         * Warteschleife braucht, und der Abbau gehört dem Framework. Im
         * Nachbau war der erste fehlende Name
         * `Symfony\Component\Console\Event\ConsoleTerminateEvent`; mit ihm
         * vorgeladen traten vier neue an seine Stelle.
         *
         * > **Eine Positivliste über das, was ein fremdes Framework nach dem
         * > eigenen Code nachlädt, wächst, während man sie füllt.**
         *
         * `exit()` überspringt `Kernel::terminate()` und damit jedes Nachladen.
         * Die Ausgabe steht zu diesem Zeitpunkt vollständig auf dem Kanal —
         * gemessen: dieselben Zeilen, `rc=0`.
         */
        exit($this->mitlesen($log, $unit));
    }

    /**
     * Alles laden, was die Warteschleife braucht — **vor** dem Absetzen.
     *
     * Nach dem Umschalten ist das Fassungsverzeichnis fort (M1), und `agent/`
     * liegt darin. Ein Nachladen scheitert dann, und zwar mitten im Update.
     *
     * `class_exists()` und nicht `Outcome::class`: Die Marke ist eine Angabe für
     * den Übersetzer und löst kein Laden aus.
     */
    private function vorladen(): void
    {
        class_exists(Outcome::class);
        class_exists(PanelUpdate::class);
    }

    /**
     * Die Zeilen des Laufs zeigen und auf sein Urteil warten.
     *
     * Gezählt wird, was schon gedruckt wurde — der Leser gibt alle Zeilen ab
     * Versatz 0 zurück, und das Log gehört ausschliesslich diesem Lauf.
     */
    private function mitlesen(string $log, string $unit): int
    {
        $gedruckt = 0;
        $frist = time() + self::FRIST;

        while (time() < $frist) {
            $zeilen = Outcome::lines($log, 0);

            foreach (array_slice($zeilen, $gedruckt) as $zeile) {
                $this->line('  <fg=gray>'.$zeile.'</>');
            }

            $gedruckt = max($gedruckt, count($zeilen));

            $urteil = Outcome::verdict($zeilen);

            if ($urteil !== null) {
                return $this->urteilen($urteil);
            }

            sleep(self::TAKT);
        }

        /*
         * **Die Frist ist abgelaufen, und das ist weder Erfolg noch Fehlschlag.**
         *
         * Ein Rückgabewert kennt kein „ich weiss es nicht". Er fällt deshalb zur
         * Seite, die den Aufrufer anhalten lässt: Ein Skript, das `srvpanel
         * update && …` schreibt, soll nicht weitermachen, solange nichts belegt
         * ist.
         *
         * > **Ein Rückgabewert, der „ich weiss es nicht" nicht ausdrücken kann,
         * > muss sich entscheiden — und die sichere Seite ist die, die den
         * > Aufrufer anhalten lässt.**
         *
         * Der Lauf selbst ist davon unberührt; er steckt in seiner Unit.
         */
        $this->newLine();
        $this->warn(sprintf('  Nach %d Minuten steht noch kein Urteil im Protokoll.', intdiv(self::FRIST, 60)));
        $this->line('  Der Lauf geht weiter — dieser Befehl hat nur aufgehört zuzusehen.');
        $this->newLine();
        $this->line(sprintf('  Zusehen:   tail -f %s', $log));
        $this->line(sprintf('  Zustand:   systemctl status %s', $unit));

        return self::FAILURE;
    }

    /**
     * Das Urteil ausgeben und in einen Rückgabewert übersetzen.
     *
     * **Die Fassungsnummern stehen im Urteil selbst** — `apt-run` schreibt
     * „Fassung 0.7.3~rc.4 wurde zu 0.7.3~rc.5." Sie hier noch einmal zu
     * erfragen hiesse, dieselbe Auskunft zweimal zu holen; die zweite wäre die,
     * die abweicht.
     */
    private function urteilen(string $urteil): int
    {
        $this->newLine();

        if (Outcome::failed($urteil)) {
            $this->error('  '.$urteil);

            return self::FAILURE;
        }

        $this->info('  '.$urteil);

        /*
         * **Der Vorbehalt gilt nur für einen Lauf, der etwas eingespielt hat.**
         * Wo nichts anstand, hat dpkg nicht gearbeitet: Es liegt keine Kopie
         * unter `/opt/srvpanel/rollback`, und es läuft keine
         * Bereitschaftsprüfung. Der Satz verspräche ein Netz, das niemand
         * gespannt hat — unter einer Zeile, die gerade gesagt hat, dass die
         * Fassung unverändert ist.
         *
         * > **Zwei Sätze über denselben Lauf, von denen einer eine Installation
         * > voraussetzt, die der andere ausschliesst.**
         *
         * Gemessen am 1. September 2026 auf `cloudsrv24` (`docs/96 §2`).
         */
        if (! Outcome::unchanged($urteil)) {
            $this->newLine();
            $this->comment('  Antwortet die Bereitschaftsprüfung danach nicht, setzt das Paket selbst auf die vorige Version zurück.');
        }

        return self::SUCCESS;
    }
}
