<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\FindingState;
use App\Models\Finding;
use App\Models\FindingNotification;
use App\Support\Diagnose\FindingLog;
use App\Support\Settings\Settings;
use App\Support\Time\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Was der Nachtlauf gefunden hat, nach draussen — B5 und B1, `docs/129 §4`.
 *
 * ## Genau eine Meldung je Kanal, und das ist keine eigene Mechanik
 *
 * Die Zusage steht in zwei Dingen, die es schon gibt:
 *
 * ```
 * gemeldet wird, was   now − first_seen_at ≥ HOLD
 *                und   für diesen Kanal keine Zeile in finding_notifications hat
 * ```
 *
 * Den ersten Teil hält {@see FindingLog}, seit A10: Eine Zeile über zwei Läufe
 * bleibt **eine**, und `first_seen_at` steht dabei still. Den zweiten hält die
 * Tabelle aus B1. Und dass ein behobener Befund **wieder** melden darf, hält
 * `FindingLog::forgetMissing()` — was ein Lauf nicht mehr nennt, wird gelöscht,
 * mitsamt den Zeilen der Zustellung (`cascadeOnDelete`).
 *
 * > **Ein zweites Zustandsbuch wäre die zweite Fassung derselben Regel — und
 * > die zweite ist die, die veraltet.**
 *
 * ## Je Kanal gebucht und nicht gemeinsam
 *
 * Bis zum 24. September 2026 war die Buchung eine Spalte `notified_at`. Mit
 * dem zweiten Kanal trägt sie nicht mehr; die Begründung steht in der
 * Migration `…_create_finding_notifications_table` und kurz:
 *
 * > **Ein Kanal, der für einen anderen mitbucht, verliert dessen Meldung — und
 * > zwar dauerhaft.**
 *
 * ## Eine Nachricht je Gegenstand und nicht je Befund
 *
 * Ein Kunde, der Platz **und** Verkehr überzieht, bekommt eine Nachricht mit
 * zwei Zeilen und nicht zwei Nachrichten. Das Abnahmekriterium sagt „genau eine
 * Mail", und zwei in derselben Minute sind für den Empfänger genau das, wogegen
 * es geschrieben ist.
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

    public function __construct(
        private readonly Settings $settings,
        private readonly Channels $channels,
    ) {}

    /**
     * Die fälligen Meldungen verschicken — über jeden Kanal, der durchkommt.
     *
     * **Je Kanal eine eigene Bilanz.** Eine Summe über beide sagte „zwei
     * Nachrichten verschickt" und liesse offen, ob das zweimal Mail war und der
     * Webhook geschwiegen hat.
     *
     * > **Eine Zahl, die eine Aufteilung zusammenfasst, sagt nicht, welche.**
     *
     * @return array<string, array{sent: int, findings: int, without_recipient: int, failed: int, skipped: int}>
     */
    public function send(Carbon $now): array
    {
        $bilanz = [];

        foreach ($this->channels->all() as $channel) {
            $bilanz[$channel->key()] = $this->over($channel, $now);
        }

        return $bilanz;
    }

    /**
     * Ein Kanal, ein Durchgang.
     *
     * @return array{sent: int, findings: int, without_recipient: int, failed: int, skipped: int}
     */
    private function over(Channel $channel, Carbon $now): array
    {
        $bilanz = ['sent' => 0, 'findings' => 0, 'without_recipient' => 0, 'failed' => 0, 'skipped' => 0];
        $faellig = $this->due($channel, $now);

        if (! $channel->usable()) {
            /*
             * **Ohne eingerichteten Kanal wird nichts gemeldet und nichts
             * gebucht.** Eine Zeile zu schreiben hiesse, eine Zustellung zu
             * behaupten, die es nicht gab — und die Meldung wäre für immer
             * fort, weil derselbe Befund über diesen Kanal nie wieder fällig
             * wird.
             */
            $bilanz['skipped'] = $faellig->count();

            return $bilanz;
        }

        foreach ($faellig->groupBy('subject') as $subject => $findings) {
            $bilanz['findings'] += $findings->count();

            /** @var list<Finding> $gruppe */
            $gruppe = $findings->values()->all();

            switch ($channel->deliver((string) $subject, $gruppe)) {
                case Delivery::Sent:
                    foreach ($gruppe as $finding) {
                        FindingNotification::record($finding, $channel, $now);
                    }

                    $bilanz['sent']++;
                    break;

                case Delivery::WithoutRecipient:
                    $bilanz['without_recipient']++;
                    break;

                case Delivery::Failed:
                    $bilanz['failed']++;
                    break;
            }
        }

        if ($bilanz['sent'] > 0) {
            $this->settings->saveNoticeSent($channel->key(), $now->toDateTimeString());
        }

        return $bilanz;
    }

    /**
     * Die Befunde, die über diesen Kanal fällig sind.
     *
     * **`Unknown` wird nicht gemeldet.** Ein `traffic_unknown` sagt „nicht
     * beurteilt"; eine Meldung darüber wäre eine über ein Problem der Messung
     * und nicht über einen Zustand.
     *
     * **Gefiltert wird zweimal, und das ist Absicht.** Die Datenbank wirft
     * weg, was schon gebucht ist oder die Haltezeit nicht erreicht hat; der
     * Kanal entscheidet danach, was ihn angeht ({@see Channel::carries()}).
     * Die zweite Frage in SQL zu stellen hiesse, jeden Kanal eine Abfrage
     * schreiben zu lassen — und die Befunde einer Nacht sind zweistellig.
     *
     * @return Collection<int, Finding>
     */
    private function due(Channel $channel, Carbon $now): Collection
    {
        $schwelle = $now->copy()->subHours(self::HOLD_HOURS);

        return Finding::query()
            ->whereDoesntHave('notifications', static fn ($q) => $q->where('channel', $channel->key()))
            ->where('first_seen_at', '<=', $schwelle)
            ->orderBy('subject')
            ->orderBy('reason')
            ->get()
            ->filter(static fn (Finding $f): bool => $f->state() !== FindingState::Unknown)
            ->filter(static fn (Finding $f): bool => $channel->carries($f))
            ->values();
    }

    /** Wann zuletzt etwas über diesen Kanal angekommen ist — für die Einstellungsseite. */
    public function lastDelivered(string $channel): ?string
    {
        return Clock::displayText($this->settings->noticeSentAt($channel));
    }
}
