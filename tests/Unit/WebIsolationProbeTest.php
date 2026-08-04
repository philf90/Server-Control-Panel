<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SubscriptionRemove;
use SrvPanel\Agent\Ops\WebIsolationProbe;
use SrvPanel\Agent\Runner;

/**
 * Die Selbstprobe darf nichts verraten — und der Rückbau muss sie aufrufen.
 *
 * **Beide Prüfungen stehen hier, weil zwei Gegenproben grün blieben.**
 *
 * Die erste: In der Selbstprobe wurde `is_readable()` durch
 * `file_get_contents()` ersetzt — aus einem Beleg wurde ein Leck, das bei einem
 * Fehlschlag genau die Datei ausgibt, an die niemand hätte kommen dürfen. Kein
 * Test hat das gemeldet, weil keiner den Inhalt des Skripts ansah.
 *
 * Die zweite: Aus `subscription.remove` wurde der Aufruf des Aufräumens
 * entfernt. `SubscriptionCleanupTest` blieb grün — er prüft die Methode über
 * Reflexion und damit ihre Wirkung, nicht ihren Anschluss. Eine Methode, die
 * richtig arbeitet und nicht aufgerufen wird, ist genau die Sorte Lücke, die
 * dieses Projekt kennt.
 */
final class WebIsolationProbeTest extends TestCase
{
    /** Ein Kontext, der nichts tut — die Prüfung greift vor jedem Zugriff. */
    private function context(): Context
    {
        $journal = new Journal(sys_get_temp_dir().'/srvpanel-probe-test.log');

        return new Context(new Runner($journal), $journal, static function (array $frame): void {});
    }

    private function script(): string
    {
        return WebIsolationProbe::script();
    }

    /**
     * Kein Weg, an einen Dateiinhalt zu kommen.
     *
     * Geprüft wird gegen die Funktionen, mit denen das ginge — nicht gegen
     * „file_get_contents", sondern gegen die ganze Familie. Wer eine davon
     * einsetzt, muss diesen Test ändern und dabei begründen, warum ein
     * Selbsttest den Inhalt einer fremden Datei ausgeben soll.
     */
    public function test_the_probe_never_reads_content(): void
    {
        $verboten = [
            'file_get_contents', 'fopen', 'fread', 'readfile', 'file(',
            'include', 'require', 'highlight_file', 'show_source', 'scandir',
            'glob(', 'opendir',
        ];

        foreach ($verboten as $funktion) {
            $this->assertStringNotContainsString($funktion, $this->script(), sprintf(
                'Die Selbstprobe benutzt %s. Sie soll melden, *ob* ein Zugriff ginge, und niemals *was* dabei herauskäme.',
                $funktion,
            ));
        }
    }

    /** Und sie beantwortet die fünf Fragen, für die es sie gibt. */
    public function test_the_probe_answers_what_the_criterion_asks(): void
    {
        $script = $this->script();

        // Die eigentliche Frage: kommt sie an eine fremde Datei?
        $this->assertStringContainsString('is_readable', $script);

        // Und die vier Angaben, an denen sich ablesen lässt, dass die Antwort
        // aus dem richtigen Prozess kommt.
        $this->assertStringContainsString('PHP_MAJOR_VERSION', $script);
        $this->assertStringContainsString('posix_getpwuid', $script);
        $this->assertStringContainsString('open_basedir', $script);
        $this->assertStringContainsString('shell_exec', $script);
    }

