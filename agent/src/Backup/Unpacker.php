<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Backup;

use SrvPanel\Agent\AgentException;
use ZipArchive;

/**
 * Die andere Hälfte der Zusage: Was das Verzeichnis sagt, kommt wieder heraus.
 *
 * ## Warum das Archiv allein nicht genügt
 *
 * `ZipArchive::extractTo()` schreibt Dateien und legt Verzeichnisse an — und
 * **benutzt den Modus im Archiv dabei gar nicht** (`docs/116` M1b, Nachtrag vom
 * 16. September 2026). Jeder Eintrag bekommt `0777` beziehungsweise `0666`
 * gegen die umask; unter der `0022`, mit der der Agent läuft, sind das `0755`
 * und `0644`. Ein privater Schlüssel mit `0600` käme also **für jeden lesbar**
 * zurück, und ein `httpdocs` verlöre sein setgid-Bit.
 *
 * Das ist der teuerste Fehler dieser Stufe, weil er wie eine gelungene
 * Wiederherstellung aussieht.
 *
 * Deshalb läuft hier **nach** dem Entpacken das Verzeichnis durch und setzt
 * jeden Modus. Und deshalb ist die Reihenfolge festgelegt: erst die Dateien,
 * dann die Verweise, **zuletzt** die Rechte.
 *
 * > **Ein Verzeichnis, dessen Rechte man vor seinem Inhalt setzt, ist ein
 * > Verzeichnis, in das man danach nicht mehr schreiben kann.** `0500` an
 * > `httpdocs` und die nächste Datei fliegt.
 *
 * ## Verweise legt der Unpacker an und nicht das Archiv
 *
 * Ein Zip trägt keine (M1b). Sie stehen mit ihrem Ziel im Verzeichnis, und hier
 * entstehen sie über `symlink()`.
 *
 * **Das Ziel wird nicht geprüft, und das ist Absicht.** Ein Verweis nach
 * `../../etc` ist im Baum eines Abonnements erlaubt — er führt, wenn der Kunde
 * ihn über SFTP benutzt, in sein eigenes Chroot und sonst nirgendwohin. Was
 * geprüft wird, ist der **Ort des Verweises** (`Manifest::relative()`), nicht
 * wohin er zeigt. Ein Unpacker, der Ziele prüfte, gäbe einem Kunden ein anderes
 * Verzeichnis zurück, als er gesichert hat.
 *
 * > **Was ein Kunde in seinem eigenen Baum anlegen darf, darf eine
 * > Wiederherstellung ihm zurückgeben.**
 */
final class Unpacker
{
    /**
     * Ein Archiv in eine Wurzel auspacken und die Rechte aus dem Verzeichnis setzen.
     *
     * Der Eigentümer wird hier **nicht** gesetzt — er kommt bei Form A aus dem
     * neuen Systembenutzer und ist Sache des Aufrufers, der ihn kennt. Diese
     * Klasse weiss nichts über Abonnements.
     *
     * @param  list<array{path: string, kind: string, mode: string, target?: string}>  $entries
     * @return array{files: int, links: int, directories: int}
     */
    public static function unpack(string $archive, string $root, array $entries): array
    {
        Store::requireZip($archive);

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw AgentException::badRequest('Die Sicherung liess sich nicht öffnen.', ['path' => $archive]);
        }

        try {
            // `extractTo` schreibt alles ausser den Verweisen — die trägt ein
            // Zip nicht.
            //
            // **Was der Sicherung selbst gehört, bleibt draussen, und gefragt
            // wird mit derselben Methode wie beim Packen.** Das Verzeichnis
            // und die Datenbankdumps sind kein Teil des Kundenbaums; extrahiert
            // landeten sie als `.srvpanel-databases/…` mitten darin, und ein
            // Kunde fände nach einer Wiederherstellung Dateien, die er nie
            // hatte. Die Dumps holt sich die Wiederherstellung einzeln.
            //
            // Ein zweiter Ausdruck an dieser Stelle wäre die zweite Fassung
            // derselben Regel, und die zweite ist die, die veraltet —
            // `Pg\Hba` und `ManagedBlock` haben das in P5b vorgeführt
            // (`docs/81 §2.3o` M22).
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name === false || Manifest::reserves($name)) {
                    continue;
                }

                // Jeder Name wird noch einmal geprüft, bevor er zu einem Pfad
                // wird. Ein Archiv kann von aussen kommen, und ein `..` darin
                // schriebe beim Entpacken irgendwohin.
                Manifest::relative($name);

