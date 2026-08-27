<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Authorization\AdminAbility;
use App\Support\Operations\Operations;
use App\Support\Time\Clock;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\SystemPackagesUpgrade;

/**
 * Der Paketstand dieses Servers und die Quellen, aus denen er kommt.
 *
 * **Warum es diese Seite gibt.** Bis hierher war „muss dieser Server
 * aktualisiert werden?" nur über SSH zu beantworten. Ein Betreiber, der dafür
 * eine zweite Anmeldung braucht, sieht ein Sicherheitsupdate spät.
 *
 * ## Warum Pakete und Quellen auf einer Seite stehen
 *
 * Weil die zweite Liste die erste erklärt. Steht dort „0 Aktualisierungen",
 * gibt es dafür zwei sehr verschiedene Gründe — der Server ist aktuell, oder
 * apt kommt an seine Quellen nicht heran. Die Zahl allein sieht in beiden
 * Fällen gleich aus, und der beruhigende Fall ist der häufigere.
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 *
 * ## Warum die Seite dem Betreiber gehört
 *
 * `docs/20 §6.1`, erstes Merkmal: Sie beschreibt die Maschine und nicht ein
 * Abonnement. Die Fassungen der installierten Pakete sagen einem Leser, welche
 * bekannten Lücken dieser Server hat — das ist keine Auskunft für einen
 * Kunden, und `can:operate-server` ist dieselbe Fähigkeit, unter der schon
 * PHP-Versionen und Datenbankserver stehen.
 *
 * ## Was hier nicht steht
 *
 * Ein Knopf, der aktualisiert. Der kommt in Schritt 6 und braucht `systemd-run`,
 * damit ein Neustart des Panels den Lauf nicht mitnimmt. Diese Seite **liest**
 * ausschliesslich; beide Operationen dahinter sind `mutating() === false`.
 */
final class UpdatesController extends Controller
{
    public function show(Request $request, Client $agent): Response
    {
        $account = $request->user();

        return Inertia::render('Updates/Index', $this->read(
            $agent,
            $account instanceof Account && $account->can(AdminAbility::OPERATE_SERVER),
        ));
    }

    /**
     * Eine eigene Paketquelle ein- oder ausschalten.
     *
     * **Der Pfad reist mit und wird im Agenten geprüft, nicht hier.** Die
     * Liste der eigenen Dateien steht in `SrvPanel\Agent\Sources::owned()` —
     * eine zweite Fassung im Panel wäre die, die beim nächsten Eintrag
     * vergessen wird, und sie stünde auf der Seite mit den Systemrechten
     * **davor**.
     *
     * > **Eine Grenze, die zweimal gezogen ist, gilt an der schwächeren
     * > Stelle.**
     *
     * **Der Wahrheitswert steht im Rumpf und nicht in der Adresse.**
     * `router.get` legt seine Werte in die URL, und dort wird aus `false` das
     * Wort `"false"`; Laravels Regel `boolean` nimmt kein Wort (`docs/66`).
     */
    public function toggle(Request $request, Operations $operations, Audit $audit): RedirectResponse
    {
        $daten = $request->validate([
            'path' => ['required', 'string'],
            'stanza' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
        ]);

        $account = $request->user();

        $operation = $operations->dispatch(
            'system.sources.toggle',
            [
                'path' => $daten['path'],
                'stanza' => (int) $daten['stanza'],
                'enabled' => (bool) $daten['enabled'],
            ],
            account: $account instanceof Account ? $account : null,
            message: ((bool) $daten['enabled'] ? 'Paketquelle einschalten' : 'Paketquelle ausschalten')
                .': '.basename((string) $daten['path']),
        );

        /*
         * **Das Protokoll nennt den Gegenstand und nicht nur die Handlung** —
         * `docs/66`: „Ein Protokoll, das die Art der Handlung nennt und nicht
         * ihren Gegenstand, beantwortet die Frage, die niemand stellt."
         */
        $audit->success('sources.toggled', context: [
            'path' => $daten['path'],
            'stanza' => (int) $daten['stanza'],
            'enabled' => (bool) $daten['enabled'],
            'operation' => (int) $operation->id,
        ]);

        return redirect()->route('operations.show', $operation);
    }

