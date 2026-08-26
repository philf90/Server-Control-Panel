<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Operations\Operations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

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
