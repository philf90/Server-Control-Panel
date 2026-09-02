<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Support\Diagnose\FindingLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    protected $model = Finding::class;

    /**
     * Ein Befund, wie ihn ein Lauf schreiben würde.
     *
     * **Die Vorgabe ist ein echter Grund und kein erfundener.** `FindingCheck`
     * wirft für einen Grund, den die Prüfung nicht kennt ({@see FindingLog}
     * fragt ihn vor dem Schreiben), und eine Factory mit einem ausgedachten
     * Wert baute Zeilen, die es so nie gibt — genau der Fehler, gegen den es
     * `FactoryDefaultTest` gibt.
     *
     * **`first_seen_at` liegt bewusst vor `measured_at`.** So sieht eine Zeile
     * nach dem zweiten Lauf aus, und das ist der Normalfall: Der erste Lauf ist
     * der seltene. Wer beide gleichsetzt, baut den Zustand, in dem „steht seit"
     * nichts aussagt, und merkt nicht, wenn die Anzeige ihn falsch rendert.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'check' => FindingCheck::UnitSchedule,
            'subject' => 'srvpanel-cron.timer',
            'reason' => 'no_next',
            'detail' => null,
            'first_seen_at' => now()->subDays(3),
            'measured_at' => now(),
        ];
    }

    /**
     * Einer, dessen Prüfung gar nicht gelaufen ist.
     *
     * Der Zustand, den `docs/98 §2` von den anderen dreien trennt: Über den
     * Gegenstand ist nichts gesagt — auch keine Entwarnung.
     */
    public function unreachable(): self
    {
        return $this->state(fn (): array => [
            'reason' => FindingCheck::UNREACHABLE,
            'detail' => null,
        ]);
    }

    /**
     * Einer mit dem Wortlaut eines Werkzeugs.
     *
     * Der Prüfkörper ist eine echte nginx-Zeile — sie trägt Datum **und**
     * Prozessnummer (`docs/81 §2.3o` M9) und ist damit genau das, was nicht in
     * die Kennung gehört.
     */
    public function withDetail(): self
    {
        return $this->state(fn (): array => [
            'check' => FindingCheck::WebConfig,
            'subject' => '/etc/nginx/nginx.conf',
            'reason' => 'invalid',
            'detail' => '2026/09/02 03:00:11 [emerg] 8896#8896: unexpected end of file, expecting "}" in /etc/nginx/srvpanel.d/kunde.conf:6',
        ]);
    }
}
