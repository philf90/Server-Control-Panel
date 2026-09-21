<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\DailyMetric;
use App\Models\Domain;
use App\Models\DomainMetric;
use App\Models\Subscription;
use App\Models\SubscriptionMetric;
use App\Support\Cron\ServerZone;
use App\Support\Plans\Quota;
use App\Support\Web\AccessCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Die Verläufe für die Abonnement- und die Domainseite (B4, `docs/129 §6`).
 *
 * Was {@see Daily} Nacht für Nacht ablegt, wird hier zu Kacheln — derselben
 * Kachel wie auf der Übersichtsseite, mit derselben Geometrie aus
 * {@see Points} und einer anderen Zeitachse: dort 24 Stunden aus dem
 * Ringpuffer, hier dreissig Tage aus der Tabelle.
 *
 * ## Eine Abfrage und nicht acht
 *
 * Gemessen (`docs/128` M4): 150 Punkte kosten als **eine** Abfrage 0,0006 s,
 * als fünf 0,0040 s. Beides ist billig, und die eine ist trotzdem die
 * richtige — sie wächst nicht mit der Zahl der Kacheln. Die Tabelle ist lang
 * und nicht breit; alle Kennzahlen eines Abonnements stehen als Zeilen
 * untereinander und kommen mit einem `where` heraus.
 *
 * ## Was diese Klasse nicht sagt
 *
 * **Die x-Achse ist der Index und nicht das Datum.** Fällt eine Nacht aus,
 * rücken die Nachbartage zusammen, und die Lücke ist im Bild nicht zu sehen —
 * die Kurve behauptet dann eine Nachbarschaft, die es nicht gab. Lesbar ist
 * sie trotzdem: Jede Stützstelle trägt ihren Tag, und die Ablesung nennt ihn.
 *
 * > **Eine Kurve, deren x-Achse der Index ist, zeigt eine Lücke nicht — sie
 * > zeigt sie als Nachbarschaft.**
 *
 * **Und sie deckt sich nicht mit der Zahl des Providers.** Gezählt wird, was
 * nginx protokolliert; TCP, TLS und Wiederholungen zählt er nicht mit
 * (`docs/129 §11`). Das gehört neben die Zahl auf der Seite und nicht in eine
 * Fussnote.
 *
 * ## Die fünfte Kachel ist nicht die fünfte des Plans
 *
 * `docs/129 §6` nennt fünf Kennzahlen je Abonnement: Speicherplatz, Traffic,
 * Zugriffe, Datenbankgrössen und **FPM-Prozesse**. Die letzte ist auf keiner
 * Maschine dieses Projekts gemessen — {@see DailyMetric::ofASubscription()}
 * sagt, warum sie deshalb nicht in der Tabelle steht. Hier stehen stattdessen
 * fünf Kacheln, von denen die fünfte die **Fehlerquote** ist: Sie ist aus
 * Zahlen gerechnet, die es gibt, und beantwortet auf der Abonnementseite
 * dieselbe Frage wie auf der Domainseite, nur über alle Domains zusammen.
 *
 * > **Ein Handgriff, der einen Zähler auf null bringt, hat den Zähler bedient
 * > und nicht den Gegenstand.** Fünf Kacheln stehen da, und es sind nicht die
 * > fünf des Plans; wer die FPM-Prozesse will, misst sie zuerst.
 */
final class History
{
    /**
     * Wie weit zurück gelesen wird — dieselbe Zahl, die {@see Daily} aufhebt.
     *
     * Eine eigene Konstante wäre die zweite Fassung derselben Entscheidung:
     * Stünde hier 60, zeigte die Seite dreissig Tage und behauptete sechzig.
     */
    public const DAYS = Daily::RETENTION_DAYS;

    /**
     * Die fünf Kacheln eines Abonnements.
     *
     * **Ohne Uhr, und das ist Absicht.** Das Fenster liegt an den Tagen, die
     * **dastehen**, und nicht an „heute": Welcher Tag gerade läuft, ist eine
     * Frage an die Zone des Servers, die hier niemand stellen muss — die
     * Tabelle enthält ohnehin nur abgeschlossene Tage, weil
     * {@see AccessCounts::split()} den laufenden als offen
     * aussortiert. Eine zweite Stelle, die nach der Serverzone fragt, wäre die
     * zweite Fassung von {@see ServerZone} — und genau die
     * hat dieses Panel ein Jahr lang eine falsche Uhrzeit anzeigen lassen
     * (`docs/108`).
     *
     * @return list<array<string,mixed>>
     */
    public function forSubscription(Subscription $subscription): array
    {
        $rows = $this->read(SubscriptionMetric::query()->where('subscription_id', (int) $subscription->id));

        return [
            $this->level($rows, DailyMetric::DiskMb, 'disk', 'Speicherplatz', 'belegt',
                $this->limit($subscription, Quota::DiskMb)),
            $this->traffic($rows),
            $this->requests($rows),
            $this->errorRate($rows),
            $this->level($rows, DailyMetric::DatabaseBytes, 'databases', 'Datenbanken', 'belegt',
                $this->limit($subscription, Quota::DatabaseMb), bytes: true),
        ];
    }

