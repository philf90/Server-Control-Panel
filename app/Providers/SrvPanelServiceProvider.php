<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Account;
use App\Support\Dns\AgentMeasurement;
use App\Support\Dns\Measurement;
use App\Support\Metrics\Collector;
use App\Support\Metrics\Store;
use App\Support\Settings\MailConfiguration;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AgentDnsCredentials;
use App\Support\Tls\DnsCredentials;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Gate;
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

        // Welche DNS-Profile hinterlegt sind, weiss der Agent — als Singleton,
        // damit eine Domainseite ihn einmal fragt und nicht je Zeile.
        $this->app->singleton(DnsCredentials::class, AgentDnsCredentials::class);

        /*
         * **P7: der Abgleich misst über den Agenten.** {@see Survey} kennt die
         * Reihenfolge des Merkmals und keine Steckdose; welche Umsetzung
         * misst, wird hier entschieden. Genau dieselbe Naht wie bei
         * `DnsCredentials` darüber — und der Grund ist derselbe: Ohne sie
         * liesse sich der Fall „diese eine Zone schweigt, die daneben nicht"
         * nirgends herstellen.
         */
        $this->app->singleton(Measurement::class, AgentMeasurement::class);

        // Als Singleton, damit die Einstellungen je Anfrage einmal gelesen
        // werden und nicht einmal je Aufrufer.
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        /*
         * Einstellungen des Betreibers sind Betreibersache.
         *
         * Als Fähigkeit und nicht als Policy: Eine Policy gehört zu einem
         * Modell, und hier gibt es keines — die Mailzugangsdaten sind eine
         * Zeile in einer Tabelle, aber niemand „besitzt" sie. Die mechanische
         * Routenprüfung nimmt `can:` in beiden Formen an; ohne diese Zeile
         * fiele die Route dort durch.
         */
        Gate::define('manage-settings', static fn (Account $account): bool => $account->isAdmin());

        /*
         * Die Mailkonfiguration entsteht erst, wenn wirklich eine Mail
         * verschickt wird.
         *
         * `resolving` läuft, sobald jemand den MailManager zum ersten Mal aus
         * dem Container holt — und das tut nur, wer eine Mail verschickt. Der
         * naheliegende Weg wäre gewesen, die Einstellungen hier in `boot()` zu
         * lesen: Das wäre eine Datenbankabfrage bei jedem Seitenaufruf, jedem
         * Artisan-Kommando und jedem Testlauf, für etwas, das ein Panel ein
         * paar Mal am Tag braucht.
         */
        $this->app->resolving(MailManager::class, function (): void {
            MailConfiguration::apply($this->app->make(Settings::class), $this->app->make('config'));
        });
    }
}
