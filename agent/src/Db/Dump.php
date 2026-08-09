<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Die Ablage der Sicherungen — und der Filter, ohne den sie sich nicht
 * zurückspielen lassen.
 *
 * ## Wo eine Sicherung liegt
 *
 * Unter {@see self::ROOT}, **nicht** unter `/var/www/vhosts/<abo>/`. Ein Dump
 * ist die vollständige Datenbank des Kunden; im Abo-Verzeichnis läge er einen
 * Verzeichniswechsel vom DocumentRoot entfernt, und den DocumentRoot stellt der
 * Kunde selbst ein. Ein Panel, das die Daten seiner Kunden in einen Ordner
 * legt, den ein Webserver ausliefern kann, hat sie veröffentlicht.
 *
 * `root:srvpanel 0640`, damit das Panel herunterladen kann, ohne den Agenten zu
 * fragen: Eine Datei von zwei Gigabyte über den Unix-Socket zurückzureichen
 * wäre der Weg, auf dem der Agent den Speicher des Servers füllt. Er schreibt,
 * das Panel liest.
 *
 * ## Die DEFINER-Falle
 *
 * `mysqldump` schreibt zu jeder Prozedur, jedem Trigger und jeder Sicht eine
 * `DEFINER`-Angabe. Beim Zurückspielen **unter einem anderen Benutzer** — und
 * genau das ist der Fall, sobald jemand sein Passwort zurückgesetzt hat oder
 * der Dump aus einem anderen Abonnement stammt — bricht MariaDB mit „Access
 * denied; you need SUPER privileges" ab. Der Kunde sähe einen Fehlschlag, den
 * er nicht deuten kann, bei einer Sicherung, die er selbst angelegt hat. Und
 * `SUPER` bekommt hier niemand: Es ist ein globales Recht, und die
 * Rechtevergabe bleibt auf Schemaebene (`docs/36 §3.1`).
 *
 * Deshalb fällt die Angabe **beim Schreiben** weg und nicht beim Einspielen:
 * Ein Dump ohne DEFINER lässt sich überall einspielen, einer mit nur an genau
 * einer Stelle.
 *
 * ## Warum der Filter über eine Datei läuft und nicht durch das Rohr
 *
 * Der erste Entwurf (`docs/36 §10.1`) wollte ihn in den Rückkanal des Runners
 * setzen. Das geht nicht, und der Grund steht in zwei Zeilen, die für jeden
 * anderen Zweck richtig sind: `Runner` deckelt die gesammelte Ausgabe bei 4 MiB
 * und liefert `onOutput` je `fread`-Chunk von 64 KiB, aufgeteilt an `\n`. **Eine
 * Zeile über eine Chunk-Grenze kommt als zwei „Zeilen" an** — für eine
 * Fortschrittsanzeige kosmetisch, für einen Filter über Kundendaten
 * Datenkorruption, und zwar an den grossen Zeilen, also an den Datenzeilen.
 *
 * `mysqldump --result-file=` schreibt deshalb unmittelbar in eine Datei; über
 * den Ausgabepfad des Runners läuft nichts. {@see self::compress()} liest sie
 * danach mit `fgets` — das respektiert echte Zeilengrenzen — und schreibt
 * komprimiert daneben. Komprimiert wird mit `gzopen`/`gzwrite` in PHP: Die
 * Positivliste des Runners wächst in P5 um **kein** Programm.
 */
final class Dump
{
    /** Die Wurzel aller Sicherungen. Steht hier und kommt nicht von aussen. */
    public const ROOT = '/var/lib/srvpanel/dumps';

    /**
     * Die Gruppe, die lesen darf — der Systembenutzer des Panels.
     *
     * Nicht der des Abonnements: Ein Kunde lädt seine Sicherung über das Panel
     * herunter und findet sie nicht über SFTP im Vorbeigehen. Sie liegt
     * ausserhalb seines Chroots, und das ist Absicht.
     */
    public const GROUP = 'srvpanel';

    /**
     * Die Datei: lesbar für die Gruppe, für sonst niemanden.
     *
     * Der Agent schreibt sie als root; das Panel liest sie über die Gruppe. Ein
     * Dump von zwei Gigabyte durch den Unix-Socket zurückzureichen wäre der
     * Weg, auf dem der Agent den Speicher des Servers füllt.
     */
    public const FILE_MODE = 0640;

