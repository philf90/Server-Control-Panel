<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\DocumentRoot;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die Selbstprobe der Abschottung — ein Skript, das versucht, was es nicht darf.
 *
 * **Warum es diese Operation gibt.** Das Abnahmekriterium von P3 lautet: „ein
 * Skript im einen Abo kann **nachweislich** nicht auf Dateien des anderen
 * zugreifen." Nachweislich heisst: ausgeführt, nicht gelesen. Ein Blick in die
 * Pool-Vorlage zeigt, dass `open_basedir` dasteht; er zeigt nicht, dass PHP es
 * anwendet, dass nginx den richtigen Sockel trifft und dass der Pool unter dem
 * richtigen Benutzer läuft. Das zeigt nur ein Skript, das es versucht.
 *
 * **Der Inhalt steht hier und kommt nicht von aussen.** Das ist die Bedingung,
 * unter der diese Operation vertretbar ist: Sie schreibt eine Datei in das
 * Verzeichnis eines Kunden — aber eine feste, aus dem Quelltext des Agenten.
 * Dieselbe Regel wie bei der Willkommensseite, die `subscription.provision`
 * anlegt. Käme der Inhalt als Argument, wäre das eine Fernsteuerung zum
 * Ablegen beliebigen PHP-Codes unter fremdem Namen.
 *
 * **Die Probe verrät nichts.** Sie antwortet mit „lesbar: ja/nein" und niemals
 * mit dem Inhalt einer Datei. Ein Selbsttest, der bei einem Fehlschlag die
 * Datei ausgibt, an die er nicht hätte kommen dürfen, hat aus einem Beleg ein
 * Leck gemacht.
 *
 * **Sie wird wieder entfernt.** Der Abnahmelauf legt sie ab, fragt sie über
 * HTTP und räumt sie weg. Bleibt sie liegen, steht im Verzeichnis eines Kunden
 * ein Skript, das Dateien auf Lesbarkeit prüft — harmlos innerhalb der
 * Abschottung, aber nichts, was dort dauerhaft zu suchen hätte.
 */
final class WebIsolationProbe implements Op
{
    /** Der Name der Datei. Auffällig genug, dass niemand sie für seine hält. */
    public const FILENAME = 'srvpanel-selbsttest.php';

    public static function name(): string
    {
        return 'web.isolation.probe';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $action = Guard::enum($args['action'] ?? 'place', ['place', 'remove'], 'action');

        /*
         * **Das Verzeichnis kommt als Argument, und es wird geprüft.**
         *
         * Vorher stand hier fest `httpdocs`. Das ist das DocumentRoot der
         * Hauptdomain; jede Zusatzdomain liefert aus einem Verzeichnis mit
         * ihrem eigenen Namen aus (§4.5). Die Selbstprobe lag deshalb für vier
         * von sechs Domains am falschen Ort, nginx antwortete mit 404, und der
         * Abnahmelauf meldete „antwortet nicht" — eine Aussage über die
         * Abschottung, die gar keine war.
         *
         * `DocumentRoot::valid()` ist derselbe Wächter, den auch das Panel
         * benutzt: kein führender Schrägstrich, kein `..`, kein reserviertes
         * Verzeichnis des Schemas. Der Wert kommt aus der Datenbank des Panels
         * und wird hier trotzdem geprüft — was als Pfad in einer Operation
         * ankommt, prüft der Agent selbst.
         */
        $documentRoot = $args['document_root'] ?? SubscriptionProvision::DOCUMENT_ROOT;

        if (! is_string($documentRoot) || ! DocumentRoot::valid($documentRoot)) {
            throw new AgentException(
                AgentException::BAD_REQUEST,
                'Das DocumentRoot ist kein Verzeichnis innerhalb des Abonnements.',
                ['document_root' => is_string($documentRoot) ? $documentRoot : gettype($documentRoot)],
            );
        }

        $root = SubscriptionProvision::VHOSTS.'/'.$subscription;
        $file = $root.'/'.$documentRoot.'/'.self::FILENAME;

        if (! is_dir($root)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Das Abonnement hat kein Verzeichnis.',
                ['subscription' => $subscription],
            );
        }

        if ($action === 'remove') {
            $existed = is_file($file);

            if ($existed) {
                unlink($file);
            }

            return ['path' => $file, 'document_root' => $documentRoot, 'placed' => false, 'existed' => $existed];
        }

        file_put_contents($file, self::script());

        // Dem Kunden gehören, damit der Pool sie lesen darf — er läuft unter
        // dessen Benutzer. `0640` und die Gruppe www-data: dieselbe Regel wie
        // bei der Willkommensseite.
        chown($file, $user);
        chgrp($file, posix_getgrnam('www-data') !== false ? 'www-data' : $user);
        chmod($file, 0o640);

        // Dasselbe Verzeichnis, in dem die Datei liegt — nicht das der
        // Hauptdomain. Sonst bekämen bei einer Zusatzdomain die Rechte des
        // einen Verzeichnisses und die Datei des anderen gesetzt.
        Filesystem::directory($root.'/'.$documentRoot, $user, 'www-data', 0o750);

        return ['path' => $file, 'document_root' => $documentRoot, 'placed' => true, 'existed' => true];
    }

    /**
     * Das Skript.
     *
     * Es beantwortet fünf Fragen, und jede davon ist eine Zeile aus der
     * Pool-Vorlage, die man sonst nur glauben kann:
     *
     * 1. Welche PHP-Version antwortet wirklich? (`php_version` je Domain)
     * 2. Unter welchem Benutzer läuft der Prozess? (`user` im Pool)
     * 3. Was sagt `open_basedir` — und wendet PHP es an?
     * 4. Lässt sich eine fremde Datei lesen? (die eigentliche Frage)
     * 5. Sind die Funktionen abgeschaltet, die einen Prozess starten?
     *
     * Der Zielpfad kommt aus der Anfrage, und das ist hier richtig: Das Skript
     * läuft *innerhalb* der Abschottung, mehr als sie erlaubt kann es nicht
     * lesen — und genau das soll es zeigen. Ausgegeben wird nie ein Inhalt,
     * nur ob ein Zugriff ginge.
     */
    public static function script(): string
    {
        return <<<'PHP'
        <?php
        // Von srvpanel-agentd erzeugt — Selbstprobe der Abschottung (P3).
        // Diese Datei legt der Abnahmelauf ab und entfernt sie wieder.
        // Sie gibt niemals den Inhalt einer Datei aus, nur ob ein Zugriff ginge.

        header('Content-Type: application/json');

        $ziel = isset($_GET['ziel']) && is_string($_GET['ziel']) ? $_GET['ziel'] : '';

        $benutzer = '?';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $eintrag = @posix_getpwuid(posix_geteuid());
            $benutzer = is_array($eintrag) ? (string) $eintrag['name'] : '?';
        }

        $gesperrt = [];
        foreach (['exec', 'shell_exec', 'passthru', 'system', 'proc_open', 'popen'] as $funktion) {
            $gesperrt[$funktion] = ! function_exists($funktion);
        }

        echo json_encode([
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'user' => $benutzer,
            'open_basedir' => (string) ini_get('open_basedir'),
            'ziel' => $ziel,
            'lesbar' => $ziel === '' ? null : (bool) @is_readable($ziel),
            'gesperrt' => $gesperrt,
        ]);

        PHP;
    }
}
