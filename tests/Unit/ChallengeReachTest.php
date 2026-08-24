<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\HttpChallenge;
use Tests\Support\ReadsMethodSource;
use Tests\Support\ReadsPackagedDirectories;

/**
 * Der Webserver kommt bis zur Prüfdatei — oder es gibt kein Zertifikat.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * 24. August 2026, `cloudsrv24`. Drei frisch angelegte Domains bekamen kein
 * Zertifikat. Am Vorgang stand:
 *
 *     Invalid response from http://hier.cloudlab24.de/.well-known/
 *     acme-challenge/rDfTSap…: 403
 *
 * Die Prüfdatei lag, sie stand auf `0644`, ihre Verzeichnisse auf `0755`, und
 * der `location`-Block zeigte auf genau diesen Ort. Alles daran war richtig.
 * Falsch war der **Weg dorthin**: `/var/lib/srvpanel` liefert das Paket als
 * `0750 srvpanel:srvpanel` aus, der nginx-Worker läuft als `www-data`, und
 * `www-data` gehört dieser Gruppe nicht an. Er kam nicht hindurch, und mehr als
 * die Zahl 403 hat davon niemand gesehen.
 *
 * > **Eine Datei, die für alle lesbar ist, ist damit nicht erreichbar — der
 * > Weg zu ihr entscheidet.**
 *
 * ## Warum kein Test das gefunden hat
 *
 * Weil keiner über die Grenze zweier Dateien hinweggesehen hat. Der Ablageort
 * steht im Agenten, die Rechte seiner Elternverzeichnisse in der Paketierung —
 * jede Seite für sich war in Ordnung. Dasselbe Muster, das dieses Projekt
 * sechsmal getroffen hat: *eine Zeichenkette, die auf etwas verweist, ohne dass
 * etwas den Bezug prüft.*
 *
 * Und es ist nicht der erste Fall dieser Art. `CronApply::SPOOL_DIR` nennt seit
 * P6 denselben Grund für die Aufzeichnungen der Cronjobs — dort hat jemand die
 * Frage gestellt, hier nicht.
 *
 * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
 * > wieder da, wenn die Vermeidung nicht die Regel wurde.**
 *
 * ## Was dieser Wächter hält
 *
 * Kein Verzeichnis, das die Paketierung anlegt, nimmt „anderen" das `x` auf dem
 * Weg zur Prüfdatei — und die Prüfdatei selbst entsteht lesbar. Beides ohne
 * einen laufenden Server: Verglichen werden die **Absichten**, und die
 * widersprechen einander schon vor der Installation.
 */
final class ChallengeReachTest extends TestCase
{
    use ReadsMethodSource;
    use ReadsPackagedDirectories;

    /**
     * Wie viele Verzeichnisse die Paketierung mindestens hergeben muss.
     *
     * **Die Untergrenze ist der Prüfkörper dieses Wächters.** Läuft der
     * Ausdruck ins Leere — weil `type: dir` anders heisst oder die Schreibweise
     * von `install -d` sich ändert —, findet die Wanderung unten kein einziges
     * Verzeichnis und meldet Grün. Das ist derselbe Fall wie überall hier:
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     *
     * Sie liegt bewusst unter dem heutigen Bestand von elf: Ein Wächter, der
     * beim Aufräumen zubeisst, wird beim Aufräumen abgeschaltet. Der Befund
     * kommt von der Wanderung, nicht von dieser Zahl.
     */
    private const MIN_DIRECTORIES = 8;

    /**
     * Auf dem Weg zur Prüfdatei nimmt kein Verzeichnis „anderen" das `x`.
     *
     * Gefragt wird nach dem `x` und nicht nach dem `r`: Betreten genügt, um
     * eine Datei mit bekanntem Namen zu öffnen, und auflisten soll dort
     * niemand.
     */
    public function test_no_packaged_directory_blocks_the_way_to_the_probe_file(): void
    {
        $verzeichnisse = $this->packagedDirectories();

        $this->assertGreaterThanOrEqual(self::MIN_DIRECTORIES, count($verzeichnisse),
            'Die Auslese der Paketierung findet fast nichts. Dann sagt dieser Wächter über den '.
            'Weg zur Prüfdatei nichts — er hat ihn gar nicht angesehen.');

        $gemessen = [];

        foreach ($this->ancestors(HttpChallenge::DIRECTORY) as $pfad) {
            if (! isset($verzeichnisse[$pfad])) {
                continue;
            }

            $modus = (int) octdec($verzeichnisse[$pfad]['mode']);
            $gemessen[] = $pfad;

            $this->assertSame(1, $modus & 0o001, sprintf(
                '%s wird als %s %s ausgeliefert und hat für „andere" kein x. Der nginx-Worker '.
                'läuft als www-data und kommt dort nicht hindurch; die Prüfung von ACME bekommt '.
                'dann 403, egal wie die Prüfdatei selbst steht. Der Ablageort gehört unter ein '.
                'Verzeichnis, das ohnehin offen ist — nicht das Verzeichnis geöffnet.',
                $pfad,
                $verzeichnisse[$pfad]['mode'],
                $verzeichnisse[$pfad]['owner'],
            ));
        }

        $this->assertNotSame([], $gemessen,
            'Kein einziges Verzeichnis auf dem Weg zu '.HttpChallenge::DIRECTORY.' steht in der '.
            'Paketierung. Damit ist über die Rechte dieses Weges nichts bekannt, und dieser '.
            'Wächter hat nichts gemessen.');
    }

