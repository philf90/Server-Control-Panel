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
     * Wie viele Zeilen am Stück gelesen werden, bevor der Abbruch geprüft wird.
     *
     * Ein Dump von vierzig Gigabyte hat Millionen Zeilen; den Abbruch je Zeile
     * zu erfragen wäre ein Funktionsaufruf je Zeile für eine Frage, deren
     * Antwort sich in einer Sekunde nicht ändert.
     */
    private const CHECK_EVERY = 20_000;

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
     * @param  null|callable():bool  $abort
     * @return int Die Grösse der komprimierten Datei in Byte
     */
    public static function compress(string $rawPath, string $targetPath, ?callable $abort = null): int
    {
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
                gzwrite($out, self::withoutDefiner($line));

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
     * Das Verzeichnis eines Abonnements anlegen — `root:root 0750`.
     *
     * Nicht der Gruppe des Panels: Sie soll die **Dateien** lesen dürfen und
     * nicht das Verzeichnis durchsuchen. Wer eine Sicherung herunterlädt, kennt
     * ihren Namen aus dem Bestand; wer ihn nicht kennt, hat dort nichts zu
     * suchen.
     */
    public static function prepare(string $subscription): string
    {
        $directory = self::directory($subscription);

        if (! is_dir(self::ROOT) && ! @mkdir(self::ROOT, 0750, true) && ! is_dir(self::ROOT)) {
            throw AgentException::execFailed('Die Wurzel der Sicherungen liess sich nicht anlegen.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw AgentException::execFailed('Das Verzeichnis der Sicherungen liess sich nicht anlegen.', [
                'path' => $directory,
            ]);
        }

        chown($directory, 'root');
        chmod($directory, 0750);

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
