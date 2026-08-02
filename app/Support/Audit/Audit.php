<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Die einzige Stelle, an der Protokolleinträge entstehen.
 *
 * Sie ist bewusst schmal: Wer protokollieren will, ruft eine Methode auf und
 * kann dabei nicht vergessen, IP und Kontext mitzugeben — beides holt sich
 * dieser Dienst selbst aus der Anfrage.
 *
 * **„Anmelden als" wird doppelt vermerkt** (§6.3). Handelt ein Admin in der
 * Sicht eines Kunden, steht im Eintrag beides: der Admin als handelnde Person
 * und das Kundenkonto als Kontext. Ein Protokoll, in dem nur der Kunde steht,
 * verschweigt genau den Fall, für den man es liest.
 */
final class Audit
{
    public function __construct(private readonly Request $request) {}

    /** @param array<string, mixed> $context */
    public function record(
        string $action,
        AuditResult $result = AuditResult::Success,
        ?Account $account = null,
        ?Model $target = null,
        ?int $subscriptionId = null,
        array $context = [],
    ): AuditEvent {
        // Wer wirklich handelt — und in wessen Kontext.
        //
        // Während „Anmelden als" ist der angemeldete Benutzer das Kundenkonto;
        // die handelnde Person ist der Admin, der den Wechsel begonnen hat.
        // Genau in dieser Reihenfolge steht es im Eintrag, sonst verschweigt
        // das Protokoll den Fall, für den man es liest.
        $impersonator = $this->impersonatorId();
        $signedIn = $account ?? $this->actor();

        return AuditEvent::query()->create([
            'account_id' => $impersonator ?? $signedIn?->id,
            'acting_as_account_id' => $impersonator !== null ? $signedIn?->id : null,
            'subscription_id' => $subscriptionId,
            'action' => $action,
            'target_type' => $target !== null ? $target::class : null,
            'target_id' => $target?->getKey(),
            'result' => $result,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function success(string $action, ?Model $target = null, array $context = []): AuditEvent
    {
        return $this->record($action, AuditResult::Success, target: $target, context: $context);
    }

    /** @param array<string, mixed> $context */
    public function failure(string $action, array $context = []): AuditEvent
    {
        return $this->record($action, AuditResult::Failure, context: $context);
    }

    /** @param array<string, mixed> $context */
    public function denied(string $action, ?Model $target = null, array $context = []): AuditEvent
    {
        return $this->record($action, AuditResult::Denied, target: $target, context: $context);
    }

    private function actor(): ?Account
    {
        $user = $this->request->user();

        return $user instanceof Account ? $user : null;
    }

    /**
     * Das Konto, in dessen Sicht gerade gehandelt wird — falls jemand
     * „Anmelden als" benutzt. Der Sitzungsschlüssel wird von der
     * Impersonation gesetzt (§6.3) und ist hier nur zu lesen.
     */
    /**
     * Der Admin, der „Anmelden als" begonnen hat — falls gerade eines läuft.
     *
     * Der Schlüssel heißt nach dem, was darin steht: die Kennung des
     * Handelnden, nicht die des Kontos, in dessen Sicht er ist. Das klingt
     * nach Wortklauberei und ist es nicht — mit dem umgekehrten Namen landet
     * beim Schreiben des Eintrags zuverlässig das Falsche im falschen Feld.
     */
    private function impersonatorId(): ?int
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $id = $this->request->session()->get(Impersonation::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }
}