    /**
     * Das Verzeichnis: **durchsuchbar** für die Gruppe, nicht auflistbar.
     *
     * **Hier stand `0750` mit der Gruppe `root`, und damit war das Herunterladen
     * kaputt** — gefunden am 8. August 2026 auf `cloudsrv24`, als das Panel auf
     * eine fertige Sicherung mit 404 antwortete. Die Absicht stand richtig da
     * („die Dateien lesen dürfen und nicht das Verzeichnis durchsuchen"), aber
     * unter Unix öffnet man eine Datei über ihren Pfad: Ohne `x` auf **jedem**
     * Verzeichnis darüber nützt das `r` an der Datei nichts. Der Modus sagte
     * also das Gegenteil dessen, was er sollte.
     *
     * `0710` mit der Gruppe des Panels trifft die Absicht genau: `--x` heisst
     * hingehen, wenn man den Namen kennt, und `ls` bleibt verwehrt. Wer eine
     * Sicherung herunterlädt, kennt ihren Namen aus dem Bestand.
     */
    public const DIRECTORY_MODE = 0710;

    /**
     * Wie viele Zeilen am Stück gelesen werden, bevor der Abbruch geprüft wird.
     *
     * Ein Dump von vierzig Gigabyte hat Millionen Zeilen; den Abbruch je Zeile
     * zu erfragen wäre ein Funktionsaufruf je Zeile für eine Frage, deren
     * Antwort sich in einer Sekunde nicht ändert.
     */
    private const CHECK_EVERY = 20_000;

    /**
     * Woran eine gzip-Datei zu erkennen ist.
     *
     * `1f 8b` steht am Anfang jedes gzip-Stroms (RFC 1952). Mehr wird nicht
     * geprüft: Ob der Inhalt danach SQL ist, entscheidet der Datenbankserver
     * beim Einspielen, und ein Agent, der SQL zu erkennen versucht, baut einen
     * Parser, den niemand geprüft hat.
     */
    private const MAGIC = "\x1f\x8b";

    /**
     * Der Deckel für die ausgepackte Grösse.
     *
     * Zwanzig Gibibyte sind mehr, als ein Formular sinnvoll entgegennimmt, und
     * weniger, als ein Dateisystem stillschweigend verkraftet.
     */
    public const MAX_UNPACKED = 20 * 1024 * 1024 * 1024;

    /** Wie viel am Stück ausgepackt wird, während gezählt wird. */
    private const CHUNK = 1024 * 1024;

    /**
     * Der Bereich, aus dem eine hochgeladene Datei kommen darf.
     *
     * **Der Schreibbereich des Panels und kein eigenes Verzeichnis.** Dort darf
     * das Panel ohnehin schreiben, dort liegt die Datei nach dem Upload, und
     * das Paket legt ihn beim Einrichten schon an.
     */
    public const STAGING_ROOT = '/var/lib/srvpanel/storage/app/private/imports';

    /** Der Ablageort einer Sicherung — gebaut, nicht entgegengenommen. */
    public static function path(string $subscription, string $storageName): string
    {
        return self::directory($subscription).'/'.self::storageName($storageName).'.sql.gz';
    }

    /** Das Verzeichnis eines Abonnements unterhalb der Wurzel. */
    public static function directory(string $subscription): string
    {
        return self::ROOT.'/'.SubscriptionProvision::subscriptionName($subscription);
    }

