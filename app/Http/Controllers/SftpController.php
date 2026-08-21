<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SshKey;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Files\Sftp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ssh\PublicKey;

/**
 * Der SFTP-Zugang eines Abonnements.
 *
 * **Hier steht keine Schlüsselprüfung.** Sie steht in
 * {@see PublicKey::parse()}, also an der Stelle, die die Zeile später auch
 * schreibt; eine zweite hier sähe aus wie die Schranke, wäre keine, und liefe
 * beim nächsten Umbau auseinander. Validiert wird, was eine Validierung ist:
 * dass überhaupt etwas geschickt wurde und wie lang es sein darf.
 *
 * **Die Ablehnung wird sichtbar gemacht.** `docs/50 §6`: Der Klient bekommt
 * `Broken pipe`, und der Grund steht nur im Serverprotokoll. Die Seite fragt
 * deshalb bei jedem Aufruf `sftp.check` — und **ein Fehler dabei ist selbst
 * eine Auskunft** und kein Grund, die Seite nicht zu zeigen. `docs/44` hat
 * vorgeführt, was ein `catch { return []; }` an so einer Stelle anrichtet: Aus
 * „nicht erreichbar" wurde „der Betreiber bietet es nicht an".
 */
final class SftpController extends Controller
{
    public function __construct(
        private readonly Sftp $sftp,
        private readonly Audit $audit,
    ) {}

