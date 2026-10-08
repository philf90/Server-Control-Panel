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
use Illuminate\Database\Eloquent\Builder;
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
 * Bis zum 21. September 2026 war die Buchung eine Spalte `notified_at`. Mit
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

    /**
     * Die Haltezeit für „Platte voll" — gemeldet beim dritten Lauf (`docs/136 §4`).
     *
     * **Entschieden hat der Betreiber am 27. September 2026: alle fünf Minuten
     * prüfen, nach zehn melden — drei Läufe hintereinander.** Zwanzig Stunden
     * wären hier sinnlos: Bei voller Platte stürzt MariaDB beim nächsten Wachsen
     * einer Tabelle ab (`docs/136 §3` M5).
     *
     * **Acht und nicht zehn, aus demselben Grund wie zwanzig und nicht
     * vierundzwanzig oben.** Der dritte Lauf kommt zehn Minuten nach dem ersten
     * — aber nicht auf die Sekunde: Der Zeitgeber streut um bis zu dreissig
     * Sekunden je Lauf, und systemd legt einen Termin ohne `AccuracySec` in ein
     * Fenster von einer Minute (gemessen: `AccuracyUSec=1min`). Eine Haltezeit
     * von genau zehn Minuten traf den dritten Lauf deshalb nur manchmal, und die
     * Meldung rutschte unvorhersehbar auf den vierten. Mit `AccuracySec=1s` liegt
     * der zweite Lauf 269 bis 331 Sekunden nach dem ersten und der dritte 569 bis
     * 631; acht Minuten liegen sicher dazwischen. `DiskCadenceTest` rechnet es aus
     * der Unit nach.
     *
     * > **Eine Haltezeit, die genau auf einen Takt fällt, zählt die Läufe nicht,
     * > sondern würfelt sie.**
     */
    public const DISK_HOLD_MINUTES = 8;

    /**
     * Die Haltezeit für eine gescheiterte Sicherung — keine (`docs/142 §6`,
     * Frage 3, entschieden vom Betreiber am 7. Oktober 2026).
     *
     * **Gemeldet wird im ersten Nachtlauf, der sie sieht.** Mit den zwanzig
     * Stunden von oben würfelte die Meldung: Den Befund stellt ein anderer
     * Zeitgeber her als der, der ihn abliest, und ob die Diagnose ihn ein- oder
     * zweimal sieht, entscheidet, ob sie vor oder nach der Sicherung läuft.
     * Gerechnet in `docs/142 §3` M3: Eine einzelne gescheiterte Sicherung
     * würde in 18 % der Fälle gemeldet, zwei hintereinander in 82 %.
     *
     * > **Eine Haltezeit über einem Zustand, den ein anderer Zeitgeber
     * > herstellt, zählt nicht die Fehlschläge, sondern wie oft der Ablesende
     * > sie antrifft.**
     *
     * **Ohne sie bleibt etwa jede fünfte einzelne ungemeldet, und das zu
     * Recht:** Dort ist die nächste Sicherung gelungen, bevor die Diagnose
     * lief, und es fehlt nichts mehr.
     */
    public const BACKUP_HOLD_MINUTES = 0;

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
     * **`$only` beschränkt den Lauf auf Schlüssel**, leer heisst alle. Die Unit
     * „Platte voll" meldet alle fünf Minuten und nur `disk.space` (`docs/136
     * §5`). Meldete sie alles, ginge ein Befund der Nacht nach zwanzig Stunden
     * hinaus, **bevor** die zweite Nacht ihn bestätigt hat — die Zusage „zwei
     * Nächte hintereinander" aus B1 wäre fort, ohne dass jemand sie angefasst
     * hat.
     *
     * @param  list<FindingCheck>  $only
     * @return array<string, array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}>
     */
    public function send(Carbon $now, array $only = []): array
    {
        $bilanz = [];

        foreach ($this->channels->all() as $channel) {
            $bilanz[$channel->key()] = $this->over($channel, $now, $only);
        }

        return $bilanz;
    }

    /**
     * Wie lange ein Befund dieses Schlüssels stehen muss, bevor er gemeldet wird.
     *
     * **Je Schlüssel und nicht je Lauf**, weil ein Befund seinen Schlüssel
     * trägt und nicht die Unit, die ihn geschrieben hat. Jeder Schlüssel hat
     * genau einen Schreiber (`DiagnoseRunTest`), und damit genau einen Takt.
     */
    public static function holdMinutes(FindingCheck $check): int
    {
        return match ($check) {
            FindingCheck::DiskSpace => self::DISK_HOLD_MINUTES,
            FindingCheck::BackupLatest => self::BACKUP_HOLD_MINUTES,
            default => self::HOLD_HOURS * 60,
        };
    }

    /**
     * Ab wann ein Befund gemeldet wird — `null`, wenn nie.
     *
     * **Eine Stelle für den Lauf und für die Seite.** {@see self::due()} fragt
     * hier, ob ein Befund fällig ist, und die Seite „Diagnose" fragt hier, ab
     * wann. Stünde die Regel zweimal da, zeigte die Seite eine Fälligkeit, nach
     * der der Lauf nicht handelt.
     *
     * `Unknown` wird nicht gemeldet; der Grund steht an {@see self::due()}.
     */
    public static function dueAt(Finding $finding): ?Carbon
    {
        if ($finding->state() === FindingState::Unknown) {
            return null;
        }

        return $finding->first_seen_at->copy()->addMinutes(self::holdMinutes($finding->check));
    }

    /**
     * Ob und wie diese Befunde gemeldet sind — für die Seite „Diagnose".
     *
     * Entschieden hat der Betreiber am 5. Oktober 2026 (`docs/141 §0`
     * Befund 5): Neben jedem Befund steht, über welchen Kanal er wann gemeldet
     * wurde, oder dass er fällig ist und noch nicht zugestellt. Bis dahin gab
     * es nur „zuletzt erfolgreich zugestellt" je Kanal, und welche Meldung das
     * war, sagte es nicht.
     *
     * **`open` nennt nur Kanäle, von denen das Panel weiss, dass sie
     * eingerichtet sind** ({@see Channel::knownUsable()}). Die Seite fragt den
     * Agenten nicht, und beim Webhook wüsste nur er es. Was ein Kanal gebucht
     * hat, steht trotzdem da — eine Buchung ist eine Tatsache und keine
     * Vermutung über die Einrichtung.
     *
     * **Warum etwas nicht ankam, steht hier nicht**, aus dem Grund, der an
     * {@see FindingNotification} steht: Eine Fehlerspalte je Befund und Kanal
     * wäre ein Protokoll, das niemand bestellt hat.
     *
     * @param  iterable<Finding>  $findings  mit geladenen `notifications`
     * @return array<int, array{sent: list<array{channel: string, at: string}>, open: list<string>, due_at: string|null, due: bool}>
     */
    public function deliveries(iterable $findings, Carbon $now): array
    {
        $eingerichtet = [];

        foreach ($this->channels->all() as $channel) {
            $eingerichtet[$channel->key()] = $channel->knownUsable() === true;
        }

        $zeilen = [];

        foreach ($findings as $finding) {
            $gebucht = [];

            foreach ($finding->notifications as $buchung) {
                $gebucht[$buchung->channel] = $buchung->notified_at;
            }

            $ab = self::dueAt($finding);
            $sent = [];
            $open = [];

            foreach ($eingerichtet as $key => $bekannt) {
                if (array_key_exists($key, $gebucht)) {
                    $sent[] = ['channel' => $key, 'at' => (string) Clock::display($gebucht[$key])];
                } elseif ($bekannt && $ab !== null) {
                    // Offen ist nur, was gemeldet werden wird — ein nicht
                    // beurteilter Befund wartet auf keinen Kanal.
                    $open[] = $key;
                }
            }

            $zeilen[$finding->id] = [
                'sent' => $sent,
                'open' => $open,
                'due_at' => Clock::display($ab),
                'due' => $ab !== null && $ab->lte($now),
            ];
        }

        return $zeilen;
    }

    /**
     * Ein Kanal, ein Durchgang.
     *
     * @param  list<FindingCheck>  $only
     * @return array{sent: int, resolved: int, findings: int, without_recipient: int, failed: int, skipped: int}
     */
    private function over(Channel $channel, Carbon $now, array $only): array
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
            $this->resolutions($channel, $only)->delete();
        }

        $faellig = $this->due($channel, $now, $only);

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
            $this->clear($channel, $bilanz, $only);
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
     * @param  list<FindingCheck>  $only
     */
    private function clear(ResolvingChannel $channel, array &$bilanz, array $only): void
    {
        $offen = $this->resolutions($channel, $only)
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
     * **Die Haltezeit hängt am Schlüssel** ({@see self::holdMinutes()}). Die
     * Abfrage nimmt die kürzeste, damit nichts Fälliges fehlt; entschieden wird
     * je Befund danach. **Und die kürzeste kommt aus `holdMinutes()` selbst** —
     * aus zwei Konstanten gerechnet, filterte die Abfrage einen neuen Schlüssel
     * mit noch kürzerer Haltezeit still heraus.
     *
     * @param  list<FindingCheck>  $only
     * @return Collection<int, Finding>
     */
    private function due(Channel $channel, Carbon $now, array $only): Collection
    {
        $kuerzeste = $now->copy()->subMinutes(min(array_map(self::holdMinutes(...), FindingCheck::cases())));

        return Finding::query()
            ->whereDoesntHave('notifications', static fn ($q) => $q->where('channel', $channel->key()))
            ->when($only !== [], static fn ($q) => $q->whereIn('check', array_map(static fn (FindingCheck $c): string => $c->value, $only)))
            ->where('first_seen_at', '<=', $kuerzeste)
            ->orderBy('check')
            ->orderBy('subject')
            ->orderBy('reason')
            ->get()
            ->filter(static fn (Finding $f): bool => self::dueAt($f)?->lte($now) === true)
            ->values();
    }

    /**
     * Die offenen Entwarnungen eines Kanals — auf Schlüssel beschränkt, wenn
     * der Lauf es ist.
     *
     * **Beschränkt wird auch hier und nicht nur bei den Meldungen.** Ein Lauf,
     * der nur `disk.space` meldet, fasst die Entwarnungen der übrigen Schlüssel
     * nicht an. Sonst hinge es am Zufall zweier Zeitgeber, welcher Lauf eine
     * Entwarnung der Nacht verschickt — und die Bilanz der Unit „Platte voll"
     * zählte Entwarnungen über Dienste und Zertifikate.
     *
     * @param  list<FindingCheck>  $only
     * @return Builder<FindingResolution>
     */
    private function resolutions(Channel $channel, array $only): Builder
    {
        return FindingResolution::query()
            ->where('channel', $channel->key())
            ->when($only !== [], static fn ($q) => $q->whereIn('check', array_map(static fn (FindingCheck $c): string => $c->value, $only)));
    }

    /** Wann zuletzt etwas über diesen Kanal angekommen ist — für die Einstellungsseite. */
    public function lastDelivered(string $channel): ?string
    {
        return Clock::displayText($this->settings->noticeSentAt($channel));
    }
}
