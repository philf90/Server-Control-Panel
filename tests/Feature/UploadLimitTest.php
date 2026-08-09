<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\ImportLimit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use SrvPanel\Agent\Ops\DbDumpImport;
use Tests\TestCase;

/**
 * Drei Zahlen, die zusammenpassen müssen — und eine Funktion, die sie benutzt.
 *
 * **Diesen Test gab es schon einmal, und er ist am 8. August 2026 gelöscht
 * worden.** Der Grund steht in `docs/36 §22.3f` und ist die teuerste Lehre
 * dieses Beitrags: Er hielt `client_max_body_size`, `upload_max_filesize` und
 * die Prüfregel gegeneinander — und **das Hochladen selbst gab es nicht**.
 * Keine Route, keine Methode, kein Feld. Drei Zahlen, die zueinander passten
 * und für nichts galten; dazu ein Panel, das 544 MB Anfragekörper annahm, ohne
 * je eine Datei entgegenzunehmen.
 *
 * Das ist die bekannte Falle dieses Projekts in ihrer eigenen Gestalt:
 * *Ein Wächter, der drei Werte gegeneinander hält, prüft nicht, dass sie
 * gelten.* Deshalb steht hier beides — der Vergleich **und** ein Aufruf, der
 * durch die Prüfregel geht. Ohne den zweiten Teil wäre dieser Test wieder das,
 * was er war.
 */
final class UploadLimitTest extends TestCase
{
    use RefreshDatabase;

    private function readFile(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }

    /** `512M`, `544m`, `256M` → Bytes. */
    private function bytes(string $value): int
    {
        $number = (int) preg_replace('/[^0-9]/', '', $value);

        return str_contains(strtolower($value), 'g')
            ? $number * 1024 * 1024 * 1024
            : $number * 1024 * 1024;
    }

    private function fromSource(string $file, string $pattern): int
    {
        $this->assertSame(
            1,
            preg_match($pattern, $this->readFile($file), $treffer),
            sprintf('In %s ist die Zahl nicht zu finden — dann prüft dieser Test nichts.', $file),
        );

        return $this->bytes($treffer[1]);
    }

    /**
     * Die drei Zahlen passen zusammen, und die Prüfregel ist die engste.
     *
     * Wer abgewiesen wird, soll die Meldung des Panels sehen und nicht die des
     * Webservers: Eine nginx-Fehlerseite weiss von PHP nichts, und ein Upload,
     * der bei 90 % abbricht, sieht aus wie ein kaputtes Netz.
     */
    public function test_the_three_numbers_fit_together(): void
    {
        $nginx = $this->fromSource('agent/src/Ops/PanelVhost.php', '/client_max_body_size\s+([0-9]+[a-zA-Z]);/');
        $post = $this->fromSource('packaging/etc/fpm.conf', '/post_max_size\]\s*=\s*([0-9]+[a-zA-Z])/');
        $upload = $this->fromSource('packaging/etc/fpm.conf', '/upload_max_filesize\]\s*=\s*([0-9]+[a-zA-Z])/');

        $this->assertGreaterThanOrEqual($post, $nginx, 'nginx nimmt weniger an als PHP — dann bricht der Upload vor der Prüfregel ab.');
        $this->assertGreaterThanOrEqual($upload, $post, 'post_max_size ist kleiner als upload_max_filesize; die Datei allein kommt dann nicht durch.');
        $this->assertGreaterThanOrEqual(
            ImportLimit::MAX_BYTES,
            $upload,
            'PHP nimmt weniger an als das Formular verspricht — die Meldung käme dann von PHP.',
        );
    }

    /**
     * Und die Prüfregel gilt tatsächlich.
     *
     * **Der Teil, der beim ersten Anlauf gefehlt hat.** Geprüft wird nicht, ob
     * `ImportLimit::rule()` irgendwo im Quelltext steht — sondern dass ein
     * Aufruf ohne Datei an genau diesem Feld scheitert. Eine Regel, die niemand
     * anwendet, ist eine Zahl in einer Klasse.
     */
    public function test_the_rule_is_reached_by_a_real_request(): void
    {
        [$account, $database] = $this->customerWithDatabase();

        $this->actingAs($account)
            ->from("/databases/{$database->id}")
            ->post("/databases/{$database->id}/dumps/import", [])
            ->assertSessionHasErrors('dump');
    }

