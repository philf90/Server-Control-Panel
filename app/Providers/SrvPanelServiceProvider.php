<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Authorization\AdminAbility;
use App\Support\Diagnose\Catalog as DiagnoseCatalog;
use App\Support\Diagnose\Check as DiagnoseCheck;
use App\Support\Diagnose\FindingLog;
use App\Support\Diagnose\Host as DiagnoseHost;
use App\Support\Diagnose\LocalHost;
use App\Support\Diagnose\Run as DiagnoseRun;
use App\Support\Diagnose\RunLog as DiagnoseRunLog;
use App\Support\Diagnose\SettingsRunLog;
use App\Support\Diagnose\TlsWire;
use App\Support\Diagnose\Wire as DiagnoseWire;
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
use Illuminate\Support\Facades\Route;
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

        /*
         * **Der Nachtlauf der Bestandsdiagnose** (A10 Schritt 6). Die Liste der
         * Prüfungen steht in {@see DiagnoseCatalog} und nicht hier: Sie ist eine
         * fachliche Aufzählung und keine Verdrahtung, und die Seite aus Schritt 7
         * liest dieselbe.
         */
        /*
         * **Die drei Nähte der Diagnose.**
         *
         * Sie sind da, weil sich die echten Klassen in keinem Test ersetzen
         * lassen: `LocalHost` fragt das Dateisystem, `TlsWire` öffnet eine
         * Verbindung, `Settings` ist `final`. Jede davon ist eine Schnittstelle,
         * und **eine Schnittstelle ohne Bindung ist im Container nicht
         * baubar** — der Lauf stirbt dann an der ersten Prüfung, die sie
         * braucht.
         *
         * **Zwei davon haben genau das getan** (`docs/99`, Punkt 1, auf
         * `cloudsrv24` gegen 0.7.3-rc.11): `Wire` kam aus Schritt 5 und wurde
         * nie gebunden, `Host` ebenso; gebunden war nur `RunLog` aus Schritt 7,
         * weil es zuletzt entstand. Der erste Lauf brach mit
         * „Target [Wire] is not instantiable" ab, bevor er etwas gemessen hat.
         *
         * > **Eine Naht, die niemand verdrahtet, ist keine Naht — sie ist ein
         * > Loch, das erst der erste echte Lauf findet.**
         *
         * Gesehen hat es kein Test, weil jeder die Prüfungen selbst
         * zusammensetzt und keiner sie über den Container baut.
         * `DiagnoseWiringTest` tut jetzt genau das.
         */
        $this->app->bind(DiagnoseHost::class, LocalHost::class);
        $this->app->bind(DiagnoseWire::class, TlsWire::class);
        $this->app->bind(DiagnoseRunLog::class, SettingsRunLog::class);

        $this->app->bind(DiagnoseRun::class, static fn ($app): DiagnoseRun => new DiagnoseRun(
            array_map(static fn (string $check): DiagnoseCheck => $app->make($check), DiagnoseCatalog::CHECKS),
            $app->make(FindingLog::class),
            $app->make(DiagnoseRunLog::class),
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
         *
         * **Seit dem 24. August lösen sie über die Rolle auf** (A9 Schritt 2,
         * `docs/82 §2.2`). Davor stand hier `$account->isAdmin()` für beide —
         * die Naht war gelegt, die Unterscheidung aber wirkungslos, weil es nur
         * eine Rolle gab.
         *
         * **Geändert hat sich genau diese eine Zeile.** Keine Aufrufstelle in
         * `routes/web.php`, kein Schlüssel in einer `can`-Ablage, kein Bild —
         * und das war der Zweck, die Fähigkeiten zwei Tage vor den Rollen zu
         * trennen.
         *
         * Gefragt wird {@see Account::fulfils()} und nicht die Rolle
         * unmittelbar: Dort steht, dass **beide** Achsen zählen — die Ebene und
         * die Rolle. Ein Kundenkonto, das durch einen Fehler `operator` trüge,
         * kommt damit nicht durch, und ein Adminkonto ohne Rolle auch nicht.
         *
         * Die Gates entstehen aus der Registratur und nicht daneben: Eine
         * Fähigkeit, die dort nicht steht, gibt es nicht.
         */
        foreach (AdminAbility::abilities() as $ability => $declaration) {
            Gate::define(
                $ability,
                static fn (Account $account): bool => $account->fulfils($declaration['role']),
            );
        }

        /*
         * `{admin}` in einer Route ist ein **Adminkonto** und sonst nichts.
         *
         * **Die zweite Falle aus `docs/82 §3` sagt, warum das hier steht und
         * nicht im Controller:**
         *
         * > Die Prüfung gehört an **dieselbe** Stelle wie die Rolle und nicht
         * > an eine zweite daneben, sonst ist sie beim nächsten Weg zum Konto
         * > nicht dabei.
         *
         * `Account` trägt keine Mandantenklammer — die Klammer gilt für
         * Abonnements und was daran hängt, nicht für Anmeldekonten. Eine
         * gewöhnliche Bindung fände damit **jedes** Konto, und
         * `/accounts/{id}/edit` mit der Kennung eines Kundenkontos wäre ein
         * Formular, das dessen Rolle setzt. Vier Methoden mit derselben
         * Vorprüfung wären dieselbe Regel an vier Stellen; die fünfte hätte sie
         * nicht.
         *
         * **404 und nicht 403:** Ob es dieses Konto gibt, ist selbst eine
         * Auskunft. Für einen Betreiber ändert das nichts — er sieht die Liste
         * ohnehin.
         */
        Route::bind('admin', static fn (string $value): Account => Account::query()
            ->where('type', AccountType::Admin)
            ->findOrFail($value));

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
