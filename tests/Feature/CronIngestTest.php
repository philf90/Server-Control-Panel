<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\CronRun;
use App\Models\Subscription;
use App\Support\Cron\Cron;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Client;
use Tests\TestCase;

/**
 * Ein aufgezeichneter Lauf kommt in der Datenbank an.
 *
 * **Gefunden am 19. August 2026 auf `cloudsrv24`, und kein Test hätte es finden
 * können — es gab keinen.** Die Läufe-Seite blieb leer, obwohl unter
 * `/var/spool/srvpanel/cron/p1139/` 88 Aufzeichnungen lagen und cron sie
 * ordentlich erzeugt hatte. Von Hand angestossen meldete der Einsammler:
 *
 *     88 Lauf/Läufe eingesammelt, 0 eingepflegt, 0 wartet/warten noch.
 *
 * Die Ursache ist die dritte Grenze dieses Projekts, angewandt an einer Stelle,
 * an der sie nichts schützt: `srvpanel-cron.service` läuft **ohne angemeldetes
 * Konto**, und die Mandantenklammer verweigert in diesem Zustand alles. Damit
 * fand `CronJob::query()` im Einpflegen keinen einzigen Job, jeder Lauf lief in
 * ein `continue`, und der Vorgang meldete Erfolg.
 *
 * **Und die Läufe waren dabei fort.** `cron.runs` löscht, was es herausgegeben
 * hat („höchstens einmal"). Der Preis dieser Bauart ist ein verlorener Lauf,
 * wenn die Antwort unterwegs verlorengeht — hier kam sie an, und verloren ging
 * trotzdem jeder, seit es das Merkmal gibt.
 *
 * > **Zwei Stellen, die dieselbe Ausnahme brauchen, und nur eine hat sie: Die
 * > andere fällt nicht auf, weil sie leise das Richtige tut — nämlich nichts.**
 *
 * **Warum es diesen Test vorher nicht gab.** {@see Client} ist
 * `final` und lässt sich nicht ersetzen; alles hinter ihm war ungeprüft.
 * {@see Cron::record()} ist die Naht, die das auflöst — sie pflegt ein, ohne zu
 * fragen.
 *
 * > **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der Weg
 * > dahinter auch nicht.**
 */
final class CronIngestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Die Gegenprobe zu allem, was hier steht, und sie kommt zuerst.**
     *
     * Alle Fälle unten behaupten etwas über den Zustand „kein Konto
     * angemeldet". Wäre die Klammer in Tests offen, prüften sie eine andere
     * Anwendung als die, die auf dem Server läuft — und wären grün, während der
     * Fehler steht.
     *
     * > **Ein Test, der eine andere Ausgangslage hat als der Server, prüft eine
     * > andere Anwendung.**
     */
    public function test_the_clamp_is_closed_in_this_situation(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1000']);
        CronJob::factory()->create(['subscription_id' => $subscription->id]);

        $this->assertSame(
            0,
            CronJob::query()->count(),
            implode("\n", [
                'Die Mandantenklammer ist in dieser Lage offen.',
                'Dann sagen die Faelle unten nichts ueber den Systemdienst aus, der',
                'ohne angemeldetes Konto laeuft — sie waeren gruen, waehrend er',
                'jeden Lauf verwirft.',
            ]),
        );
    }

    /**
     * Ohne angemeldetes Konto — die Lage des Systemdienstes.
     */
    public function test_a_run_arrives_without_an_authenticated_account(): void
    {
        $subscription = Subscription::factory()->create(['system_user' => 'p1139']);
        $job = CronJob::factory()->create(['subscription_id' => $subscription->id]);

        $stored = app(Cron::class)->record([$this->run('p1139', (int) $job->id)]);

        $this->assertSame(1, $stored, 'Der Lauf wurde nicht eingepflegt — genau der Befund vom 19. August 2026.');

        $zeilen = app(Tenancy::class)->withoutRestriction(
            static fn (): int => CronRun::query()->count(),
        );

        $this->assertSame(1, $zeilen, 'Es steht keine Zeile in der Datenbank, obwohl das Einpflegen eine meldet.');
    }

    /**
     * Und die Wand hält: Eine fremde Jobnummer wird verworfen.
     *
     * **Nicht die Klammer schützt hier, sondern diese Prüfung.** Der Wrapper
     * schreibt in ein Verzeichnis, in das das Abonnement schreiben darf — was
     * dort steht, ist eine Behauptung des Kunden und keine Auskunft. Wer die
     * Nummer glaubt, hängt einen Lauf an einen fremden Job.
     */
    public function test_a_run_that_names_a_foreign_job_is_dropped(): void
    {
        $eigen = Subscription::factory()->create(['system_user' => 'p1000']);
        $fremd = Subscription::factory()->create(['system_user' => 'p2000']);

        CronJob::factory()->create(['subscription_id' => $eigen->id]);
        $fremderJob = CronJob::factory()->create(['subscription_id' => $fremd->id]);

        // Der Lauf kommt unter dem Namen von „eigen" an und nennt den Job von
        // „fremd" — genau das, was ein Kunde in seine Ablage schreiben könnte.
        $stored = app(Cron::class)->record([$this->run('p1000', (int) $fremderJob->id)]);

        $this->assertSame(0, $stored, 'Eine fremde Jobnummer wurde eingepflegt.');

        $zeilen = app(Tenancy::class)->withoutRestriction(
            static fn (): int => CronRun::query()->count(),
        );

        $this->assertSame(0, $zeilen, 'Der fremde Lauf steht in der Datenbank.');
    }

    /**
     * Ein Lauf, wie ihn `cron-run` aufzeichnet.
     *
     * @return array<string, mixed>
     */
    private function run(string $user, int $job): array
    {
        return [
            'user' => $user,
            'job' => $job,
            'started' => '2026-08-19T08:44:00Z',
            'duration_ms' => 12,
            'exit' => 0,
            'status' => 'ok',
            'output' => "eins\n",
            'truncated' => false,
        ];
    }
}