    /**
     * Und die drei einer Domain — Traffic, Zugriffe, Fehlerquote
     * (`docs/129 §6`).
     *
     * Platz und Datenbanken fehlen hier, weil sie dem Abonnement gehören und
     * nicht einer seiner Domains; {@see DailyMetric::ofADomain()} legt sie
     * deshalb gar nicht erst ab.
     *
     * @return list<array<string,mixed>>
     */
    public function forDomain(Domain $domain): array
    {
        $rows = $this->read(DomainMetric::query()->where('domain_id', (int) $domain->id));

        return [$this->traffic($rows), $this->requests($rows), $this->errorRate($rows)];
    }

    /**
     * Die eine Abfrage — und was sie zurückgibt, ist nach Kennzahl und Tag
     * sortiert abgelegt.
     *
     * **Die Mandantenklammer bleibt dran.** Beide Modelle tragen
     * `BelongsToSubscription`; ein Kunde bekommt hier die Zeilen seines
     * Abonnements und sonst keine, und zwar ohne dass diese Klasse etwas dafür
     * tut. Ein `withoutRestriction()` stünde hier falsch: Der Nachtlauf
     * braucht es, weil er ohne Konto läuft — eine Seite hat immer eines.
     *
     * **Gelesen wird alles und angezeigt werden die letzten dreissig Tage.**
     * Das Abräumen in {@see Daily::forget()} hält die Tabelle klein, und
     * trotzdem schneidet diese Stelle noch einmal zu: Sonst hinge die Zusage
     * der Seite daran, dass der Nachtlauf läuft — und eine Seite, die
     * neunzig Tage zeigt und dreissig behauptet, ist von einer heilen nicht zu
     * unterscheiden.
     *
     * > **Eine Grenze, die nur ein anderer Lauf herstellt, ist keine Zusage
     * > dieser Seite.**
     *
     * @param  Builder<SubscriptionMetric>|Builder<DomainMetric>  $query
     * @return array<string, array<string, float>>
     */
    private function read(Builder $query): array
    {
        $alle = [];
        $tage = [];

        foreach ($query->orderBy('day')->get() as $row) {
            /*
             * `day` ist ein Kalendertag in der Zone des Servers und kein
             * Zeitpunkt in UTC — {@see \App\Support\Time\Clock} ist hier
             * deshalb die falsche Stelle. Sie rechnet um, und eine Umrechnung
             * schöbe eine Zeile über die Tagesgrenze.
             */
            $tag = $row->day->toDateString();

            $alle[$row->metric->value][$tag] = (float) $row->value;
            $tage[$tag] = true;
        }

        $tage = array_keys($tage);
        sort($tage);
        $behalten = array_flip(array_slice($tage, -self::DAYS));

        $out = [];

        foreach ($alle as $metric => $werte) {
            $out[$metric] = array_intersect_key($werte, $behalten);
        }

        return $out;
    }

