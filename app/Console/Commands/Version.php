<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Panel\Release;
use Illuminate\Console\Command;

/**
 * Welche Fassung des Panels läuft hier?
 *
 * **Der Anlass ist ein Befehl, der eine Antwort gab und die Frage nicht
 * beantwortete.** `srvpanel --version` reicht wie alles an `artisan` durch, und
 * `artisan --version` nennt die Fassung von **Laravel**. Auf `cloudsrv24` stand
 * am 10. August 2026 „Laravel Framework 13.23.0" — richtig, und für die Frage
 * „ist rc.2 installiert?" wertlos.
 *
 * Der Betreiber musste sich mit `dpkg-query` und `readlink` behelfen. Beides
 * beantwortet die Frage; beides muss man wissen.
 *
 * **Ausgegeben wird die Fassung allein, ohne Zierrat.** Der häufigste Gebrauch
 * ist eine Zeile in einem Fehlerbericht oder ein Vergleich in einem Skript —
 * beides bricht an einem Satz drumherum. Wer mehr will, nimmt `--details`.
 *
 * **Und die Abkürzung dafür heisst nicht `-v`.** Der erste Entwurf schrieb
 * `{--v|…}`, und das bricht beim *Bauen* des Kommandos: Symfony belegt `-v`
 * selbst für die Redseligkeit, und ein zweiter Anspruch darauf ist eine
 * `LogicException` — nicht in diesem Kommando, sondern in jedem Aufruf von
 * `artisan`, weil die Liste beim Start entsteht. Derselbe Fehlertyp wie ein
 * Name, der der Basisklasse gehört: Er bricht vor dem ersten Befehl.
 */
final class Version extends Command
{
    protected $signature = 'srvpanel:version {--details : Dazu Verzeichnis und Commit}';

    /*
     * „Version" und nicht „Fassung" — `docs/19 §3` hat das Wort verbraucht, und
     * `WordChoiceTest` hat genau diese Zeile gemeldet. Bemerkenswert daran ist,
     * *welche* Zeile: Kommentare und Changelog dürfen „Fassung" sagen, weil sie
     * niemand in der Oberfläche liest. Die Beschreibung eines Kommandos steht in
     * `artisan list`, also vor den Augen des Betreibers.
     */
    protected $description = 'Zeigt die laufende Version des Panels';

    public function handle(): int
    {
        $this->line(Release::version());

        if ($this->option('details') !== true) {
            return self::SUCCESS;
        }

        /*
         * **Der Pfad steht dabei, weil er die Quelle der Auskunft ist.** Wer
         * die Fassung anzweifelt, soll sehen, woher sie kommt — und nicht
         * raten müssen, ob hier eine Datei, eine Umgebungsvariable oder ein
         * Verzeichnisname gelesen wurde.
         */
        $this->line('Verzeichnis: '.base_path());

        $commit = (string) config('srvpanel.source.commit');

        // Leer ist eine Auskunft: Der Commit steht nur in einem Paket, das ein
        // Freigabelauf gebaut hat. Eine erfundene Kennung wäre schlimmer als
        // keine.
        $this->line('Commit:      '.($commit === '' ? '(unbekannt)' : $commit));

        return self::SUCCESS;
    }
}
