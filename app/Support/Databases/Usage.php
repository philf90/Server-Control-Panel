<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Models\Database;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\Client;

/**
 * Den belegten Platz der Datenbanken messen und an die Zeilen schreiben.
 *
 * **Der Zwilling von {@see \App\Support\Subscriptions\Usage}**, und absichtlich
 * bis in die Methodennamen: eine Messung für alle, kein Vorgang, ohne
 * Mandantenklammer. Wer den einen liest, hat den anderen verstanden.
 *
 * **Die Summe je Abonnement wird nicht abgelegt.** Sie steht als
 * `SUM(size_mb)` über den Datenbanken des Abonnements — und das ist kein
 * Sparen, sondern eine Entscheidung gegen einen zweiten Wahrheitsort: Eine
 * abgelegte Summe geht auseinander, sobald eine Datenbank entfernt wird, ohne
 * dass jemand nachrechnet. Genau diese Sorte Abweichung findet niemand, weil
 * beide Zahlen für sich plausibel aussehen.
 *
 * `disk_used_mb` am Abonnement ist der Gegenfall und bleibt abgelegt: Dort gibt
 * es keine Zeilen, über die man summieren könnte — die Zahl kommt als eine
 * Zahl vom Dateisystem.
 */
final class Usage
{
    public function __construct(
        private readonly Client $agent,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Einmal messen und schreiben.
     *
     * @return array{measured: int, reported: int, matched: int, available: bool, reason?: string}
     */
    public function measure(): array
    {
        return $this->apply($this->agent->call('db.usage'));
    }

    /**
     * Die Antwort des Agenten an die Datenbanken schreiben.
     *
     * Getrennt vom Holen, aus dem Grund, der beim Abonnement schon steht: Was
     * hier passiert, ist Zuordnen — welches Schema gehört zu welcher Zeile, was
     * geschieht mit einem Schema, das das Panel nicht kennt, was mit einer
     * Zeile, die in der Messung fehlt. Hinter einem Socket wäre das nur mit
     * laufendem MariaDB zu prüfen.
     *
     * @param  array<string, mixed>  $result
     * @return array{measured: int, reported: int, matched: int, available: bool, reason?: string}
     */
    public function apply(array $result): array
    {
        if (($result['available'] ?? false) !== true) {
            // Kein Wert wird zurückgesetzt: Ohne Antwort weiss das Panel nichts
            // Neues, und „nichts Neues" ist kein Grund, eine Messung von gestern
            // zu verwerfen. Der Zeitstempel daneben sagt, wie alt sie ist.
            return [
                'measured' => 0,
                'reported' => 0,
                'matched' => 0,
                'available' => false,
                'reason' => (string) ($result['reason'] ?? 'kein Grund genannt'),
            ];
        }

        $sizes = is_array($result['databases'] ?? null) ? $result['databases'] : [];
        $now = now();

        return $this->tenancy->withoutRestriction(function () use ($sizes, $now): array {
            $measured = 0;
            $matched = 0;

            Database::query()->chunkById(200, function ($databases) use ($sizes, $now, &$measured, &$matched): void {
                foreach ($databases as $database) {
                    $bytes = $sizes[(string) $database->name] ?? null;

                    if ($bytes !== null) {
                        $matched++;
                    }

                    /*
                     * **Ein Schema ohne Eintrag ist eine gemessene Null.**
                     * `information_schema` führt ein Schema erst auf, wenn es
                     * eine Tabelle hat — eine frisch angelegte, leere Datenbank
                     * steht dort nicht. Das ist kein Messfehler, sondern die
                     * richtige Antwort: Sie belegt nichts.
                     *
                     * Der Unterschied zu `null` bleibt trotzdem erhalten, denn
                     * `size_measured_at` wird mitgeschrieben — „noch nie
                     * gemessen" ist der Zustand *vor* dem ersten Lauf und nicht
                     * der einer leeren Datenbank.
                     */
                    $database->forceFill([
                        'size_mb' => intdiv(max(0, (int) ($bytes ?? 0)), 1024 * 1024),
                        'size_measured_at' => $now,
                    ])->saveQuietly();

                    $measured++;
                }
            });

            /*
             * **Drei Zahlen und nicht eine, und der Anlass ist ein grüner Lauf.**
             * Bis zum 8. August 2026 stand hier nur `measured` — die Zahl der
             * *geschriebenen* Zeilen. Der Abnahmelauf meldete „2 Datenbank(en)
             * gemessen", und genau dieselbe Zeile wäre erschienen, wenn die
             * Abfrage **gar nichts** geliefert hätte: Eine Datenbank ohne
             * Treffer bekommt `size_mb = 0` als gemessene Null, und das ist
             * richtig — aber es macht die Zahl als Beleg wertlos.
             *
             * `reported` ist, was der Server genannt hat; `matched`, wie viel
             * davon einer Zeile des Panels zuzuordnen war. Weichen sie
             * auseinander, sieht man es. Vorher war ein Tippfehler in der
             * Abfrage von einem erfolgreichen Lauf nicht zu unterscheiden.
             *
             * Wortwörtlich derselbe Fehler wie bei `refused` im
             * Abnahmelauf desselben Tages — eine Zahl über dem, was wir getan
             * haben, statt über dem, was geschehen ist.
             */
            return [
                'measured' => $measured,
                'reported' => count($sizes),
                'matched' => $matched,
                'available' => true,
            ];
        });
    }
}
