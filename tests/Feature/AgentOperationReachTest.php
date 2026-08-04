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
 * Zeigt jeder Operationsname des Panels auf eine Operation, die es gibt?
 *
 * **Der Fund, aus dem dieser Test entstanden ist.** Mit P3 stehen die Namen
 * der Agent-Operationen als Zeichenketten in zehn Dateien: `web.site.apply` im
 * Lebenslauf, `php.versions` im Steuerungscode, `subscription.remove` im
 * Abonnement, `panel.tls.info` in den Einstellungen. Geprüft hat sie nichts.
 *
 * Das ist wortwörtlich das Muster, das CLAUDE.md als das wiederkehrende
 * beschreibt — „eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ,
 * ein Test oder ein Werkzeug den Bezug prüft" — und es ist diesmal an einer
 * besonders unangenehmen Stelle: Ein Tippfehler in `web.site.aply` fällt nicht
 * beim Übersetzen auf, nicht in der Oberfläche und nicht im Test. Er fällt auf,
 * wenn ein Kunde eine Domain anlegt und der Vorgang mit „Unbekannte Operation"
 * scheitert.
 *
 * Der Test hält **drei** Listen zusammen:
 *
 * 1. was das Panel abschickt (die Zeichenketten unten),
 * 2. was der Agent kennt ({@see Registry::names()}),
 * 3. was ein Lebenslauf danach beantwortet ({@see Lifecycles::handled()}).
 */
