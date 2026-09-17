<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Enums\SubscriptionStatus;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Backups\RestoreLifecycle;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Domains;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Die Wiederherstellung legt nicht noch einmal an, was schon dasteht.
 *
 * ## Der Befund, für den es diesen Wächter gibt
 *
 * Gemessen im Abnahmelauf von P8 (17. September 2026), auf der Vorgangsseite
 * einer gelungenen Wiederherstellung:
 *
 *     "failures": [{ "gegenstand": "p8-abnahme.invalid",
 *                    "grund": "Diese Sorte Domain lässt sich nicht anlegen." }]
 *
 * Die Domain war da — gemessen, `type=main`, Vhost-Datei gelegt, und die
 * Bestandsdiagnose fand keinen einzigen `web.%`-Befund.
 *
 * `rebuildDomains()` lief über **alle** Domains der Beschreibung und rief
 * {@see Domains::create()}. `DomainType::creatable()` gibt
 * `[Addon, Subdomain, Alias]` zurück — `Main` gehört nicht dazu, denn die
 * Hauptdomain legt `subscription.provision` an, und der Vorgang läuft vorher
 * (gemessen: 889 vor 890). Die Abweisung war richtig; sie als Fehlschlag zu
 * melden war es nicht.
 *
 * > **Ein gemeldeter Fehlschlag für etwas, das gelungen ist, ist schlimmer als
 * > kein Bericht — er schickt den Leser dorthin, wo nichts zu beheben ist.**
 *
 * ## Und die zweite Wirkung wog schwerer als die erste
 *
 * Eine **Subdomain unter der Hauptdomain** sucht ihren Elternteil in der Liste
 * der gerade angelegten. Dort stand er nie, weil das Anlegen geworfen hatte —
 * und der Kunde bekam sie nicht zurück, mit der Meldung „Die Domain …, unter
 * der sie hängt, ist nicht angelegt worden".
 *
 * Der Prüfkörper des Abnahmelaufs hatte nur die Hauptdomain. Deshalb hat es
 * niemand gesehen, und deshalb baut dieser Wächter den Fall, der fehlte.
 *
 * > **Ein Prüfkörper, der die Bedingung nicht herstellt, unter der der Fehler
 * > entsteht, misst ihn nicht.**
 *
 * ## Was er nicht kann
 *
 * Er ruft `rebuildDomains()` über Reflexion und nicht den ganzen Lebenslauf.
 * Gemessen ist damit die Stelle, an der der Befund sass — nicht, dass
 * `afterSuccess()` sie auch ruft. Das hält {@see BackupReachTest}
 * an der Reihenfolge im Rumpf.
 */
final class RestoreDomainsTest extends TestCase
{
    use RefreshDatabase;
    use WithoutPhpComments;

    /**
     * **Die Voraussetzung, und sie kommt zuerst.**
     *
     * Liesse `Domains::create()` eine Hauptdomain zu, gäbe es den Fehlschlag
     * nicht, den dieser Wächter verhindern soll — und die Fälle darunter wären
     * grün, ohne etwas zu messen.
     *
     * > **Ein Prüfkörper, der einen Zustand behauptet, statt ihn zu prüfen,
     * > hört auf zu messen, sobald jemand den Zustand herstellt.**
     */
    public function test_a_main_domain_cannot_be_created_through_this_door(): void
    {
        $this->assertNotContains(
            DomainType::Main,
            DomainType::creatable(),
            'Eine Hauptdomain lässt sich jetzt anlegen — dann prüfen die Fälle darunter eine Regel ohne Gegenstand.',
        );
    }

