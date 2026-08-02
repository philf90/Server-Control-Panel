<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Kennzahlen\Sammler;
use App\Support\Kennzahlen\Speicher;
use CloudSrv\Agent\Client;
use Illuminate\Support\ServiceProvider;

final class CloudSrvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => new Client(
            (string) config('cloudsrv.agent.socket'),
            (int) config('cloudsrv.agent.zeitlimit'),
        ));

        $this->app->singleton(Speicher::class, static fn (): Speicher => new Speicher(
            (string) config('cloudsrv.kennzahlen.verzeichnis'),
            (int) config('cloudsrv.kennzahlen.vorhalt'),
        ));

        $this->app->bind(Sammler::class, static fn ($app): Sammler => new Sammler(
            $app->make(Client::class),
            $app->make(Speicher::class),
        ));
    }
}
