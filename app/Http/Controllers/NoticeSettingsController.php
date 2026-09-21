<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Notify\Channels;
use App\Support\Notify\Delivery;
use App\Support\Notify\Notices;
use App\Support\Notify\NotifyTarget;
use App\Support\Notify\WebhookChannel;
use App\Support\Time\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Notify\Providers;
use SrvPanel\Agent\Notify\Target;

/**
 * Die Meldewege dieses Servers — B1, `docs/129 §7`.
 *
 * **Warum eine eigene Seite und nicht ein Bereich auf `/settings/mail`.** Der
 * Mailversand ist ein *Werkzeug*: Über ihn geht auch der Einmal-Link zum Setzen
 * eines Passworts, und er hat mit Meldungen nichts zu tun. Wer „wie erfahre ich,
 * dass etwas kaputt ist" sucht, sucht nicht unter „Mailversand".
 *
 * > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
 * > dort?** Der Fehler, den dieses Repo fünfmal gemacht hat, war jedes Mal
 * > derselbe — die Handlung lag dort, wo der Bauende sie gebaut hatte.
 *
 * **Die Adresse des Meldeziels kommt nie zurück.** Gezeigt wird der
 * Rechnername; der Grund steht in {@see Target}: Bei Slack, Discord und den
 * meisten Eingangshaken berechtigt die Adresse *allein* zur Zustellung. Ein
 * Formular, das den gespeicherten Wert zurückschickt, damit es „vollständig"
 * aussieht, legt ihn im Quelltext jeder Seite ab, die es zeigt — derselbe Satz
 * wie beim Passwort des Relays.
 *
 * **`can:operate-server` und nicht `can:manage-settings`.** Entscheidung 3 aus
 * `docs/129 §2`: Nur der Betreiber richtet ein Fernziel ein. Der Administrator
 * sieht den Server, er wählt keine Adresse nach draussen.
 */
final class NoticeSettingsController extends Controller
{
    public function show(Channels $channels, Notices $notices, NotifyTarget $target): Response
    {
        return Inertia::render('Settings/Notices', [
            /*
             * Die Kanäle kommen aus {@see Channels} und nicht aus einer Liste
             * hier. `ChannelReachTest` hält beide Richtungen aneinander — eine
             * zweite Aufzählung wäre genau der tote Eintrag, den er finden
             * soll.
             */
            'channels' => array_map(fn ($channel): array => [
                'key' => $channel->key(),
                'usable' => $channel->usable(),
                'delivered_at' => $notices->lastDelivered($channel->key()),
            ], $channels->all()),

            /*
             * **`stored_at` wird hier zu Text und nicht im Browser.** Ein
             * `toLocaleString()` im Template entschiede die Zone am Gerät des
             * Betrachters — und daneben stünde „zuletzt erfolgreich
             * zugestellt" in der eingestellten Anzeigezone. Zwei Angaben, zwei
             * Zonen, und niemand sieht es, solange beide zufällig dieselbe
             * sind. Der Agent liefert Unix-Sekunden, {@see Clock} macht die
             * Anzeige daraus.
             */
            'target' => self::describe($target),

            /*
             * **Der dritte Zustand.** „Kein Meldeziel" und „der Agent
             * antwortet nicht" sehen ohne diese Zeile gleich aus — und der
             * Betreiber trüge ein zweites Ziel ein, während das erste meldet.
             */
            'live' => $target->reachable(),

            'secret_min' => Target::SECRET_MIN,

            /*
             * **Die Empfänger kommen aus der Positivliste des Agenten.** Eine
             * zweite Aufzählung auf der Seite wäre genau der tote Eintrag, den
             * `ChannelReachTest` für die Kanäle verhindert — hier eine Ebene
             * tiefer. Ob ein Empfänger signiert, steht daneben und wird nicht
             * am Schlüssel abgelesen: Das ist eine Frage an {@see Providers}.
             */
            'providers' => array_map(static fn (string $key, string $label): array => [
                'value' => $key,
                'label' => $label,
                'signs' => Providers::signs($key),
            ], array_keys(Providers::LABELS), array_values(Providers::LABELS)),
        ]);
    }

    /**
     * Was der Agent über das Ziel sagt — mit dem Zeitpunkt als Anzeige.
     *
     * @return array{host: string, provider: string, stored_at: string|null, signed: bool}|null
     */
    private static function describe(NotifyTarget $target): ?array
    {
        $beschrieben = $target->describe();

        if ($beschrieben === null) {
            return null;
        }

        return [
            'host' => $beschrieben['host'],
            'provider' => Providers::LABELS[$beschrieben['provider']] ?? $beschrieben['provider'],
            'stored_at' => Clock::display(Carbon::createFromTimestampUTC($beschrieben['stored_at'])),
            'signed' => $beschrieben['signed'],
        ];
    }