    /** Was schon dasteht, wird übergangen — und meldet keinen Fehlschlag. */
    public function test_the_existing_main_domain_is_not_rebuilt(): void
    {
        [$abo, $haupt] = $this->prüfkörper();

        $fehler = $this->rebuild($abo, [
            ['name' => $haupt->name, 'type' => 'main', 'parent' => null, 'document_root' => 'httpdocs'],
        ]);

        $this->assertSame(
            [],
            $fehler,
            'Die Wiederherstellung meldet einen Fehlschlag für die Hauptdomain, die schon dasteht.',
        );

        $this->assertSame(
            1,
            $this->ungeklammert(static fn (): int => Domain::query()->where('subscription_id', $abo->id)->count()),
            'Die Hauptdomain steht zweimal da — dann hat die Wiederherstellung sie doch angelegt.',
        );
    }

    /**
     * Und eine Subdomain findet ihren Elternteil.
     *
     * **Der Fall, den der Abnahmelauf nicht hatte.** Ohne ihn bliebe die
     * Behebung eine über eine Zeile in `failures`; sie ist eine über eine
     * Domain, die der Kunde sonst nicht zurückbekäme.
     */
    public function test_a_subdomain_finds_the_parent_that_was_already_there(): void
    {
        [$abo, $haupt] = $this->prüfkörper();

        $fehler = $this->rebuild($abo, [
            ['name' => $haupt->name, 'type' => 'main', 'parent' => null, 'document_root' => 'httpdocs'],
            ['name' => 'shop.'.$haupt->name, 'type' => 'subdomain', 'parent' => $haupt->name, 'document_root' => 'shop'],
        ]);

        $this->assertSame([], $fehler, 'Die Subdomain ist nicht angelegt worden.');

        $kind = $this->ungeklammert(static fn (): ?Domain => Domain::query()->where('name', 'shop.'.$haupt->name)->first());

        $this->assertNotNull($kind, 'Die Subdomain fehlt — ihr Elternteil war für sie nicht auffindbar.');
        $this->assertSame((int) $haupt->id, (int) $kind->parent_domain_id, 'Die Subdomain hängt am falschen Elternteil.');
    }

    /**
     * **Der Rahmen, den der Helfer unten nachbaut — gemessen am Quelltext.**
     *
     * `rebuild()` öffnet die Mandantenklammer, weil `afterSuccess()` das tut.
     * Fiele sie dort weg, liefe der Lebenslauf gegen `whereRaw('0 = 1')`:
     * `Subscription::query()->find()` gäbe `null`, die Methode kehrte wortlos
     * zurück, und **keine** Domain käme wieder — während dieser Wächter grün
     * bliebe, weil er seine eigene Klammer mitbringt.
     *
     * > **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist
     * > die, die veraltet.**
     *
     * Gefragt wird nach der **Verschachtelung** und nicht nach dem Vorkommen:
     * Ein `withoutRestriction(` irgendwo in der Datei erfüllte die Regel sonst,
     * ohne dass der Aufruf darin stünde. Gezählt werden die Klammern ab dem
     * Aufruf, wie es {@see TopLevelSetupTest} für `.vue` tut.
     */
    public function test_the_lifecycle_opens_the_clamp_around_this_call(): void
    {
        $quelle = (string) file_get_contents(
            (string) (new ReflectionClass(RestoreLifecycle::class))->getFileName(),
        );

        $rumpf = $this->withoutComments($quelle);
        $ab = strpos($rumpf, 'withoutRestriction(');

        $this->assertNotFalse($ab, 'afterSuccess() öffnet die Mandantenklammer nicht mehr.');

        $tiefe = 0;
        $bis = strlen($rumpf);

        for ($i = $ab + strlen('withoutRestriction'); $i < strlen($rumpf); $i++) {
            if ($rumpf[$i] === '(') {
                $tiefe++;

                continue;
            }

            if ($rumpf[$i] !== ')') {
                continue;
            }

            if (--$tiefe === 0) {
                $bis = $i;

                break;
            }
        }

        $this->assertStringContainsString(
            'rebuildDomains(',
            substr($rumpf, $ab, $bis - $ab),
            'rebuildDomains() steht nicht mehr innerhalb der gelösten Mandantenklammer — dann findet es im Betrieb keine Domain, und dieser Wächter merkt es nicht.',
        );
    }

