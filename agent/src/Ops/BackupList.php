<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Was wirklich unter {@see Store::ROOT} liegt — `backup.list`.
 *
 * ## Warum das eine Operation braucht und kein `glob()` im Panel
 *
 * Das Verzeichnis ist `0710 root:srvpanel`: **durchsuchbar** für die Gruppe,
 * nicht auflistbar. Das Panel kommt an eine Datei heran, deren Namen es kennt,
 * und kann nicht nachsehen, welche es gibt — genau so gebaut, und deshalb
 * beantwortet diese Frage der Agent.
 *
 * `Diagnose\LocalHost::cronFiles()` darf `glob()`, weil `/etc/cron.d` für alle
 * lesbar ist. Hier ist es das nicht.
 *
 * > **Ein Ablageort, den das Panel nicht auflisten darf, beantwortet die Frage
 * > „was liegt hier" nicht von selbst.**
 *
 * ## Wozu
 *
 * `docs/117 §9` Punkt 7: `Diagnose\Checks\Backups` geht von den **Zeilen** aus
 * und findet deshalb nur, was das Panel kennt. Die Gegenrichtung — eine Datei,
 * zu der es keine Zeile gibt — prüft ohne diese Operation niemand. Sie entsteht,
 * wenn ein `backup.remove` scheitert, nachdem die Zeile fort ist, und sie kostet
 * Platz, den niemand zuordnet.
 *
 * > **Ein Wächter, der vom Bestand des Panels ausgeht, sieht nur, was das Panel
 * > kennt — und ein Rest ist gerade das, was es nicht kennt.**
 *
 * ## Sie ändert nichts
 *
 * `mutating() === false`, wie `backup.verify`. Sie liest ein Verzeichnis und
 * gibt Namen zurück; was mit einem Rest geschieht, entscheidet ein Mensch —
 * **gemeldet und nicht gelöscht**, dieselbe Regel wie bei jedem anderen Rest
 * seit A10.
 */
final class BackupList implements Op
{
    public static function name(): string
    {
        return 'backup.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $context->progress(50, 'Ablageort lesen');

        $dateien = [];
        $verzeichnisse = [];

        foreach ($this->directories() as $verzeichnis) {
            $abonnement = basename($verzeichnis);
            $verzeichnisse[] = $abonnement;

            foreach ((array) glob($verzeichnis.'/*.zip') as $pfad) {
                if (! is_string($pfad) || ! is_file($pfad)) {
                    continue;
                }

                /*
                 * **Ohne die Grösse, und das ist entschieden.** Sie wäre die
                 * nützlichere Auskunft — ein Rest kostet Platz — und sie
                 * kostete eine vierte Fassung von `formatBytes`: `SizeUnitTest`
                 * hält fest, dass eine Byte-Zahl eine Byte-Zahl bleibt, bis sie
                 * jemand **anzeigt**, und der Befundtext ist keine Anzeige,
                 * sondern eine abgelegte Zeichenkette.
                 *
                 * > **Ein Feld, das geschrieben und nie gelesen wird, ist von
                 * > aussen nicht von einem zu unterscheiden, das es nicht
                 * > gibt.**
                 *
                 * Wer aufräumt, hat den Pfad — und `ls -l` daneben.
                 */
                $dateien[] = [
                    'subscription' => $abonnement,
                    'storage' => basename($pfad, '.zip'),
                ];
            }
        }

        $context->progress(100, 'fertig');

        /*
         * **Die Verzeichnisse stehen daneben, und nicht nur ihre Dateien.**
         *
         * Befund 10 des P8-Abnahmelaufs: Nach dem Entfernen aller Stände blieb
         * `/var/lib/srvpanel/backups/<abo>/` leer liegen, und die
         * Bestandsdiagnose meldete „Keine Befunde an den Sicherungen". Sie
         * sucht Dateien ohne Zeile — ein leeres Verzeichnis hat keine Datei.
         *
         * > **Eine Abwesenheit ist nur dann ein Befund, wenn die Anwesenheit im
         * > Erfolgsfall belegt ist.** Aus der Dateiliste allein lässt sich „es
         * > liegt hier nichts" nicht von „es gibt hier nichts" trennen.
         *
         * Die Namen liegen ohnehin vor — {@see self::directories()} läuft sie
         * schon ab. Was gefehlt hat, war nicht die Auskunft, sondern dass
         * jemand sie weitergibt.
         *
         * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so
         * > gut wie keine.**
         *
         * **Geurteilt wird hier nicht.** Ob ein leeres Verzeichnis ein Rest
         * ist, hängt an Zeilen und Abonnements, die der Agent nicht kennt; er
         * sagt, was liegt.
         */
        return ['files' => $dateien, 'directories' => $verzeichnisse];
    }

    /**
     * Die Verzeichnisse unterhalb der Wurzel — eines je Abonnement.
     *
     * **Ohne Symlinks.** Ein Verweis hier wäre ein Weg aus dem Ablageort
     * heraus, und diese Operation liest zwar nur — aber was sie zurückgibt,
     * liest die Bestandsdiagnose als „hier liegt eine Sicherung".
     *
     * @return list<string>
     */
    private function directories(): array
    {
        if (! is_dir(Store::ROOT)) {
            return [];
        }

        $gefunden = [];

        foreach ((array) glob(Store::ROOT.'/*', GLOB_ONLYDIR) as $pfad) {
            if (is_string($pfad) && ! is_link($pfad)) {
                $gefunden[] = $pfad;
            }
        }

        sort($gefunden);

        return $gefunden;
    }
}
