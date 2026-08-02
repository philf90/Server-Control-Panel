<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Metrics\Collector;
use App\Support\Metrics\Store;
use CloudSrv\Agent\Client;
use Illuminate\Support\ServiceProvider;

final class CloudSrvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => new Client(
            (string) config('cloudsrv.agent.socket'),
            (int) config('cloudsrv.agent.timeout'),
        ));

        $this->app->singleton(Store::class, static fn (): Store => new Store(
            (string) config('cloudsrv.metrics.directory'),
            (int) config('cloudsrv.metrics.retention'),
        ));

        $this->app->bind(Collector::class, static fn ($app): Collector => new Collector(
            $app->make(Client::class),
            $app->make(Store::class),
        ));
    }
}
