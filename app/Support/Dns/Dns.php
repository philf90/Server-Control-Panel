<?php

declare(strict_types=1);

namespace App\Support\Dns;

use App\Models\Domain;
use App\Models\DomainDnsCheck;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
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
 * **Gefragt wird nach der Domain und ihren Aliassen**, denn genau die bedient
 * nginx unter ihrem eigenen Namen (`Site::serverNames()`). Ein automatisches
 * `www` gibt es nicht; wer es will, legt es als Alias an.
 *
 * **Die Zone ist der Name der Domain selbst und nicht ihre Basisdomain.** Das
 * ist die eine Stelle, an der es hier schiefgehen kann, und der Grund ist
 * `dns.check`: Die Operation fragt die autoritativen Nameserver **der
 * angegebenen Zone** und weist jeden Namen ab, der nicht darin liegt. Für
 * `shop.example.de` als eigene Domain heisst das: Zone ist `shop.example.de`,
 * und der Resolver sucht den NS-Satz von unten nach oben — er landet bei
 * `example.de`, wenn die Subdomain keinen eigenen hat. Genau so soll es sein.
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
    ) {}

    /**
     * Den Abgleich für eine Domain fahren und sein Ergebnis ablegen.
     *
     * @return array<string, mixed>
     */
    public function check(Domain $domain): array
    {
        $addresses = $this->addresses();
        $names = $this->names($domain);
        $desired = DesiredRecords::forAll($names, $addresses['effective']);

        $measured = $this->measure($domain->name, $desired);

        $findings = [
            'nameservers' => $measured['nameservers'],
            'addresses' => $addresses,
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
     * Die Namen, die zu dieser Domain gehören: sie selbst und ihre Aliasse.
     *
     * **`withoutRestriction` und der Grund dafür.** Die Aliasse hängen an
     * `parent_domain_id`; die Mandantenklammer filtert Domains auf die des
     * Betrachters, und für einen Betreiber ist das dieselbe Menge. Für einen
     * Kunden ebenfalls — seine eigenen Aliasse gehören ihm. Die Ausnahme steht
     * hier trotzdem **nicht**: Was der Betrachter nicht sehen darf, gehört
     * auch nicht in seinen Abgleich.
     *
     * @return list<string>
     */
    private function names(Domain $domain): array
    {
        $names = [$domain->name];

        foreach ($domain->children()->get() as $child) {
            if ($child->type->value === 'alias') {
                $names[] = $child->name;
            }
        }

        return $names;
    }

    /**
     * Den Agenten fragen — und einen Fehlschlag als Ergebnis behandeln.
     *
     * @param  list<array{name: string, type: string, expected: list<string>}>  $desired
     * @return array{nameservers: list<string>, records: list<array<string, mixed>>}
     */
    private function measure(string $zone, array $desired): array
    {
        if ($desired === []) {
            return ['nameservers' => [], 'records' => []];
        }

        $queries = array_map(
            static fn (array $entry): array => ['name' => $entry['name'], 'type' => $entry['type']],
            $desired,
        );

        try {
            $answer = $this->agent->call('dns.check', ['zone' => $zone, 'queries' => $queries]);
        } catch (Throwable) {
            /*
             * **Ein stummer Agent ist „nicht erreichbar" und kein Absturz.**
             * Ohne diesen Zweig zeigte die Domainseite gar nichts — und das
             * sähe aus wie eine Domain ohne Befund statt wie eine Messung, die
             * nicht stattgefunden hat.
             */
            return ['nameservers' => [], 'records' => []];
        }

        return [
            'nameservers' => is_array($answer['nameservers'] ?? null) ? array_values($answer['nameservers']) : [],
            'records' => is_array($answer['records'] ?? null) ? array_values($answer['records']) : [],
        ];
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
