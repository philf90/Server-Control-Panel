<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Operations\Operations;
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
    public function show(Client $agent): Response
    {
        return Inertia::render('Updates/Index', $this->read($agent));
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
     * @return array<string, mixed>
     */
    private function read(Client $agent): array
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
            'reboot' => ServerController::prompt(),

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
