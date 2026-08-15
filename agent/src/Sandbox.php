<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use Socket;
use Throwable;

/**
 * Die Grenze, in der jede Datei-Operation eines Abonnements läuft.
 *
 * **Warum es diese Klasse gibt.** Bis P5c nahm keine Operation einen Pfad
 * entgegen — sie baute ihn aus einem Namen gegen eine Positivliste
 * ({@see Ops\SubscriptionProvision}). Ein Dateimanager kann das nicht: Er
 * bekommt den Pfad vom Kunden, das ist seine Aufgabe. Damit fällt der Schutz
 * weg, der P0 bis P5c getragen hat, und muss durch etwas Gleichwertiges
 * ersetzt werden.
 *
 * **Die naheliegende Ersetzung ist gemessen falsch.** Eine bessere Pfadprüfung
 * — `is_link`, dann `realpath($x) === $x`, dann zugreifen — verliert das
 * Rennen gegen einen Prozess des Abonnements, der `renameat2(RENAME_EXCHANGE)`
 * in einer Schleife fährt: **11 081 von 36 056 bestandenen Prüfungen** lasen
 * eine Datei ausserhalb der Grenze (`docs/50 §3`, einunddreissig Prozent). Der
 * Tausch ist atomar; es gibt keinen Augenblick, an dem eine Prüfung sich
 * stossen könnte.
 *
 * **Deshalb wird hier nicht geprüft, sondern eingesperrt.** Der Pfad des
 * Kunden wird *innerhalb eines Chroots* gedeutet, und dort kann kein Pfad
 * etwas ausserhalb bezeichnen — kein `..`, kein `/etc/passwd`, kein Symlink
 * dorthin, und auch kein Verzeichnis, das mitten im Vorgang durch einen
 * Symlink ersetzt wird. Die Grenze hält der Kernel und nicht diese Datei.
 * Gemessen: 0 Ausbrüche unter demselben Angreifer (`docs/50 §4`).
 *
 * **Die Rechteabgabe ist nicht die Zugabe, sie ist die Grenze.** Für root ist
 * `chroot` keine Schranke: Ein roher `chroot(2)` als root bricht aus,
 * derselbe Code nach `setuid` nicht (`docs/50 §5`). Dass PHPs `chroot()`
 * hinterher selbst `chdir("/")` macht und dem klassischen Ausbruch damit den
 * Hebel nimmt, ist eine Eigenheit der Implementierung und keine Zusage —
 * hierauf stützt sich nichts.
 *
 * > Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke.
 *
 * **Und `posix_setgroups()` gibt es in PHP nicht.** Ein Kind, das nur
 * `setgid` und `setuid` aufruft, behält die Zusatzgruppen von root und liest
 * damit eine Datei mit `root:root 0640` (gemessen, `docs/50 §5`).
 * {@see posix_initgroups()} davor schliesst das — und weil die Lücke nur
 * auffällt, wenn root überhaupt Zusatzgruppen hat, hat sie im Container
 * zuerst sauber ausgesehen.
 *
 * **Was hier ausdrücklich nicht steht: FFI.** `openat2(RESOLVE_BENEATH)` hält
 * den Systemaufruf tadellos — kein einziges Mal ausserhalb aufgelöst, alle
 * 34 947 Abweisungen `EXDEV`. Es hält nur den Vorgang nicht: PHPs
 * Dateifunktionen nehmen Pfade und keine Deskriptoren, und der Weg zurück über
 * `/proc/self/fd/N` ist eine **zweite Pfadauflösung** und damit dasselbe
 * Rennen noch einmal (8 106 Ausbrüche; derselbe fd über `read(2)` gelesen:
 * null). Wer `openat2` benutzen wollte, müsste Lesen, Schreiben, Auflisten,
 * Kopieren und Umbenennen als root in FFI nachbauen.
 *
 * **Und kein neues Programm auf der Positivliste.** {@see Runner} verbietet
 * `setpriv`, `runuser`, `su` und `sudo` ausdrücklich und mit Begründung;
 * `pcntl_fork` und die `posix_*`-Funktionen laufen im Prozess und rühren die
 * Liste nicht an.
 */
final class Sandbox
{
    /**
     * Wie lange ein Kind höchstens laufen darf, in Sekunden.
     *
     * Ein Kind, das hängt, darf den Agenten nicht mitnehmen — er bedient einen
     * Socket und hat andere Aufrufer.
     */
    public const TIMEOUT = 300;

