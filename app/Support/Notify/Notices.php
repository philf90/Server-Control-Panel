<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Models\Finding;
use App\Models\FindingNotification;
use App\Models\FindingResolution;
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
 *
 * ## Und seit dem 24. September auch das Gegenteil
 *
 * Was fort ist, wird abgemeldet — bei den Kanälen, die eine Entwarnung kennen
 * ({@see ResolvingChannel}). Den Augenblick kennt allein
 * {@see FindingLog::forgetMissing()}, und deshalb schreibt der ihn auf; hier
 * wird die Warteschlange geleert.
 *
 * **Die Entwarnung geht vor der Meldung hinaus.** Beide betreffen denselben
 * Empfänger und oft denselben Gegenstand; kommt die Entwarnung hinterher,
 * liest sie sich wie die Rücknahme dessen, was gerade gemeldet wurde.
 *
 * > **Zwei Meldungen über denselben Gegenstand haben eine richtige
 * > Reihenfolge, und sie ist nicht die, in der sie entstanden sind.**
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
     * @return array<string, array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}>
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
     * @return array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}
     */
    private function over(Channel $channel, Carbon $now): array
    {
        $bilanz = ['sent' => 0, 'resolved' => 0, 'findings' => 0, 'without_recipient' => 0, 'failed' => 0, 'skipped' => 0];

        if (! $channel instanceof ResolvingChannel) {
            /*
             * **Ein Kanal, der keine Entwarnung kennt, verbraucht seine Zeilen
             * hier.** {@see FindingLog::forgetMissing()} schreibt eine je
             * Kanal, dem gemeldet wurde — es weiss nicht, wer daraus etwas
             * macht, und soll es nicht wissen. Blieben sie liegen, wäre
             * `finding_resolutions` eine Warteschlange, aus der niemand nimmt,
             * also eine Tabelle, die wächst.
             *
             * **Auch dann, wenn der Kanal gar nicht eingerichtet ist.** Ob
             * entwarnt wird, entscheidet die Art des Empfängers und nicht sein
             * Zustand.
             */
            FindingResolution::query()->where('channel', $channel->key())->delete();
        }

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

        if ($channel instanceof ResolvingChannel) {
            $this->clear($channel, $bilanz);
        }

        foreach ($faellig->groupBy(static fn (Finding $f): string => $channel->batchKey($f->check, $f->subject)) as $findings) {
            $bilanz['findings'] += $findings->count();

            /** @var non-empty-list<Finding> $gruppe */
            $gruppe = $findings->values()->all();

            switch ($channel->deliver($gruppe)) {
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

        if ($bilanz['sent'] + $bilanz['resolved'] > 0) {
            /*
             * **Eine Entwarnung zählt als Zustellung.** „Zuletzt erfolgreich
             * zugestellt" beantwortet die Frage, ob dieser Weg noch trägt —
             * und dafür ist es gleichgültig, was in der Nachricht stand.
             */
            $this->settings->saveNoticeSent($channel->key(), $now->toDateTimeString());
        }

        return $bilanz;
    }

    /**
     * Was fort ist, abmelden — und die Zeile erst danach verbrauchen.
     *
     * **Gelöscht wird nur nach einer gelungenen Zustellung**, aus demselben
     * Grund, aus dem {@see FindingNotification} nur die gelungene bucht: Eine
     * Zeile, die nach einem Fehlschlag verschwindet, nimmt der Entwarnung ihre
     * Fälligkeit, und der Vorfall bliebe beim Empfänger für immer offen.
     *
     * > **Ein Vermerk über eine Zustellung, die nicht stattfand, ist teurer als
     * > keiner.**
     *
     * @param  array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}  $bilanz
     */
    private function clear(ResolvingChannel $channel, array &$bilanz): void
    {
        $offen = FindingResolution::query()
            ->where('channel', $channel->key())
            ->orderBy('check')
            ->orderBy('subject')
            ->orderBy('reason')
            ->get();

        $gebuendelt = $offen->groupBy(
            static fn (FindingResolution $r): string => $channel->batchKey($r->check, $r->subject),
        );

        foreach ($gebuendelt as $zeilen) {
            /** @var non-empty-list<FindingResolution> $gruppe */
            $gruppe = $zeilen->values()->all();

            if ($channel->deliverResolved($gruppe) !== Delivery::Sent) {
                $bilanz['failed']++;

                continue;
            }

            FindingResolution::query()
                ->whereIn('id', array_map(static fn (FindingResolution $r): int => $r->id, $gruppe))
                ->delete();

            $bilanz['resolved']++;
        }
    }

    /**
     * Die Befunde, die über diesen Kanal fällig sind.
     *
     * **`Unknown` wird nicht gemeldet**, und das schliesst
     * {@see FindingCheck::UNREACHABLE} ein: „Diese Prüfung ist nicht
     * durchgelaufen" sagt etwas über die Messung und nicht über einen Zustand.
     *
     * **Für den Betreiber ist das eine offene Frage und keine Entscheidung.**
     * Ein Kunde kann mit „nicht beurteilt" nichts anfangen; ein Betreiber
     * schon — eine Prüfung, die zwei Nächte lang nicht durchläuft, heisst, dass
     * der Agent nicht antwortet. Dagegen steht, dass `docs/129 §4` die Auslöser
     * von B1 aufzählt und diesen nicht nennt, und dass ein ausgefallener Agent
     * **beide** Kanäle betrifft — der Webhook geht durch ihn hindurch. Wer es
     * bauen will, entscheidet zuerst, wer es bekommt.
     *
     * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
     * > nicht als Zusage.**
     *
     * @return Collection<int, Finding>
     */
    private function due(Channel $channel, Carbon $now): Collection
    {
        $schwelle = $now->copy()->subHours(self::HOLD_HOURS);

        return Finding::query()
            ->whereDoesntHave('notifications', static fn ($q) => $q->where('channel', $channel->key()))
            ->where('first_seen_at', '<=', $schwelle)
            ->orderBy('check')
            ->orderBy('subject')
            ->orderBy('reason')
            ->get()
            ->filter(static fn (Finding $f): bool => $f->state() !== FindingState::Unknown)
            ->values();
    }

    /** Wann zuletzt etwas über diesen Kanal angekommen ist — für die Einstellungsseite. */
    public function lastDelivered(string $channel): ?string
    {
        return Clock::displayText($this->settings->noticeSentAt($channel));
    }
}