    /**
     * Eine gemeinsame Achse über mehrere Kennzahlen **einer** Kachel.
     *
     * **Warum die Achse je Kachel entsteht und nicht je Seite.** Zwei Kacheln
     * dürfen verschieden viele Tage haben — die eine Kennzahl gibt es seit
     * gestern, die andere seit einem Monat. Innerhalb *einer* Kachel geht das
     * nicht: Die beiden Richtungen des Verkehrs teilen sich eine Achse, und
     * die Fehlerquote braucht Zähler und Nenner am selben Tag.
     *
     * Ein Tag, den eine der Kennzahlen nicht hat, zählt als **0** — und das
     * ist hier vertretbar, weil die Kennzahlen einer Kachel von **einem** Lauf
     * geschrieben werden: Fehlte eine, wäre das ein Befund und keine Lücke,
     * und eine Null macht ihn sichtbar statt ihn zu verstecken.
     *
     * @param  array<string, array<string, float>>  $rows
     * @param  list<DailyMetric>  $metrics
     * @return array{labels: list<string>, values: array<string, list<float>>}
     */
    private function axis(array $rows, array $metrics): array
    {
        $days = [];

        foreach ($metrics as $metric) {
            foreach (array_keys($rows[$metric->value] ?? []) as $day) {
                $days[$day] = true;
            }
        }

        $days = array_keys($days);
        sort($days);

        $values = [];

        foreach ($metrics as $metric) {
            $values[$metric->value] = array_map(
                static fn (string $day): float => $rows[$metric->value][$day] ?? 0.0,
                $days,
            );
        }

        // „21.09." und nicht „2026-09-21": In der Ablesung einer Kachel stehen
        // bei 1440 px rund 25 Zeichen, und das Jahr ist über dreissig Tage
        // dreissigmal dasselbe.
        return [
            'labels' => array_map(static fn (string $day): string => Carbon::parse($day)->format('d.m.'), $days),
            'values' => $values,
        ];
    }

    /**
     * Eine Kachel über einen **Stand** — belegter Platz, belegte Datenbanken.
     *
     * Ein Stand darf gegen sein Kontingent gemessen werden: Beide sind
     * derselbe Gegenstand zum selben Zeitpunkt. Für einen **Fluss** gilt das
     * nicht, siehe {@see self::traffic()}.
     *
     * @param  array<string, array<string, float>>  $rows
     * @return array<string,mixed>
     */
    private function level(array $rows, DailyMetric $metric, string $key, string $label, string $subline, ?float $limitMb, bool $bytes = false): array
    {
        $achse = $this->axis($rows, [$metric]);
        $werte = $achse['values'][$metric->value];

        // Die Datenbanken liegen in Byte in der Tabelle und stehen auf dieser
        // Seite seit P5 in MB — die Kachel richtet sich nach der Zahl, die
        // zwei Zeilen über ihr steht, und nicht nach der Ablage.
        if ($bytes) {
            $werte = array_map(static fn (float $v): float => $v / 1_048_576.0, $werte);
        }

        $reihe = Points::build(
            $werte,
            $achse['labels'],
            $werte === [] ? 0.0 : min($werte),
            $werte === [] ? 0.0 : max($werte),
            Points::plainFormatter(' MB', 0),
            ' MB',
            $limitMb,
        );

        return [
            'key' => $key,
            'label' => $label,
            'value' => Points::latest($reihe, '—'),
            'unit' => 'MB',
            'subline' => $subline,
            'series' => $reihe,
        ];
    }

    /**
     * Die Verkehrskachel — beide Richtungen, eine Achse.
     *
     * **Ausgehend steht oben.** Auf einem Webserver ist es die Richtung, die
     * zuerst an die Grenze stösst: Eine Seite auszuliefern kostet ein
     * Vielfaches dessen, was ihre Anforderung kostet. Dieselbe Begründung wie
     * bei der Netzkachel der Übersicht, nur ist dort die ruhigere Richtung
     * oben gelandet, bis sie jemand gemessen hat.
     *
     * **Ohne Schwelle, und das ist eine Angabe.** Der Katalog führt
     * {@see Quota::TrafficGb} — aber das ist eine Menge je **Monat**, und
     * diese Kurve zeigt Tage. Eine Tageszahl gegen ein Monatskontingent zu
     * halten hiesse, dreissigmal zu früh zu warnen.
     *
     * > **Eine Schwelle, die eine andere Grösse misst als die Kurve, ist
     * > keine.**
     *
     * @param  array<string, array<string, float>>  $rows
     * @return array<string,mixed>
     */
    private function traffic(array $rows): array
    {
        $achse = $this->axis($rows, [DailyMetric::TrafficSentBytes, DailyMetric::TrafficReceivedBytes]);
        $raus = $achse['values'][DailyMetric::TrafficSentBytes->value];
        $rein = $achse['values'][DailyMetric::TrafficReceivedBytes->value];

        /*
         * Eine gemeinsame Spanne über beide Richtungen — sonst füllt jede die
         * 24 Einheiten der Kachel aus, und der tausendfach kleinere eingehende
         * Verkehr läge gleich hoch wie der ausgehende. `Store::pair()` nennt
         * denselben Grund für die Netzkachel; hier steht er noch einmal, weil
         * die Quelle eine andere ist und der Fehler derselbe wäre.
         */
        $min = $raus === [] ? 0.0 : min(min($raus), min($rein));
        $max = $raus === [] ? 0.0 : max(max($raus), max($rein));

        $eine = static fn (array $werte): array => Points::build(
            $werte,
            $achse['labels'],
            $min,
            $max,
            Points::bytesFormatter($werte === [] ? 0.0 : max($werte), ''),
            Points::bytesUnit($werte === [] ? 0.0 : max($werte), '')[1],
            null,
        );

        $ausgehend = $eine($raus);
        $eingehend = $eine($rein);

        return [
            'key' => 'traffic',
            'label' => 'Traffic',
            'value' => Points::latest($ausgehend, '—'),
            'unit' => $ausgehend['unit'],
            'subline' => 'ausgehend',
            'series' => $ausgehend,
            'second' => [
                'label' => 'eingehend',
                'value' => Points::latest($eingehend, '—'),
                'unit' => $eingehend['unit'],
                'series' => $eingehend,
            ],
        ];
    }

