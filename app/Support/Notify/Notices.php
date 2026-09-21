<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Mail\QuotaWarning;
use App\Models\Finding;
use App\Models\Subscription;
use App\Support\Diagnose\FindingLog;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Was der Nachtlauf gefunden hat, an den Kunden — B5, `docs/129 §9`.
 *
 * ## Genau eine Mail, und das ist keine eigene Mechanik
 *
 * Die Zusage steht in zwei Feldern, die es schon gibt:
 *
 * ```
 * gemeldet wird, was   now − first_seen_at ≥ HOLD
 *                und   notified_at is null
 * ```
 *
 * Den ersten Teil hält {@see FindingLog}, seit A10: Eine
 * Zeile über zwei Läufe bleibt **eine**, und `first_seen_at` steht dabei still.
 * Den zweiten hält die Spalte aus B5. Und dass ein behobener Befund **wieder**
 * melden darf, hält `FindingLog::forgetMissing()` — was ein Lauf nicht mehr
 * nennt, wird gelöscht, mitsamt der Erinnerung an die Zustellung.
 *
 * > **Ein zweites Zustandsbuch wäre die zweite Fassung derselben Regel — und
 * > die zweite ist die, die veraltet.**
 *
 * ## Eine Mail je Abonnement und nicht je Befund
 *
 * Ein Kunde, der Platz **und** Verkehr überzieht, bekommt eine Nachricht mit
 * zwei Zeilen und nicht zwei Nachrichten. Das Abnahmekriterium sagt „genau eine
 * Mail", und zwei Mails in derselben Minute sind für den Empfänger genau das,
 * wogegen es geschrieben ist.
 */
final class Notices
{
    /**
     * Wie lange ein Zustand stehen muss, bevor er gemeldet wird.
     *
     * **Zwanzig Stunden, und die Zahl hängt am Zeitgeber.** `srvpanel-diagnose`
     * läuft `OnCalendar=daily` mit `RandomizedDelaySec=1h`; zwischen zwei
     * Läufen liegen damit 23 bis 25 Stunden. Eine Haltezeit **unter** 23
     * Stunden heisst: Gemeldet wird, was zwei Läufe hintereinander dasteht —
     * genau der Vorschlag aus `docs/129 §4`. Eine darüber verschöbe die
     * Meldung unvorhersehbar auf den dritten Lauf, weil der Abstand streut.
     *
     * > **Eine Entprellung ohne ihren Takt ist eine halbe Zahl.**
     *
     * Sie ist eine Entscheidung und keine Messung; sie steht an dieser einen
     * Stelle, damit der Betreiber sie an einer Stelle ändert.
     */
    public const HOLD_HOURS = 20;

    /** Der einzige Kanal, den es heute gibt. Der zweite ist der Webhook aus `docs/129 §7`. */
    public const CHANNEL = 'mail';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Settings $settings,
    ) {}

    /**
     * Die fälligen Meldungen verschicken.
     *
     * @return array{sent: int, findings: int, without_recipient: int, failed: int, skipped: int}
     */
    public function send(Carbon $now): array
    {
        $bilanz = ['sent' => 0, 'findings' => 0, 'without_recipient' => 0, 'failed' => 0, 'skipped' => 0];

        if (! $this->settings->mail()->usable()) {
            /*
             * **Ohne eingetragenes Relay wird nichts gemeldet und nichts
             * vermerkt.** `notified_at` zu setzen hiesse, eine Zustellung zu
             * behaupten, die es nicht gab — und die Meldung wäre für immer
             * fort, weil dieselbe Zeile nie wieder fällig wird.
             */
            $bilanz['skipped'] = $this->due($now)->count();

            return $bilanz;
        }

        foreach ($this->due($now)->groupBy('subject') as $subject => $findings) {
            $bilanz['findings'] += $findings->count();
            $empfaenger = $this->recipients((string) $subject);

            if ($empfaenger === []) {
                $bilanz['without_recipient']++;

                continue;
            }

            try {
                Mail::to($empfaenger)->send(new QuotaWarning((string) $subject, $this->lines($findings->all())));
            } catch (\Throwable $e) {
                // Kein `notified_at`: Was nicht ankam, bleibt fällig. Der
                // nächste Lauf versucht es wieder, und bis dahin steht
                // „zuletzt erfolgreich zugestellt" unverändert da.
                $bilanz['failed']++;

                continue;
            }

            $findings->each(static function (Finding $finding) use ($now): void {
                $finding->notified_at = $now;
                $finding->save();
            });

            $bilanz['sent']++;
        }

        if ($bilanz['sent'] > 0) {
            $this->settings->saveNoticeSent(self::CHANNEL, $now->toDateTimeString());
        }

        return $bilanz;
    }

    /**
     * Die Befunde, die fällig sind.
     *
     * **`Unknown` wird nicht gemeldet.** Ein `traffic_unknown` sagt „nicht
     * beurteilt"; eine Mail darüber wäre eine Meldung an den Kunden über ein
     * Problem des Servers, und die gehört dem Betreiber (B1).
     *
     * @return Collection<int, Finding>
     */
    private function due(Carbon $now): Collection
    {
        $schwelle = $now->copy()->subHours(self::HOLD_HOURS);

        return Finding::query()
            ->where('check', FindingCheck::QuotaExceeded->value)
            ->whereNull('notified_at')
            ->where('first_seen_at', '<=', $schwelle)
            ->orderBy('subject')
            ->orderBy('reason')
            ->get()
            ->filter(static fn (Finding $f): bool => $f->check->state($f->reason) !== FindingState::Unknown)
            ->values();
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
    private function lines(array $findings): array
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

    /** Wann zuletzt etwas über diesen Kanal angekommen ist — für die Einstellungsseite. */
    public function lastDelivered(): ?string
    {
        return Clock::displayText($this->settings->noticeSentAt(self::CHANNEL));
    }
}
