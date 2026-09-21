<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FindingCheck;
use App\Models\FindingResolution;
use App\Support\Diagnose\FindingLog;
use App\Support\Notify\WebhookChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FindingResolution>
 */
class FindingResolutionFactory extends Factory
{
    protected $model = FindingResolution::class;

    /**
     * Eine Entwarnung, wie {@see FindingLog::forgetMissing()} sie vormerkt.
     *
     * **Die Vorgabe ist die Abschrift eines echten Befundes** — dieselben
     * Werte, die {@see FindingFactory} baut. Eine erfundene Prüfung mit einem
     * erfundenen Grund ergäbe eine Zeile, deren Satz
     * {@see FindingCheck::sentence()} gar nicht kennt, und der Prüfkörper
     * beschriebe einen Zustand, den es nie gibt.
     *
     * **Der Kanal ist der, der entwarnt.** Eine Zeile für einen Kanal ohne
     * Entwarnung ist im Betrieb der Augenblick vor ihrem Wegräumen; als Vorgabe
     * wäre sie ein Prüfkörper, den der nächste Lauf löscht.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'check' => FindingCheck::UnitSchedule,
            'subject' => 'srvpanel-cron.timer',
            'reason' => 'no_next',
            'channel' => WebhookChannel::CHANNEL,
            'resolved_at' => now(),
        ];
    }
}