    /**
     * Der Name einer Ablage: `<datenbank>-<zeitstempel>-<zufall>`.
     *
     * Geprüft wird gegen eine Positivliste, und die ist eng: Kleinbuchstaben,
     * Ziffern, Unterstrich und Bindestrich. Kein Punkt (er trennt die Endung),
     * kein Schrägstrich, kein `..`.
     */
    public static function storageName(string $value): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_\-]{0,95}$/D', $value)) {
            throw AgentException::badRequest('Unzulässiger Name für eine Ablage.', ['name' => $value]);
        }

        return $value;
    }

    /**
     * Streicht die DEFINER-Angabe aus einer Zeile — oder lässt sie in Ruhe.
     *
     * **Die Falle in der Falle:** Ein blindes Suchen-und-Ersetzen über den
     * ganzen Dump verändert Nutzdaten. Eine Tabelle mit dem Text `DEFINER=` in
     * einer Spalte — ein Forum, in dem jemand über MySQL schreibt — käme
     * verändert zurück, und das fiele erst auf, wenn ein Kunde seine Daten
     * vermisst. Es wäre stille Datenkorrektur durch ein Sicherungswerkzeug,
     * also das Gegenteil dessen, wofür man es benutzt.
     *
     * Der Filter greift deshalb **nur auf Zeilen, die eine Anweisung
     * beginnen** — `/*!5…` oder `CREATE ` — und nur auf die `DEFINER=`-Angabe
     * darin. `DefinerStripTest` prüft beide Richtungen.
     */
    public static function withoutDefiner(string $line): string
    {
        $trimmed = ltrim($line);

        if (! str_starts_with($trimmed, '/*!5') && ! str_starts_with($trimmed, 'CREATE ')) {
            return $line;
        }

        /*
         * `DEFINER=` gefolgt von Benutzer und Wirt, in Backticks oder in
         * Anführungszeichen — beides kommt vor. Ein abschliessendes Leerzeichen
         * fällt mit, damit aus `CREATE DEFINER=… VIEW` ein `CREATE VIEW` wird
         * und nicht ein `CREATE  VIEW`.
         *
         * `SQL SECURITY DEFINER` bleibt stehen: Das ist kein Benutzername,
         * sondern die Angabe, in wessen Rechten die Prozedur läuft — und ohne
         * DEFINER-Zeile ist das der aufrufende Benutzer, also genau der, der
         * gerade einspielt.
         */
        return (string) preg_replace(
            '/DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|"[^"]*"|[^\s@]+)@(?:`[^`]*`|\'[^\']*\'|"[^"]*"|[^\s*]+)\s?/i',
            '',
            $line,
        );
    }

    /**
     * Die Rohdatei gefiltert und komprimiert danebenlegen — zeilenweise.
     *
     * Der Speicherbedarf ist eine Zeile, nicht ein Dump. Die Rohdatei bleibt
     * stehen; sie zu entfernen ist Sache des Aufrufers, der auch weiss, ob der
     * Lauf durchgekommen ist.
     *
     * **Der Filter wird übergeben und nicht angenommen.** Bis P5b stand
     * {@see self::withoutDefiner()} fest in der Schleife — richtig, solange es
     * ein Datenbanksystem gab. `pg_dump` schreibt keine `DEFINER`-Angaben
     * (gemessen, `docs/38 §13.2`), und ein Filter, der über Kundendaten läuft
     * und nichts zu suchen hat, ist ein Risiko ohne Gegenwert. `null` heisst
     * deshalb *unverändert durchschreiben*; wer filtern will, sagt es.
     *
     * Ein Vorgabewert wäre hier falsch gewesen: Er hätte für das eine System
     * gegolten und für das andere gegen die Absicht — der Aufruf soll die
     * Entscheidung tragen und nicht die Signatur.
     *
     * @param  null|callable():bool  $abort
     * @param  null|callable(string):string  $filter  Je Zeile; `null` schreibt unverändert
     * @return int Die Grösse der komprimierten Datei in Byte
     */
    public static function compress(
        string $rawPath,
        string $targetPath,
        ?callable $abort = null,
        ?callable $filter = null,
    ): int {
        $in = @fopen($rawPath, 'rb');

        if ($in === false) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht lesen.', ['path' => $rawPath]);
        }

        $out = @gzopen($targetPath, 'wb6');

        if ($out === false) {
            fclose($in);

            throw AgentException::execFailed('Die Sicherung liess sich nicht schreiben.', ['path' => $targetPath]);
        }

        $lines = 0;

        try {
            while (($line = fgets($in)) !== false) {
                gzwrite($out, $filter === null ? $line : $filter($line));

                if (++$lines % self::CHECK_EVERY === 0 && $abort !== null && $abort()) {
                    throw new AgentException(
                        AgentException::CANCELLED,
                        'Die Sicherung wurde abgebrochen.',
                    );
                }
            }
        } finally {
            gzclose($out);
            fclose($in);
        }

        $size = @filesize($targetPath);

        return $size === false ? 0 : $size;
    }

    /**
     * Eine komprimierte Sicherung wieder auspacken — für das Zurückspielen.
     *
     * `mysql` liest kein gzip, und der Umweg über den Arbeitsspeicher ist genau
     * der, den `Runner::run()` mit `$inputFile` vermeidet. Also wird auf die
     * Platte ausgepackt und die Datei als Standardeingabe übergeben.
     */
    public static function decompress(string $sourcePath, string $targetPath): void
    {
        $in = @gzopen($sourcePath, 'rb');

        if ($in === false) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht öffnen.', ['path' => $sourcePath]);
        }

        $out = @fopen($targetPath, 'wb');

        if ($out === false) {
            gzclose($in);

            throw AgentException::execFailed('Die Sicherung liess sich nicht auspacken.', ['path' => $targetPath]);
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 262144);

                if ($chunk === false) {
                    throw AgentException::execFailed('Die Sicherung ist beschädigt.', ['path' => $sourcePath]);
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            gzclose($in);
        }
    }

    /**
     * Die ersten Bytes — sonst ist es keine gzip-Datei, wie sie auch heisse.
     *
     * **Stand bis P5b in `DbDumpImport` und ist mit dem zweiten
     * Datenbanksystem hierher gezogen.** Was diese drei Prüfungen tun, hat mit
     * MariaDB oder PostgreSQL nichts zu tun: Es ist eine Datei, sie ist
     * gepackt, sie ist zu gross oder sie liegt auf einem anderen Einhängepunkt.
     * Sie ein zweites Mal zu schreiben wäre die zweite Fassung derselben Regel,
     * und die zweite ist die, die veraltet.
     */
    public static function requireGzip(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::execFailed('Die hochgeladene Datei ist nicht lesbar: '.$path);
        }

        $head = (string) fread($handle, 2);
        fclose($handle);

        if ($head !== self::MAGIC) {
            throw AgentException::badRequest(
                'Das ist keine gzip-Datei. Erwartet wird eine Sicherung, wie dieses Panel sie schreibt '
                .'(.sql.gz) — die Endung allein genügt nicht.',
            );
        }
    }

    /**
     * Wie gross die Datei ausgepackt ist — gezählt, nicht abgelesen.
     *
     * Gelesen wird nach nirgendwo: Was zählt, ist die Zahl, und die entsteht
     * beim Durchlauf. Über dem Deckel wird abgebrochen, ohne den Rest zu lesen
     * — eine Zip-Bombe soll Sekunden kosten und nicht Stunden.
     */
    public static function unpackedSize(string $path): int
    {
        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::badRequest('Die hochgeladene Datei liess sich nicht auspacken.');
        }

        $bytes = 0;

        while (! gzeof($handle)) {
            $chunk = gzread($handle, self::CHUNK);

            if ($chunk === false) {
                gzclose($handle);

                throw AgentException::badRequest('Die hochgeladene Datei bricht mittendrin ab — sie ist unvollständig.');
            }

            $bytes += strlen($chunk);

            if ($bytes > self::MAX_UNPACKED) {
                gzclose($handle);

                throw AgentException::denied(sprintf(
                    'Ausgepackt ist die Sicherung grösser als %d GB. So etwas gehört nicht durch ein '
                    .'Formularfeld — dafür gibt es Sicherungsziele.',
                    intdiv(self::MAX_UNPACKED, 1024 * 1024 * 1024),
                ));
            }
        }

        gzclose($handle);

        return $bytes;
    }

    /**
     * Platz dort, wo die Daten hinkommen — und nicht dort, wo die Datei liegt.
     *
     * **Das Datenverzeichnis kommt als Argument**, und das ist der eine
     * Unterschied zur Fassung aus P5: Für MariaDB ist es `/var/lib/mysql`, für
     * PostgreSQL das Verzeichnis des Clusters, und das steht nicht fest — es
     * kommt aus `pg_lsclusters`. Eine Konstante hier wäre eine Behauptung über
     * fremde Einrichtung.
     */
    public static function requireSpace(int $unpacked, string $dataDirectory): void
    {
        $free = @disk_free_space($dataDirectory);

        if ($free === false) {
            // Keine Auskunft ist kein Grund abzuweisen: Der Pfad kann auf einem
            // Server anders liegen, und ein Hochladen, das an einer fehlenden
            // Messung scheitert, wäre eine Grenze ohne Gegenstand.
            return;
        }

        if ($free < $unpacked) {
            throw AgentException::denied(sprintf(
                'Auf %s sind %d MB frei, die Sicherung braucht ausgepackt %d MB. Ein Zurückspielen, '
                .'dem mittendrin der Platz ausgeht, hinterlässt eine halbe Datenbank.',
                $dataDirectory,
                intdiv((int) $free, 1024 * 1024),
                intdiv($unpacked, 1024 * 1024),
            ));
        }
    }

    /**
     * Verschieben, und wenn das nicht geht, kopieren.
     *
     * `rename()` scheitert über Dateisystemgrenzen hinweg — der Schreibbereich
     * des Panels und `/var/lib/srvpanel/dumps` können auf verschiedenen
     * Einhängepunkten liegen. Dann bleibt Kopieren, und die Quelle geht
     * hinterher: Zwei Kopien eines halben Gigabytes sind ein voller Datenträger
     * aus Versehen.
     */
    public static function moveInto(string $source, string $target): void
    {
        if (@rename($source, $target)) {
            return;
        }

        if (! @copy($source, $target)) {
            throw AgentException::execFailed('Die Sicherung liess sich nicht übernehmen: '.$target);
        }

        @unlink($source);
    }

    /**
     * Das Verzeichnis eines Abonnements anlegen — `root:srvpanel 0710`.
     *
     * **Beide Ebenen, und jedes Mal.** Die Wurzel bekommt denselben Modus wie
     * das Verzeichnis darunter: Ein `x` auf dem einen nützt nichts, wenn es auf
     * dem anderen fehlt — der Pfad wird ganz durchlaufen. Und gesetzt wird bei
     * jedem Lauf und nicht nur beim Anlegen, damit eine Installation, die den
     * alten Modus hat, sich mit der nächsten Sicherung selbst berichtigt (siehe
     * {@see self::DIRECTORY_MODE}).
     */
    public static function prepare(string $subscription): string
    {
        $directory = self::directory($subscription);

        if (! is_dir(self::ROOT) && ! @mkdir(self::ROOT, self::DIRECTORY_MODE, true) && ! is_dir(self::ROOT)) {
            throw AgentException::execFailed('Die Wurzel der Sicherungen liess sich nicht anlegen.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, self::DIRECTORY_MODE, true) && ! is_dir($directory)) {
            throw AgentException::execFailed('Das Verzeichnis der Sicherungen liess sich nicht anlegen.', [
                'path' => $directory,
            ]);
        }

        // Eine Gruppe, die es nicht gibt, ist kein Grund zum Abbruch — dieselbe
        // Vorsicht wie in `DbDumpCreate::handOver()`. Ohne sie bleibt das
        // Verzeichnis root allein: enger als vorgesehen, nicht weiter.
        $group = posix_getgrnam(self::GROUP) !== false;

        foreach ([self::ROOT, $directory] as $path) {
            chown($path, 'root');

            if ($group) {
                chgrp($path, self::GROUP);
            }

            // Nach `chown`/`chgrp`, nicht davor: Beide löschen unter Linux die
            // setuid- und setgid-Bits, und ein `chmod` davor wäre damit halb
            // wirkungslos. Hier stehen sie ohnehin nicht — aber die Reihenfolge
            // ist die, die immer stimmt.
            chmod($path, self::DIRECTORY_MODE);
        }

        return $directory;
    }

    /**
     * Das Verzeichnis eines Abonnements wieder entfernen — beim Rückbau.
     *
     * Über {@see Filesystem::removeTree()}, also mit denselben Schranken wie
     * `subscription.remove`: keinem Symlink folgen, und der aufgelöste Pfad
     * muss derselbe sein.
     */
    public static function removeDirectory(string $subscription): bool
    {
        $directory = self::directory($subscription);

        if (! is_dir($directory) || is_link($directory)) {
            return false;
        }

        if (realpath($directory) !== $directory) {
            throw AgentException::denied('Der aufgelöste Pfad weicht ab — es wird nichts entfernt.');
        }

        Filesystem::removeTree($directory);

        return true;
    }
}
