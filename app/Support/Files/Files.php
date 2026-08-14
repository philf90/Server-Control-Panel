<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\Subscription;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Sandbox;

/**
 * Der Dateimanager — die Seite des Panels.
 *
 * Der Plan ist `docs/51`. Diese Klasse ist schmal, und das ist ihr Zweck: Sie
 * setzt die Nutzlast zusammen, ruft den Agenten und gibt zurück, was er sagt.
 * **Die Grenze steht im Agenten** ({@see Sandbox}), nicht hier
 * — und das ist keine Arbeitsteilung, sondern die Architektur:
 *
 * > **Eine Grenze, die im Prozess des Kunden durchgesetzt wird, ist keine.**
 *
 * Hier steht deshalb **keine Pfadprüfung**. Es wäre die naheliegendste Zeile
 * der Welt, ein `str_contains($path, '..')` hinzuschreiben, und sie wäre
 * schädlich: Sie sähe aus wie die Schranke, wäre keine (`docs/50 §3` hat
 * gemessen, was Pfadprüfungen unter Nebenläufigkeit taugen — 31 %
 * Durchlässigkeit), und der nächste Umbau verliesse sich auf sie.
 *
 * ## Drei Regeln, dieselben wie beim Datenbankmanagement
 *
 * **1. Kein Aufruf geht durch die Warteschlange.** Ein eingereihter Vorgang
 * legt seine Argumente in `operations.payload` ab, und dort stünde der Inhalt
 * einer Kundendatei — eine `wp-config.php` mit ihren Zugangsdaten zum Beispiel.
 * Dieselbe Regel wie in `docs/46 §12`:
 *
 * > **Was nicht in der Warteschlange stehen darf, ist nicht nur ein Geheimnis —
 * > es ist alles, was dem Kunden gehört.**
 *
 * Was länger dauert als eine Antwort — Entpacken, Suchen — bekommt in Schritt 7
 * einen eigenen Weg, der den Inhalt **nicht** mitreichen muss.
 *
 * **2. Das Abonnement kommt als Modell und nicht als Name.** Damit ist es durch
 * die Mandantenklammer gekommen, bevor diese Klasse es sieht. Der Agent baut
 * die Wurzel danach selbst aus dem Namen ({@see
 * \SrvPanel\Agent\Files\Workspace::fromArgs()}) — zwei Wände, und die zweite
 * gehört uns nicht.
 *
 * **3. Der Systembenutzer kommt aus dem Abonnement und nicht aus der Anfrage.**
 * Er entscheidet, als wer die Sandbox läuft; käme er von aussen, wäre die
 * gesamte Rechteabgabe eine Angabe des Aufrufers.
 */
final class Files
{
    public function __construct(private readonly Client $agent) {}

    /**
     * Ein Verzeichnis auflisten.
     *
     * @return array<string, mixed>
     */
    public function list(Subscription $subscription, string $path): array
    {
        return $this->call($subscription, 'files.list', ['path' => $path]);
    }

    /**
     * Eine Datei lesen.
     *
     * @return array<string, mixed>
     */
    public function read(Subscription $subscription, string $path): array
    {
        return $this->call($subscription, 'files.read', ['path' => $path]);
    }

    /**
     * Eine Datei schreiben.
     *
     * @return array<string, mixed>
     */
    public function write(Subscription $subscription, string $path, string $content): array
    {
        return $this->call($subscription, 'files.write', ['path' => $path, 'content' => $content]);
    }

    /**
     * Ein Verzeichnis anlegen.
     *
     * @return array<string, mixed>
     */
    public function makeDirectory(Subscription $subscription, string $path): array
    {
        return $this->call($subscription, 'files.mkdir', ['path' => $path]);
    }

    /**
     * Entfernen — ein Verzeichnis nur mit ausdrücklicher Zustimmung.
     *
     * @return array<string, mixed>
     */
    public function remove(Subscription $subscription, string $path, bool $recursive = false): array
    {
        return $this->call($subscription, 'files.remove', ['path' => $path, 'recursive' => $recursive]);
    }

    /**
     * Umbenennen und Verschieben — dieselbe Bewegung.
     *
     * @return array<string, mixed>
     */
    public function move(Subscription $subscription, string $from, string $to): array
    {
        return $this->call($subscription, 'files.move', ['from' => $from, 'to' => $to]);
    }

    /**
     * Kopieren.
     *
     * @return array<string, mixed>
     */
    public function copy(Subscription $subscription, string $from, string $to): array
    {
        return $this->call($subscription, 'files.copy', ['from' => $from, 'to' => $to]);
    }

    /**
     * Rechte setzen.
     *
     * @return array<string, mixed>
     */
    public function chmod(Subscription $subscription, string $path, int $mode): array
    {
        return $this->call($subscription, 'files.chmod', ['path' => $path, 'mode' => $mode]);
    }

    /**
     * Eine hochgeladene Datei übernehmen.
     *
     * **`$source` ist ein Pfad im Zwischenlager und nicht der Inhalt.** Der
     * Agent öffnet ihn vor seinem `chroot` und schreibt den Strom im Kind
     * weiter; damit wandert auch eine grosse Datei nicht durch den
     * Arbeitsspeicher — und schon gar nicht durch `operations.payload`.
     *
     * @return array<string, mixed>
     */
    public function upload(Subscription $subscription, string $source, string $path): array
    {
        return $this->call($subscription, 'files.upload', ['source' => $source, 'path' => $path]);
    }

    /**
     * Ein Archiv entpacken.
     *
     * **Das Archiv liegt schon im Abonnement** und wird nicht mitgeschickt —
     * deshalb steht in `operations.payload` ein Pfad und kein Inhalt, und
     * deshalb darf dieser Vorgang über die Warteschlange laufen, obwohl die
     * acht anderen es nicht dürfen.
     *
     * @return array<string, mixed>
     */
    public function extract(Subscription $subscription, string $path, string $target): array
    {
        return $this->call($subscription, 'files.extract', ['path' => $path, 'target' => $target]);
    }

    /**
     * Einen Baum zu einem Zip packen.
     *
     * @return array<string, mixed>
     */
    public function compress(Subscription $subscription, string $path, string $target): array
    {
        return $this->call($subscription, 'files.compress', ['path' => $path, 'target' => $target]);
    }

    /**
     * Nach Namen und wahlweise nach Inhalt suchen.
     *
     * @return array<string, mixed>
     */
    public function search(Subscription $subscription, string $path, string $query, bool $content): array
    {
        return $this->call($subscription, 'files.search', [
            'path' => $path,
            'query' => $query,
            'content' => $content,
        ]);
    }

    /**
     * Der eine Aufruf, durch den alle gehen.
     *
     * `subscription` und `user` stehen hier und nicht in den acht Methoden
     * darüber: Acht abgeschriebene Fassungen wären acht Gelegenheiten, in einer
     * davon den Benutzer aus der Anfrage zu nehmen statt aus dem Abonnement.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function call(Subscription $subscription, string $operation, array $args): array
    {
        return $this->agent->call($operation, [
            'subscription' => $subscription->name,
            'user' => $subscription->system_user,
        ] + $args);
    }
}
