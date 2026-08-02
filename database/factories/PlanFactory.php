<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Paket '.fake()->unique()->word(),
            'description' => null,
            'quotas' => [
                'disk_mb' => 5120,
                'traffic_gb' => 100,
                'domains' => 5,
                'subdomains' => 25,
                'databases' => 5,
                'ftp_accounts' => 5,
                'cron_jobs' => 10,
                'php_versions' => ['8.3', '8.4'],
                'fpm_processes' => 10,
            ],
            'features' => [
                'dns_edit' => true,
                'certificate_upload' => false,
                'backups' => true,
                'php_settings' => true,
            ],
            'is_default' => false,
        ];
    }
}
