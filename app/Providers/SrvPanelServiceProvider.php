<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Metrics\Collector;
use App\Support\Metrics\Store;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\ServiceProvider;
use SrvPanel\Agent\Client;

final class SrvPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ein Mandant je Anfrage, und zwar genau einer.
        //
        // Als Singleton, weil der globale Filter der Modelle ihn über den
        // Container zieht und dabei denselben Zustand sehen muss wie die
        // Middleware, die ihn gesetzt hat. Zwei Instanzen wären eine
        // Mandantenklammer, die an manchen Stellen offen steht.
        $this->app->singleton(Tenancy::class);

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
