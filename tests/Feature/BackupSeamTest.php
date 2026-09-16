<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Subscription;
use App\Support\Backups\Backups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Store;
use Tests\TestCase;

/**
 * Die Naht zwischen Panel und Agent für den Namen einer Sicherung (P8).
 *
 * ## Der Grund: zwei Zeichenmengen, die sich um genau ein Zeichen unterscheiden
 *
 * Gemessen am 16. September 2026:
 *
 * | Wer | erlaubt |
 * |---|---|
 * | Ein **Abonnementname** (`SubscriptionProvision::subscriptionName()`) | `^[a-z0-9]([a-z0-9.\-]{0,61}[a-z0-9])?$` |
 * | Ein **Ablagename** (`Store::storageName()`) | `^[a-z0-9][a-z0-9_\-]{0,95}$` |
 *
 * Der Unterschied ist der **Punkt**. Ein Abonnement heisst regelmässig
 * `shop.example`, und `shop.example-20260916-120000` weist der Agent ab — nach
 * dem Anlegen der Zeile, also mit einer Sicherung, die auf „wird erstellt"
 * stehenbleibt und nie eine wird.
 *
 * > **Zwei Prüfungen, die fast dasselbe erlauben, sind die gefährlichere Art
 * > von Naht: Sie hält für jeden Prüfkörper, den man beiläufig wählt.**
 *
 * ## Gemessen an der Wirkung und durch die Tür
 *
 * Nicht am Quelltext von `Backups::storageName()` — der ist privat, und ein
 * Wächter, der ihn nachbaut, wäre die zweite Fassung derselben Regel. Gefahren
 * wird `Backups::create()`, und der entstandene Name geht durch **dieselbe**
 * Prüfung, die der Agent macht.
 *
 * Das Vorbild ist {@see MaintenanceSeamTest}, und der Fund dahinter war
 * derselbe: zwei Formate, jedes für sich richtig, und niemand hat sie
 * aneinandergehalten.
 */
