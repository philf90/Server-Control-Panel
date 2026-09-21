<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\FindingCheck;
use App\Mail\QuotaWarning;
use App\Models\Finding;
use App\Models\Subscription;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Der Kanal an den **Kunden** — B5, `docs/129 §9`.
 *
 * **Er trägt heute genau die Kontingentbefunde**, und das ist eine
 * Entscheidung und kein Rest: Was `docs/129 §4` als Auslöser von B1 aufzählt
 * (Dienst tot, Timer ohne Termin, Zertifikat, Sicherung, Updates), geht den
 * Kunden nichts an. Eine Mail darüber wäre eine Meldung an den Kunden über ein
 * Problem des Servers — und die gehört dem Betreiber, also dem Webhook.
 *
 * > **Ein Kanal, der alles trägt, hat keinen Empfänger, sondern eine
 * > Verteilerliste.**
 *
 * **Was noch fehlt und benannt ist:** Die Meldungen des Betreibers **auch**
 * über Mail — an seine eigene Adresse. Dafür braucht es die Frage, welche
 * Adresse das ist, und die ist nicht gestellt; sie steht mit der Erweiterung
 * auf die übrigen Prüfungen in derselben Runde an. Heute ist der Weg zum
 * Betreiber der Webhook.
 */
final class MailChannel implements Channel
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Settings $settings,
    ) {}

    /**
     * Der Schlüssel dieses Kanals — als Konstante und nicht nur als Rückgabe.
     *
     * Wer ihn braucht, hat nicht immer eine Instanz: Die Einstellungsseite des
     * Mailversands fragt „zuletzt erfolgreich zugestellt" für genau diesen
     * Kanal. Ein zweites Mal hingeschriebenes `'mail'` wäre der Verweis ohne
     * Prüfung, an dem dieses Projekt am häufigsten verloren hat.
     */
    public const CHANNEL = 'mail';

    public function key(): string
    {
        return self::CHANNEL;
    }

    /**
     * Ohne eingetragenes Relay wird nichts gemeldet und nichts vermerkt.
     *
     * `notified_at` zu buchen hiesse, eine Zustellung zu behaupten, die es nie
     * gab — und die Meldung wäre für immer fort, weil dieselbe Zeile nie wieder
     * fällig wird.
     */
    public function usable(): bool
    {
        return $this->settings->mail()->usable();
    }

    public function carries(Finding $finding): bool
    {
        return $finding->check === FindingCheck::QuotaExceeded;
    }

    /** @param  list<Finding>  $findings */
    public function deliver(string $subject, array $findings): Delivery
    {
        $empfaenger = $this->recipients($subject);

        if ($empfaenger === []) {
            return Delivery::WithoutRecipient;
        }

        try {
            Mail::to($empfaenger)->send(new QuotaWarning($subject, self::lines($findings)));
        } catch (Throwable) {
            // Keine Buchung: Was nicht ankam, bleibt fällig. Der nächste Lauf
            // versucht es wieder, und bis dahin steht „zuletzt erfolgreich
            // zugestellt" unverändert da.
            return Delivery::Failed;
        }

        return Delivery::Sent;
    }

    /**
     * Die Zeilen einer Nachricht — Beschriftung und gemessener Wert.
     *
     * Die Beschriftung kommt aus {@see FindingCheck::sentence()} und nicht aus
     * einer zweiten Liste hier: Was der Betreiber auf der Diagnoseseite liest,
     * liest der Kunde in seiner Mail.
     *
     * @param  list<Finding>  $findings
     * @return list<array{label: string, detail: string}>
     */
    private static function lines(array $findings): array
    {
        return array_map(static fn (Finding $f): array => [
            'label' => $f->check->sentence($f->reason),
            'detail' => (string) ($f->detail ?? '—'),
        ], $findings);
    }

    /**
     * An wen die Nachricht geht.
     *
     * **An die Konten des Kunden und nicht an eine Adresse am Abonnement** —
     * eine solche gibt es nicht, und sie zu erfinden hiesse, eine zweite
     * Wahrheit neben `accounts.email` zu pflegen.
     *
     * Die Klammer wird gelöst, weil dieser Lauf kein angemeldetes Konto hat;
     * im Grundzustand käme eine leere Liste zurück, und das sähe aus wie
     * „dieser Kunde hat kein Konto".
     *
     * @return list<string>
     */
    private function recipients(string $subscription): array
    {
        /** @var list<string> $adressen */
        $adressen = [];

        $this->tenancy->withoutRestriction(static function () use ($subscription, &$adressen): void {
            $abo = Subscription::query()->where('name', $subscription)->first();

            $adressen = $abo?->customer?->accounts()
                ->whereNotNull('email')
                ->orderBy('id')
                ->pluck('email')
                ->map(static fn (mixed $mail): string => (string) $mail)
                ->all() ?? [];
        });

        return array_values(array_filter($adressen, static fn (string $mail): bool => $mail !== ''));
    }
}
