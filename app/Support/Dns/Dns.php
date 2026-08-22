<?php

declare(strict_types=1);

namespace App\Support\Dns;

use App\Models\Domain;
use App\Models\DomainDnsCheck;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use App\Support\Tls\AcmeSettings;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\Names;

/**
 * Der Abgleich, angeschlossen: Bestand, Uhr und Ablage.
 *
 * **Diese Klasse entscheidet nichts.** Was gefragt wird und was die Antwort
 * bedeutet, steht in {@see Survey} — ohne Modell, ohne Datenbank, ohne Uhr und
 * deshalb prüfbar. Hier steht nur, woher die Namen kommen, woher die Adressen,
 * welche Zertifizierungsstelle gilt und wohin das Ergebnis geht.
 *
 * **Der Schnitt ist am 21. August nachgezogen worden, und zwar aus Anlass.**
 * Solange die Reihenfolge des ganzen Merkmals hier stand, hing sie an Eloquent
 * und `now()`; kein Durchgang kam daran vorbei. Der einzige echte Fehler dieser
 * Stufe steckte genau dort.
 *
 * > **Der Fehler sitzt da, wo kein Test hinkommt — und das ist keine
 * > Beobachtung über den Zufall.**
 *
 * **Die Namen kommen aus {@see Domain::serverNames()}** und werden hier nicht
 * nachgebaut: Genau diese bedient nginx unter ihrem eigenen `server_name`. Ein
 * automatisches `www` gibt es nicht; wer es will, legt es als Alias an.
 */
final class Dns
{
    public function __construct(
        private readonly Survey $survey,
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

        $findings = ['addresses' => $addresses] + $this->survey->of(
            $domain->serverNames(),
            $addresses['effective'],
            Directories::caa($this->tls->directory()),
        );

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
            /*
             * **Über {@see Clock} und nicht als ISO-8601 an den Browser.**
             * Bis zum 22. August 2026 stand hier `toIso8601String()`, und die
             * Seite rechnete mit `new Date().toLocaleString()` — also in der
             * Zeitzone des **Browsers**. Daneben rendert die Vorgangsliste
             * derselben Seite über `Clock::display()`, also in der
             * **eingestellten** Anzeigezone. Zwei Zeitangaben auf einer Seite,
             * die in verschiedenen Zonen rechnen.
             *
             * Aufgefallen ist es niemandem, weil beide Zonen auf dem
             * Messrechner dieselbe waren (`docs/74`, Befund 3).
             *
             * > **Zwei Zeitangaben auf einer Seite, die in verschiedenen Zonen
             * > rechnen, sind schlimmer als eine falsche: Man kann sie
             * > miteinander vergleichen.**
             */
            'checked_at' => Clock::display($check->checked_at),
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