final class AgentOperationReachTest extends TestCase
{
    /**
     * Aufgaben, nach denen sich am Bestand des Panels nichts ändert.
     *
     * Der Grund steht im Wert und nicht in einem Kommentar daneben: Eine Liste
     * ohne Begründung je Eintrag wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const WITHOUT_LIFECYCLE = [
        'agent.ping' => 'Fragt nur nach der Version des Agenten.',
        'system.info' => 'Liest Kennzahlen für die Übersicht.',
        'service.status' => 'Liest den Zustand einer Unit.',
        'service.action' => 'Startet, stoppt oder lädt eine Unit — das Panel führt darüber keinen Bestand.',
        'config.validate' => 'Prüft eine Konfigurationsdatei.',
        'webserver.detect' => 'Schaut nach, welcher Webserver läuft.',
        'web.logs.tail' => 'Liest die letzten Zeilen eines Protokolls.',
        'web.logrotate.apply' => 'Schreibt eine logrotate-Datei; im Panel steht dazu nichts.',
        'php.pool.apply' => 'Der Pool gehört zum Abonnement und hat keinen eigenen Zustand im Panel.',
        'php.pool.remove' => 'Dasselbe in der Gegenrichtung.',
        'subscription.usage' => 'Die Messung schreibt ihr Ergebnis selbst (App\Support\Subscriptions\Usage).',
        'subscription.quota' => 'Setzt ein Kontingent; der Zustand des Abonnements bleibt derselbe.',
        'panel.provision' => 'Ersteinrichtung, läuft aus einem Konsolenkommando.',
        'panel.update' => 'Aktualisierung, läuft aus einem Konsolenkommando.',
        'panel.tls.ensure' => 'Stellt das Zertifikat der Oberfläche aus; es steht nicht im Bestand.',
        'panel.tls.info' => 'Liest das Zertifikat der Oberfläche.',
        'panel.vhost.apply' => 'Schreibt den Server-Block des Panels.',
    ];

    private function registry(): Registry
    {
        return new Registry(new Config);
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
     * Jede Zeichenkette im Panel, aus der eine Operation des Agenten wird.
     *
     * Gesucht wird an den drei Stellen, an denen das sichtbar passiert, und
     * nicht nach „irgendetwas mit einem Punkt darin": Die Protokollereignisse
     * heissen `subscription.updated` und `auth.login` und sähen sonst aus wie
     * Operationen.
     *
     * **Das ist ein Netz und keine vollständige Liste.** Wo ein Name durch eine
     * eigene Methode gereicht wird, findet ihn dieser Ausdruck nicht — die
     * Vollständigkeit trägt die erklärte Liste in
     * {@see self::test_every_operation_of_the_agent_is_used()}.
     *
     * @return array<string, list<string>> Name => Fundstellen
     */
    private function dispatched(): array
    {
        $found = [];

        foreach ($this->phpFiles('app') as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);

            $patterns = [
                // Der unmittelbare Aufruf: $agent->call('panel.tls.info')
                '/->call\(\s*\'([a-z][a-z0-9.]*)\'/',

                // Die Operation eines Vorgangs — was RunAgentOperation sendet.
                '/\'type\'\s*=>\s*\'([a-z][a-z0-9.]*)\'/',

                // Ein Vorgang für ein Objekt: dispatch($domain, 'web.site.apply', …)
                //
                // `dispatch[A-Za-z]*` und nicht `dispatch`: Es gibt daneben
                // `dispatchForSubscription()`, und die erste Fassung dieses
                // Ausdrucks sah sie nicht — ein Tippfehler in dem Namen, den
                // sie abschickt, blieb in der Gegenprobe unbemerkt.
                '/->dispatch[A-Za-z]*\(\s*\$[a-zA-Z]+,\s*\'([a-z][a-z0-9.]*)\'/',
            ];

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] as $name) {
                    $found[$name][] = $relative;
                }
            }
        }

        return $found;
    }

    public function test_every_operation_the_panel_sends_exists_in_the_agent(): void
    {
        $known = $this->registry()->names();
        $dispatched = $this->dispatched();

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThan(10, count($dispatched), 'Es werden kaum Operationsnamen gefunden — dann prüft dieser Test nichts.');

        $unknown = [];

        foreach ($dispatched as $name => $places) {
            if (! in_array($name, $known, true)) {
                $unknown[] = sprintf('%s (%s)', $name, implode(', ', array_unique($places)));
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "Diese Namen schickt das Panel an den Agenten, und der kennt sie nicht:\n  %s\n\n".
            'Bekannt sind: %s',
            implode("\n  ", $unknown),
            implode(', ', $known),
        ));
    }

    /**
     * Die Gegenrichtung: Beantwortet jede Aufgabe jemand?
     *
     * Eine Aufgabe ohne Lebenslauf läuft durch, der Agent tut seine Arbeit, und
     * im Panel ändert sich nichts — ohne Fehler und ohne Meldung. Wer eine
     * dazunimmt, muss deshalb entweder einen Lebenslauf ergänzen oder hier
     * aufschreiben, warum keiner nötig ist.
     */
    public function test_every_dispatched_task_is_answered_or_declared(): void
    {
        $handled = Lifecycles::handled();
        $offen = [];

        foreach (array_keys($this->dispatched()) as $name) {
            if (in_array($name, $handled, true)) {
                continue;
            }

            if (array_key_exists($name, self::WITHOUT_LIFECYCLE)) {
                continue;
            }

            $offen[] = $name;
        }

        $this->assertSame([], $offen, sprintf(
            "Zu diesen Aufgaben gibt es keinen Lebenslauf und keine Begründung:\n  %s\n\n".
            'Entweder fehlt die Antwort in einem AfterOperation — oder sie ändert nichts, '.
            'und dann gehört sie mit Grund in AgentOperationReachTest::WITHOUT_LIFECYCLE.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Und was ein Lebenslauf beantwortet, muss es geben.
     *
     * Der Fehler in dieser Richtung ist stiller als der andere: Ein
     * Lebenslauf, der auf `web.site.aply` wartet, wartet für immer. Der Vorgang
     * läuft, der Agent antwortet, und der Zustand bleibt auf „wird angelegt"
     * stehen.
     */
    public function test_every_handled_task_is_an_operation_of_the_agent(): void
    {
        $known = $this->registry()->names();

        $this->assertNotSame([], Lifecycles::handled(), 'Kein Lebenslauf beantwortet etwas — dann prüft dieser Test nichts.');

        foreach (Lifecycles::handled() as $task) {
            $this->assertContains($task, $known, sprintf(
                'Ein Lebenslauf wartet auf %s; der Agent kennt diese Operation nicht.',
                $task,
            ));
        }
    }

    /**
     * Und die Begründungen zeigen ebenfalls auf etwas Vorhandenes.
     *
     * Dieselbe Gegenrichtung wie in `RouteGuard`: Eine Ausnahme für eine
     * Operation, die es nicht mehr gibt, fällt sonst nie auf — und deckt
     * irgendwann etwas, an das niemand mehr gedacht hat.
     */
    public function test_every_declared_exception_is_still_an_operation(): void
    {
        $known = $this->registry()->names();

        foreach (array_keys(self::WITHOUT_LIFECYCLE) as $name) {
            $this->assertContains($name, $known, sprintf(
                'WITHOUT_LIFECYCLE nennt %s; diese Operation gibt es im Agenten nicht mehr.',
                $name,
            ));
        }
    }

    /**
     * Jede Operation des Agenten wird auch benutzt.
     *
     * Eine Operation, die niemand aufruft, ist Code, der als root läuft und für
     * den es keinen Weg gibt — die Angriffsfläche ohne den Nutzen.
     *
     * **Gefragt wird die erklärte Liste und nicht der Quelltext.** Der erste
     * Entwurf suchte die Namen an den Aufrufstellen, und das ging schief: Der
     * Steuerungscode der Abonnements reicht sie über eine eigene Methode
     * durch, und `subscription.provision` sah dadurch unbenutzt aus. Ein
     * Ausdruck, der jede Schreibweise eines Aufrufs erraten muss, ist kein
     * Wächter, sondern eine zweite Fehlerquelle. Die Liste aus
     * {@see Lifecycles::handled()} und {@see self::WITHOUT_LIFECYCLE} ist
     * dagegen eine Aussage, die jemand hinschreibt — und sie wird in beide
     * Richtungen gegen die Registratur gehalten.
     *
     * Genau so gefunden: `php.pool.remove` und `web.logrotate.apply` waren in
     * P3 gebaut und von nichts aufgerufen.
     */
    public function test_every_operation_of_the_agent_is_used(): void
    {
        $erklärt = array_merge(Lifecycles::handled(), array_keys(self::WITHOUT_LIFECYCLE));

        foreach (Task::cases() as $task) {
            $erklärt[] = $task->operation();
        }

        $unused = [];

        foreach ($this->registry()->names() as $name) {
            if (! in_array($name, $erklärt, true)) {
                $unused[] = $name;
            }
        }

        $this->assertSame([], $unused, sprintf(
            "Diese Operationen kennt der Agent, und niemand ruft sie auf:\n  %s\n\n".
            'Code, der als root läuft und zu dem es keinen Weg gibt, ist Angriffsfläche ohne Nutzen.',
            implode("\n  ", $unused),
        ));
    }
}
