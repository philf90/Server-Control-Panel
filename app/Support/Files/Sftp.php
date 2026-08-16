<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\SshKey;
use App\Models\Subscription;
use App\Support\Databases\RemoteAccess;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ssh\PublicKey;

/**
 * Der SFTP-Zugang — der Sollzustand, jedes Mal ganz.
 *
 * ## Zwei Zustände, die zusammen stehen und fallen
 *
 * Ein Abonnement mit SFTP hat **einen Block in `sshd_config`** und **eine
 * Schlüsseldatei**. Fehlt eines von beiden, ist der Zugang tot — und zwar auf
 * eine Art, die im Panel wie „eingerichtet" aussieht. Das ist wörtlich die
 * gefährlichere Hälfte aus `docs/45`:
 *
 * > **Ein Bestand ohne Zeile ist das Gegenteil eines Rests: Das Panel sagt
 * > „erreichbar", und die Anwendung kommt nicht herein.**
 *
 * Deshalb steht jede Änderung in einer Transaktion mit dem Aufruf des Agenten,
 * und der Block wird aus dem **vollständigen** Bestand gebaut, nicht
 * fortgeschrieben.
 *
 * ## Warum das einen unbeschränkten Blick kostet
 *
 * `sshd_config` ist eine Datei des **Servers** und nicht eines Abonnements. Wer
 * sie je Mandant schriebe, schriebe beim zweiten Kunden den Block des ersten
 * weg. {@see self::accesses()} liest deshalb ohne Klammer — die ausdrückliche
 * Ausnahme aus der dritten Grenze, mit derselben Begründung wie
 * {@see RemoteAccess::rules()}.
 *
 * Die Frage „darf dieser Benutzer diesen Schlüssel eintragen" beantwortet der
 * Controller, bevor er hier hereinkommt.
 *
 * ## Unmittelbar und nicht über die Warteschlange
 *
 * Der Vorgang dauert Millisekunden, und der Kunde soll die Meldung an seinem
 * Formular lesen. Ein eingereihter Vorgang trüge ausserdem den Sollzustand von
 * *jetzt* in seiner Nutzlast:
 *
 * > **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
 * > anderen Vorgänge derselben Reihe nicht.**
 */
final class Sftp
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Client $agent,
    ) {}

    /**
     * Ein Schlüssel dazu — geprüft mit der Regel des Agenten.
     *
     * **Und die Prüfung ist die des Agenten und keine zweite hier.**
     * {@see PublicKey::parse()} weist Optionszeilen ab, erklärt einen privaten
     * Schlüssel als solchen und rechnet den Fingerabdruck; eine eigene
     * Formulierung im Formular wäre die, die beim nächsten Mal auseinanderläuft.
     * Dieselbe Entscheidung wie bei `Hba::cidr()` in P5b.
     *
     * @throws AgentException wenn der Schlüssel nicht taugt oder das Schreiben scheitert
     */
    public function add(Subscription $subscription, string $key, string $label, ?string $by = null): SshKey
    {
        // Vor der Transaktion: Ein untauglicher Schlüssel soll gar nicht erst
        // eine aufmachen, und die Meldung ist dieselbe.
        $parsed = PublicKey::parse($key);

        /** @var SshKey $row */
        $row = DB::transaction(function () use ($subscription, $parsed, $label, $by): SshKey {
            /** @var SshKey $row */
            $row = SshKey::query()->updateOrCreate(
                [
                    'subscription_id' => (int) $subscription->id,
                    'fingerprint' => $parsed['fingerprint'],
                ],
                [
                    'label' => $label,
                    'type' => $parsed['type'],
                    'bits' => $parsed['bits'],
                    'public_key' => $parsed['type'].' '.$parsed['material'],
                    'created_by' => $by,
                ],
            );

            /*
             * **Erst der Block, dann die Schlüssel.** Scheitert der zweite
             * Schritt, rollt die Transaktion die Zeile zurück, und übrig bleibt
             * ein Block ohne Schlüsseldatei — also kein Zugang. Andersherum
             * bliebe eine Schlüsseldatei ohne Block liegen, und die ist
             * ebenfalls kein Zugang, aber sie enthält Kundendaten. Von zwei
             * Resten ist der leere der bessere.
             */
            $this->sync();
            $this->write($subscription);

            return $row;
        });

        return $row;
    }

    /** Einen Schlüssel wieder wegnehmen — und die Datei sofort nachziehen. */
    public function remove(Subscription $subscription, SshKey $key): void
    {
        DB::transaction(function () use ($subscription, $key): void {
            $key->delete();

            $this->write($subscription);
            $this->sync();
        });
    }

    /**
     * Die Schlüsseldatei eines Abonnements neu schreiben.
     *
     * Ohne Schlüssel wird sie **entfernt** und nicht geleert: Eine leere Datei
     * sähe aus wie „Zugang eingerichtet, keine Schlüssel" und ist dasselbe wie
     * „kein Zugang". Zwei Zustände für eine Sache sind einer zu viel.
     *
     * @return array<string,mixed>
     */
    public function write(Subscription $subscription): array
    {
        $keys = SshKey::query()
            ->where('subscription_id', (int) $subscription->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (SshKey $key): array => [
                'key' => $key->public_key,
                'label' => $key->label,
            ])
            ->all();

        return $this->agent->call('sftp.key.apply', [
            'user' => (string) $subscription->system_user,
            'keys' => array_values($keys),
        ]);
    }

    /**
     * Den verwalteten Block auf den Stand des Bestands bringen.
     *
     * **Jedes Abonnement mit mindestens einem Schlüssel bekommt einen Block.**
     * Der Schalter „SFTP an/aus" ist damit der Bestand selbst und kein zweites
     * Feld daneben — und die beiden können nicht auseinanderlaufen, weil es nur
     * eines gibt.
     *
     * @return array<string,mixed>
     */
    public function sync(): array
    {
        return $this->agent->call('sftp.access', ['accesses' => $this->accesses()]);
    }

    /**
     * Jeder Zugang, den es geben soll — über den ganzen Bestand.
     *
     * Ein Abonnement ohne Systembenutzer bekommt keinen: Der Block nennt ihn,
     * und einen Block ohne Benutzer gibt es nicht.
     *
     * @return list<array{user: string, name: string}>
     */
    public function accesses(): array
    {
        /** @var list<array{user: string, name: string}> $accesses */
        $accesses = [];

        $this->tenancy->withoutRestriction(function () use (&$accesses): void {
            $mitSchluessel = SshKey::query()
                ->distinct()
                ->pluck('subscription_id')
                ->all();

            if ($mitSchluessel === []) {
                return;
            }

            $subscriptions = Subscription::query()
                ->whereIn('id', $mitSchluessel)
                ->whereNotNull('system_user')
                ->orderBy('name')
                ->get();

            foreach ($subscriptions as $subscription) {
                $accesses[] = [
                    'user' => (string) $subscription->system_user,
                    'name' => (string) $subscription->name,
                ];
            }
        });

        return $accesses;
    }

    /**
     * Nachsehen, warum es klemmt.
     *
     * @return array<string,mixed>
     */
    public function check(Subscription $subscription): array
    {
        return $this->agent->call('sftp.check', [
            'user' => (string) $subscription->system_user,
            'name' => (string) $subscription->name,
        ]);
    }
}
