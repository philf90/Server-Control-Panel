<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\AgentException;
use ZipArchive;

/**
 * Archive lesen und schreiben — innerhalb der Sandbox.
 *
 * **Was hier steht, ist keine Schranke.** Die hält das Chroot: Ein Eintrag
 * `../../../../etc/cron.d/x` kann die Wurzel des Abonnements nicht verlassen,
 * ganz gleich, was diese Datei täte. Was hier steht, verhindert etwas anderes —
 * dass ein Archiv sich **innerhalb** des Abonnements verlegt:
 *
 * > Ein Archiv, das nach `httpdocs/` entpackt wird, hat in `.ssh/` nichts zu
 * > suchen. Nicht, weil der Kunde dort nicht schreiben dürfte — er darf —,
 * > sondern weil er es an dieser Stelle nicht gemeint hat.
 *
 * Der Unterschied ist wichtig genug für einen eigenen Absatz, weil er
 * bestimmt, wie streng die Prüfungen unten sein müssen: Sie dürfen sich irren,
 * ohne dass jemand ausbricht. Eine Prüfung, an der die Sicherheit hinge, dürfte
 * das nicht, und sie stünde dann auch nicht hier, sondern im Kernel.
 *
 * **Zwei Grenzen sind trotzdem hart**, und beide haben nichts mit Pfaden zu
 * tun: die Zahl der Einträge und die ausgepackte Gesamtgrösse. Ein Archiv von
 * wenigen Kilobyte kann sich zu Gigabyte auspacken; die Quota des Abonnements
 * fängt das am Ende ab, aber erst, nachdem der Vorgang lange gelaufen ist und
 * das Dateisystem gefüllt hat.
 */
final class Archive
{
    /** Wie viele Einträge ein Archiv haben darf. */
    public const MAX_ENTRIES = 20000;

    /** Wie gross es ausgepackt werden darf. */
    public const MAX_UNPACKED = 2 * 1024 * 1024 * 1024;

    /**
     * Ein Archiv entpacken. Läuft **ausschliesslich** innerhalb der Sandbox.
     *
     * @return array{entries: int, bytes: int, skipped: list<string>, unnamed: int, written: int, redirected: list<string>}
     */
    public static function extract(string $archive, string $target): array
    {
        if (! is_dir($target)) {
            throw new AgentException(AgentException::NOT_FOUND, 'Das Zielverzeichnis gibt es nicht.', ['path' => $target]);
        }

        if (! is_writable($target)) {
            throw AgentException::denied('In dieses Verzeichnis darf das Abonnement nicht schreiben.');
        }

        ['names' => $names, 'unnamed' => $unnamed] = self::names($archive);

        if (count($names) + $unnamed > self::MAX_ENTRIES) {
            throw AgentException::badRequest('Das Archiv hat mehr Einträge als erlaubt.', [
                'entries' => count($names) + $unnamed,
                'max' => self::MAX_ENTRIES,
            ]);
        }

        $entries = 0;
        $bytes = 0;
        $skipped = [];

        foreach ($names as $name => $size) {
            $bytes += $size;

            if ($bytes > self::MAX_UNPACKED) {
                throw AgentException::badRequest('Das Archiv packt sich zu gross aus.', [
                    'max_bytes' => self::MAX_UNPACKED,
                ]);
            }

            $relative = self::normalise((string) $name);

            if ($relative === null) {
                $skipped[] = (string) $name;

                continue;
            }

            $entries++;
        }

        // Erst nach der Zählung auspacken: Ein Archiv, das an Eintrag 19 999
        // die Grenze reisst, soll gar nicht erst angefangen haben.
        return ['entries' => $entries, 'bytes' => $bytes, 'skipped' => $skipped, 'unnamed' => $unnamed]
            + self::unpack($archive, $target, $names, $skipped);
    }

