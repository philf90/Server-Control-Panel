<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\SubscriptionStatus;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\WebLifecycle;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Die nginx-Blöcke neu schreiben, so wie die Vorlage sie heute meint.
 *
 * **Warum es dieses Kommando gibt — die Lücke, die es schliesst.** Die Vorlage
 * lebt im Agenten, die ausgelieferte Datei ist eine Kopie davon, und nach einem
 * Update bringt sie niemand nach: `panel.vhost.apply` ruft ausschliesslich
 * `srvpanel setup`, und `srvpanel update` fasst nginx nicht an. Auf einem
 * Server, der einmal eingerichtet wurde, steht deshalb der Block von damals —
 * beliebig alt.
 *
 * **Aufgefallen ist das an der teuersten Stelle.** P4 hat der Oberfläche einen
 * Block auf Port 80 gegeben, damit sie die Prüfung von ACME beantworten kann.
 * Auf dem Zielserver kam die Bestellung trotzdem nicht durch: 404. Der neue
 * Block stand im Code und nicht in `/etc/nginx`, die Anfrage fand keinen
 * passenden `server_name` und landete beim Vorgabeserver auf Port 80 — einem
 * Kundenblock aus P3, der die Prüfadresse nicht kennt. Kein Fehler, keine
 * Meldung, nur eine Zahl.
 *
 * Es ist dasselbe Muster wie überall in diesem Projekt: eine Kopie, die
 * niemand nachzieht. Deshalb ruft das postinstall-Skript dieses Kommando nach
 * jedem Umschalten — was in der Vorlage steht, steht danach auch auf der
 * Platte.
 *
 * **Seit dem 25. September 2026 gilt das auch für die Rotationsdateien der
 * Abonnements** — dieselbe Kopie, nur eine Datei weiter. Die Einzelheiten
 * stehen bei {@see self::rotations()}.
 */
final class ApplyVhost extends Command
{
    protected $signature = 'srvpanel:vhost
        {--sites : Auch die Server-Blöcke der Kundendomains neu schreiben}';

    protected $description = 'Schreibt den Server-Block der Oberfläche und die Rotationsdateien neu — mit --sites auch die Server-Blöcke der Kundendomains';

    public function handle(Client $agent, Tenancy $tenancy, WebLifecycle $web): int
    {
        $panel = $this->panelBlock($agent);

        $this->rotations($tenancy, $web);

        // **Auch dann, wenn der Block der Oberfläche gescheitert ist.** Wer
        // beides verlangt hat, verliert sonst das zweite wegen des ersten —
        // und die beiden hängen nicht zusammen: Die Kundenblöcke gehen über
        // die Warteschlange und nicht über diesen Aufruf.
        if ($this->option('sites') === true) {
            $this->sites($tenancy, $web);
        }

        return $panel ? self::SUCCESS : self::FAILURE;
    }

    /** Der Server-Block der Oberfläche — geschrieben oder begründet nicht. */
    private function panelBlock(Client $agent): bool
    {
        try {
            // Ohne Portangabe: `panel.vhost.apply` liest ihn aus dem Block,
            // der dasteht. Ein Aufruf, der 8443 annähme, verschöbe das Panel
            // jedes Betreibers, der etwas anderes gewählt hat.
            $vhost = $agent->call('panel.vhost.apply', [], ['source' => 'cli', 'command' => 'srvpanel:vhost']);
        } catch (AgentException $error) {
            $this->error('Server-Block der Oberfläche: '.$error->getMessage());

            return false;
        }

        $this->info(sprintf(
            'Server-Block der Oberfläche geschrieben: %s (Port %d, %s).',
            is_string($vhost['path'] ?? null) ? $vhost['path'] : '?',
            is_int($vhost['port'] ?? null) ? $vhost['port'] : 0,
            ($vhost['acme'] ?? false) === true ? 'Zertifikat von Let’s Encrypt' : 'selbstsigniertes Zertifikat',
        ));

        return true;
    }

