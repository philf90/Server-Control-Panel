<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunAgentOperation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * Stimmt das Paket noch mit der Anwendung überein?
 *
 * **Warum das ein Test sein muss.** Die systemd-Units rufen die Anwendung über
 * Zeichenketten auf: einen Kommandonamen, einen Warteschlangennamen. Ändert
 * sich der Name in der Anwendung, merkt es hier niemand — kein Typ, kein
 * Aufruf, keine Referenz. Es fällt erst auf dem Server auf, und dort als
 * Dienst, der nicht startet, oder als Vorgang, der ewig „wartet".
 *
 * Genau das war zweimal der Fall, beide Male als Rest der Umbenennung auf
 * englische Bezeichner:
 *
 * - `srvpanel-metrics.service` rief `artisan srvpanel:kennzahlen` auf. Das
 *   Kommando heisst `srvpanel:metrics`. Der Dienst wäre auf jedem Server in
 *   eine Neustartschleife gelaufen — und mit ihm wären alle Verlaufskacheln
 *   leer geblieben.
 * - `srvpanel-worker.service` horchte auf `vorgaenge,standard`. Aufträge gehen
 *   in `operations`. Kein einziger Vorgang wäre je ausgeführt worden.
 * - `install.sh` holt `php-source.sh` von der Seite, der Freigabelauf hat es
 *   dorthin nie kopiert. Ohne PHP-Quelle kein PHP 8.4, und die Installation
 *   endete mit einer apt-Meldung über `php8.4-cli`, die die Ursache nicht
 *   nennt. Gefunden hat das kein Test, sondern der erste Mensch, der es
 *   benutzen wollte.
 */
final class PackagingTest extends TestCase
{
    private function unit(string $name): string
    {
        $path = dirname(__DIR__, 2).'/packaging/systemd/'.$name;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> */
    private function artisanCommands(): array
    {
        $commands = [];

        /** @var Command $command */
        foreach ($this->app->make(Kernel::class)->all() as $command) {
            $commands[] = $command->getName() ?? '';
        }

        return array_values(array_filter($commands));
    }

    /**
     * Ein Warten, das ablaufen kann, muss dabei auch scheitern.
     *
     * **Der Fall, aus dem diese Prüfung kommt.** Der Integrationslauf wartet
     * darauf, dass systemd im Container hochkommt. Lief die Schleife ab,
     * passierte nichts: Im einen Schritt fing ein `| head -1` den
     * Rückgabewert ab, im anderen stand hinter der Schleife gar keine
     * Prüfung. Der Lauf ging weiter und scheiterte drei Schritte später an
     * einer dpkg-Sperre — an einer Stelle, die niemand mit dem Hochfahren in
     * Verbindung bringt. Zwei Läufe auf Ubuntu sind so verlorengegangen.
     *
     * Geprüft wird die Sache und nicht der eine Schritt: Wer auf
     * `is-system-running` wartet, muss im selben Schritt sagen, was gilt,
     * wenn es nicht kommt.
     */
    public function test_every_wait_for_systemd_fails_when_it_times_out(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

        // Ein Schritt beginnt mit „- name:" — daran wird zerlegt.
        $steps = preg_split('/^      - (?:name|uses):/m', $ci) ?: [];

        $waiting = array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, 'is-system-running'),
        );

        $this->assertNotSame([], $waiting, 'Kein Schritt wartet mehr auf systemd — dann prüft dieser Test nichts.');