    /**
     * Wie viele Anfragen — eine Anzahl und keine Besucher.
     *
     * `docs/129 §11` sagt es ausdrücklich: Gezählt werden Anfragen und Bytes.
     * Besucher, Sitzungen und Herkunft wären ein eigenes Merkmal, und ein
     * Wort wie „Besuche" an dieser Stelle behauptete sie.
     *
     * @param  array<string, array<string, float>>  $rows
     * @return array<string,mixed>
     */
    private function requests(array $rows): array
    {
        $achse = $this->axis($rows, [DailyMetric::Requests]);
        $werte = $achse['values'][DailyMetric::Requests->value];

        $reihe = Points::build(
            $werte,
            $achse['labels'],
            $werte === [] ? 0.0 : min($werte),
            $werte === [] ? 0.0 : max($werte),
            Points::plainFormatter('', 0),
            '',
            null,
        );

        return [
            'key' => 'requests',
            'label' => 'Zugriffe',
            'value' => Points::latest($reihe, '—'),
            'unit' => '',
            'subline' => 'Anfragen',
            'series' => $reihe,
        ];
    }

    /**
     * Die Fehlerquote — gerechnet und nicht abgelegt.
     *
     * {@see DailyMetric::Errors} begründet, warum die Tabelle zwei ganze
     * Zahlen führt statt einer Quote: Zwei abgelegte Zahlen, aus denen sich
     * die dritte ergibt, sind besser als drei, von denen eine veralten kann.
     *
     * **Ein Tag ohne Anfragen hat keine Quote und nicht die Quote null.** Null
     * Fehler aus null Anfragen ist keine Auskunft über die Gesundheit einer
     * Website — hier steht trotzdem 0, weil die Kurve eine Zahl braucht, und
     * die Ablesung nennt den Tag dazu. Eine Lücke ist an dieser x-Achse
     * ohnehin nicht darstellbar (siehe den Kopf dieser Klasse).
     *
     * @param  array<string, array<string, float>>  $rows
     * @return array<string,mixed>
     */
    private function errorRate(array $rows): array
    {
        $achse = $this->axis($rows, [DailyMetric::Requests, DailyMetric::Errors]);
        $anfragen = $achse['values'][DailyMetric::Requests->value];
        $fehler = $achse['values'][DailyMetric::Errors->value];

        $werte = [];

        foreach ($anfragen as $i => $anzahl) {
            $werte[] = $anzahl > 0.0 ? ($fehler[$i] ?? 0.0) / $anzahl * 100.0 : 0.0;
        }

        $reihe = Points::build(
            $werte,
            $achse['labels'],
            0.0,
            $werte === [] ? 0.0 : max($werte),
            Points::plainFormatter(' %', 0),
            ' %',
            null,
        );

        return [
            'key' => 'errors',
            'label' => 'Fehlerquote',
            'value' => Points::latest($reihe, '—'),
            'unit' => '%',
            'subline' => '4xx und 5xx',
            'series' => $reihe,
        ];
    }

    /**
     * Das Kontingent als Schwelle — oder `null`, wenn es keines gibt.
     *
     * `null` heisst „für diese Kennzahl gibt es auf diesem Plan keine Grenze"
     * und ist eine Angabe, keine Nachlässigkeit; {@see Store::series()} nennt
     * denselben Unterschied.
     */
    private function limit(Subscription $subscription, Quota $quota): ?float
    {
        $wert = $subscription->quota($quota->value);

        return is_numeric($wert) ? (float) $wert : null;
    }
}