final class BackupSeamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Namen, die ein Abonnement wirklich haben darf.
     *
     * **Jeder einzelne ist gegen die Prüfung des Agenten gehalten**, aus der
     * sie stammen — keiner ist ausgedacht. Der Punkt ist der Fall, um
     * dessentwillen es diesen Wächter gibt; die anderen stehen daneben, damit
     * „kommt durch" nicht heisst „es kommt sowieso alles durch".
     *
     * @return iterable<string, array{string}>
     */
    public static function subscriptionNames(): iterable
    {
        yield 'gewöhnlich' => ['shop'];
        yield 'mit Punkt' => ['shop.example'];
        yield 'mit zwei Punkten' => ['www.shop.example'];
        yield 'mit Bindestrich' => ['shop-zwei'];
        yield 'nur Ziffern' => ['1001'];
        yield 'am Rand der Länge' => [str_repeat('a', 63)];
    }

    #[DataProvider('subscriptionNames')]
    public function test_the_name_the_panel_builds_is_one_the_agent_takes(string $name): void
    {
        $subscription = Subscription::factory()->create(['name' => $name]);

        $backup = app(Backups::class)->create($subscription);

        // Durch dieselbe Tür, durch die der Agent den Namen nimmt. Wirft sie,
        // bliebe die Zeile darüber für immer auf „wird erstellt" stehen.
        $this->assertSame(
            $backup->storage_name,
            Store::storageName($backup->storage_name),
            sprintf('Das Abonnement %s erzeugt einen Ablagenamen, den der Agent abweist.', $name),
        );
    }

    /**
     * **Die Gegenprobe**, und ohne sie prüft der Fall darüber nichts.
     *
     * Der ungereinigte Name — Abonnement plus Zeitstempel, so wie ein erster
     * Wurf ihn zusammensetzen würde — muss abgewiesen werden. Käme er durch,
     * wäre `Store::storageName()` keine Schranke und der Wächter darüber eine
     * Zusage über eine Prüfung, die nichts prüft.
     */
    public function test_the_unsanitised_name_really_is_refused(): void
    {
        $this->expectException(AgentException::class);

        Store::storageName('shop.example-20260916-120000');
    }

    /**
     * Und die Fassung, die das Panel hinausgibt, nimmt der Agent auch.
     *
     * **Gemessen an der Wirkung und nicht am Ausdruck**: `config('app.version')`
     * geht durch dieselbe Tür, durch die der Agent sie nimmt
     * ({@see Manifest::panelVersion()}). In einem Quellbaum steht dort das Wort
     * `Quellbaum`, auf einem Server die Freigabe — beide gemessen.
     *
     * Ohne diesen Fall fiele eine Fassung, die der Agent abweist, erst auf dem
     * Server auf: Die Zeile stünde, der Vorgang wäre eingereiht, und die
     * Sicherung bliebe auf „wird erstellt".
     */
    public function test_the_panel_version_is_one_the_agent_takes(): void
    {
        $version = config('app.version');

        $this->assertSame($version, Manifest::panelVersion($version));
    }

    /**
     * **Die Gegenprobe**: Eine Fassung, die es nicht gibt, wird abgewiesen.
     *
     * Ohne sie belegte der Fall darüber nur, dass die Methode zurückgibt, was
     * man ihr gibt.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function badVersions(): iterable
    {
        yield 'leer' => [''];
        yield 'null' => [null];
        yield 'mit Leerzeichen' => ['0.7.4 rc1'];
        yield 'mit Schrägstrich' => ['../../etc'];
        yield 'zu lang' => [str_repeat('9', 65)];
    }

    #[DataProvider('badVersions')]
    public function test_a_version_the_agent_would_refuse_is_refused(mixed $value): void
    {
        $this->expectException(AgentException::class);

        Manifest::panelVersion($value);
    }

    /**
     * Und die Operation **ruft** die Prüfung, statt sie nur zu haben.
     *
     * **Der Eingriff, der die Zeile aus `BackupCreate` nimmt, hat die beiden
     * Fälle darüber nicht rot gemacht** — sie messen die Tür und nicht, ob
     * jemand hindurchgeht. Dieselbe zweite Richtung, auf der
     * `SourceKeyFilterTest` seit A1 besteht: *rechnet richtig* **und** *wird
     * gerufen*.
     *
     * > **Ein Wächter über eine Prüfung sagt nichts darüber, ob sie jemand
     * > benutzt.**
     *
     * Gehalten am Quelltext, weil ein Lauf der Operation `/var/www/vhosts`
     * bräuchte. Was der Ausdruck nicht kann, ist eine Umbenennung der Methode —
     * dagegen steht die Untergrenze daneben.
     */
    public function test_the_operation_really_asks_for_the_version(): void
    {
        $quelltext = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/BackupCreate.php',
        );

        $this->assertStringContainsString(
            "Manifest::panelVersion(\$args['panel']",
            $quelltext,
            'Die Fassung muss durch dieselbe Tür, gegen die dieser Wächter misst.',
        );

        // **Die Untergrenze, und sie bindet den Namen an ein Verhalten.** Ein
        // `method_exists()` täte es nicht: Es ist auf eine bekannte Klasse
        // immer wahr, und PHPStan sagt das auch. Die Zeile oben ist erst dann
        // eine Zusage über eine *Prüfung*, wenn die genannte Methode wirklich
        // eine ist.
        $verweigert = false;

        try {
            Manifest::panelVersion('0.7.4 mit Leerzeichen');
        } catch (AgentException) {
            $verweigert = true;
        }

        $this->assertTrue(
            $verweigert,
            'Der gesuchte Name gehört einer Methode, die nichts abweist — dann prüft die Zeile darüber nichts.',
        );
    }

    /**
     * Zwei Sicherungen desselben Abonnements bekommen zwei Namen.
     *
     * `storage_name` ist eindeutig, und der Lebenslauf findet seine Zeile
     * darüber. Zwei gleiche Namen wären nicht bloss ein Fehlschlag beim
     * Einfügen — sie wären zwei Vorgänge, die auf dieselbe Zeile zeigen.
     *
     * Die Auflösung des Zeitstempels ist **eine Sekunde**; zwei Sicherungen in
     * derselben Sekunde sind der Fall, den das trifft. Hier wird er gemessen
     * und nicht angenommen.
     */
    public function test_two_backups_of_the_same_subscription_never_share_a_name(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'shop']);
        $backups = app(Backups::class);

        $first = $backups->create($subscription);
        $second = $backups->create($subscription);

        $this->assertNotSame(
            $first->storage_name,
            $second->storage_name,
            'Zwei Sicherungen in derselben Sekunde teilen sich sonst ihre Zeile.',
        );

        // **Ohne die Klammer gezählt, und das ist keine Bequemlichkeit.** Die
        // Mandantenklammer steht im Grundzustand auf `whereRaw('0 = 1')`, und
        // in einem Test ist niemand angemeldet — `Backup::query()->count()`
        // gibt hier `0` zurück, wortlos und für jede Zahl von Zeilen.
        //
        // > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit
        // > einer leeren Liste und nicht mit einem Fehler.** (`docs/78`)
        $this->assertSame(
            2,
            Backup::query()->withoutGlobalScopes()->count(),
            'Zwei Aufrufe müssen zwei Zeilen ergeben und nicht eine überschriebene.',
        );
    }
}