    /**
     * Die Paketlisten auffrischen.
     *
     * **Ein eigener Griff und kein Nebeneffekt des Ansehens.** `apt-get update`
     * nimmt die Sperre und dauert auf einem kalten Server über eine Minute; bei
     * jedem Seitenaufruf wäre das ein Lauf, den niemand bestellt hat.
     *
     * > **Eine Anzeige, die beim Ansehen etwas verändert, ist keine Anzeige.**
     */
    public function refresh(Request $request, Operations $operations, Audit $audit): RedirectResponse
    {
        $account = $request->user();

        $operation = $operations->dispatch(
            'system.packages.refresh',
            [],
            account: $account instanceof Account ? $account : null,
            message: 'Paketlisten auffrischen',
        );

        $audit->success('packages.refreshed', context: ['operation' => (int) $operation->id]);

        return redirect()->route('operations.show', $operation);
    }

    /**
     * Aktualisierungen installieren.
     *
     * ## Warum hier kein Muster über den Paketnamen steht
     *
     * Weil die Prüfung im Agenten sitzt, und zwar gegen die Liste, die er
     * **selbst** gerade gelesen hat (`docs/81 §5`: *„Kein Freitext erreicht
     * apt."*). Ein `regex` an dieser Stelle wäre eine zweite Fassung derselben
     * Grenze, und sie stünde vor der schwächeren:
     *
     * > **Eine Grenze, die zweimal gezogen ist, gilt an der schwächeren
     * > Stelle.**
     *
     * Ein Muster müsste ausserdem jede Schreibweise erraten, die apt als Option
     * deutet — gemessen am 26. August 2026: `--reinstall` als Paketname wird
     * von apt **als Option** geschluckt und ergibt „0 upgraded" mit
     * Rückgabewert 0. Eine Positivliste muss nichts erraten.
     *
     * ## Warum die Namen überhaupt mitreisen
     *
     * Weil der Betreiber sie ausgewählt hat. `all` und `security` kommen ohne
     * aus — bei `security` stellt der Agent die Liste selbst zusammen, denn
     * `apt-get -t <suite>` ist kein Sicherheitsfilter (gemessen: 140 statt 142
     * Pakete, also weniger und nicht andere).
     */
    public function install(Request $request, Operations $operations, Audit $audit): RedirectResponse
    {
        /*
         * **`mode` heisst hier „Umfang" und im Dateimanager „Rechte".**
         *
         * Ein Feldname kann in zwei Formularen zwei Dinge bedeuten; die Liste
         * in `lang/de/validation.php` trägt die häufigere, und der Ausweg für
         * die andere ist der dritte Wert von `validate()`. Ohne ihn läse der
         * Betreiber hier „Das ausgewählte Rechte ist ungültig" — ein Feld, das
         * es auf dieser Seite nicht gibt (`docs/66`, Befund 3).
         */
        $daten = $request->validate([
            'mode' => ['required', 'string', Rule::in(SystemPackagesUpgrade::MODES)],
            'packages' => ['array'],
            'packages.*' => ['string'],
        ], [], ['mode' => 'Umfang']);

        $mode = (string) $daten['mode'];

        /** @var list<string> $pakete */
        $pakete = $mode === 'packages' ? array_values($daten['packages'] ?? []) : [];

        $account = $request->user();

        $operation = $operations->dispatch(
            'system.packages.upgrade',
            ['mode' => $mode, 'packages' => $pakete],
            account: $account instanceof Account ? $account : null,
            message: 'Aktualisierungen installieren: '.match ($mode) {
                'all' => 'alle',
                'security' => 'nur Sicherheit',
                // Die Einzahl steht hier ausgeschrieben und nicht als
                // `$n === 1 ? … : …` an fünf Stellen: Diese Zeile wird
                // gelesen, nicht gerechnet.
                default => count($pakete) === 1
                    ? 'ein Paket ('.$pakete[0].')'
                    : count($pakete).' Pakete',
            },
        );

        $audit->success('packages.upgraded', context: [
            'mode' => $mode,
            'packages' => $pakete,
            'operation' => (int) $operation->id,
        ]);

        return redirect()->route('operations.show', $operation);
    }

