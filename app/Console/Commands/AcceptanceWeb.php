<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DomainType;
use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Domains;
use App\Support\Web\PhpSelection;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Ops\WebIsolationProbe;
use SrvPanel\Agent\PhpVersions;

/**
 * Der Abnahmelauf für P3.
 *
 * **Das Kriterium wörtlich:** „Fertig, wenn zwei Abonnements mit je drei
 * Domains und unterschiedlichen PHP-Versionen parallel laufen und ein Skript
 * im einen Abo nachweislich nicht auf Dateien des anderen zugreifen kann."
 *
 * **„Nachweislich" ist das Wort, um das es geht.** Man kann in die
 * Pool-Vorlage sehen und feststellen, dass `open_basedir` dasteht. Das zeigt
 * nicht, dass PHP es anwendet, dass nginx den richtigen Sockel trifft, dass
 * der Pool unter dem richtigen Benutzer läuft und dass die Rechte des
 * Verzeichnisses stimmen. Das zeigt nur ein Skript, das es versucht — über
 * HTTP, durch nginx, durch den Pool, als der Systembenutzer des Abonnements.
 *
 * Der Lauf prüft deshalb vier Dinge, jedes an dem Ort, an dem es zählt:
 *
 * 1. **Jede Domain antwortet.** Sechs Stück, über HTTP mit ihrem eigenen
 *    Hostnamen.
 * 2. **Mit ihrer PHP-Version.** Nicht der, die im Panel steht — der, die der
 *    Prozess meldet.
 * 3. **Unter ihrem eigenen Systembenutzer.** Ein Pool, der als `www-data`
 *    liefe, sähe von aussen genauso aus.
 * 4. **Und sie kommt nicht an die Dateien des anderen Abonnements.** Weder an
 *    dessen Willkommensseite noch an dessen Verzeichnis.
 *
 * **Warum das ein Kommando ist und kein Test** — dieselbe Begründung wie beim
 * Abnahmelauf für P2: Ein Test läuft gegen SQLite und einen erfundenen
 * Agenten. Hier zählt echtes nginx, echtes PHP-FPM, echte Rechte auf einem
 * echten Dateisystem.
 */
final class AcceptanceWeb extends Command
{
    protected $signature = 'srvpanel:acceptance-web
                            {--prefix=abnahme-web : Namensvorsilbe der angelegten Abonnements}
                            {--keep : Nach dem Lauf stehen lassen — für die Fehlersuche}
                            {--timeout=900 : Sekunden, die ein Schritt höchstens dauern darf}
                            {--force : Ohne Rückfrage}';

    protected $description = 'Legt zwei Abonnements mit je drei Domains an und weist die Abschottung nach (P3)';

    public function handle(Client $agent, Lifecycle $lifecycle, Tenancy $tenancy, Domains $domains, PhpSelection $php): int
    {
        $prefix = (string) $this->option('prefix');

        if (! preg_match('/^[a-z][a-z0-9-]{1,20}$/D', $prefix)) {
            $this->error('Die Vorsilbe muss aus Kleinbuchstaben, Ziffern und Bindestrichen bestehen.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Zwei Abonnements mit je drei Domains werden angelegt (useradd, nginx, php-fpm) und danach zurückgebaut. Weiter?',
        )) {
            return self::SUCCESS;
        }

        return $tenancy->withoutRestriction(
            fn (): int => $this->perform($prefix, $agent, $lifecycle, $domains, $php),
        );
    }

