<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Metrics\Collector;
use App\Support\Metrics\Store;
use Illuminate\Support\ServiceProvider;
use SrvPanel\Agent\Client;

final class SrvPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => new Client(
            (string) config('srvpanel.agent.socket'),
            (int) config('srvpanel.agent.timeout'),
        ));

        $this->app->singleton(Store::class, static fn (): Store => new Store(
            (string) config('srvpanel.metrics.directory'),
            (int) config('srvpanel.metrics.retention'),
        ));

        $this->app->bind(Collector::class, static fn ($app): Collector => new Collector(
            $app->make(Client::class),
            $app->make(Store::class),
        ));
    }
}