    /**
     * Die unbeaufsichtigten Updates schalten.
     *
     * **Ein Wahrheitswert im Rumpf und kein Umfang.** Der Schalter hat zwei
     * Stellungen; welche Einstellungen daraus werden, entscheidet der Agent
     * (`SrvPanel\Agent\Unattended::fragment()`) und nicht diese
     * Steuerung — sonst gäbe es zwei Fassungen der Entscheidung aus
     * `docs/81 §3`, Frage 4, und die zweite veraltet.
     *
     * **Und der Erfolg wird im Agenten nachgelesen, nicht hier.** Ob die
     * Einstellung wirkt, entscheidet `apt-config dump`; das Panel hat darauf
     * keinen Blick, und ein zweiter hier wäre einer auf die eigene Datei.
     */
    public function unattended(Request $request, Operations $operations, Audit $audit): RedirectResponse
    {
        $daten = $request->validate(['enabled' => ['required', 'boolean']]);

        $an = (bool) $daten['enabled'];
        $account = $request->user();

        $operation = $operations->dispatch(
            'system.packages.unattended',
            ['enabled' => $an],
            account: $account instanceof Account ? $account : null,
            message: $an
                ? 'Unbeaufsichtigte Sicherheitsupdates einschalten'
                : 'Unbeaufsichtigte Sicherheitsupdates ausschalten',
        );

        $audit->success('packages.unattended', context: [
            'enabled' => $an,
            'operation' => (int) $operation->id,
        ]);

        return redirect()->route('operations.show', $operation);
    }

    /**
     * Die Quellenliste ohne alles, was am Schlüssel hängt.
     *
     * **Gesetzt wird `null` und nicht ein leeres `keys`.** Vier Stellen der
     * Seite lesen `key`: die Spalte selbst, der Text „in der Datei" bzw. der
     * Pfad, die Zusammenfassung der ablaufenden Schlüssel und die der nicht
     * lesbaren. Ein leeres `keys` liesse drei davon weiterlaufen und dabei
     * „—" zeigen — also eine Aussage über den Schlüssel, wo gar keine
     * gemacht werden soll.
     *
     * > **Ein leerer Wert sagt „nichts gefunden". Ein fehlender sagt „nicht
     * > deine Frage". Das ist nicht dasselbe.**
     *
     * Nebenbei zwingt `null` den Übersetzer, jede der vier Stellen zu zeigen:
     * Der Typ auf der Seite ist nullable, und `vue-tsc` findet, was ein
     * Mensch übersieht.
     *
     * @param  array<string, mixed>  $sources
     * @return array<string, mixed>
     */
    private static function withoutKeys(array $sources): array
    {
        if (! isset($sources['files']) || ! is_array($sources['files'])) {
            return $sources;
        }

        foreach ($sources['files'] as $i => $datei) {
            if (! is_array($datei) || ! isset($datei['entries']) || ! is_array($datei['entries'])) {
                continue;
            }

            foreach ($datei['entries'] as $j => $eintrag) {
                if (is_array($eintrag)) {
                    $sources['files'][$i]['entries'][$j]['key'] = null;
                }
            }
        }

        return $sources;
    }