    /**
     * Der Name eines Eintrags, auf etwas Harmloses zurückgeführt.
     *
     * **Vier Fälle fliegen heraus**, und jeder von ihnen ist ein Archiv, das
     * woanders hin will, als man es entpackt:
     *
     * 1. Ein absoluter Pfad (`/etc/passwd`).
     * 2. Ein `..`, das über den Anfang hinausführt.
     * 3. Ein Windows-Laufwerk (`C:\…`) oder ein Backslash als Trenner — beides
     *    kommt aus Archiven, die auf anderen Systemen entstanden sind.
     * 4. Ein Nullbyte, an dem PHP den Pfad abschneidet.
     *
     * @return string|null `null`, wenn der Eintrag nicht entpackt wird.
     */
    public static function normalise(string $name): ?string
    {
        if (str_contains($name, "\0")) {
            return null;
        }

        // Backslash als Trenner behandeln und nicht als Zeichen im Namen:
        // Ein Eintrag `..\..\x` wäre sonst ein gültiger Dateiname mit Punkten.
        $name = str_replace('\\', '/', $name);

        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name) === 1) {
            return null;
        }

        $parts = [];

        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                // **Nicht abschneiden, sondern verwerfen.** `array_pop` würde
                // aus `a/../../b` ein `b` machen — also einen Eintrag
                // entpacken, den das Archiv so nie benannt hat. Was hinaus
                // will, wird übersprungen und gemeldet.
                return null;
            }

            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /**
     * Die Einträge mit ihrer ausgepackten Grösse.
     *
     * **`PharData` zählt anders, als es aufzählt — und das hat ein ganzes
     * Merkmal gekostet.** Die Schleife `foreach (new PharData($archive) as
     * $file)` läuft über die **oberste Ebene** und über sonst nichts. Ein
     * gewöhnliches Tar mit `oben.txt`, `dir/mitte.txt` und `dir/sub/tief.txt`
     * ergab damit zwei Namen statt fünf: `oben.txt` wurde geschrieben, `dir`
     * landete unter „verlegt", und die beiden Dateien darunter verschwanden
     * spurlos — samt ihrer Grösse, mit der die Obergrenze rechnet.
     *
     * Gemessen am 18. August 2026 beim Bau der Punkte 7 und 8 des
     * Angriffsdurchgangs (`docs/62`); Zip war nie betroffen, weil `ZipArchive`
     * über den **Index** aufzählt und keine Ebenen kennt.
     *
     * > **Eine Aufzählung, die Ebenen hat, zählt nicht dasselbe wie eine, die
     * > keine hat.**
     *
     * Und die zweite Hälfte derselben Messung: Ein Eintrag, der nach `..`
     * hinausführt, taucht in **keiner** Aufzählung auf — `count($phar)` kennt
     * ihn, der Iterator nicht. Er wird deshalb nicht erraten, sondern gezählt:
     * `unnamed` ist die Zahl der Einträge, die das Archiv hat und die sich
     * nicht benennen lassen. Sie zu verschweigen hiesse, einem Kunden „0
     * übersprungen" für ein Archiv zu melden, dem etwas fehlt.
     *
     * @return array{names: array<string, int>, unnamed: int}
     */
    private static function names(string $archive): array
    {
        if (self::isZip($archive)) {
            $zip = new ZipArchive;

            if ($zip->open($archive) !== true) {
                throw AgentException::badRequest('Das Archiv lässt sich nicht öffnen.');
            }

            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat !== false) {
                    $names[$stat['name']] = (int) $stat['size'];
                }
            }

            $zip->close();

            return ['names' => $names, 'unnamed' => 0];
        }

        $phar = new PharData($archive);
        $root = 'phar://'.$archive;
        $names = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);

            // Verzeichnisse tragen den Schrägstrich am Ende — dieselbe Form,
            // in der `ZipArchive` sie liefert. `unpack()` erkennt sie daran
            // und legt sie an, statt einen Strom darauf zu öffnen.
            $names[$file->isDir() ? $relative.'/' : $relative] = $file->isDir() ? 0 : (int) $file->getSize();
        }

        return ['names' => $names, 'unnamed' => max(0, count($phar) - count($names))];
    }

    /**
     * @param  array<string, int>  $names
     * @param  list<string>  $skipped
     * @return array{written: int, redirected: list<string>}
     */
    private static function unpack(string $archive, string $target, array $names, array $skipped): array
    {
        $written = 0;

        if (self::isZip($archive)) {
            $zip = new ZipArchive;

            if ($zip->open($archive) !== true) {
                throw AgentException::badRequest('Das Archiv lässt sich nicht öffnen.');
            }

            $verlegt = [];

            foreach (array_keys($names) as $name) {
                $relative = self::normalise((string) $name);

                if ($relative === null) {
                    continue;
                }

                if (self::place($zip->getStream((string) $name) ?: null, $target, $relative, str_ends_with((string) $name, '/'))) {
                    $written++;
                } else {
                    // **Auch das gehört gemeldet.** Ein Eintrag, den
                    // `place()` ablehnt — weil ein Bestandteil des Weges ein
                    // Verweis ist —, verschwand hier zuerst spurlos: Der Lauf
                    // meldete „0 geschrieben, 0 übersprungen", und der Kunde
                    // hätte ein leeres Verzeichnis und keine Auskunft.
                    $verlegt[] = (string) $name;
                }
            }

            $zip->close();

            return ['written' => $written, 'redirected' => $verlegt];
        }

        $phar = new PharData($archive);
        $verlegt = [];

        foreach ($names as $name => $size) {
            $relative = self::normalise((string) $name);

            if ($relative === null) {
                continue;
            }

            // **Ein Verzeichnis ist kein Strom.** Bis zur Berichtigung der
            // Aufzählung gab es in einem Tar für diese Datei gar keine
            // Verzeichnisse — jeder Name kam von der obersten Ebene und wurde
            // als Datei behandelt. Ein `fopen` auf ein Verzeichnis im Phar
            // schlägt fehl, und der Eintrag landete unter „verlegt": die
            // Meldung, die dem Kunden sagte, dass etwas nicht stimmt, ohne zu
            // sagen, was.
            $directory = str_ends_with((string) $name, '/');
            $stream = $directory ? null : @fopen($phar[(string) $name]->getPathname(), 'rb');

            if (self::place($stream ?: null, $target, $relative, $directory)) {
                $written++;
            } else {
                $verlegt[] = (string) $name;
            }
        }

        return ['written' => $written, 'redirected' => $verlegt];
    }

    /**
     * Einen Eintrag an seinen Platz legen.
     *
     * **Kein Bestandteil des Weges darf ein Verweis sein.** Wäre
     * `httpdocs/bilder` ein Symlink nach `../.ssh`, schriebe ein Eintrag
     * `bilder/authorized_keys` dorthin — innerhalb des Abonnements, also kein
     * Ausbruch, aber auch nicht das, was jemand gemeint hat, der ein Archiv in
     * `httpdocs` entpackt.
     *
     * Zwischen dieser Prüfung und dem Schreiben liegt ein Zeitfenster. Es ist
     * hier folgenlos: Beide Seiten liegen im Chroot, und wer es ausnutzt,
     * verlegt sich seine eigenen Dateien.
     *
     * @param  resource|null  $stream
     */
    private static function place($stream, string $target, string $relative, bool $directory): bool
    {
        $parts = explode('/', $relative);
        $path = rtrim($target, '/');

        foreach ($parts as $index => $part) {
            $path .= '/'.$part;
            $last = $index === count($parts) - 1;

            if ($last && ! $directory) {
                break;
            }

            if (is_link($path)) {
                return false;
            }

            if (! is_dir($path) && ! @mkdir($path, 0o750)) {
                return false;
            }
        }

        if ($directory) {
            return true;
        }

        if (is_link($path) || $stream === null) {
            return false;
        }

        $target = @fopen($path, 'wb');

        if ($target === false) {
            return false;
        }

        $bytes = @stream_copy_to_stream($stream, $target);
        @fclose($target);
        @fclose($stream);

        // Bei erschöpfter Quota bricht der Strom ab und meldet eine Zahl —
        // nicht `false`. Ein halb entpackter Eintrag ist schlimmer als keiner.
        if ($bytes === false) {
            @unlink($path);

            return false;
        }

        return true;
    }

    private static function isZip(string $archive): bool
    {
        $handle = @fopen($archive, 'rb');

        if ($handle === false) {
            throw new AgentException(AgentException::NOT_FOUND, 'Das Archiv gibt es nicht.');
        }

        $magic = (string) @fread($handle, 4);
        @fclose($handle);

        // **Am Inhalt und nicht an der Endung.** Eine Datei `x.zip`, die ein
        // Tar ist, wäre sonst ein Fehler ohne erkennbaren Grund.
        return str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06");
    }
}