                $names[] = $name;
            }

            if ($names !== [] && ! $zip->extractTo($root, $names)) {
                throw AgentException::execFailed('Die Sicherung liess sich nicht auspacken.', ['path' => $root]);
            }
        } finally {
            $zip->close();
        }

        return self::applyManifest($root, $entries);
    }

    /**
     * Die Verweise anlegen und danach jeden Modus setzen.
     *
     * **Die Verzeichnisse zuletzt und von innen nach aussen.** Sortiert wird
     * nach Tiefe, absteigend — das tiefste Verzeichnis bekommt seinen Modus
     * zuerst.
     *
     * **Gemessen am 16. September 2026, und die erste Begründung hier war
     * falsch.** Sie nannte das Schreibrecht; verloren geht aber das
     * *Durchqueren*: Nimmt ein `chmod` dem Elternteil das `x`-Bit, scheitert
     * das `chmod` am Kind mit `No such file or directory`. Und es gilt nur für
     * einen unprivilegierten Aufrufer — **als root gelingt es** (beide
     * Richtungen gemessen: `uid 65534` gescheitert, root `2750` gesetzt).
     *
     * Der Agent läuft als root, die Reihenfolge ist hier also wirkungslos. Sie
     * steht trotzdem, weil sie nichts kostet und der Tag kommt, an dem jemand
     * diese Klasse aus einem Kontext ruft, der keine Rechte hat.
     *
     * > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist
     * > auch dann falsch, wenn der Handgriff daneben richtig ist.**
     *
     * `BackupPromiseTest` kann das nicht halten — er läuft als root, und dort
     * ist der Fehler nicht herstellbar. Der Eingriff dazu im Bruchskript würde
     * grün bleiben und damit das Gegenteil belegen.
     *
     * @param  list<array{path: string, kind: string, mode: string, target?: string}>  $entries
     * @return array{files: int, links: int, directories: int}
     */
    private static function applyManifest(string $root, array $entries): array
    {
        $files = 0;
        $links = 0;
        $directories = [];

        foreach ($entries as $entry) {
            // Dieselbe Frage wie beim Auspacken und beim Packen, und zwar mit
            // derselben Methode: Was der Sicherung gehört, ist nie ausgepackt
            // worden — ohne diese Zeile meldete der Lauf die Dumps als
            // fehlende Dateien des Kunden.
            if (Manifest::reserves($entry['path'])) {
                continue;
            }

            $path = $root.'/'.Manifest::relative($entry['path']);

            if ($entry['kind'] === Manifest::KIND_LINK) {
                $target = $entry['target'] ?? '';

                // Ein Verweis, den das Entpacken schon als Datei angelegt hat,
                // gäbe es zweimal. `extractTo` kann das nicht, weil ein Zip
                // keine Verweise trägt — aber ein Archiv von aussen könnte
                // einen Eintrag desselben Namens enthalten.
                if (file_exists($path) || is_link($path)) {
                    @unlink($path);
                }

                if (! @symlink($target, $path)) {
                    throw AgentException::execFailed('Ein Verweis liess sich nicht anlegen.', [
                        'path' => $entry['path'],
                    ]);
                }

                $links++;

                // Kein `chmod`: Ein Symlink trägt unter Linux immer `0777`, und
                // `chmod` auf ihn folgt ihm — es setzte den Modus seines Ziels.
                continue;
            }

            if ($entry['kind'] === Manifest::KIND_DIRECTORY) {
                if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
                    throw AgentException::execFailed('Ein Verzeichnis liess sich nicht anlegen.', [
                        'path' => $entry['path'],
                    ]);
                }

                // Gesammelt und nicht gesetzt — die Reihenfolge entscheidet.
                $directories[$path] = Manifest::modeFrom($entry['mode']);

                continue;
            }

            if (! is_file($path)) {
                throw AgentException::execFailed('Eine Datei fehlt in der Sicherung.', [
                    'path' => $entry['path'],
                ]);
            }

            chmod($path, Manifest::modeFrom($entry['mode']));
            $files++;
        }

        // Tiefste zuerst: `usort` über die Zahl der Schrägstriche, absteigend.
        $paths = array_keys($directories);
        usort($paths, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($paths as $path) {
            chmod($path, $directories[$path]);
        }

        return ['files' => $files, 'links' => $links, 'directories' => count($directories)];
    }
}