    /**
     * Ein Meldeziel hinterlegen.
     *
     * **Geprüft wird hier und im Agenten, und das sind nicht zwei Fassungen.**
     * Diese Prüfung sagt dem Betreiber, was an seiner Eingabe nicht stimmt,
     * am Feld; die im Agenten ist die Grenze. Fiele diese weg, bekäme der
     * Betreiber eine Ausnahme statt einer Meldung — fiele die dort weg, wäre
     * die Grenze fort.
     */
    public function update(Request $request, NotifyTarget $target, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            /*
             * **`https` steht als eigene Regel da und nicht nur in `url`.**
             * Laravels `url` nimmt jedes Schema, `http://127.0.0.1:9200`
             * eingeschlossen — und genau das ist der Fall, gegen den Grenze 1
             * geschrieben ist.
             */
            'url' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],

            // Was der Agent nicht kennt, wird hier schon abgewiesen — und der
            // Agent weist es noch einmal ab. Diese Prüfung sagt dem Betreiber,
            // was an seiner Eingabe nicht stimmt; die dort ist die Grenze.
            'provider' => ['required', Rule::in(array_keys(Providers::LABELS))],

            // Leer heisst „ohne Signatur" und nicht „unverändert": Bei Slack
            // und Discord gibt es gar keines, dort trägt die Adresse alles.
            'secret' => ['nullable', 'string', 'min:'.Target::SECRET_MIN, 'max:255'],
        ], [], [
            /*
             * **Nur `secret` steht hier, `url` in `lang/de/validation.php`.**
             * Die Liste dort trägt den Namen, der über alle Seiten passt; wo
             * eine Seite ein anderes Wort benutzt, steht es hier. Das Feld
             * heisst auf dieser Seite „Geheimnis zum Signieren", weil daneben
             * noch eine Adresse steht und „Geheimnis" allein offen liesse,
             * welches (`docs/66`, Befund 3).
             */
            'secret' => 'Geheimnis zum Signieren',

            /*
             * **„Empfänger" und nicht „Anbieter".** Die Liste in
             * `lang/de/validation.php` trägt „Anbieter", und das ist auf der
             * DNS-Seite richtig — dort ist der Anbieter derjenige, der die Zone
             * führt. Hier ist es der, der die Meldung annimmt, und die beiden
             * im selben Panel gleich zu nennen wäre die Verwechslung, die diese
             * Seite gerade auflöst.
             */
            'provider' => 'Empfänger',
        ]);

        try {
            $target->store($data['url'], $data['secret'] ?? null, $data['provider']);
        } catch (AgentException $error) {
            $audit->failure('settings.notices.stored', ['error' => mb_substr($error->getMessage(), 0, 500)]);

            return to_route('settings.notices')->with('error', 'Das Meldeziel ließ sich nicht hinterlegen: '.mb_substr($error->getMessage(), 0, 500));
        }

        /*
         * Im Protokoll steht der Rechner und nicht die Adresse — aus demselben
         * Grund, aus dem die Seite ihn zeigt. Ein Protokolleintrag mit der
         * vollen Adresse wäre das Geheimnis in einer Tabelle, die der
         * Administrator lesen darf.
         */
        $audit->success('settings.notices.stored', context: [
            'host' => (string) parse_url($data['url'], PHP_URL_HOST),
            'provider' => $data['provider'],
            'signed' => ($data['secret'] ?? null) !== null,
        ]);

        return to_route('settings.notices')->with('success', 'Meldeziel hinterlegt.');
    }

    /** Das Meldeziel wieder entfernen. */
    public function destroy(NotifyTarget $target, Audit $audit): RedirectResponse
    {
        try {
            $entfernt = $target->forget();
        } catch (AgentException $error) {
            $audit->failure('settings.notices.forgotten', ['error' => mb_substr($error->getMessage(), 0, 500)]);

            return to_route('settings.notices')->with('error', 'Das Meldeziel ließ sich nicht entfernen: '.mb_substr($error->getMessage(), 0, 500));
        }

        $audit->success('settings.notices.forgotten', context: ['removed' => $entfernt]);

        return to_route('settings.notices')->with(
            'success',
            $entfernt ? 'Meldeziel entfernt.' : 'Es war keines hinterlegt.',
        );
    }

    /**
     * Eine Probezustellung.
     *
     * **Sie geht denselben Weg wie eine echte Meldung** — dieselbe Operation,
     * dieselbe Signatur, dasselbe Zeitlimit. Ein eigener Weg zum Ausprobieren
     * bliebe grün, während der echte nicht durchkommt.
     *
     * > **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den
     * > falschen Weg.**
     *
     * **Sie schreibt „zuletzt erfolgreich zugestellt" nicht.** Sie belegt, dass
     * das Ziel erreichbar ist, und nicht, dass eine Meldung ankam — derselbe
     * Satz wie bei der Testmail.
     */
    public function test(WebhookChannel $webhook, Audit $audit): RedirectResponse
    {
        if (! $webhook->usable()) {
            return to_route('settings.notices')->with('error', 'Erst ein Meldeziel hinterlegen.');
        }

        if ($webhook->probe() === Delivery::Failed) {
            $audit->failure('settings.notices.tested');

            return to_route('settings.notices')->with('error', 'Die Probezustellung ist nicht angekommen.');
        }

        $audit->success('settings.notices.tested');

        return to_route('settings.notices')->with('success', 'Probezustellung angekommen.');
    }
}