        foreach ($waiting as $step) {
            $this->assertStringContainsString('::error::', $step, implode(' ', [
                'Ein Schritt wartet auf systemd und sagt nicht, was gilt, wenn es nicht kommt.',
                'Der Lauf geht dann weiter und scheitert an einer Stelle, die mit der Ursache nichts zu tun hat.',
                'Schritt:'."\n".trim(substr($step, 0, 400)),
            ]));

            $this->assertStringContainsString('exit 1', $step, 'Der Schritt meldet den Fehlschlag, bricht aber nicht ab.');
        }
    }

    public function test_the_release_publishes_every_file_the_installer_fetches(): void
    {
        $root = dirname(__DIR__, 2);
        $installer = (string) file_get_contents($root.'/packaging/install.sh');
        $release = (string) file_get_contents($root.'/.github/workflows/release.yml');

        // Alles, was install.sh unterhalb der Seitenwurzel holt. `${REPO_URL%/apt}`
        // ist genau diese Wurzel — die Schreibweise steht so im Skript.
        preg_match_all('#\$\{REPO_URL%/apt\}/([A-Za-z0-9._\-]+)#', $installer, $matches);

        $this->assertNotSame([], $matches[1], 'install.sh holt nichts von der Seite — dann stimmt dieser Test nicht mehr.');

        $missing = [];

        foreach (array_unique($matches[1]) as $file) {
            // Der Freigabelauf kopiert die Datei aus packaging/ in den
            // Pages-Branch. Beides muss stimmen: Sie muss im Repository
            // liegen, und sie muss veröffentlicht werden.
            if (! is_file($root.'/packaging/'.$file)) {
                $missing[] = $file.' (fehlt in packaging/)';

                continue;
            }

            if (! str_contains($release, 'packaging/'.$file.' '.$file)) {
                $missing[] = $file.' (wird vom Freigabelauf nicht veröffentlicht)';
            }
        }

        $this->assertSame([], $missing, sprintf(
            "install.sh holt diese Dateien von der Seite, aber sie kommen dort nicht an:\n  %s\n\n".
            'Ein `curl -f` ins Leere gibt nichts aus, und ein leeres `sh` endet mit 0 — '.
            'der Fehlschlag ist also unsichtbar, bis Schritte später etwas anderes scheitert.',
            implode("\n  ", $missing),
        ));
    }

    /**
     * Erweiterungen, die Debian in php8.4-cli bzw. php8.4-common mitbringt.
     *
     * Sie brauchen kein eigenes Paket. Die Liste steht hier und nicht im
     * Kopf: Wer eine Erweiterung fälschlich für eingebaut hält, merkt es
     * sonst erst auf einem fremden Server.
     *
     * @var list<string>
     */
    private const BUILT_IN = [
        'ctype', 'filter', 'hash', 'openssl', 'session', 'tokenizer', 'json',
        'fileinfo', 'iconv', 'phar', 'pcre', 'sockets', 'posix', 'pcntl',
        'simplexml', 'spl', 'date', 'random',
    ];

    /** @var array<string,string> Erweiterung => Debian-Paket */
    private const NEEDS_PACKAGE = [
        'mbstring' => 'php8.4-mbstring',
        'dom' => 'php8.4-xml',
        'xml' => 'php8.4-xml',
        'libxml' => 'php8.4-xml',
        'xmlwriter' => 'php8.4-xml',
        'xmlreader' => 'php8.4-xml',
        'curl' => 'php8.4-curl',
        'pdo_mysql' => 'php8.4-mysql',
        'mysqli' => 'php8.4-mysql',
        'intl' => 'php8.4-intl',
        'zip' => 'php8.4-zip',
        'gd' => 'php8.4-gd',
        'bcmath' => 'php8.4-bcmath',
        'redis' => 'php8.4-redis',
    ];

    public function test_the_package_declares_every_extension_its_dependencies_need(): void
    {
        $root = dirname(__DIR__, 2);
        $declared = (string) file_get_contents($root.'/packaging/nfpm.yaml');
        $missing = [];

        // Jedes `ext-…` aus dem gesamten Abhängigkeitsbaum. Die Frage ist
        // nicht, was wir zu brauchen glauben, sondern was die Pakete
        // verlangen, die wir ausliefern.
        foreach (glob($root.'/vendor/*/*/composer.json') ?: [] as $path) {
            $manifest = json_decode((string) file_get_contents($path), true);

            if (! is_array($manifest) || ! is_array($manifest['require'] ?? null)) {
                continue;
            }

            foreach (array_keys($manifest['require']) as $requirement) {
                if (! is_string($requirement) || ! str_starts_with($requirement, 'ext-')) {
                    continue;
                }

                $extension = substr($requirement, 4);

                if (in_array($extension, self::BUILT_IN, true)) {
                    continue;
                }

                $package = self::NEEDS_PACKAGE[$extension] ?? null;

                if ($package === null) {
                    $missing[] = sprintf('%s (unbekannt — gehört in NEEDS_PACKAGE oder BUILT_IN)', $extension);

                    continue;
                }

                if (! str_contains($declared, '- '.$package)) {
                    $missing[] = sprintf('%s braucht %s', $extension, $package);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), sprintf(
            "Diese Erweiterungen verlangt der Abhängigkeitsbaum, das Paket führt sie nicht:\n  %s\n\n".
            'Ein `apt install srvpanel` liefert dann ein Panel, dem etwas fehlt — '.
            'und der Fehlschlag kommt erst bei der Ersteinrichtung.',
            implode("\n  ", array_unique($missing)),
        ));
    }

    public function test_the_package_declares_the_database_driver(): void
    {
        // `pdo_mysql` verlangt kein Composer-Paket — es steht in keinem
        // `require`, weil Laravel den Treiber erst zur Laufzeit auswählt. Genau
        // deshalb fiel es durch: Die Prüfung oben hätte es nie gefunden, und
        // ohne den Treiber scheitert die erste Migration.
        $declared = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/nfpm.yaml');

        $this->assertStringContainsString('- php8.4-mysql', $declared);
    }

    public function test_the_php_source_package_ships_the_script_it_runs(): void
    {
        $root = dirname(__DIR__, 2);
        $nfpm = (string) file_get_contents($root.'/packaging/nfpm-php-source.yaml');
        $postinstall = (string) file_get_contents($root.'/packaging/scripts/php-source-postinstall.sh');

        // Der Pfad steht in zwei Dateien: einmal als Ziel im Paket, einmal als
        // Aufruf im postinst. Genau die Verbindung, die sonst niemand prüft —
        // und ein postinst, das ins Leere greift, scheitert erst auf dem
        // Server des Kunden.
        $found = preg_match('#dst:\s*(/usr/share/[A-Za-z0-9./_\-]+)#', $nfpm, $matches);

        $this->assertSame(1, $found, 'nfpm-php-source.yaml legt kein Skript unter /usr/share ab.');
        $this->assertStringContainsString(
            $matches[1],
            $postinstall,
            sprintf('Das postinst ruft nicht %s auf, wohin das Paket das Skript legt.', $matches[1]),
        );

        // Und es muss das gemeinsame Skript sein, keine Kopie: Drei Wege
        // (install.sh, CI, Paket) auf drei Fassungen laufen irgendwann
        // auseinander.
        $this->assertStringContainsString('./packaging/php-source.sh', $nfpm);
    }

    public function test_neither_package_depends_on_the_other(): void
    {
        $root = dirname(__DIR__, 2);
        $panel = (string) file_get_contents($root.'/packaging/nfpm.yaml');
        $helper = (string) file_get_contents($root.'/packaging/nfpm-php-source.yaml');

        // Ein `Depends: srvpanel-php-source` am Panel sähe hilfreich aus und
        // wäre wirkungslos: apt löst die Abhängigkeiten auf, bevor das erste
        // Paketskript läuft — die Quelle käme also immer zu spät. Eine
        // Beziehung, die nur Absicht ausdrückt und nichts bewirkt, ist beim
        // Lesen der Paketbeziehungen schlimmer als keine.
        $this->assertStringNotContainsString('srvpanel-php-source', $panel);
        $this->assertDoesNotMatchRegularExpression('/^\s*-\s*srvpanel\s*$/m', $helper);
    }

    public function test_the_build_produces_both_packages(): void
    {
        $build = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/build.sh');

        foreach (['packaging/nfpm.yaml', 'packaging/nfpm-php-source.yaml'] as $config) {
            $this->assertStringContainsString($config, $build, sprintf(
                '%s wird von build.sh nicht gebaut — dann liegt das Paket in keinem Freigabelauf.',
                $config,
            ));
        }
    }

    public function test_every_unit_calls_an_artisan_command_that_exists(): void
    {
        $known = $this->artisanCommands();
        $unknown = [];

        foreach (glob(dirname(__DIR__, 2).'/packaging/systemd/*.service') ?: [] as $path) {
            $content = (string) file_get_contents($path);

            if (preg_match_all('/artisan\s+([a-z][a-z0-9:_\-]*)/', $content, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $name) {
                if (! in_array($name, $known, true)) {
                    $unknown[] = basename($path).' → '.$name;
                }
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "Diese Units rufen ein Kommando auf, das es nicht gibt:\n  %s",
            implode("\n  ", $unknown),
        ));
    }

    /**
     * Jede Unit, die im Verzeichnis liegt, geht auch mit dem Paket mit.
     *
     * **Der Fehler, den das abfängt, sieht nach nichts aus.** Eine Unit, die
     * in `packaging/systemd` liegt und in `nfpm.yaml` fehlt, wird gebaut,
     * geprüft, gelesen — und ist auf dem Server nicht da. Es gibt keine
     * Fehlermeldung dazu: Der Dienst läuft eben nicht, und niemand vermisst
     * einen Takt, den es noch nie gab.
     *
     * Umgekehrt gilt es auch: Ein Eintrag in nfpm.yaml, zu dem keine Datei
     * mehr existiert, lässt den Paketbau scheitern — das fällt wenigstens auf.
     */
    public function test_every_unit_is_shipped_with_the_package(): void
    {
        $nfpm = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/nfpm.yaml');
        $units = glob(dirname(__DIR__, 2).'/packaging/systemd/*') ?: [];

        $this->assertGreaterThan(4, count($units), 'Es werden kaum Units gelesen — dann prüft dieser Test nichts.');

        foreach ($units as $path) {
            $name = basename($path);

            $this->assertStringContainsString(
                '/lib/systemd/system/'.$name,
                $nfpm,
                sprintf('%s liegt im Verzeichnis, geht aber nicht mit dem Paket mit.', $name),
            );
        }
    }

    /**
     * Ein Timer ohne seinen Dienst startet nichts.
     *
     * `srvpanel-usage.timer` ruft `srvpanel-usage.service` auf — der Name ist
     * die ganze Verbindung zwischen beiden, es gibt keine Zeile, die sie
     * nennt. Fehlt der Dienst, meldet systemd das erst beim Start des Timers,
     * und der wird beim Einrichten mit `|| true` weggeschluckt.
     */
    public function test_every_timer_has_the_service_it_starts(): void
    {
        $timers = glob(dirname(__DIR__, 2).'/packaging/systemd/*.timer') ?: [];

        $this->assertNotSame([], $timers, 'Kein Timer mehr da — dann prüft dieser Test nichts.');

        foreach ($timers as $path) {
            $service = preg_replace('/\.timer$/', '.service', $path);

            $this->assertFileExists(
                (string) $service,
                sprintf('%s startet einen Dienst, den es nicht gibt.', basename($path)),
            );
        }
    }

    /**
     * Der Timer wird beim Einrichten auch angestellt.
     *
     * Ein `enable` auf den Dienst statt auf den Timer ist der naheliegende
     * Fehler: Beide heissen gleich, `systemctl enable srvpanel-usage.service`
     * läuft ohne Murren durch — und der Takt steht trotzdem still.
     */
    public function test_the_timer_is_enabled_and_stopped_again(): void
    {
        $postinstall = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/scripts/postinstall.sh');
        $preremove = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/scripts/preremove.sh');

        foreach (glob(dirname(__DIR__, 2).'/packaging/systemd/*.timer') ?: [] as $path) {
            $name = basename($path);

            $this->assertMatchesRegularExpression(
                '/systemctl\s+enable[^\n]*\s'.preg_quote($name, '/').'\b/',
                $postinstall,
                $name.' wird beim Einrichten nicht angestellt — der Takt liefe nie.',
            );

            /*
             * Beim Entfernen nicht wörtlich geprüft: Sobald es zwei Timer
             * gibt, steht dort eine Schleife über die Namen, und ein Muster
             * auf `systemctl stop <name>.timer` fände sie nicht — es hielte
             * eine richtige Umsetzung für einen Fehler. Geprüft wird deshalb
             * beides einzeln: dass es ein Anhalten von Timern gibt und dass
             * dieser Timer dabei vorkommt.
             */
            $this->assertMatchesRegularExpression(
                '/systemctl\s+stop\s+[^\n]*\.timer/',
                $preremove,
                'preremove.sh hält überhaupt keinen Timer an.',
            );

            $this->assertStringContainsString(
                basename($name, '.timer'),
                $preremove,
                $name.' wird beim Entfernen nicht angehalten.',
            );
        }
    }

    /**
     * Die Kurznamen, die `packaging/bin/srvpanel` auf `srvpanel:` abbildet.
     *
     * Gelesen wird der `case`-Zweig selbst und nicht mit einem Muster über die
     * ganze Datei gesucht: Mein erster Versuch verlangte ein `|` vor dem
     * Namen und meldete deshalb ausgerechnet den ersten Eintrag als fehlend.
     *
     * @return list<string>
     */
    private function wrapperCommands(): array
    {
        $wrapper = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/bin/srvpanel');

        if (preg_match('/^\s*([a-z][a-z0-9_|-]*)\)\s*$/m', $wrapper, $match) !== 1) {
            $this->fail('In packaging/bin/srvpanel ist kein case-Zweig mit Kommandonamen zu finden.');
        }

        return array_values(array_filter(explode('|', $match[1])));
    }

    public function test_the_wrapper_knows_every_command_of_the_panel(): void
    {
        // `srvpanel setup` ruft `artisan srvpanel:setup` auf — die Zuordnung
        // steht als feste Liste im Wrapper, weil ein Ableiten hiesse, für
        // jeden Aufruf erst PHP zu starten. Eine feste Liste läuft aber
        // auseinander, und genau das ist passiert: `admin` fehlte. Wer nach
        // der Ersteinrichtung `srvpanel admin` tippte — den Befehl, den die
        // Einrichtung selbst nennt —, bekam „Command not defined" und kam
        // nicht in sein Panel.
        $known = $this->wrapperCommands();
        $missing = [];

        foreach ($this->artisanCommands() as $name) {
            if (! str_starts_with($name, 'srvpanel:')) {
                continue;
            }

            $short = substr($name, strlen('srvpanel:'));

            if (! in_array($short, $known, true)) {
                $missing[] = $short;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Diese Kommandos kennt packaging/bin/srvpanel nicht:\n  %s\n\n".
            'Auf dem Server heisst das „Command not defined" — artisan kennt sie, der Wrapper nicht.',
            implode("\n  ", $missing),
        ));
    }

    public function test_the_setup_points_at_a_command_that_exists(): void
    {
        $setup = (string) file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/Setup.php');
        $known = $this->wrapperCommands();

        // Die Ersteinrichtung nennt am Ende den nächsten Schritt. Zeigt der
        // ins Leere, ist der Hinweis schlimmer als keiner — er kostet den
        // Leser die Zeit, in der er dem Vorschlag folgt, und lässt ihn dann
        // an sich selbst zweifeln statt an der Anleitung.
        preg_match_all('/srvpanel ([a-z][a-z0-9-]*)/', $setup, $matches);

        $named = array_values(array_unique($matches[1]));

        $this->assertNotSame([], $named, 'Die Einrichtung nennt kein srvpanel-Kommando mehr — dann stimmt dieser Test nicht.');

        foreach ($named as $short) {
            $this->assertContains($short, $known, sprintf(
                'Die Einrichtung schlägt „srvpanel %s" vor, der Wrapper kennt es nicht.',
                $short,
            ));
        }
    }

    public function test_the_writable_area_lives_outside_the_release(): void
    {
        $root = dirname(__DIR__, 2);
        $nfpm = (string) file_get_contents($root.'/packaging/nfpm.yaml');
        $build = (string) file_get_contents($root.'/packaging/build.sh');

        // Der Fund kam vom ersten echten Update: dpkg meldete fünf Mal
        // „Directory not empty" für storage-Unterverzeichnisse der alten
        // Fassung. Solange das Fassungsverzeichnis wörtlich `${VERSION}` hiess
        // und sich nie änderte, war storage versehentlich dauerhaft. Mit dem
        // richtigen Namen ist es das nicht mehr — und ab P2 stünden dort
        // Sicherungen und Kundendateien, die ein Update mitnähme.
        $this->assertMatchesRegularExpression(
            '#src:\s+/var/lib/srvpanel/storage\s+dst:\s+/opt/srvpanel/releases/\$\{VERSION\}/storage\s+type:\s+symlink#',
            $nfpm,
            'Das Paket legt storage nicht als Verweis nach /var/lib/srvpanel/storage.',
        );

        // Gegenprobe: Der Auslieferungsbaum darf storage nicht mitbringen,
        // sonst überschriebe das Verzeichnis den Verweis.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s+agent app .*\bstorage\b/m',
            $build,
            'build.sh kopiert storage in den Auslieferungsbaum — dann ist der Verweis aus nfpm.yaml wirkungslos.',
        );
    }

    public function test_no_unit_declares_the_release_storage_writable(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/packaging/systemd/*.service') ?: [] as $path) {
            // Gelesen werden die ReadWritePaths-Zeilen und nicht die Datei:
            // Der Kommentar darüber nennt den Pfad, um zu erklären, warum er
            // dort nicht mehr steht — ein Test über die ganze Datei stolperte
            // über die eigene Begründung.
            preg_match_all('/^ReadWritePaths=(.*)$/m', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $paths) {
                // storage in der Fassung ist ein Verweis. systemd löst
                // ReadWritePaths beim Start auf; fehlt das Ziel noch, startet
                // die Unit nicht — und /var/lib/srvpanel deckt es ohnehin ab.
                $this->assertStringNotContainsString(
                    '/opt/srvpanel/current/storage',
                    $paths,
                    sprintf('%s erklärt den Verweis auf storage für beschreibbar.', basename($path)),
                );
            }
        }
    }

    public function test_the_postinstall_creates_every_directory_laravel_expects(): void
    {
        $postinstall = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/scripts/postinstall.sh');

        // Laravel legt diese Verzeichnisse nicht an, es setzt sie voraus.
        // Vorher kamen sie aus dem Paket; seit dem Umzug muss sie das
        // postinst anlegen, und eine vergessene Zeile fällt erst beim ersten
        // Schreibversuch auf dem Server auf.
        $missing = [];

        foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $part) {
            if (! str_contains($postinstall, $part)) {
                $missing[] = $part;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Das postinst legt diese Verzeichnisse unter /var/lib/srvpanel/storage nicht an:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    public function test_the_panel_log_is_rotated(): void
    {
        $root = dirname(__DIR__, 2);
        $logrotate = (string) file_get_contents($root.'/packaging/etc/logrotate');

        // Das Protokoll des Panels liegt unter storage/logs und nicht unter
        // /var/log/srvpanel — es war deshalb von keiner Regel erfasst und
        // wuchs unbegrenzt. Auf einem Server, der Kunden trägt, ist eine
        // volllaufende Platte kein Schönheitsfehler.
        $this->assertStringContainsString('/var/lib/srvpanel/storage/logs/*.log', $logrotate);
    }

    public function test_the_worker_listens_on_the_queue_the_operations_go_to(): void
    {
        $unit = $this->unit('srvpanel-worker.service');

        $found = preg_match('/--queue=([a-zA-Z0-9,_\-]+)/', $unit, $matches);
        $this->assertSame(1, $found, 'Die Unit des Arbeiters nennt keine Warteschlange.');

        $queues = explode(',', $matches[1]);

        $this->assertContains(RunAgentOperation::QUEUE, $queues, sprintf(
            'Der Arbeiter horcht auf %s, Vorgänge gehen aber nach %s. '.
            'Sie blieben dann für immer auf „wartet" stehen.',
            $matches[1],
            RunAgentOperation::QUEUE,
        ));

        // Die Standardwarteschlange muss mit dabei sein: Was Laravel selbst
        // einreiht — Mails etwa — trägt keinen eigenen Namen.
        $this->assertContains((string) config('queue.connections.database.queue'), $queues);
    }

    public function test_a_dispatched_operation_carries_that_queue(): void
    {
        // Die Gegenprobe zur Unit: Der Name steht nicht nur als Konstante da,
        // der Auftrag trägt ihn auch.
        $job = new RunAgentOperation(1);

        $this->assertSame(RunAgentOperation::QUEUE, $job->queue);
    }
}
