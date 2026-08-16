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
            $key = $this->sftp->add(
                $subscription,
                $data['key'],
                $data['label'],
                $request->user()?->email,
            );
        } catch (AgentException $error) {
            throw ValidationException::withMessages(['key' => $error->getMessage()]);
        }

        $this->audit->record('sftp.key.add', subscriptionId: (int) $subscription->id, context: [
            'fingerprint' => $key->fingerprint,
            'type' => $key->type,
        ]);

        return to_route('sftp.show', ['subscription' => $subscription->id])
            ->with('success', 'Der Schlüssel ist eingetragen.');
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
            $this->sftp->remove($subscription, $key);
        } catch (AgentException $error) {
            return to_route('sftp.show', ['subscription' => $subscription->id])
                ->with('error', $error->getMessage());
        }

        $this->audit->record('sftp.key.remove', subscriptionId: (int) $subscription->id, context: [
            'fingerprint' => $key->fingerprint,
        ]);

        return to_route('sftp.show', ['subscription' => $subscription->id])
            ->with('success', 'Der Schlüssel ist entfernt.');
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
