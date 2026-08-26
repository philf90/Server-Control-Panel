<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Web\Page;
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
             * **Die Seitengrösse kommt von hier und nicht aus der Vorlage.**
             * Sie steht als {@see Page::SIZE} an einer Stelle, und jede
             * blätternde Liste dieses Panels benutzt dieselbe. Eine 50 in der
             * `.vue` wäre deren zweite Fassung.
             */
            'page_size' => Page::SIZE,
        ];
    }
}