    /**
     * Beide Operationen, und ein Ausfall trägt die Seite trotzdem.
     *
     * **Getrennt gefangen und nicht zusammen.** Die Quellen sind die
     * Erklärung für den Paketstand — fällt der Paketstand aus, ist die
     * Quellenliste die Auskunft, die weiterhilft. Ein gemeinsamer `try` gäbe
     * dem Betreiber im häufigsten Fehlerfall genau die Hälfte weg, die den
     * Fehler erklärt.
     *
     * > **Zwei Fragen, die einander erklären, dürfen nicht an derselben
     * > Antwort scheitern.**
     *
     * **Und seit dem 27. August 2026 hängt am Payload die Rollenteilung**
     * (`docs/81 §3` Frage 2). Zwei Stellen fallen für den Administrator weg,
     * beide gemessen (`docs/81 §2.3l`); alles Übrige bleibt — Zahlen,
     * Paketliste, zurückgehaltene samt Grund, Conffiles und der Zustand der
     * Automatik gehören ausdrücklich auch ihm.
     *
     * **1. Die Schlüssel je Quelle.** Entschieden vom Betreiber, und nicht,
     * weil ein Fingerabdruck geheim wäre — er steht in der Dokumentation
     * jeder Distribution und auf Schlüsselservern. Ein Vertrauensanker ist
     * nicht der Gegenstand des Administrators; wer ihn nicht schalten darf,
     * muss ihn auch nicht lesen.
     *
     * > **Eine Angabe, die man weder braucht noch ändern darf, ist keine
     * > Auskunft — sie ist eine Einladung, sie doch zu benutzen.**
     *
     * **2. Der Anteil für den Neustart.** `/server/reboot` bleibt beim
     * Betreiber, also darf der Knopf gar nicht erst erscheinen
     * (`AbilityReachTest`). Der Rechnername darin ist kein Geheimnis — er
     * steht im Zertifikat und in der Adresszeile —, aber ein Wert, der nur da
     * ist, weil ein Knopf ihn braucht, geht mit dem Knopf.
     *
     * @param  bool  $operator  Darf der Betrachter am Server drehen?
     * @return array<string, mixed>
     */
    private function read(Client $agent, bool $operator): array
    {
        $packages = null;
        $sources = null;
        $errors = [];

        try {
            $packages = $agent->call('system.packages.list', []);
        } catch (AgentException $exception) {
            $errors['packages'] = $exception->getMessage();
        }

        try {
            $sources = $agent->call('system.sources.list', []);
        } catch (AgentException $exception) {
            $errors['sources'] = $exception->getMessage();
        }

        /*
         * **Die beiden Zeitstempel der Automatik werden hier zu Text und
         * nicht im Browser.** Der Agent liefert Unixzeit; ein
         * `new Date(...).toLocaleString()` auf der Seite rechnete in der Zone
         * des **Betrachters**, und daneben stünden Zeiten aus
         * {@see Clock::display()} in der eingestellten Anzeigezone. Zwei
         * Angaben in zwei Zonen nebeneinander, und niemand sieht es, solange
         * beide zufällig dieselbe haben (`docs/40`).
         */
        if (is_array($packages) && isset($packages['unattended']['last']) && is_array($packages['unattended']['last'])) {
            foreach ($packages['unattended']['last'] as $name => $zeit) {
                $packages['unattended']['last'][$name] = is_int($zeit)
                    ? Clock::display(Carbon::createFromTimestampUTC($zeit))
                    : null;
            }
        }

        if (! $operator && is_array($sources)) {
            $sources = self::withoutKeys($sources);
        }

        return [
            'packages' => $packages,
            'sources' => $sources,
            'errors' => $errors,

            /*
             * **Der Neustart-Knopf steht am zweiten seiner beiden Anlässe**
             * (`docs/81 §6`). Hier ist er `/run/reboot-required`, auf der
             * Übersicht der neuere Kernel in `/boot` — zwei Fragen an zwei
             * Quellen, eine Handlung, und beide Male steht sie neben ihrem
             * Anlass statt in einem Menü.
             */
            'reboot' => $operator ? ServerController::prompt() : null,

            /*
             * **Hier stand eine `page_size` aus {@see Page::SIZE}, und sie ist
             * wieder fort.** Die Begründung lautete „eine Zahl, eine Stelle" —
             * und war falsch: `Page::SIZE` ist die Seitengrösse der
             * blätternden **Tabellen** dieses Panels, in denen eine Zeile eine
             * Zeile ist. Hier ist eine Zeile bei 390 px ein Kärtchen von
             * 179 px, und dieselbe 50 ergibt vierzehn Bildschirme statt drei.
             *
             * > **Zwei Zahlen, die zufällig gleich sind, sind keine
             * > gemeinsame Zahl.**
             *
             * Die Seitengrösse dieser Liste steht deshalb in der Vorlage, dort
             * gemessen und begründet.
             */
        ];
    }
}