    /**
     * Wie viel Ergebnis durch das Socketpaar passt.
     *
     * Die Grenze steht hier und nicht am Puffer des Kernels: Ein Kind, das
     * mehr schreiben will, als der Elternprozess liest, blockiert — und ein
     * Stillstand ohne Fehlermeldung ist der teuerste Fehlerweg, den es gibt.
     */
    public const MAX_RESULT = 8 * 1024 * 1024;

    /**
     * Eine Arbeitsfunktion ohne Rechte im Chroot des Abonnements ausführen.
     *
     * Der Rückgabewert der Funktion geht als JSON durch das Socketpaar zurück;
     * er muss deshalb serialisierbar sein. Eine {@see AgentException} aus der
     * Funktion kommt mit Code und Meldung beim Aufrufer an.
     *
     * @param  string  $root  Die Wurzel des Abonnements — sie wird das `/` des Kindes.
     * @param  string  $user  Der Systembenutzer des Abonnements.
     * @param  callable():mixed  $work  Läuft im Kind, ohne Rechte, im Chroot.
     * @param  list<Socket|resource>  $close  Was das Kind geerbt hat und schliessen muss — allen voran der Socket des Agenten.
     * @param  string|null  $withGroup  Eine zusätzliche Gruppe für das Kind — siehe {@see child()}.
     * @return mixed Was `$work` zurückgegeben hat.
     */
    public static function run(
        string $root,
        string $user,
        callable $work,
        array $close = [],
        ?string $withGroup = null,
    ): mixed {
        $account = self::account($user);
        $account['extra_gid'] = self::groupId($withGroup);
        self::rootDirectory($root);
        self::preload();

        // **Das Socketpaar entsteht vor dem fork, und das ist kein Detail.**
        // Nach dem chroot kann das Kind keine Datei ausserhalb mehr öffnen —
        // auch keine für sein Ergebnis. Ein Deskriptor, der vorher da war,
        // bleibt gültig; ein Pfad, der vorher galt, nicht.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            throw AgentException::execFailed('Der Rückweg aus der Sandbox liess sich nicht anlegen.');
        }

