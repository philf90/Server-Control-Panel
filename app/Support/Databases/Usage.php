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
     * @return array{measured: int, available: bool, reason?: string}
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
     * @return array{measured: int, available: bool, reason?: string}
     */
    public function apply(array $result): array
    {
        if (($result['available'] ?? false) !== true) {
            // Kein Wert wird zurückgesetzt: Ohne Antwort weiss das Panel nichts
            // Neues, und „nichts Neues" ist kein Grund, eine Messung von gestern
            // zu verwerfen. Der Zeitstempel daneben sagt, wie alt sie ist.
            return [
                'measured' => 0,
                'available' => false,
                'reason' => (string) ($result['reason'] ?? 'kein Grund genannt'),
            ];
        }

        $sizes = is_array($result['databases'] ?? null) ? $result['databases'] : [];
        $now = now();

        return $this->tenancy->withoutRestriction(function () use ($sizes, $now): array {
            $measured = 0;

            Database::query()->chunkById(200, function ($databases) use ($sizes, $now, &$measured): void {
                foreach ($databases as $database) {
                    $bytes = $sizes[(string) $database->name] ?? null;

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

            return ['measured' => $measured, 'available' => true];
        });
    }
}