    /**
     * Und der Ablageort selbst steht in **beiden** Quellen der Paketierung.
     *
     * **Sonst entstünde er beim ersten Bestellen** — mit dem Modus, den der
     * Agent gerade mitgibt, und unter einer `umask`, die niemand aufgeschrieben
     * hat. Ein Verzeichnis, dessen Rechte vom Zufall des ersten Aufrufs
     * abhängen, ist keine Zusage.
     *
     * **Je Quelle und nicht über die Vereinigung**, und das ist beim
     * Gegenprüfen aufgefallen: Ein Eingriff, der den Eintrag aus `nfpm.yaml`
     * nimmt, liess diesen Wächter grün — `postinstall.sh` zahlte für ihn mit.
     * Die beiden vertreten einander aber nicht: `nfpm.yaml` legt das
     * Verzeichnis beim Entpacken an, `postinstall.sh` richtet bestehende
     * Installationen nach. Fehlt das eine, hat ein frisch installierter Server
     * es nicht; fehlt das andere, ein aktualisierter.
     *
     * > **Eine Untergrenze über die Vereinigung hält auch dann, wenn eine der
     * > Quellen blind ist — die andere zahlt für sie mit.**
     */
    public function test_the_probe_directory_is_shipped_by_both_sources(): void
    {
        $quellen = [];

        foreach ($this->packagedDirectories(true) as $eintrag) {
            if ($eintrag['pfad'] === HttpChallenge::DIRECTORY) {
                $quellen[] = $eintrag['quelle'];
            }
        }

        foreach (['nfpm.yaml', 'postinstall.sh'] as $quelle) {
            $this->assertContains($quelle, $quellen, sprintf(
                '%s steht nicht in %s. Dann entsteht der Ablageort dort erst bei der ersten '.
                'Bestellung, mit den Rechten, die dabei zufällig gelten.',
                HttpChallenge::DIRECTORY,
                $quelle,
            ));
        }
    }

    /**
     * Ein Pfad mit einer Variablen wird nicht stillschweigend übergangen.
     *
     * **Weil „nicht aufgelöst" wie „nicht betroffen" aussieht.** In
     * `postinstall.sh` steht eine Schleife über `"…/${part}"`; dieser Wächter
     * kann sie nicht auflösen. Solange ihr fester Teil neben dem Weg zur
     * Prüfdatei liegt, ist das ohne Folgen — zöge der Ablageort dorthin, wäre
     * die Wanderung oben blind und trotzdem grün.
     *
     * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
     * > gemessen — er hat an dieser Stelle gar nicht gemessen.**
     */
    public function test_no_unresolved_path_lies_on_the_way_to_the_probe_file(): void
    {
        foreach (array_keys($this->packagedDirectories()) as $pfad) {
            $pfad = trim((string) $pfad, '"\'');

            if (! str_contains($pfad, '$')) {
                continue;
            }

            $fest = rtrim(substr($pfad, 0, (int) strpos($pfad, '$')), '/');

            $this->assertFalse($fest !== '' && str_starts_with(HttpChallenge::DIRECTORY.'/', $fest.'/'),
                sprintf('%s liegt auf dem Weg zu %s und enthält eine Variable, die dieser Wächter '.
                    'nicht auflösen kann. Solange sie dort steht, misst er den Weg nicht.',
                    $pfad, HttpChallenge::DIRECTORY));
        }
    }

    /**
     * Und die Prüfdatei entsteht so, dass ein Fremder sie lesen kann.
     *
     * Die zweite Hälfte derselben Regel: Der Weg nützt nichts, wenn am Ende
     * eine Datei liegt, die nur `root` lesen darf. Beide Zahlen stehen in
     * {@see HttpChallenge::present()} — gelesen wird dort und nicht in einem
     * Kommentar daneben.
     */
    public function test_the_probe_file_and_its_directory_are_created_readable(): void
    {
        $quelle = $this->methodSource(HttpChallenge::class, 'present');

        $this->assertIsString($quelle, 'HttpChallenge::present() ist nicht lesbar — ohne die '.
            'Methode sagt dieser Wächter über die Rechte der Prüfdatei nichts.');

        $this->assertSame(1, preg_match('/mkdir\(\$directory,\s*(0o[0-7]{3})/', $quelle, $d),
            'In present() steht kein `mkdir($directory, 0o…)`. Der Ausdruck läuft ins Leere, und '.
            'über den Modus des Verzeichnisses ist damit nichts gesagt.');

        $this->assertSame(1, preg_match('/chmod\(\$file,\s*(0o[0-7]{3})/', $quelle, $f),
            'In present() steht kein `chmod($file, 0o…)`. Der Ausdruck läuft ins Leere, und über '.
            'den Modus der Prüfdatei ist damit nichts gesagt.');

        $this->assertSame(1, ((int) octdec(substr($d[1], 2))) & 0o001,
            'Das Verzeichnis der Prüfdatei entsteht ohne x für „andere". nginx läuft als '.
            'www-data und kommt nicht hinein — die Prüfung von ACME endet mit 403.');

        $this->assertSame(4, ((int) octdec(substr($f[1], 2))) & 0o004,
            'Die Prüfdatei entsteht ohne r für „andere". Genau sie soll jeder von aussen abrufen '.
            'können; ein Geheimnis steht nicht darin.');
    }

    /**
     * Jeder Schritt des Pfades, von der Wurzel bis zum Ziel.
     *
     * @return list<string>
     */
    private function ancestors(string $pfad): array
    {
        $teile = array_values(array_filter(explode('/', $pfad), static fn (string $t): bool => $t !== ''));
        $bisher = '';
        $wege = [];

        foreach ($teile as $teil) {
            $bisher .= '/'.$teil;
            $wege[] = $bisher;
        }

        return $wege;
    }
}