    /**
     * Ein Prüfkörper, wie `subscription.provision` ihn hinterlässt: ein
     * Abonnement mit seiner Hauptdomain und sonst nichts.
     *
     * @return array{0: Subscription, 1: Domain}
     */
    private function prüfkörper(): array
    {
        $plan = Plan::factory()->create([
            'quotas' => [
                Quota::Domains->value => 5,
                Quota::Subdomains->value => 5,
            ],
        ]);

        $abo = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $haupt = Domain::factory()->main()->create([
            'subscription_id' => $abo->id,
            'name' => $abo->name,
        ]);

        return [$abo, $haupt];
    }

    /**
     * Den Bestand lesen, wie ihn eine angemeldete Sitzung sähe.
     *
     * **Die Nachmessung braucht dieselbe Klammer wie die Tür** — aus dem
     * gleichen Grund und eine Stelle weiter: Ohne sie zählte
     * `Domain::query()->count()` gegen `whereRaw('0 = 1')` und gäbe **null**,
     * gleich ob die Wiederherstellung angelegt hat oder nicht.
     *
     * > **Eine Nachmessung, die im Grundzustand alles verweigert, antwortet mit
     * > einer leeren Liste und nicht mit einem Fehler** — und eine Null, die
     * > „nicht nachgesehen" bedeutet, sieht aus wie „nichts angelegt".
     *
     * Beim ersten Wurf ist genau das passiert: Die `failures` waren schon leer
     * — die Behebung wirkte —, und die beiden Zeilen darunter meldeten
     * trotzdem Rot.
     *
     * @template T
     *
     * @param  Closure(): T  $frage
     * @return T
     */
    private function ungeklammert(Closure $frage): mixed
    {
        return app(Tenancy::class)->withoutRestriction($frage);
    }

    /**
     * `rebuildDomains()` durch die Tür, an der der Befund sass.
     *
     * Über Reflexion und nicht über `afterSuccess()`: Der volle Lebenslauf
     * bräuchte ein Manifest, einen Vorgang und zwei Datenbankserver, und
     * {@see Restore} ist `final` — es lässt sich nicht
     * ersetzen. Gemessen werden soll die Stelle und nicht das Zimmer dahinter.
     *
     * ## Und die Mandantenklammer gehört dazu, gemessen
     *
     * Der erste Wurf rief die Stelle **ohne** sie — und beide Fälle waren rot,
     * mit genau den beiden Meldungen, die dieser Wächter verhindern soll.
     * `$nachName` kam leer zurück: Ein `Domain`-Modell steht im Grundzustand
     * auf `whereRaw('0 = 1')`, und im Betrieb löst `afterSuccess()` das für
     * seinen ganzen Rumpf.
     *
     * > **Ein Prüfkörper, der eine Stelle aus ihrem Rahmen herausgelöst aufruft,
     * > misst sie unter einer Bedingung, die es im Betrieb nicht gibt — und
     * > sein Rot sieht aus wie ein Befund am Prüfling.**
     *
     * Dass der Rahmen wirklich so aussieht, hält der Fall
     * {@see self::test_the_lifecycle_opens_the_clamp_around_this_call} —
     * sonst wäre diese Klammer eine zweite Fassung einer Regel, die niemand
     * prüft, und die zweite ist die, die veraltet.
     *
     * @param  list<array<string,mixed>>  $domains
     * @return list<array{gegenstand: string, grund: string}>
     */
    private function rebuild(Subscription $abo, array $domains): array
    {
        $fehler = [];

        $tür = new ReflectionMethod(RestoreLifecycle::class, 'rebuildDomains');
        $tür->setAccessible(true);

        app(Tenancy::class)->withoutRestriction(function () use ($tür, $abo, $domains, &$fehler): void {
            $tür->invokeArgs(app(RestoreLifecycle::class), [$abo, ['domains' => $domains], &$fehler]);
        });

        /** @var list<array{gegenstand: string, grund: string}> $fehler */
        return $fehler;
    }
}