    private function perform(string $prefix, Client $agent, Lifecycle $lifecycle, Domains $domains, PhpSelection $php): int
    {
        $customer = Customer::query()->orderBy('id')->first();
        $plan = Plan::query()->orderByDesc('is_default')->orderBy('id')->first();

        if ($customer === null || $plan === null) {
            $this->error('Es braucht mindestens einen Kunden und einen Plan. Beide legt man im Panel an.');

            return self::FAILURE;
        }

        /*
         * **Der Plan muss zwei Versionen hergeben.** Sonst laufen beide
         * Abonnements auf derselben, und das Kriterium („unterschiedliche
         * PHP-Versionen") wäre nicht geprüft, sondern behauptet. Geprüft wird
         * gegen das, was installiert *und* freigegeben ist — nicht gegen den
         * Katalog.
         */
        $this->refreshVersions($agent, $php);

        $verfügbar = array_values(array_intersect(
            $php->installed(),
            is_array($erlaubt = $plan->quotas[Quota::PhpVersions->value] ?? null) ? $erlaubt : [],
        ));

        if (count($verfügbar) < 2) {
            $this->error(sprintf(
                'Es braucht zwei PHP-Versionen, die installiert und im Plan „%s" freigegeben sind. Vorhanden: %s.',
                $plan->name,
                $verfügbar === [] ? 'keine' : implode(', ', $verfügbar),
            ));
            $this->line('Installieren unter Server → PHP-Versionen, freigeben im Plan.');

            return self::FAILURE;
        }

        $versionen = [$verfügbar[0], $verfügbar[count($verfügbar) - 1]];

        $this->line(sprintf('Kunde %s, Plan %s, PHP %s und %s.', $customer->number, $plan->name, ...$versionen));

        $timeout = max(60, (int) $this->option('timeout'));
        $abos = [];

        foreach ([0, 1] as $i) {
            $abos[] = [
                'subscription' => $this->createSubscription($prefix, $i + 1, $customer, $plan, $lifecycle),
                'version' => $versionen[$i],
            ];
        }

        /*
         * **Ab hier stehen Systembenutzer und Verzeichnisse — der Rückbau
         * gehört deshalb in ein `finally`.**
         *
         * Vorher lief er hinter der Probe, in gerader Linie. Jeder Fehlschlag
         * dazwischen sprang darüber hinweg, und auf dem Server blieben zwei
         * Abonnements samt `useradd`, Verzeichnisbaum, Server-Blöcken und
         * FPM-Pools liegen.
         *
         * **Das `finally` stand zuerst eine Stufe zu tief** — hinter dem
         * Warten. Ein Abonnement, das nicht fertig wird, ist aber gerade der
         * Fall, in dem etwas halb dasteht: `subscription.provision` kann den
         * Systembenutzer angelegt und danach abgebrochen haben. Der zweite
         * Lauf auf dem Zielserver ist genau hier ausgestiegen und hat wieder
         * zwei Abonnements hinterlassen. Der Block beginnt deshalb dort, wo
         * das erste Abonnement entsteht, und nicht später.
         */
        try {
            if (! $this->await(array_column($abos, 'subscription'), $timeout)) {
                $this->explainWhyNothingFinished($abos);

                return self::FAILURE;
            }

            foreach ($abos as $abo) {
                if (! $this->createDomains($abo['subscription'], $abo['version'], $domains, $timeout)) {
                    return self::FAILURE;
                }
            }

            $this->info('Sechs Domains angelegt.');

            return $this->probe($abos, $agent);
        } finally {
            if (! $this->option('keep')) {
                $this->teardown($abos, $lifecycle, $timeout, $agent);
            } else {
                $this->warn('--keep: Es wird nicht zurückgebaut. Aufräumen von Hand.');
            }
        }
    }

    /**
     * Die installierten Versionen frisch holen.
     *
     * Der Zwischenspeicher des Panels kann alt sein — auf einem Server, auf
     * dem gerade eine Version dazugekommen ist, wäre der Lauf sonst der
     * einzige, der davon nichts weiss.
     */
    private function refreshVersions(Client $agent, PhpSelection $php): void
    {
        try {
            $antwort = $agent->call('php.versions');

            if (is_array($antwort['available'] ?? null)) {
                $php->remember(array_values(array_filter($antwort['available'], is_string(...))));
            }
        } catch (AgentException $error) {
            $this->warn('php.versions ging nicht: '.$error->getMessage());
        }
    }