    /**
     * Das DocumentRoot kommt als Argument — und wird geprüft.
     *
     * **Warum es überhaupt eines gibt.** Vorher stand `httpdocs` fest im Code.
     * Das ist das Verzeichnis der Hauptdomain; jede Zusatzdomain liefert aus
     * einem Verzeichnis mit ihrem eigenen Namen aus. Beim Abnahmelauf auf dem
     * Zielserver lag die Selbstprobe deshalb für vier von sechs Domains am
     * falschen Ort — nginx antwortete mit 404, und der Lauf meldete „antwortet
     * nicht": eine Aussage über die Abschottung, die gar keine war.
     *
     * **Und warum der Agent sie trotzdem prüft**, obwohl der Wert aus der
     * Datenbank des Panels kommt: Was als Pfad in einer Operation ankommt,
     * prüft der Agent selbst. Die Abweisung passiert vor jedem Zugriff auf das
     * Dateisystem — deshalb lässt sie sich hier ohne Server prüfen.
     */
    public function test_a_document_root_that_leaves_the_subscription_is_refused(): void
    {
        $verboten = [
            '../../etc',
            '/etc/nginx',
            'httpdocs/../../etc',
            '..',
            '.ssh',
            'logs',          // reserviertes Verzeichnis des Schemas
            'conf',
            '',
            'httpdocs; rm -rf /',
        ];

        foreach ($verboten as $wert) {
            $abgewiesen = false;

            try {
                (new WebIsolationProbe)->execute([
                    'subscription' => 'beispiel.de',
                    'user' => 'p1001',
                    'document_root' => $wert,
                    'action' => 'place',
                ], $this->context());
            } catch (AgentException $error) {
                $abgewiesen = $error->errorCode === AgentException::BAD_REQUEST;
            }

            $this->assertTrue($abgewiesen, sprintf(
                'Das DocumentRoot „%s" wurde nicht abgewiesen. Es entscheidet, wohin der Agent als root eine Datei schreibt.',
                $wert,
            ));
        }
    }

    /**
     * Ein brauchbares Verzeichnis kommt durch die Prüfung.
     *
     * Danach scheitert der Lauf am fehlenden Abo-Verzeichnis — und genau das
     * belegt, dass die Formprüfung ihn durchgelassen hat. Ohne diese
     * Gegenrichtung wäre der Test darüber auch mit einer Prüfung bestanden,
     * die alles ablehnt.
     */
    public function test_a_usable_document_root_passes_the_check(): void
    {
        foreach (['httpdocs', 'eins-beispiel.de', 'beispiel.de/public'] as $wert) {
            try {
                (new WebIsolationProbe)->execute([
                    'subscription' => 'beispiel.de',
                    'user' => 'p1001',
                    'document_root' => $wert,
                    'action' => 'place',
                ], $this->context());

                $this->fail('Ohne Abo-Verzeichnis darf der Lauf nicht durchgehen.');
            } catch (AgentException $error) {
                $this->assertSame(AgentException::NOT_FOUND, $error->errorCode, sprintf(
                    'Das DocumentRoot „%s" wurde als unbrauchbar abgewiesen.',
                    $wert,
                ));
            }
        }
    }

    /** Ein Skript, das PHP nicht übersetzen kann, meldet gar nichts. */
    public function test_the_probe_is_valid_php(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'srvpanel-probe').'.php';
        file_put_contents($file, $this->script());

        $output = [];
        $status = 0;
        exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $status);

        @unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * Der Rückbau ruft das Aufräumen auch auf.
     *
     * **Eine Prüfung am Quelltext, und das ist Absicht.** `execute()` legt
     * Systembenutzer an und löscht Verzeichnisbäume — in diesem Container
     * läuft es nicht, und in der CI wäre es ein Lauf mit `useradd`. Was sich
     * ohne all das feststellen lässt, ist die Frage, an der die Gegenprobe
     * hängen blieb: Steht der Aufruf überhaupt da?
     *
     * Dieselbe Machart wie in `PackagingTest`, wo geprüft wird, dass eine
     * systemd-Unit ein Kommando aufruft, das es gibt.
     */
    public function test_the_teardown_calls_the_cleanup(): void
    {
        $method = new ReflectionMethod(SubscriptionRemove::class, 'execute');
        $file = (string) $method->getFileName();

        $source = implode("\n", array_slice(
            file($file) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('$this->removeConfiguration(', $source, implode(' ', [
            'subscription.remove räumt Server-Blöcke, FPM-Pools und die Rotation nicht mehr ab.',
            'Sie liegen ausserhalb des Abo-Verzeichnisses; der Baumlauf sieht sie nicht.',
        ]));

        // Und vor dem Verzeichnis: Ein nginx, das dazwischen neu lädt, fände
        // sonst ein `root`, das es nicht mehr gibt.
        $this->assertLessThan(
            strpos($source, '$this->removeRoot('),
            strpos($source, '$this->removeConfiguration('),
            'Die Konfiguration muss vor dem Verzeichnis fallen.',
        );
    }
}