    /**
     * Der Weg hinein, ohne dass der Kunde eine Abo-Kennung kennen muss.
     *
     * **Gemeldet vom Betreiber am 17. August 2026** während der Zwischenabnahme
     * (`docs/59`, Befund 19): Der SFTP-Zugang lag drei Klicks tief —
     * Abonnements, Name, Bereich —, also genau dort, wo der Dateimanager vor
     * `docs/55` Befund 8 lag.
     *
     * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
     * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
     *
     * **Die Bauart ist wörtlich die von {@see FileController::pick()}**, und das
     * ist Absicht: Es ist dieselbe Frage — ein Merkmal, das an *einem*
     * Abonnement hängt, braucht einen Menüpunkt ohne Kennung darin. Eine zweite
     * Antwort auf dieselbe Frage wäre die, die beim nächsten Umbau auseinander
     * läuft.
     *
     * **Die Mandantenklammer hat schon gefiltert**, bevor diese Zeile läuft;
     * `manageSftp` ist die zweite Frage und nicht die erste. Ein Admin sieht
     * damit alle Abonnements — für ihn steht der Punkt aber nicht im Menü, weil
     * „welches" bei tausend Kunden keine Auswahlliste mehr ist, sondern die
     * Abonnementsliste, die es schon gibt.
     */
    public function pick(Request $request): RedirectResponse|Response
    {
        $account = $request->user();

        $erreichbar = Subscription::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Subscription $s): bool => $account?->can('manageSftp', $s) ?? false)
            ->values();

        if ($erreichbar->count() === 1) {
            return to_route('sftp.show', ['subscription' => $erreichbar->first()?->id]);
        }

        return Inertia::render('Subscriptions/SftpPick', [
            'subscriptions' => $erreichbar
                ->map(static fn (Subscription $s): array => [
                    'id' => $s->id,
                    'name' => $s->name,
                ])
                ->all(),
        ]);
    }

    public function show(Subscription $subscription): Response
    {
        $keys = SshKey::query()
            ->where('subscription_id', (int) $subscription->id)
            ->orderBy('label')
            ->get()
            ->map(static fn (SshKey $key): array => [
                'id' => (int) $key->id,
                'label' => $key->label,
                'type' => $key->type,
                'bits' => $key->bits,
                'fingerprint' => $key->fingerprint,
                'created_by' => $key->created_by,
            ])
            ->all();

        return Inertia::render('Subscriptions/Sftp', [
            'subscription' => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'system_user' => $subscription->system_user,
            ],
            'keys' => $keys,
            'check' => $this->check($subscription),
            'can' => [
                'manage' => request()->user()?->can('manageSftp', $subscription) ?? false,
            ],
        ]);
    }

    public function store(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],

            // Die Obergrenze ist dieselbe wie im Agenten, und sie steht hier,
            // damit der Kunde eine Feldmeldung bekommt statt einer Absage aus
            // der Tiefe. Sie ist nicht die Schranke — die steht dort.
            'key' => ['required', 'string', 'max:'.PublicKey::MAX_LENGTH],
        ]);

        try {
            $ergebnis = $this->sftp->add(
                $subscription,
                $data['key'],
                $data['label'],
                $request->user()?->email,
            );
        } catch (AgentException $error) {
            /*
             * **Nur eine abgewiesene Eingabe ist ein Fehler am Feld.**
             * Der Fund aus Phase B von Punkt 8 (`docs/59`, Befund 11): Bei
             * kaputter `sshd_config` brach der Vorgang richtig ab, und die
             * Meldung landete am Schlüsselfeld — das Feld wurde rot, obwohl der
             * Schlüssel einwandfrei war. `PublicKey::parse()` hatte ihn eine
             * Zeile vorher gelesen.
             *
             * > **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn
             * > für einen Zustand des Servers setzt, schickt den Leser dorthin,
             * > wo nichts zu ändern ist.**
             *
             * `badRequest` kommt aus der Prüfung der Eingabe; alles andere —
             * `exec_failed`, `timeout`, `internal` — ist ein Zustand des Servers
             * und gehört an die Zusammenfassung, ohne ein Feld anzufassen.
             * Der Schlüsselname `server` ist deshalb keiner eines Feldes.
             */
            if ($error->errorCode === AgentException::BAD_REQUEST) {
                throw ValidationException::withMessages(['key' => $error->getMessage()]);
            }

            throw ValidationException::withMessages([
                'server' => 'Der Schlüssel ist in Ordnung; der Server hat die Änderung nicht '
                    .'angenommen. '.$error->getMessage(),
            ]);
        }

        $this->audit->record('sftp.key.add', target: $ergebnis['key'], subscriptionId: (int) $subscription->id, context: [
            'fingerprint' => $ergebnis['key']->fingerprint,
            'type' => $ergebnis['key']->type,
        ]);

        return to_route('sftp.show', ['subscription' => $subscription->id])
            ->with('success', self::spoken('Der Schlüssel ist eingetragen.', $ergebnis['note']));
    }

    public function destroy(Subscription $subscription, SshKey $key): RedirectResponse
    {
        /*
         * **Der Schlüssel muss zu diesem Abonnement gehören.** Die
         * Mandantenklammer sorgt dafür, dass niemand einen fremden findet; sie
         * sorgt nicht dafür, dass der gefundene zu der Adresse passt, unter der
         * er aufgerufen wurde. Zwei Abonnements desselben Kunden sind genau der
         * Fall, in dem das auseinandergeht.
         */
        abort_unless((int) $key->subscription_id === (int) $subscription->id, 404);

        try {
            $note = $this->sftp->remove($subscription, $key);
        } catch (AgentException $error) {
            return to_route('sftp.show', ['subscription' => $subscription->id])
                ->with('error', $error->getMessage());
        }

        $this->audit->record('sftp.key.remove', target: $key, subscriptionId: (int) $subscription->id, context: [
            'fingerprint' => $key->fingerprint,
        ]);

        return to_route('sftp.show', ['subscription' => $subscription->id])
            ->with('success', self::spoken('Der Schlüssel ist entfernt.', $note));
    }

    /**
     * Die Erfolgsmeldung, und dahinter die Auskunft des Agenten, wenn es eine gibt.
     *
     * **Der Fund** (`docs/59`, Befund 21): `sftp.access` baut den Satz
     * „ssh.service ist inactive — die neue Datei gilt ab der nächsten Verbindung",
     * und niemand trug ihn. `docs/58` Punkt 9 verlangt ihn ausdrücklich; ohne ihn
     * liest der Kunde „eingetragen" und weiss nicht, dass sein laufender Zugang
     * noch den alten Stand benutzt.
     *
     * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
     * > keine.**
     */
    private static function spoken(string $success, ?string $note): string
    {
        return $note === null ? $success : $success.' '.$note.'.';
    }

    /**
     * Was der Agent zur Lage sagt — und was er sagt, wenn er nichts sagen kann.
     *
     * @return array<string,mixed>
     */
    private function check(Subscription $subscription): array
    {
        if ($subscription->system_user === null) {
            return ['unavailable' => 'Dieses Abonnement hat keinen Systembenutzer.'];
        }

        try {
            return $this->sftp->check($subscription);
        } catch (AgentException $error) {
            return ['unavailable' => $error->getMessage()];
        }
    }
}
