<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as TextResponse;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Connection;
use SrvPanel\Agent\Logs;
use SrvPanel\Agent\Ops\SystemLogsTail;

/**
 * Die Protokolle des Servers an einer Stelle.
 *
 * **Warum es diese Seite gibt.** Bis hierher waren die Protokolle des Servers
 * nur über SSH zu lesen. Das Panel schreibt selbst welche — `laravel.log`, das
 * Protokoll des Agenten, das des Updates —, und ab A1 erzeugt es einen
 * Paketlauf, dessen Ausgabe jemand lesen können muss. Ein Protokoll, das man
 * nur mit einer zweiten Anmeldung sieht, wird nicht gelesen.
 *
 * **`/var/log/apt/history.log` ist die eigentliche Begründung.** Dort steht,
 * **wer** ein Paket eingespielt hat — auf einem echten Server mit
 * `Requested-By`, also auch dann, wenn es an der Kommandozeile geschah und
 * damit an diesem Panel vorbei. Das Panel-Protokoll kann das nicht wissen.
 *
 * ## Warum die ganze Seite dem Betreiber gehört
 *
 * `docs/20 §6.1` nennt drei Merkmale, und eines genügt. Hier trifft das
 * dritte: **Ein Stacktrace zeigt, was ihn ausgelöst hat.** Eine Ausnahme beim
 * Verbindungsaufbau trägt die Zugangsdaten der Datenbank im Text, eine beim
 * Zertifikatsbezug den Token des DNS-Anbieters. `laravel.log` ist damit kein
 * Protokoll wie ein Zugriffslog, sondern eine Datei, in der jedes Geheimnis
 * des Panels stehen **kann**.
 *
 * > **Geteilt wird nach Wirkung, nicht nach Bildschirm.**
 *
 * Deshalb `can:operate-server` für die ganze Seite und nicht je Quelle. Eine
 * Teilung je Quelle ist möglich und später zu haben — sie wäre heute eine
 * Entscheidung über sieben Dateien, von denen fünf auf diesem Server noch gar
 * nicht existieren.
 *
 * ## Was hier nicht steht
 *
 * Die Protokolle der Kundendomains. Die liegen an der Domain, gehören zu einem
 * Abonnement und fallen damit unter die Mandantenklammer
 * ({@see DomainController::logs()}). Eine Seite, die beides führte, wäre der
 * Ort, an dem ein Kunde das Protokoll eines anderen bekommt.
 */
final class LogsController extends Controller
{
    /** Wie viele Zeilen die Seite voreingestellt zeigt. */
    private const DEFAULT_LINES = 200;

    public function show(Request $request, Client $agent): Response
    {
        return Inertia::render('Logs/Index', $this->read($request, $agent));
    }