        [$parentSide, $childSide] = $pair;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSide);
            fclose($childSide);

            throw AgentException::execFailed('Die Sandbox liess sich nicht abspalten.');
        }

        if ($pid === 0) {
            fclose($parentSide);
            self::child($root, $account, $work, $childSide, $close);
            // Nicht erreichbar: child() endet immer mit exit.
        }

        fclose($childSide);

        return self::parent($pid, $parentSide);
    }

    /**
     * Alles laden, was das Kind braucht — **vor** dem Chroot.
     *
     * **Der Autoloader ist nach dem Chroot blind.** `agent/src/…` liegt
     * ausserhalb der Abo-Wurzel; ein `require` im Kind schlägt fehl. Das
     * Tückische daran ist nicht der Fehlschlag, sondern *wann* er kommt: Eine
     * Klasse, die nur im Fehlerfall gebraucht wird, fehlt auch nur im
     * Fehlerfall.
     *
     * **Diese Falle stand im Plan, und der erste Bau ist trotzdem
     * hineingelaufen.** Hier wurde nur {@see AgentException} geladen; die
     * gesamte Dateiverwaltung meldete daraufhin
     * `Class "SrvPanel\Agent\Files\Entry" not found` — als `internal`, also
     * als Fehler des Agenten und nicht als das, was es war.
     *
     * > Eine Falle, die man kennt und benennt, ist keine, in die man nicht
     * > fällt.
     *
     * **Deshalb wird jetzt aufgezählt statt aufgelistet.** Das Verzeichnis
     * `Files/` wird vollständig geladen; wer dort eine Klasse hinzufügt, muss
     * an nichts denken. Eine handgepflegte Liste wäre genau die Sorte
     * Zeichenkette, die auf etwas verweist, ohne dass jemand den Bezug prüft.
     */
    private static function preload(): void
    {
        class_exists(AgentException::class);
        class_exists(Guard::class);
        class_exists(Filesystem::class);
        class_exists(Ops\SubscriptionProvision::class);

        foreach (glob(__DIR__.'/Files/*.php') ?: [] as $file) {
            class_exists(__NAMESPACE__.'\\Files\\'.basename($file, '.php'));
        }
    }

    /**
     * Und wenn doch etwas fehlt, soll es das sagen.
     *
     * Ein Autoloader, der im Kind als letzter drankommt und eine verständliche
     * Meldung wirft. Ohne ihn heisst der Fehler `Class "…" not found` und
     * landet als `internal` beim Aufrufer — richtig, und trotzdem eine
     * Sackgasse für den, der ihn liest.
     */
    private static function explainMissingClasses(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (! str_starts_with($class, __NAMESPACE__.'\\')) {
                return;
            }

            throw AgentException::execFailed(
                'In der Sandbox fehlt eine Klasse — sie gehört in Sandbox::preload().',
                ['class' => $class],
            );
        });
    }

    /**
     * Was das Kind geerbt hat und nicht behalten darf, schliessen.
     *
     * **Der Grund steht in `AgentIdentityTest` und ist älter als diese
     * Klasse.** Als `docs/38 §6` einen Kennungswechsel im {@see Runner} erwog,
     * wurde er gemessen und verworfen — unter anderem, weil *der geforkte
     * Prozess den Socket des Agenten erbt*. Ein Kind, das ohne Rechte im
     * Verzeichnis des Kunden arbeitet und dabei den Deskriptor hält, über den
     * das Panel mit dem Agenten spricht, ist genau der Fehler, den jener
     * Wächter festhält.
     *
     * **Übergeben wird, was zu schliessen ist, und es wird nicht aufgezählt.**
     * Der erste Entwurf lief über `/proc/self/fd` und `fclose(fopen('php://fd/N'))`
     * — und das schliesst gar nichts: PHP dupliziert den Deskriptor beim
     * Öffnen, das Original bleibt offen (gemessen am 14. August 2026; vor und
     * nach dem `fclose` standen dieselben Nummern in `/proc/self/fd`, und die
     * ursprüngliche Datei war weiter lesbar). Ein Aufräumen, das aussieht wie
     * eines und keines ist, wäre hier das Schlimmste von allem.
     *
     * > **Ein Deskriptor, der beim Öffnen dupliziert wird, wird beim Schliessen
     * > nicht geschlossen.**
     *
     * Deshalb nennt der Aufrufer seine Handles.
     * `SandboxReachTest::test_the_child_closes_what_it_inherited` hält fest,
     * dass dieser Aufruf die erste Anweisung im Kind ist — eine Zusage, die
     * sonst niemand einlöst.
     *
     * @param  list<Socket|resource>  $handles
     */
    private static function closeInherited(array $handles): void
    {
        foreach ($handles as $handle) {
            if ($handle instanceof Socket) {
                @socket_close($handle);

                continue;
            }

            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }

    /**
     * Das Kind: erst einsperren, dann Rechte abgeben, dann arbeiten.
     *
     * **Die Reihenfolge ist die Sache selbst.** `chroot` braucht root, die
     * Rechteabgabe macht es unwiderruflich. Andersherum wäre beides wertlos.
     *
     * @param  array{uid: int, gid: int, name: string, extra_gid: int|null}  $account
     * @param  callable():mixed  $work
     * @param  resource  $back
     * @param  list<Socket|resource>  $close
     */
    private static function child(string $root, array $account, callable $work, $back, array $close): never
    {
        try {
            self::closeInherited($close);
            self::explainMissingClasses();

            if (! @chroot($root)) {
                throw AgentException::execFailed('Das Chroot liess sich nicht setzen.');
            }

            chdir('/');

            /*
             * **Erst die Zusatzgruppen, dann die Kennungen.** initgroups
             * braucht root, und nach setuid() gibt es kein Zurück. Ohne diese
             * Zeile behält das Kind die Zusatzgruppen von root und liest damit
             * Dateien, die `root:root 0640` gehören (gemessen, docs/50 §5).
             *
             * ## Das zweite Argument, und warum es manchmal eine fremde Gruppe ist
             *
             * `initgroups(3)` setzt die Gruppen des Benutzers aus `/etc/group`
             * **plus** die hier genannte. Normalerweise ist das die eigene
             * Gruppe des Abonnements, also nichts Zusätzliches.
             *
             * `files.chmod` verlangt `www-data`, und der Grund ist eine
             * Eigenheit von `chmod(2)`: **Der Kernel entfernt `S_ISGID`
             * lautlos**, wenn der aufrufende Prozess weder `CAP_FSETID` hat
             * noch der Gruppe der Datei angehört — und gibt dabei `true`
             * zurück. Ein Kunde konnte das setgid-Bit seines eigenen
             * Verzeichnisses damit nicht erhalten; jedes `chmod` aus dem
             * Rechte-Editor nahm es weg, und die nächste Datei darin war für
             * nginx unlesbar (gemessen auf `cloudsrv24`, `docs/55` Befund 17).
             *
             * > **Ein Rückgabewert `true` ist keine Zusage darüber, was
             * > geschrieben wurde.**
             *
             * **Warum das nichts aufmacht.** Das Kind sitzt im Chroot des
             * Abonnements, und dort gehört alles mit der Gruppe `www-data`
             * diesem Kunden — die Gruppe verschafft ihm keinen fremden
             * Zugriff. Sie gilt ausserdem nur für **diesen einen** Vorgang:
             * Das Kind macht einen `chmod` und beendet sich.
             *
             * `SandboxGroupTest` besteht darauf, dass keine zweite Operation
             * sie verlangt — die Begründung oben gilt für einen Griff, der
             * nichts liest, und nicht als Freibrief.
             */
            if (! posix_initgroups($account['name'], $account['extra_gid'] ?? $account['gid'])) {
                throw AgentException::execFailed('Die Zusatzgruppen liessen sich nicht setzen.');
            }

            // Gruppe vor Benutzer: Nach setuid() darf ein Prozess seine Gruppe
            // nicht mehr wechseln.
            if (! posix_setgid($account['gid']) || ! posix_setuid($account['uid'])) {
                throw AgentException::execFailed('Die Rechte liessen sich nicht abgeben.');
            }

            // Der Gürtel zum Hosenträger: Wenn die Abgabe misslungen wäre,
            // ohne es zu melden, endet es hier und nicht in einer Operation,
            // die als root im Verzeichnis des Kunden schreibt.
            if (posix_geteuid() === 0 || posix_getuid() === 0) {
                throw AgentException::execFailed('Die Rechte sind nach der Abgabe noch die von root.');
            }

            $result = [
                'ok' => true,
                'value' => $work(),
                // Nicht Zierde, sondern Beleg: Der Aufrufer soll nachweisen
                // können, dass der Vorgang wirklich ohne Rechte lief, und
                // nicht bloss, dass nichts danebenging (docs/51 §4, Punkt 13
                // und 14).
                'uid' => posix_getuid(),
                'groups' => posix_getgroups(),
            ];
        } catch (AgentException $e) {
            $result = ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'code' => AgentException::INTERNAL, 'message' => $e->getMessage(), 'details' => []];
        }

        $payload = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false || strlen($payload) > self::MAX_RESULT) {
            $payload = json_encode([
                'ok' => false,
                'code' => AgentException::INTERNAL,
                'message' => 'Das Ergebnis der Sandbox passt nicht in den Rückweg.',
                'details' => [],
            ], JSON_UNESCAPED_SLASHES);
        }

        @fwrite($back, (string) $payload);
        @fclose($back);

        // `exit` und nicht `return`: Ein Kind, das aus dieser Funktion
        // herausliefe, wäre eine zweite Kopie des Agenten mit demselben
        // Socket.
        exit(0);
    }

    /**
     * Der Elternprozess: lesen, warten, unterscheiden.
     *
     * **Ein Kind, das stirbt, ist kein leeres Ergebnis.** Genau dieser Fehler
     * steckte in `docs/48`: Der Vorgang bekam „vermutlich Zeitüberschreitung"
     * nach einer Sekunde Laufzeit, weil der Fehlerweg selbst fehlgeschlagen
     * war.
     *
     * > Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.
     *
     * @param  resource  $side
     */
    private static function parent(int $pid, $side): mixed
    {
        stream_set_blocking($side, false);

        $deadline = time() + self::TIMEOUT;
        $raw = '';
        $killed = false;

        while (true) {
            $read = [$side];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) > 0) {
                $chunk = fread($side, 65536);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $raw .= $chunk;

                if (strlen($raw) > self::MAX_RESULT) {
                    $killed = true;
                    posix_kill($pid, SIGKILL);
                    break;
                }

                continue;
            }

            if (feof($side)) {
                break;
            }

            if (time() >= $deadline) {
                $killed = true;
                posix_kill($pid, SIGKILL);
                break;
            }
        }

        fclose($side);
        pcntl_waitpid($pid, $status);

        if ($killed) {
            throw new AgentException(AgentException::TIMEOUT, 'Der Vorgang in der Sandbox wurde abgebrochen.');
        }

        // **Ein Signal ist ein Fehlschlag und keine leere Antwort.** Ohne
        // diesen Zweig sähe ein abgeschossenes Kind aus wie eines, das nichts
        // zu sagen hatte.
        if (pcntl_wifsignaled($status)) {
            throw AgentException::execFailed('Die Sandbox wurde durch ein Signal beendet.', [
                'signal' => pcntl_wtermsig($status),
            ]);
        }

        $decoded = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            throw AgentException::execFailed('Die Sandbox hat kein verwertbares Ergebnis geliefert.', [
                'exit_code' => pcntl_wexitstatus($status),
            ]);
        }

        if ($decoded['ok'] !== true) {
            throw new AgentException(
                is_string($decoded['code'] ?? null) ? $decoded['code'] : AgentException::INTERNAL,
                is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Der Vorgang in der Sandbox ist fehlgeschlagen.',
                is_array($decoded['details'] ?? null) ? $decoded['details'] : [],
            );
        }

        // Der Beleg aus dem Kind wird geprüft und nicht bloss weitergereicht:
        // Ein Ergebnis, das behauptet, als root gelaufen zu sein, ist ein
        // Fehler und kein Ergebnis.
        if (($decoded['uid'] ?? 0) === 0 || in_array(0, $decoded['groups'] ?? [0], true)) {
            throw AgentException::execFailed('Die Sandbox meldet Rechte, die sie nicht haben darf.');
        }

        return $decoded['value'] ?? null;
    }

    /**
     * Der Systembenutzer, unter dem gearbeitet wird.
     *
     * Die Form ist dieselbe wie in {@see Ops\SubscriptionProvision}: `p` und
     * Ziffern. Sie steht hier nicht doppelt, sondern wird dort erfragt — zwei
     * Fassungen derselben Regel wären zwei Gelegenheiten, sie auseinander
     * laufen zu lassen.
     *
     * @return array{uid: int, gid: int, name: string}
     */
    private static function account(string $user): array
    {
        $name = Ops\SubscriptionProvision::systemUser($user);
        $entry = posix_getpwnam($name);

        if ($entry === false) {
            throw new AgentException(AgentException::NOT_FOUND, 'Den Systembenutzer des Abonnements gibt es nicht.');
        }

        if ($entry['uid'] === 0 || $entry['gid'] === 0) {
            throw AgentException::denied('Der Systembenutzer des Abonnements ist root.');
        }

        return ['uid' => $entry['uid'], 'gid' => $entry['gid'], 'name' => $name, 'extra_gid' => null];
    }

    /**
     * Die Kennung einer zusätzlichen Gruppe — oder `null`, wenn keine gefragt ist.
     *
     * **Ein fehlender Eintrag ist kein Fehler.** `www-data` gibt es auf einem
     * Server ohne nginx nicht, und der Vorgang soll dort trotzdem laufen: Dann
     * bleibt es bei der eigenen Gruppe des Abonnements, und das setgid-Bit
     * fällt weg — was ohne Webserver niemanden stört. Ein Abbruch wäre die
     * schlechtere Antwort auf eine Lage, die keinen Schaden anrichtet.
     */
    private static function groupId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        $entry = posix_getgrnam($name);

        if ($entry === false) {
            return null;
        }

        if ($entry['gid'] === 0) {
            throw AgentException::denied('Die zusätzliche Gruppe der Sandbox ist root.');
        }

        return $entry['gid'];
    }

    /**
     * Die Wurzel, die das `/` des Kindes wird.
     *
     * **Sie kommt nicht vom Kunden.** Der Pfad *innerhalb* darf von ihm
     * kommen — dafür ist diese Klasse da —, die Wurzel selbst nie. Geprüft
     * wird deshalb gegen {@see Ops\SubscriptionProvision::VHOSTS} und nicht
     * gegen eine Angabe im Aufruf.
     */
    private static function rootDirectory(string $root): void
    {
        $path = rtrim($root, '/');

        if (! str_starts_with($path.'/', Ops\SubscriptionProvision::VHOSTS.'/')) {
            throw AgentException::denied('Die Wurzel der Sandbox liegt nicht unterhalb der Vhost-Wurzel.');
        }

        if ($path === Ops\SubscriptionProvision::VHOSTS) {
            throw AgentException::denied('Die Vhost-Wurzel selbst ist keine Sandbox — sie enthält alle Abonnements.');
        }

        if (! is_dir($path) || is_link($path)) {
            throw new AgentException(AgentException::NOT_FOUND, 'Die Wurzel des Abonnements gibt es nicht.');
        }
    }
}
