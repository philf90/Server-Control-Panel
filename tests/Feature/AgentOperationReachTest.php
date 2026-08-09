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
     * Aufgaben ohne Lebenslauf — mit Grund.
     *
     * Der Regelfall ist: Am Bestand des Panels ändert sich nichts. Es gibt
     * einen zweiten, und er steht seit dem zweiten Wurf von P4 in der Liste —
     * eine Aufgabe, die den Bestand ändert, aber **nicht über die
     * Warteschlange laufen darf**: Ein eingereihter Vorgang legt seine
     * Argumente in `operations.payload` ab, und ein privater Schlüssel gehört
     * dort nicht hin. Wer so etwas einträgt, schreibt den Grund dazu.
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
        'web.isolation.probe' => 'Legt die Selbstprobe des Abnahmelaufs ab und entfernt sie wieder; im Panel steht dazu nichts.',
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
        'db.remote.access' => 'Schaltet die Horchadresse des Datenbankservers um; sie ist eine Eigenschaft des Servers und steht im Panel in keinem Bestand — gefragt wird sie über db.server.info.',
        'acme.account.ensure' => 'Legt das ACME-Konto an; der Kontoschlüssel bleibt im Agenten und steht im Panel nirgends.',
        'acme.certificate.info' => 'Liest ein abgelegtes Zertifikat; der Erneuerungslauf fragt damit nach, ohne etwas zu ändern.',
        'acme.certificate.remove' => 'Entfernt den Ablageort eines verwaisten Zertifikats. `srvpanel tls --prune` ruft unmittelbar auf und löscht die Zeile danach selbst (App\Support\Tls\CertificatePrune) — ein Lebenslauf hätte hier nichts zu beantworten, denn welcher Ablageort fort darf, ist eine Frage an den Bestand und nicht an einen einzelnen Vorgang.',
        'tls.certificate.upload' => 'Der private Schlüssel darf nicht in operations.payload liegen — das Kommando ruft unmittelbar auf und schreibt den Bestand über App\Support\Tls\CertificateRecord.',
        'dns.credential.store' => 'Dasselbe für ein DNS-Token: Es überquert den Socket genau einmal und liegt danach im Agenten, nicht im Panel.',
        'dns.credential.list' => 'Zeigt, welche DNS-Profile hinterlegt sind; im Bestand des Panels steht dazu nichts.',
        'dns.credential.forget' => 'Entfernt ein DNS-Profil im Agenten; im Bestand des Panels steht dazu nichts.',

        /*
         * P5 — Datenbanken (docs/36 §8).
         *
         * Zwei Gründe, und sie stehen je Eintrag. Der erste ist der bekannte:
         * Ein eingereihter Vorgang legt seine Argumente in `operations.payload`
         * ab, und ein Datenbankpasswort gehört dort nicht hin — dieselbe Regel
         * wie bei `tls.certificate.upload` und `dns.credential.store`. Der
         * zweite ist neu: Eine Operation, die Millisekunden dauert, braucht
         * keinen Vorgang, und ihre Zeile schreibt der Dienst, nachdem der Agent
         * geantwortet hat.
         *
         * `db.database.remove` und `db.user.lock` stehen **nicht** hier — sie
         * laufen über die Warteschlange und werden von
         * `App\Support\Databases\DbLifecycle` beantwortet.
         */
        'db.server.info' => 'Liest Version, Horchadresse und liegengebliebene befristete Zugänge; im Bestand des Panels steht dazu nichts.',
        'db.database.create' => 'CREATE DATABASE dauert Millisekunden. Die Zeile schreibt App\Support\Databases\Databases, nachdem der Agent geantwortet hat — derselbe Weg wie bei CertificateRecord nach einem Hochladen.',
        'db.user.create' => 'Das Passwort darf nicht in operations.payload liegen (docs/36 §4). Der Dienst ruft unmittelbar auf und schreibt den Bestand selbst.',
        'db.user.password' => 'Dasselbe für das Zurücksetzen: Es überquert den Socket genau einmal und liegt danach nirgends — weder im Panel noch im Agenten.',
        'db.user.grant' => 'Vergibt oder nimmt ein Recht für genau ein Paar. Die Zuordnungstabelle schreibt der Dienst, nachdem der Agent geantwortet hat.',
        'db.user.remove' => 'Entfernt einen Zugang. DROP USER dauert Millisekunden, und die Zeile geht danach im selben Aufruf.',
        'db.isolation.probe' => 'Die Selbstprobe des Abnahmelaufs (docs/36 §17). Sie läuft aus `srvpanel acceptance-db`, und zwar unmittelbar: Ihr Argument ist das Passwort eines Kundenzugangs, und das gehört nicht in operations.payload — dieselbe Regel wie bei tls.certificate.upload. Im Bestand des Panels steht zu ihr nichts.',
        'db.usage' => 'Die Messung schreibt ihr Ergebnis selbst (App\Support\Databases\Usage) — wortwörtlich derselbe Grund wie bei subscription.usage: Sie läuft am Zeitgeber, niemand hat sie ausgelöst, und alle fünfzehn Minuten ein Vorgang je Abonnement wäre ein Protokoll, das niemand mehr liest.',

        /*
         * P5b — PostgreSQL (docs/38 §7).
         *
         * Nur `pg.server.info` steht hier: Sie wird unmittelbar gerufen und
         * ändert am Bestand des Panels nichts. `pg.server.install` läuft über
         * den Aufgabenkatalog ({@see Task::PostgresInstall}) und braucht
         * deshalb keinen Eintrag — genau wie `php.version.install`, der sie
         * nachgebaut ist.
         */
        'pg.server.info' => 'Liest, ob PostgreSQL installiert ist, ob ein Cluster läuft und ob die Rolle für das Panel existiert; im Bestand des Panels steht dazu nichts — wortgleich der Grund von db.server.info.',
        'pg.database.create' => 'CREATE DATABASE dauert Millisekunden. Die Zeile schreibt App\Support\Databases\Databases, nachdem der Agent geantwortet hat — wortgleich der Grund von db.database.create.',
        'pg.role.create' => 'Das Passwort darf nicht in operations.payload liegen (docs/38 §4). Der Dienst ruft unmittelbar auf und schreibt den Bestand selbst — und dieselbe Operation setzt das Passwort an einer vorhandenen Rolle, weil CREATE ROLE kein IF NOT EXISTS kennt.',
        'pg.role.grant' => 'Vergibt oder nimmt ein Recht für genau ein Paar. Die Zuordnungstabelle schreibt der Dienst, nachdem der Agent geantwortet hat.',
        'pg.role.remove' => 'Entfernt einen Zugang. DROP ROLE dauert Millisekunden, und die Zeile geht danach im selben Aufruf. Der Rückbau einer ganzen Datenbank läuft dagegen über die Warteschlange und wird von App\Support\Databases\PgLifecycle beantwortet.',
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

    /**
     * Operationen mit Begründung, die trotzdem niemand aufruft.
     *
     * **Ein Eintrag hier ist eine offene Entscheidung und kein Freibrief.** Er
     * gehört mit Datum und Grund hinein, und der nächste, der ihn liest, soll
     * ihn auflösen können — entweder wird die Operation angeschlossen oder sie
     * geht.
     *
     * @var array<string, string>
     */
    private const UNREACHED = [
        'acme.account.ensure' => 'Gefunden am 8. August 2026, als dieser Wächter entstand. Der '
            .'Kommentar der Operation sagt, die Oberfläche zeige die Adresse an dem Knopf, der '
            .'sie auslöst — diesen Knopf gibt es nicht, und `app/` nennt den Namen nirgends. In '
            .'der Praxis entsteht das ACME-Konto beim Bestellen mit (`AcmeCertificate` öffnet die '
            .'Sitzung mit einem `Account`), die Operation ist also überflüssig geworden statt '
            .'vergessen. Ob sie angeschlossen oder entfernt wird, ist eine Entscheidung mit '
            .'TLS-Folgen und gehört dem Betreiber.',
    ];

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
    /**
     * Eine Erklärung ist noch kein Aufruf.
     *
     * **Der Wächter darunter hatte eine Lücke, und sie hat drei Monate
     * gehalten.** Er nimmt eine Operation als „benutzt" an, sobald sie in
     * {@see self::WITHOUT_LIFECYCLE} steht — und dort steht sie, weil erklärt
     * ist, *warum sie keinen Lebenslauf hat*. Das ist eine andere Frage als
     * die, ob jemand sie aufruft. `db.user.grant` hatte seit P5 einen Eintrag,
     * eine fertige Methode in `Databases` und keinen einzigen Aufrufer: kein
     * Controller, keine Route, kein Test. Aufgefallen ist es einer Frage des
     * Betreibers, nicht diesem Test (docs/36 §22.3o).
     *
     * Deshalb die zweite Hälfte: Wer erklärt, dass ein Dienst unmittelbar
     * aufruft, muss zeigen, dass es diesen Dienst gibt. Gesucht wird der Name
     * im Quelltext unter `app/` — das ist grob, aber es ist die Frage, um die
     * es geht: Führt ein Weg dorthin?
     *
     * @return list<string>
     */
    private function calledInThePanel(): array
    {
        $source = '';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        $called = [];

        foreach ($this->registry()->names() as $name) {
            if (str_contains($source, "'".$name."'")) {
                $called[] = $name;
            }
        }

        return $called;
    }

    public function test_every_operation_without_a_lifecycle_is_called_somewhere(): void
    {
        $called = $this->calledInThePanel();
        $unreached = [];

        foreach (array_keys(self::WITHOUT_LIFECYCLE) as $name) {
            if (! in_array($name, $called, true) && ! array_key_exists($name, self::UNREACHED)) {
                $unreached[] = $name;
            }
        }

        $this->assertSame([], $unreached, sprintf(
            "Diese Operationen haben eine Begründung, warum sie keinen Lebenslauf brauchen —\n".
            "und niemand ruft sie auf:\n  %s\n\n".
            'Die Begründung sagt „der Dienst ruft unmittelbar auf". Dann muss es diesen Dienst '.
            'geben. Code, der als root läuft und zu dem kein Weg führt, ist Angriffsfläche ohne '.
            'Nutzen.',
            implode("\n  ", $unreached),
        ));
    }

    /**
     * Und die Ausnahmeliste altert nicht still vor sich hin.
     *
     * Steht ein Name in {@see self::UNREACHED} und wird er wieder aufgerufen,
     * gehört der Eintrag entfernt — sonst nimmt er die Operation dauerhaft von
     * der Prüfung aus.
     */
    public function test_the_list_of_unreached_operations_does_not_outlive_them(): void
    {
        $called = $this->calledInThePanel();

        foreach (array_keys(self::UNREACHED) as $name) {
            $this->assertNotContains(
                $name,
                $called,
                $name.' steht in UNREACHED und wird wieder aufgerufen. Der Eintrag gehört entfernt.',
            );
        }
    }

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
