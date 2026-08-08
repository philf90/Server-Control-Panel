<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Operations\Lifecycles;
use App\Support\Operations\Task;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Ein Geheimnis überquert den Socket, es liegt nicht in der Warteschlange.
 *
 * **Die Regel gilt seit P4 und war bis P5 eine Gewohnheit.** Ein eingereihter
 * Vorgang legt seine Argumente in `operations.payload` ab — dauerhaft, im
 * Klartext, in der Datenbank des Panels. Für `tls.certificate.upload` (privater
 * Schlüssel) und `dns.credential.store` (DNS-Token) steht der Grund seit dem
 * zweiten Wurf von P4 als Begründung in
 * {@see AgentOperationReachTest::WITHOUT_LIFECYCLE}. Durchgesetzt hat ihn
 * nichts: Wer eine dieser Operationen versehentlich über
 * `Operation::query()->create(['type' => …])` einreihte, bekam kein Rot, sondern
 * ein Passwort in einer Tabelle.
 *
 * P5 macht die Regel zum dritten und vierten Mal nötig (`db.user.create`,
 * `db.user.password`). Beim dritten Mal wird aus einer Gewohnheit ein Wächter.
 *
 * **Zwei Hälften.** Die eine prüft den Weg: Keine dieser Operationen wird
 * eingereiht. Die andere prüft die Ablage: Keine Tabelle des Panels hat eine
 * Spalte, in die ein solches Geheimnis passte.
 */
final class SecretsStayOutOfTheQueueTest extends TestCase
{
    /**
     * Operationen, deren Argumente ein Geheimnis tragen — mit Angabe, welches.
     *
     * Der Wert ist die Begründung und nicht ein Kommentar daneben: Eine Liste
     * ohne Grund je Eintrag wächst, bis sie alles enthält, und dann prüft sie
     * nichts mehr.
     *
     * @var array<string, string>
     */
    private const CARRIES_A_SECRET = [
        'tls.certificate.upload' => 'der private Schlüssel des hochgeladenen Zertifikats',
        'dns.credential.store' => 'das API-Token des DNS-Anbieters',
        'db.user.create' => 'das Passwort des Datenbankbenutzers',
        'db.user.password' => 'dasselbe Passwort beim Zurücksetzen',
    ];

    /**
     * Die Namen aller Operationen des Agenten.
     *
     * @return list<string>
     */
    private function names(): array
    {
        return (new Registry(new Config))->names();
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Keine dieser Operationen wird eingereiht.
     *
     * Gesucht wird an den beiden Stellen, an denen ein Vorgang entsteht: die
     * Zeile `'type' => '…'` beim Anlegen und der Aufruf
     * `->dispatch…($objekt, '…')`. Beide Formen kennt schon
     * {@see AgentOperationReachTest::dispatched()} — hier stehen sie noch
     * einmal, weil dieser Test eine andere Frage stellt und sie ohne die
     * Fundstelle nicht beantworten kann.
     */
    public function test_no_secret_carrying_operation_is_queued(): void
    {
        $found = [];
        $searched = 0;

        foreach ($this->phpFiles('app') as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);
            $searched++;

            foreach ([
                '/\'type\'\s*=>\s*\'([a-z][a-z0-9.]*)\'/',
                '/->dispatch[A-Za-z]*\(\s*\$[a-zA-Z]+,\s*\'([a-z][a-z0-9.]*)\'/',
            ] as $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] as $name) {
                    if (array_key_exists($name, self::CARRIES_A_SECRET)) {
                        $found[] = sprintf('%s in %s', $name, $relative);
                    }
                }
            }
        }

        // Ein Ausdruck, der keine Datei liest, ist kein bestandener Test.
        $this->assertGreaterThan(20, $searched, 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Operationen tragen ein Geheimnis und werden über die Warteschlange eingereiht:\n  %s\n\n"
            .'Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab — dauerhaft und im '
            .'Klartext. Sie gehören als unmittelbarer Aufruf (`Client::call`) an den Agenten, und die Zeile '
            .'schreibt der Dienst danach selbst.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und keine von ihnen wird von einem Lebenslauf erwartet.
     *
     * Ein Lebenslauf beantwortet ausschliesslich Aufgaben aus der
     * Warteschlange. Stünde eine dieser Operationen in
     * {@see Lifecycles::handled()}, wäre das entweder ein Lebenslauf, der ewig
     * wartet — oder der Beleg, dass sie doch eingereiht wird.
     */
    public function test_no_secret_carrying_operation_expects_a_lifecycle(): void
    {
        $handled = Lifecycles::handled();

        foreach (array_keys(self::CARRIES_A_SECRET) as $name) {
            $this->assertNotContains($name, $handled, sprintf(
                '%s trägt ein Geheimnis und darf nicht über die Warteschlange laufen; ein Lebenslauf dafür wäre der Beleg, dass sie es doch tut.',
                $name,
            ));
        }
    }

    /** Und sie steht auch nicht im Aufgabenkatalog, den der Browser auslösen darf. */
    public function test_no_secret_carrying_operation_is_in_the_task_catalogue(): void
    {
        foreach (Task::cases() as $task) {
            $this->assertArrayNotHasKey($task->operation(), self::CARRIES_A_SECRET, sprintf(
                'Die Aufgabe %s schickt %s ab; der Katalog läuft über die Warteschlange.',
                $task->value,
                $task->operation(),
            ));
        }
    }

    /**
     * Die Begründungen zeigen auf etwas Vorhandenes.
     *
     * Dieselbe Gegenrichtung wie in {@see RemovalPathTest} und in `RouteGuard`:
     * Eine Ausnahme für eine Operation, die es nicht mehr gibt, fällt sonst nie
     * auf.
     */
    public function test_every_declared_operation_still_exists(): void
    {
        $names = $this->names();

        foreach (array_keys(self::CARRIES_A_SECRET) as $name) {
            $this->assertContains($name, $names, sprintf(
                'CARRIES_A_SECRET nennt %s; diese Operation gibt es im Agenten nicht mehr.',
                $name,
            ));
        }
    }

    /**
     * Und die zweite Hälfte: keine Spalte, in die ein Geheimnis passte.
     *
     * **Am Schema und nicht an einer Absicht** — eine Spalte, die es nicht
     * gibt, lässt sich nicht versehentlich füllen. Gelesen wird die Migration
     * als Text, weil dieser Test ohne Datenbank läuft; das ist dieselbe Bauart
     * wie bei den Vorlagen, die `SiteTemplateTest` als Zeichenkette prüft.
     */
    public function test_the_database_tables_have_no_place_for_a_secret(): void
    {
        $migrations = $this->phpFiles('database/migrations');
        $found = [];
        $read = 0;

        foreach ($migrations as $path) {
            if (! str_contains($path, 'databases_tables')) {
                continue;
            }

            $read++;
            $source = (string) file_get_contents($path);

            foreach (['password', 'secret', 'token'] as $word) {
                if (preg_match('/\$table->[a-zA-Z]+\(\s*\'[^\']*'.$word.'/i', $source) === 1) {
                    $found[] = sprintf('%s in %s', $word, str_replace(dirname(__DIR__, 2).'/', '', $path));
                }
            }
        }

        $this->assertSame(1, $read, 'Die Migration der Datenbanktabellen wurde nicht gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "In diesen Spalten liesse sich ein Geheimnis ablegen:\n  %s\n\n"
            .'Das Datenbankpasswort wird erzeugt, einmal angezeigt und vergessen (docs/36 §4, Entscheidung 3).',
            implode("\n  ", $found),
        ));
    }
}