    /**
     * Die Rotationsdatei jedes Abonnements — bei jedem Lauf und nicht erst mit
     * `--sites`.
     *
     * **Bis hierher schrieb sie allein `subscription.provision`**, und der Kopf
     * der Datei sagt seit P3: „Änderungen von Hand werden beim nächsten Lauf
     * überschrieben." Einen nächsten Lauf gab es nicht. Auf `cloudsrv24` stand
     * am 25. September die Datei vom 14. August — mit dem `USR1`, an dem jede
     * Nacht die Wiederöffnung scheiterte (Befund 7 des B2-Laufs).
     *
     * > **Eine Datei, die ankündigt, beim nächsten Lauf überschrieben zu werden,
     * > braucht einen nächsten Lauf.**
     *
     * **Warum ohne Option:** `--sites` ist ausdrücklich, weil ein neu
     * geschriebener Server-Block für jede Domain ohne Zertifikat eines bestellt.
     * Eine Rotationsdatei bestellt nichts und startet nichts; sie wird
     * überschrieben, und das nächste logrotate liest sie. Weil das
     * postinstall-Skript dieses Kommando nach jedem Update ruft, steht danach
     * die Vorlage auf der Platte.
     *
     * **Gesperrte Abonnements gehören dazu** — ihre Domains antworten mit 503,
     * und nginx schreibt das weiter in ihre Protokolle. Eines, das noch
     * angelegt wird, nicht: Das schreibt seine Datei am Ende von
     * `subscription.provision` selbst.
     */
    private function rotations(Tenancy $tenancy, WebLifecycle $web): void
    {
        $subscriptions = $tenancy->withoutRestriction(fn (): array => Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Suspended->value])
            ->orderBy('id')
            ->get()
            ->all());

        if (! is_array($subscriptions) || $subscriptions === []) {
            $this->line('  Keine Abonnements, keine Rotationsdateien.');

            return;
        }

        foreach ($subscriptions as $subscription) {
            if (! $subscription instanceof Subscription) {
                continue;
            }

            $tenancy->withoutRestriction(function () use ($web, $subscription): void {
                $web->applyRotation($subscription);
            });
        }

        $this->info(sprintf('  %d Rotationsdateien der Abonnements eingereiht.', count($subscriptions)));
    }

    /**
     * Und die Blöcke der Kundendomains.
     *
     * **Warum das eine ausdrückliche Option ist und nicht mitläuft.** Ein
     * Server-Block, der neu geschrieben wird, löst den Lebenslauf aus — und der
     * bestellt für jede Domain ohne Zertifikat eines. Bei zwanzig Domains ist
     * das erwünscht, bei tausend ist es eine Bestellwelle, die in die
     * Wochengrenze der Zertifizierungsstelle läuft und die *neuen* Domains
     * gleich mit aussperrt. Wer die Option tippt, hat sich dafür entschieden;
     * das postinstall-Skript tippt sie nicht.
     *
     * **Gezählt und gesagt wird trotzdem**, wieviele Bestellungen daraus
     * werden. Eine Zahl, die niemand nennt, ist eine Überraschung mit
     * Wartezeit.
     */
    private function sites(Tenancy $tenancy, WebLifecycle $web): void
    {
        $domains = $tenancy->withoutRestriction(fn (): array => Domain::query()

            // Ein Alias hat keinen eigenen Block — er steht im `server_name`
            // seiner Elterndomain. Für ihn etwas anzuwenden hiesse, denselben
            // Block ein zweites Mal zu schreiben.
            ->where('type', '!=', DomainType::Alias->value)
            ->where('status', '!=', DomainStatus::Removing->value)
            ->orderBy('id')
            ->get()
            ->all());

        if (! is_array($domains) || $domains === []) {
            $this->line('  Keine Kundendomains.');

            return;
        }

        $ordering = 0;

        foreach ($domains as $domain) {
            if (! $domain instanceof Domain) {
                continue;
            }

            if ($domain->certificate_id === null) {
                $ordering++;
            }

            // Als Block und nicht als Pfeil: `apply()` gibt nichts zurück, und
            // ein Pfeilausdruck darüber liefert den Rückgabewert einer
            // void-Methode weiter — PHPStan sagt dazu zu Recht etwas.
            $tenancy->withoutRestriction(function () use ($web, $domain): void {
                $web->apply($domain, 'Server-Block neu geschrieben für '.$domain->name);
            });
        }

        $this->info(sprintf('  %d Server-Blöcke der Kundendomains eingereiht.', count($domains)));

        if ($ordering > 0) {
            $this->line(sprintf(
                '  %d davon haben noch kein Zertifikat — für sie wird danach eines bestellt.',
                $ordering,
            ));
        }
    }
}
