<?php

declare(strict_types=1);

namespace App\Support\Dns;

use App\Models\Domain;
use App\Models\DomainDnsCheck;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Names;
use Throwable;

/**
 * Der Abgleich: was eine Domain braucht, gegen das, was das DNS ausliefert.
 *
 * **Diese Klasse führt die vier Teile zusammen und entscheidet nichts selbst.**
 * Der Sollzustand steht in {@see DesiredRecords}, die Adressen in
 * {@see ServerAddresses}, das Urteil in {@see Comparison}, die Messung im
 * Agenten. Was hier steht, ist die Reihenfolge — und die Frage, welche Namen
 * überhaupt zu einer Domain gehören.
 *
 * **Gefragt wird nach der Domain und ihren Aliassen** — die Liste kommt aus
 * {@see Domain::serverNames()} und wird hier nicht nachgebaut. Genau diese
 * Namen bedient nginx unter ihrem eigenen `server_name`; ein automatisches
 * `www` gibt es nicht, wer es will, legt es als Alias an.
 *
 * **Und es geht ein Aufruf je Name hinaus und nicht einer je Domain.** Der
 * erste Wurf fragte alle Namen unter der Zone der Domain — und das ist falsch,
 * denn **ein Alias darf jeden Namen tragen** (`Domains::parent()` sagt es
 * wörtlich: „genau dafür gibt es ihn"). Ein Alias `beispiel.at` an einer
 * Domain `beispiel.de` liegt nicht in deren Zone; `dns.check` weist ihn zu
 * Recht ab, die Ausnahme wird gefangen — und **die ganze Domain** stand als
 * „nicht erreichbar" da, auch die Einträge, die in Ordnung waren.
 *
 * > **Ein Fehler an einem Namen, der als Zustand der ganzen Domain erscheint,
 * > ist schlimmer als kein Befund.**
 *
 * Je Name ist ausserdem sachlich richtiger: Die Sätze eines fremden Alias
 * liegen auf **anderen** Nameservern, und die der eigenen Zone zu fragen
 * ergäbe eine Antwort von jemandem, der nicht zuständig ist.
 *
 * **Ein Fehlschlag ist ein Ergebnis und keine Ausnahme.** Antwortet der Agent
 * nicht, steht das als „nicht erreichbar" da — mit Zeitpunkt. Eine Seite, die
 * bei einem stummen Agenten gar nichts zeigt, sieht aus wie eine Domain ohne
 * Befund.
 */
final class Dns
{
    public function __construct(
        private readonly Client $agent,
        private readonly Settings $settings,
        private readonly Tenancy $tenancy,
        private readonly AcmeSettings $tls,
    ) {}

    /**
     * Den Abgleich für eine Domain fahren und sein Ergebnis ablegen.
     *
     * @return array<string, mixed>
     */
    public function check(Domain $domain): array
    {
        $addresses = $this->addresses();
        $desired = DesiredRecords::forAll($domain->serverNames(), $addresses['effective']);

        $measured = $this->measure($desired, $domain->serverNames());

        $findings = [
            'nameservers' => $measured['nameservers'],
            'addresses' => $addresses,
            /*
             * **Das CAA-Urteil steht neben den Einträgen und nicht darunter.**
             * Es beantwortet eine andere Frage: nicht „kommt jemand an", sondern
             * „lässt sich ein Zertifikat bestellen". Ein Satz, der uns nicht
             * nennt, kostet Fehlversuche, die für jeden Kunden dieses Servers
             * zählen (`docs/34 §11`).
             */
            'authorities' => $measured['authorities'],
            'records' => array_map(
                static fn (array $entry): array => [
                    'name' => $entry['name'],
                    'type' => $entry['type'],
                    'state' => $entry['state']->value,
                    'expected' => $entry['expected'],
                    'found' => $entry['found'],
                ],
                Comparison::of($desired, $measured['records']),
            ),
        ];

        return $this->store($domain, $findings);
    }

    /**
     * Was zuletzt gemessen wurde — oder `null`, wenn noch nie.
     *
     * **`null` heisst „noch nie geprüft" und nicht „nichts gefunden".** Die
     * Seite soll vor dem ersten Lauf schweigen statt Entwarnung zu geben —
     * derselbe Grund wie bei `Settings::diskQuota()`.
     *
     * @return array<string, mixed>|null
     */
    public function last(Domain $domain): ?array
    {
        $check = DomainDnsCheck::query()->where('domain_id', $domain->id)->first();

        if ($check === null) {
            return null;
        }

        return [
            'checked_at' => $check->checked_at->toIso8601String(),
            'findings' => $check->findings,
        ];
    }