    /**
     * Das Angezeigte als Textdatei.
     *
     * **Und nicht die ganze Datei, und die Beschriftung sagt das auch.** Eine
     * Antwort des Agenten passt in {@see Connection::CONTENT_MAX}
     * — knapp ein Megabyte —, und ein Zugriffsprotokoll ist ein Vielfaches
     * davon. Ein Knopf „Herunterladen", der stillschweigend die letzten
     * fünfhundert Zeilen gibt, verspräche etwas, das dieser Weg nicht halten
     * kann; wer die ganze Datei braucht, holt sie über SSH.
     *
     * > **Ein Knopf, der mehr verspricht, als der Weg dahinter trägt, ist eine
     * > Zusage und keine Bequemlichkeit.**
     */
    public function download(Request $request, Client $agent): TextResponse
    {
        $data = $this->read($request, $agent);
        $lines = is_array($data['result']['lines'] ?? null) ? $data['result']['lines'] : [];

        return response(
            implode("\n", $lines)."\n",
            200,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$this->filename($data['source']).'"',
            ],
        );
    }

    /**
     * Quellenliste und die Zeilen der gewählten Quelle.
     *
     * **Eine Stelle für beide Wege.** Die Seite und das Herunterladen zeigen
     * dasselbe; entstünde es zweimal, wäre die heruntergeladene Datei
     * irgendwann etwas anderes als das, was danebenstand.
     *
     * @return array<string, mixed>
     */
    private function read(Request $request, Client $agent): array
    {
        /*
         * **Die Quelle wird gegen die Liste des Agenten geprüft und nicht
         * gegen ein Muster.** Die Liste steht in {@see Logs} — einmal, und
         * beide Seiten lesen dieselbe. Eine eigene Aufzählung hier wäre die
         * zweite Fassung, und die zweite ist die, die beim nächsten Eintrag
         * vergessen wird.
         *
         * Und sie wird **hier** geprüft, obwohl der Agent es noch einmal tut:
         * Ein unbekannter Schlüssel soll eine Meldung am Formular ergeben und
         * keinen Abbruch aus dem Agenten, den niemand deuten kann.
         */
        $keys = Logs::keys();
        $source = $request->string('source')->toString();

        if (! in_array($source, $keys, true)) {
            $source = $keys[0];
        }

        /*
         * **`lines` reist in der Adresse und ist damit eine Zeichenkette.**
         * Das ist der Fund aus `docs/66`: `router.get` legt seine Werte in die
         * URL, und dort ist alles Text. Für eine Zahl geht das gut — `integer()`
         * wandelt —, für einen Wahrheitswert ginge es nicht, und deshalb hat
         * diese Seite keinen.
         */
        $lines = min(max($request->integer('lines', self::DEFAULT_LINES), 10), SystemLogsTail::MAX_LINES);
        $filter = trim($request->string('filter')->toString());

        $sources = [];
        $result = ['lines' => [], 'exists' => false, 'note' => null, 'matched' => 0, 'window' => 0, 'truncated' => false];
        $error = null;

        try {
            $catalog = $agent->call('system.logs.list', []);
            $sources = is_array($catalog['sources'] ?? null) ? $catalog['sources'] : [];

            $answer = $agent->call('system.logs.tail', [
                'source' => $source,
                'lines' => $lines,
                'filter' => $filter === '' ? null : $filter,
            ]);

            $result = $answer;
        } catch (AgentException $exception) {
            /*
             * **Der Agent kann weg sein, und dann ist die Seite trotzdem eine
             * Seite.** Sie zeigt dann, was sie weiss — die Liste aus der
             * Positivliste — und die Meldung darüber. Ein 500er für einen
             * nicht laufenden Dienst wäre die schlechtere Auskunft.
             */
            $error = $exception->getMessage();
        }

        return [
            'sources' => $sources === [] ? $this->fallback() : $this->withDisplayTime($sources),
            'source' => $source,
            'lines' => $lines,
            'filter' => $filter,
            'result' => $result,
            'error' => $error,
        ];
    }

    /**
     * Die Zeitpunkte in die Anzeigezone rechnen.
     *
     * Der Agent schickt Unixzeit; **die Anzeigezone kennt nur das Panel**
     * ({@see Clock}, `docs/40`). Ein im Agenten gebauter Text wäre die zweite
     * Fassung davon und stünde in UTC, während die Seite daneben in der
     * eingestellten Zone rechnet.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function withDisplayTime(array $sources): array
    {
        foreach ($sources as $index => $source) {
            $at = is_int($source['modified'] ?? null) ? $source['modified'] : null;

            $sources[$index]['modified_display'] = $at === null
                ? null
                : Clock::display(CarbonImmutable::createFromTimestampUTC($at));
        }

        return $sources;
    }

    /**
     * Was die Seite zeigt, wenn der Agent nicht antwortet.
     *
     * **Die Positivliste ohne den Bestand** — die Quellen gibt es, ihre Grösse
     * kennt niemand. `exists` bleibt deshalb `null` und nicht `false`:
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts
     * > zu tun".**
     *
     * @return list<array<string, mixed>>
     */
    private function fallback(): array
    {
        $sources = [];

        foreach (Logs::sources() as $key => $source) {
            $sources[] = [
                'key' => $key,
                'kind' => $source['kind'],
                'label' => $source['label'],
                'origin' => $source['path'] ?? $source['unit'],
                'exists' => null,
                'size' => null,
                'modified' => null,
                'modified_display' => null,
            ];
        }

        return $sources;
    }

    /** Ein Dateiname, der die Quelle nennt und keinen Pfad enthält. */
    private function filename(string $source): string
    {
        return 'srvpanel-'.$source.'-'.CarbonImmutable::now('UTC')->format('Ymd-His').'.log';
    }
}