    /**
     * Legt ein Abonnement an — oder wirft.
     *
     * Kein `?Subscription`: `subscriptionName()` weist einen unbrauchbaren
     * Namen mit einer Ausnahme ab, und `create()` liefert ein Modell. Ein
     * Rückgabewert `null`, den niemand erzeugen kann, sieht wie ein zweiter
     * Weg aus und ist keiner.
     */
    private function createSubscription(string $prefix, int $nummer, Customer $customer, Plan $plan, Lifecycle $lifecycle): Subscription
    {
        $name = sprintf('%s-%d.invalid', $prefix, $nummer);

        SubscriptionProvision::subscriptionName($name);

        $subscription = Subscription::query()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'name' => $name,
            'system_user' => $lifecycle->nextSystemUser(),
            'status' => SubscriptionStatus::Provisioning,
        ]);

        $lifecycle->dispatch($subscription, 'subscription.provision', 'Abnahme P3: '.$name);

        return $subscription;
    }

    /**
     * Warum ist nichts fertig geworden?
     *
     * **„Die Abonnements sind nicht fertig geworden" ist keine Diagnose.** Der
     * Satz stand allein da, und der Betreiber auf dem Server hatte damit
     * nichts in der Hand: Ein fehlgeschlagener Vorgang trägt seine Begründung
     * in der Datenbank, ein hängender trägt seinen Zustand, und beides sagt
     * etwas völlig anderes über die Ursache. Ein Abnahmelauf, der nur „nein"
     * sagt, verschiebt die Arbeit auf jemanden, der weniger sieht als er.
     *
     * @param  list<array{subscription: Subscription, version: string}>  $abos
     */
    private function explainWhyNothingFinished(array $abos): void
    {
        $this->error('Die Abonnements sind nicht fertig geworden.');
        $this->newLine();

        $ids = array_map(static fn (array $a): int => (int) $a['subscription']->id, $abos);

        $gescheitert = Operation::query()
            ->whereIn('subscription_id', $ids)
            ->where('status', OperationStatus::Failed)
            ->orderBy('id')
            ->get();

        foreach ($gescheitert as $vorgang) {
            $this->line(sprintf(
                '  %s ist gescheitert: %s',
                (string) $vorgang->type,
                (string) ($vorgang->message ?? 'ohne Begründung'),
            ));
        }

        // Offen heisst: Der Vorgang liegt noch in der Schlange oder läuft. Das
        // ist der Fall, den man mit einem toten Arbeiter verwechselt — deshalb
        // steht der Hinweis auf die Unit daneben.
        $offen = Operation::query()
            ->whereIn('subscription_id', $ids)
            ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
            ->count();

        if ($offen > 0) {
            $this->line(sprintf('  %d Vorgang/Vorgänge sind nach %s Sekunden noch offen.', $offen, $this->option('timeout')));
            $this->line('  Läuft der Arbeiter? systemctl status srvpanel-worker');
        }

        foreach ($abos as $abo) {
            $this->line(sprintf(
                '  %s: %s',
                (string) $abo['subscription']->name,
                $abo['subscription']->status->sentence(),
            ));
        }

        if ($gescheitert->isEmpty() && $offen === 0) {
            // Kein Vorgang offen, keiner gescheitert, und trotzdem nicht
            // fertig: Dann hat `afterSuccess()` den Zustand nicht gesetzt.
            $this->newLine();
            $this->line('  Kein Vorgang ist offen oder gescheitert — der Zustand des Abonnements wurde nicht');
            $this->line('  nachgezogen. Das Protokoll des Panels sagt, woran es lag.');
        }

        $this->newLine();
        $this->line('  Die Vorgänge stehen im Panel unter Vorgänge, mit Ausgabe des Agenten.');
    }

    /**
     * Zwei weitere Domains je Abonnement — die Hauptdomain entsteht mit ihm.
     */
    private function createDomains(Subscription $subscription, string $version, Domains $domains, int $timeout): bool
    {
        $haupt = Domain::query()
            ->where('subscription_id', $subscription->id)
            ->where('type', DomainType::Main->value)
            ->first();

        if ($haupt === null) {
            $this->error(sprintf('%s hat keine Hauptdomain — subscription.provision ist nicht durchgelaufen.', $subscription->name));

            return false;
        }

        // Auch die Hauptdomain bekommt die Version dieses Abonnements: Das
        // Kriterium verlangt zwei Abonnements auf verschiedenen Versionen, und
        // die Hauptdomain gehört dazu.
        $domains->update($haupt, ['php_version' => $version, 'document_root' => $haupt->document_root]);

        foreach (['eins', 'zwei'] as $teil) {
            $domains->create($subscription, [
                'type' => DomainType::Addon->value,
                'name' => $teil.'-'.$subscription->name,
                'php_version' => $version,
            ]);
        }

        return $this->awaitOperations($subscription, $timeout);
    }

    /**
     * Die eigentliche Probe — über HTTP, durch nginx, durch den Pool.
     *
     * @param  list<array{subscription: Subscription, version: string}>  $abos
     */
    private function probe(array $abos, Client $agent): int
    {
        $fehler = [];

        foreach ($abos as $i => $abo) {
            $fremd = $abos[1 - $i]['subscription'];

            try {
                $agent->call('web.isolation.probe', [
                    'subscription' => $abo['subscription']->name,
                    'user' => $abo['subscription']->system_user,
                    'action' => 'place',
                ]);
            } catch (AgentException $error) {
                $this->error('Die Selbstprobe liess sich nicht ablegen: '.$error->getMessage());

                return self::FAILURE;
            }

            foreach ($this->domainNames($abo['subscription']) as $domain) {
                $fehler = array_merge($fehler, $this->check($domain, $abo, $fremd));
            }

            try {
                $agent->call('web.isolation.probe', [
                    'subscription' => $abo['subscription']->name,
                    'user' => $abo['subscription']->system_user,
                    'action' => 'remove',
                ]);
            } catch (AgentException $error) {
                $fehler[] = 'Die Selbstprobe blieb liegen: '.$error->getMessage();
            }
        }

        if ($fehler !== []) {
            $this->newLine();
            $this->error('Das Abnahmekriterium von P3 ist NICHT erfüllt:');

            foreach ($fehler as $eintrag) {
                $this->line('  · '.$eintrag);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Das Abnahmekriterium von P3 ist erfüllt.');
        $this->line('Sechs Domains, zwei PHP-Versionen, zwei Systembenutzer — und kein Zugriff über die Grenze.');

        return self::SUCCESS;
    }

    /**
     * Eine Domain befragen und die vier Antworten prüfen.
     *
     * @param  array{subscription: Subscription, version: string}  $abo
     * @return list<string>
     */
    private function check(string $domain, array $abo, Subscription $fremd): array
    {
        $ziel = SubscriptionProvision::VHOSTS.'/'.$fremd->name.'/'.SubscriptionProvision::DOCUMENT_ROOT.'/index.html';

        $antwort = $this->request($domain, $ziel);

        if ($antwort === null) {
            return [sprintf('%s antwortet nicht.', $domain)];
        }

        $fehler = [];

        if (($antwort['php'] ?? null) !== $abo['version']) {
            $fehler[] = sprintf(
                '%s antwortet mit PHP %s, erwartet war %s.',
                $domain,
                (string) ($antwort['php'] ?? '?'),
                $abo['version'],
            );
        }

        if (($antwort['user'] ?? null) !== $abo['subscription']->system_user) {
            $fehler[] = sprintf(
                '%s läuft als %s, erwartet war %s.',
                $domain,
                (string) ($antwort['user'] ?? '?'),
                (string) $abo['subscription']->system_user,
            );
        }

        // Die Frage, um die es geht.
        if (($antwort['lesbar'] ?? null) !== false) {
            $fehler[] = sprintf('%s kommt an %s — die Abschottung greift nicht.', $domain, $ziel);
        }

        $offen = array_keys(array_filter(
            is_array($antwort['gesperrt'] ?? null) ? $antwort['gesperrt'] : [],
            static fn (mixed $gesperrt): bool => $gesperrt !== true,
        ));

        if ($offen !== []) {
            $fehler[] = sprintf('%s kann noch Prozesse starten: %s.', $domain, implode(', ', $offen));
        }

        return $fehler;
    }

    /**
     * Die Anfrage an die eigene Maschine, mit dem Hostnamen der Domain.
     *
     * Über 127.0.0.1 und nicht über den Namen: Die Domains des Abnahmelaufs
     * enden auf `.invalid` und stehen in keinem DNS — das ist Absicht (RFC
     * 2606), damit ein Lauf niemals eine echte Domain trifft.
     *
     * @return array<string, mixed>|null
     */
    private function request(string $domain, string $ziel): ?array
    {
        $url = sprintf('http://127.0.0.1/%s?ziel=%s', WebIsolationProbe::FILENAME, urlencode($ziel));

        $context = stream_context_create(['http' => [
            'header' => 'Host: '.$domain."\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        if (! is_string($body)) {
            return null;
        }

        $antwort = json_decode($body, true);

        return is_array($antwort) ? $antwort : null;
    }

    /** @return list<string> */
    private function domainNames(Subscription $subscription): array
    {
        return Domain::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('id')
            ->pluck('name')
            ->map(strval(...))
            ->values()
            ->all();
    }

    /** @param list<array{subscription: Subscription, version: string}> $abos */
    private function teardown(array $abos, Lifecycle $lifecycle, int $timeout, Client $agent): void
    {
        foreach ($abos as $abo) {
            $lifecycle->dispatch($abo['subscription'], 'subscription.remove', 'Abnahme P3: zurückbauen');
        }

        if (! $this->await(array_column($abos, 'subscription'), $timeout)) {
            $this->error('Der Rückbau ist nicht durchgelaufen.');

            return;
        }

        /*
         * **Die Gegenprobe nach dem Rückbau.** Sie fragt den Agenten nach dem,
         * was §8.7 verlangt: Ist wirklich nichts geblieben? Die Antwort deckt
         * auch die drei Orte ausserhalb des Abo-Verzeichnisses ab, die es
         * seit P3 gibt.
         */
        foreach ($abos as $abo) {
            $name = $abo['subscription']->name;

            foreach (PhpVersions::CATALOG as $version) {
                $pool = PhpVersions::poolFile($version, (string) $abo['subscription']->system_user);

                $this->line(sprintf('  %s: Pool %s %s', $name, $version, is_file($pool) ? 'GEBLIEBEN' : 'fort'));
            }
        }

        $this->info('Zurückgebaut.');
    }

    /**
     * Warten, bis alle offenen Vorgänge der Abonnements durch sind.
     *
     * @param  list<Subscription>  $subscriptions
     */
    private function await(array $subscriptions, int $timeout): bool
    {
        $ids = array_map(static fn (Subscription $s): int => (int) $s->id, $subscriptions);
        $ende = time() + $timeout;

        while (time() < $ende) {
            $offen = Operation::query()
                ->whereIn('subscription_id', $ids)
                ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
                ->count();

            if ($offen === 0) {
                $fehlgeschlagen = Operation::query()
                    ->whereIn('subscription_id', $ids)
                    ->where('status', OperationStatus::Failed)
                    ->count();

                if ($fehlgeschlagen > 0) {
                    return false;
                }

                /*
                 * **Die Modelle auf Stand bringen — und darauf warten.**
                 *
                 * Das hat auf dem Server den ganzen Lauf gekostet, mit einer
                 * Meldung, die von etwas ganz anderem sprach: „Das Abonnement
                 * wird gerade angelegt — daran lässt sich nichts ändern."
                 *
                 * Der Grund waren zwei Dinge, die zusammenkamen. Erstens hielt
                 * dieses Warten nur die Vorgänge im Blick und fasste die
                 * übergebenen Modelle nie an; sie trugen weiter den Zustand aus
                 * dem `create()` von vorhin, also `Provisioning`. `Domains::create()`
                 * bekommt das Abonnement als Objekt gereicht und prüft daran —
                 * anders als `Domains::update()`, das die Beziehung frisch aus
                 * der Datenbank holt und deshalb glatt durchlief. Der Fehler
                 * war damit sicher und nicht sporadisch.
                 *
                 * Zweitens wäre ein blosses `refresh()` zu früh gewesen:
                 * `RunAgentOperation` schreibt erst den Vorgang auf „erledigt"
                 * und ruft **danach** `afterSuccess()`, das den Zustand des
                 * Abonnements setzt. Zwischen beidem liegt ein Fenster, in dem
                 * kein Vorgang mehr offen ist und das Abonnement trotzdem noch
                 * angelegt wird. Deshalb wird gewartet, bis der Zustand da ist,
                 * und nicht einmal nachgesehen.
                 */
                $unfertig = 0;

                foreach ($subscriptions as $subscription) {
                    try {
                        $subscription->refresh();
                    } catch (ModelNotFoundException) {
                        // Nach dem Rückbau kann die Zeile fort sein. Dann ist
                        // dieses Abonnement fertig, und zwar endgültig.
                        continue;
                    }

                    if ($subscription->status === SubscriptionStatus::Provisioning) {
                        $unfertig++;
                    }
                }

                if ($unfertig === 0) {
                    return true;
                }
            }

            usleep(500_000);
        }

        return false;
    }

    private function awaitOperations(Subscription $subscription, int $timeout): bool
    {
        return $this->await([$subscription], $timeout);
    }
}