    /**
     * Die Adressen — abgeleitet und übersteuert, beide sichtbar.
     *
     * **Beide, und das ist keine Bequemlichkeit** (`docs/72 §2.1a`). Eine
     * Übersteuerung ist eine im Panel gemerkte Fassung eines Serverzustands
     * und kann veralten; wer nur das Ergebnis zeigt, macht aus einer alten
     * Eintragung eine falsche Auskunft über jede Domain.
     *
     * @return array{derived: list<string>, override: list<string>, effective: list<string>}
     */
    public function addresses(): array
    {
        $derived = ServerAddresses::routable(Names::addresses());
        $override = $this->settings->dnsAddresses();

        return [
            'derived' => $derived,
            'override' => $override,
            'effective' => ServerAddresses::effective($derived, $override),
        ];
    }

    /**
     * Den Agenten fragen — je Name einmal, und einen Fehlschlag als Ergebnis.
     *
     * **Je Name, weil ein Alias jeden Namen tragen darf** (siehe den
     * Klassenkopf). Die Zone ist dabei der Name selbst; `Resolver` sucht den
     * NS-Satz von unten nach oben und landet bei der Zone darüber, wenn der
     * Name keinen eigenen hat.
     *
     * **Ein Fehlschlag bleibt bei seinem Namen.** Scheitert die Frage nach
     * einem Alias, stehen dessen Einträge als „nicht erreichbar" da — und die
     * der Domain daneben so, wie sie gemessen wurden.
     *
     * @param  list<array{name: string, type: string, expected: list<string>}>  $desired
     * @param  list<string>  $names  Auch die ohne Sollzustand — CAA gilt für jeden
     * @return array{nameservers: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}
     */
    private function measure(array $desired, array $names): array
    {
        $jeName = [];

        /*
         * **Nach CAA wird für jeden Namen gefragt, auch ohne Sollzustand.**
         * Führt der Server keine öffentliche Adresse, gibt es keinen `A`-Satz
         * zu erwarten — ein CAA, das die Bestellung verbietet, gibt es
         * trotzdem, und es kostete dann Fehlversuche ohne jede Anzeige.
         */
        foreach ($names as $name) {
            $jeName[$name][] = ['name' => $name, 'type' => 'CAA'];
        }

        foreach ($desired as $entry) {
            $jeName[$entry['name']][] = ['name' => $entry['name'], 'type' => $entry['type']];
        }

        $nameservers = [];
        $records = [];
        $authorities = [];
        $ca = Directories::caa($this->tls->directory());

        foreach ($jeName as $name => $queries) {
            try {
                $answer = $this->agent->call('dns.check', ['zone' => $name, 'queries' => $queries]);
            } catch (Throwable) {
                /*
                 * **Ein stummer Agent ist „nicht erreichbar" und kein
                 * Absturz.** Ohne diesen Zweig zeigte die Domainseite gar
                 * nichts — und das sähe aus wie eine Domain ohne Befund statt
                 * wie eine Messung, die nicht stattgefunden hat.
                 */
                continue;
            }

            foreach (is_array($answer['nameservers'] ?? null) ? $answer['nameservers'] : [] as $server) {
                if (is_string($server) && ! in_array($server, $nameservers, true)) {
                    $nameservers[] = $server;
                }
            }

            foreach (is_array($answer['records'] ?? null) ? $answer['records'] : [] as $record) {
                $records[] = $record;
            }

            foreach (is_array($answer['authorities'] ?? null) ? $answer['authorities'] : [] as $satz) {
                $stumm = ($satz['answered'] ?? 0) === 0;

                $urteil = Authority::judge(
                    is_array($satz['values'] ?? null) ? array_values($satz['values']) : [],
                    $ca,
                );

                /*
                 * **Ein Name ohne Antwort bekommt kein Urteil.** `answered = 0`
                 * heisst „nicht erreichbar", und daraus ein „kein CAA gefunden"
                 * zu machen wäre eine Entwarnung, die niemand gemessen hat.
                 */
                $authorities[] = [
                    'name' => is_string($satz['name'] ?? null) ? $satz['name'] : $name,
                    'state' => $stumm ? 'unknown' : $urteil['state'],
                    'reason' => $stumm ? null : $urteil['reason'],
                    'issuers' => $stumm ? [] : $urteil['issuers'],
                ];
            }
        }

        return ['nameservers' => $nameservers, 'records' => $records, 'authorities' => $authorities];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function store(Domain $domain, array $findings): array
    {
        $now = now();

        /*
         * **`withoutRestriction` genau hier und nicht weiter oben.** Die
         * Klammer greift beim Schreiben über `subscription_id`, und die Zeile
         * gehört dem Abonnement der Domain — nicht dem des Betrachters. Ein
         * Betreiber, der die Domain eines Kunden prüft, schriebe sonst gegen
         * eine Klammer, die ihn ausschliesst.
         */
        $this->tenancy->withoutRestriction(function () use ($domain, $findings, $now): void {
            DomainDnsCheck::query()->updateOrCreate(
                ['domain_id' => $domain->id],
                [
                    'subscription_id' => $domain->subscription_id,
                    'checked_at' => $now,
                    'findings' => $findings,
                ],
            );
        });

        return ['checked_at' => $now->toIso8601String(), 'findings' => $findings];
    }
}
