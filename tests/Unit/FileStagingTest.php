<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Files\Staging;
use Tests\Support\WithoutPhpComments;

/**
 * Panel und Agent meinen dasselbe Zwischenlager — und zwei Lager bleiben zwei.
 *
 * **Der Anlass ist der Fehler, der in diesem Projekt am häufigsten auftritt:**
 * eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder
 * ein Werkzeug den Bezug prüft. Das Panel legt eine hochgeladene Datei über
 * Laravels `local`-Ablage ab; der Agent nimmt nur Pfade unterhalb von
 * {@see Staging::ROOT} an. Zwei Zeichenketten, die dasselbe meinen müssen —
 * und keine von beiden weiss von der anderen.
 *
 * `UploadLimitTest` prüft dieselbe Zusage für die Datenbanksicherungen. Dieser
 * Wächter ist nicht seine Kopie, sondern seine zweite Hälfte: Er hält
 * ausserdem fest, dass die beiden Lager **getrennt** bleiben.
 *
 * **Warum die Trennung eine Regel ist und keine Ordnungsliebe.** Beide sind
 * Vorräte, aus denen der Agent als root liest. Lägen sie im selben
 * Verzeichnis, dürfte `db.dump.import` jede hochgeladene Kundendatei einspielen
 * und `files.upload` jede Datenbanksicherung verteilen.
 *
 * > **Zwei Positivlisten, die auf dasselbe Verzeichnis zeigen, sind eine
 * > Positivliste.**
 */
final class FileStagingTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Der Ort, den das Panel benutzt, ist der, den der Agent erwartet.
     *
     * Verglichen werden die **Werte** und nicht der Quelltext einer bestimmten
     * Datei — genau aus dem Grund, den `UploadLimitTest` dokumentiert: Ein
     * `grep` auf eine Datei wird beim nächsten Umzug zum Fehlschlag, und ein
     * Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen abgeschaltet.
     */
    public function test_the_panel_and_the_agent_mean_the_same_handover(): void
    {
        $suffix = '/storage/app/private/uploads';

        $this->assertStringEndsWith($suffix, Staging::ROOT, 'Der Agent erwartet die Datei woanders.');
        $this->assertStringStartsWith('/var/lib/srvpanel/', Staging::ROOT);

        // Der Teil, den der Controller über `Storage::disk('local')` anspricht.
        // Laravels `local` zeigt auf `storage/app/private`; was der Controller
        // daran hängt, muss der Rest dieses Pfades sein.
        $this->assertSame(
            'uploads',
            basename(Staging::ROOT),
            'Der Controller legt die Datei unter „uploads" ab — der Agent sucht anderswo.',
        );
    }

    /**
     * Und das Lager der Dateien ist nicht das der Sicherungen.
     */
    public function test_the_two_stores_stay_apart(): void
    {
        $this->assertNotSame(
            rtrim(Dump::STAGING_ROOT, '/'),
            rtrim(Staging::ROOT, '/'),
            'Datei-Uploads und Sicherungen teilen sich ein Zwischenlager.',
        );

        // Und keines liegt im anderen: `/…/imports` und `/…/imports/uploads`
        // wären zwei verschiedene Zeichenketten und trotzdem ein Vorrat.
        $this->assertStringStartsNotWith(rtrim(Dump::STAGING_ROOT, '/').'/', Staging::ROOT);
        $this->assertStringStartsNotWith(rtrim(Staging::ROOT, '/').'/', Dump::STAGING_ROOT);
    }

    /**
     * Der Agent nimmt die Quelle nicht ungeprüft entgegen.
     *
     * **Das ist die eine Zeile, die diese Operation von einem Fernlesegerät
     * unterscheidet.** `files.upload` liest ihre Quelle als root und
     * ausserhalb jedes Chroots — das Ziel ist eingesperrt, die Quelle nicht.
     * Ohne die Prüfung gegen {@see Staging::ROOT} wäre `source: /etc/shadow`
     * ein gültiger Aufruf.
     */
    public function test_the_upload_confines_its_source(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/FilesUpload.php'),
        );

        $this->assertMatchesRegularExpression(
            '/Guard::pathInside\(\s*\$args\[.source.\][^,]*,\s*\[Staging::ROOT\]\s*\)/',
            $source,
            implode("\n", [
                'files.upload prueft seine Quelle nicht gegen das Zwischenlager.',
                'Sie wird als root und ausserhalb jedes Chroots gelesen — ohne diese',
                'Schranke waere „source: /etc/shadow" ein gueltiger Aufruf.',
            ]),
        );
    }

    /**
     * Und sie wird vor dem Chroot geöffnet.
     *
     * Der Strom muss in das Kind hinein, und ein Pfad kommt dort nicht mehr an.
     * Steht das `fopen` innerhalb der Arbeitsfunktion, scheitert jeder Upload —
     * und zwar erst zur Laufzeit.
     */
    public function test_the_source_is_opened_before_the_child_starts(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/FilesUpload.php'),
        );

        $open = strpos($source, 'fopen($source');
        $run = strpos($source, '$workspace->run(');

        $this->assertIsInt($open, 'Die Quelle wird nicht mehr geöffnet.');
        $this->assertIsInt($run, 'Es gibt keine Sandbox mehr.');
        $this->assertLessThan(
            (int) $run,
            (int) $open,
            'Die Quelle wird erst im Kind geöffnet — dort gibt es ihren Pfad nicht mehr.',
        );
    }
}