    /**
     * Was keine gzip-Datei ist, kommt nicht in die Liste.
     *
     * Eine Datei heisst `.sql.gz`, weil jemand sie so genannt hat. Ohne diese
     * Prüfung läge sie im Verzeichnis der Sicherungen, sähe dort aus wie eine,
     * und der Fehler käme beim Zurückspielen — an einer Datenbank, die dabei
     * schon geleert ist.
     */
    public function test_a_file_that_is_not_gzip_is_refused(): void
    {
        [$account, $database] = $this->customerWithDatabase();

        Queue::fake();

        $response = $this->actingAs($account)
            ->from("/databases/{$database->id}")
            ->post("/databases/{$database->id}/dumps/import", [
                'dump' => UploadedFile::fake()->createWithContent('sicherung.sql.gz', 'PK'."\x03\x04".'kein gzip'),
            ]);

        $response->assertSessionHasErrors('dump');

        $this->assertStringContainsString(
            'gzip',
            (string) session('errors')?->first('dump'),
            'Die Abweisung stammt nicht aus der Prüfung der Magic Bytes — dann belegt dieser Test nichts.',
        );

        // Und es steht nichts im Bestand. Eine Zeile ohne Datei wäre ein
        // Eintrag, der auf einen Vorgang wartet, den es nie gab.
        $this->assertSame(0, $this->dumpCount());
    }

    /**
     * Eine echte gzip-Datei wird übernommen — Zeile, Vorgang, Herkunft.
     *
     * **Bis hierher läuft alles ohne Agenten**: Das Panel legt die Datei ab und
     * reiht einen Vorgang ein; was der Agent daraus macht, ist seine Sache. Die
     * `kind`-Spalte ist der Punkt — eine mitgebrachte Sicherung ist etwas
     * anderes als eine, die dieser Server geschrieben hat.
     */
    public function test_a_real_gzip_file_is_taken_over(): void
    {
        [$account, $database] = $this->customerWithDatabase();

        Queue::fake();

        $inhalt = (string) gzencode("-- SrvPanel\nINSERT INTO t VALUES (1);\n");

        $this->actingAs($account)
            ->post("/databases/{$database->id}/dumps/import", [
                'dump' => UploadedFile::fake()->createWithContent('mitgebracht.sql.gz', $inhalt),
            ])
            ->assertSessionHasNoErrors();

        $dump = app(Tenancy::class)->withoutRestriction(
            static fn (): ?DatabaseDump => DatabaseDump::query()->latest('id')->first(),
        );

        $this->assertNotNull($dump, 'Es ist keine Sicherung im Bestand entstanden.');
        $this->assertSame('imported', $dump->kind, 'Die Herkunft steht nicht an der Zeile.');

        // Und die Datei liegt in der Übergabe, aus der der Agent sie holt.
        $source = $this->latestOperationSource();

        $this->assertNotNull($source, 'Der Vorgang nennt keine Quelle.');
        $this->assertFileExists($source);
        $this->assertSame($inhalt, (string) file_get_contents($source));
    }

    /**
     * Panel und Agent meinen dieselbe Übergabe.
     *
     * Das Panel schreibt nach `storage_path('app/private/imports')`, der Agent
     * nimmt nur Pfade unterhalb von {@see DbDumpImport::STAGING_ROOT} entgegen.
     * In der Auslieferung ist `storage` ein Verweis nach
     * `/var/lib/srvpanel/storage` — zwei Zeichenketten, die dasselbe meinen
     * müssen, und genau dafür gibt es diese Prüfung.
     */
    public function test_the_panel_and_the_agent_mean_the_same_handover(): void
    {
        $this->assertStringEndsWith('storage/app/private/imports', DbDumpImport::STAGING_ROOT);
        $this->assertStringStartsWith('/var/lib/srvpanel/', DbDumpImport::STAGING_ROOT);

        $this->assertStringContainsString(
            "storage_path('app/private/imports')",
            $this->readFile('app/Http/Controllers/DatabaseController.php'),
            'Das Panel legt die Datei woanders ab, als der Agent sie erwartet.',
        );
    }

    /**
     * Ein Abonnement, eine Datenbank, ein Kunde, der sie sehen darf.
     *
     * @return array{0: Account, 1: Database}
     */
    private function customerWithDatabase(): array
    {
        [$subscription, $database] = app(Tenancy::class)->withoutRestriction(function (): array {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            return [$subscription, Database::factory()->forSubscription($subscription, 'shop')->create()];
        });

        $customer = Customer::query()->findOrFail($subscription->customer_id);

        return [Account::factory()->customer($customer)->create(), $database];
    }

    private function dumpCount(): int
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): int => DatabaseDump::query()->count(),
        );
    }

    private function latestOperationSource(): ?string
    {
        $operation = app(Tenancy::class)->withoutRestriction(
            static fn (): ?Operation => Operation::query()
                ->where('type', 'db.dump.import')
                ->latest('id')
                ->first(),
        );

        $source = $operation?->payload['source'] ?? null;

        return is_string($source) ? $source : null;
    }
}
