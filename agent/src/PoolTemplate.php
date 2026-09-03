<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Der PHP-FPM-Pool eines Abonnements — die vierte Schicht aus §6.2.
 *
 * **Hier steht das Abnahmekriterium von P3.** „Ein Skript im einen Abo kann
 * nachweislich nicht auf Dateien des anderen zugreifen": Das entscheidet sich
 * an drei Zeilen dieser Vorlage — `user`, `group` und `open_basedir`. Alles
 * darüber im Panel — Policies, Mandantenklammer, Kontingente — schützt den
 * Bestand. Diese Datei schützt das Dateisystem, und sie ist die einzige, die
 * noch greift, wenn im Panel etwas falsch ist.
 *
 * **Ein Pool je Abonnement und Version.** Nicht je Domain: Drei Domains
 * desselben Abonnements gehören demselben Systembenutzer und dürfen einander
 * lesen — sie liegen ohnehin in einem Verzeichnisbaum. Ein Pool je Domain
 * verdreifachte nur die Zahl der wartenden Prozesse.
 *
 * **`php_admin_value` und nicht `php_value`.** Der Unterschied ist die ganze
 * Abschottung: Was als `php_admin_value` im Pool steht, lässt sich weder durch
 * `ini_set()` im Skript noch durch einen `PHP_VALUE` aus dem Server-Block
 * überschreiben. Stünde `open_basedir` als `php_value` da, wäre es eine
 * Empfehlung.
 */
final class PoolTemplate
{
    /**
     * Die Funktionen, mit denen sich aus PHP ein Prozess starten lässt.
     *
     * `open_basedir` deckt die Dateiseite ab; das hier ist die Prozessseite.
     * Ohne sie führt jede Lücke in einer Kundenanwendung direkt zu einer
     * Shell als deren Systembenutzer — und von dort ist der Weg zu allem, was
     * `www-data` lesen darf, kurz.
     *
     * `mail` steht **nicht** darauf: Es startet zwar einen Prozess, aber ohne
     * Mailversand aus PHP ist ein Hosting-Paket keines. Der Weg dorthin führt
     * über P-Stufe Mail und nicht über eine abgeschaltete Funktion.
     *
     * @var list<string>
     */
    public const DISABLED_FUNCTIONS = [
        'dl',
        'exec',
        'passthru',
        'pcntl_exec',
        'popen',
        'proc_nice',
        'proc_open',
        'proc_terminate',
        'shell_exec',
        'system',
    ];

    /**
     * Was ausserhalb des Abonnements noch lesbar sein muss.
     *
     * Nur die PHP-Bibliotheken der Distribution. Kein `/tmp`: Dafür gibt es
     * `sys_temp_dir` im Abonnement — ein geteiltes `/tmp` ist der Ort, an dem
     * sich zwei Abonnements über hochgeladene Dateien und Sitzungskennungen
     * begegnen, und genau das soll hier nicht passieren.
     *
     * @var list<string>
     */
    public const SHARED_PATHS = ['/usr/share/php/'];

    /**
     * Was diese Vorlage zusagt — die Schlüssel der Abschottung (A10 Schritt 4).
     *
     * Die Bestandsdiagnose fragt die Pool-Datei auf dem Datenträger, ob diese
     * Schlüssel noch dastehen. Es sind die, an denen `PhpIsolationTest` den
     * erzeugten Text misst: Wer die Datei um einen davon kürzt, öffnet die
     * Abschottung eines Kunden. `PromiseReachTest` hält die Liste gegen die
     * Vorlage.
     *
     * @var list<string>
     */
    public const PROMISED = [
        'user', 'group',
        'listen', 'listen.owner', 'listen.group', 'listen.mode',
        'security.limit_extensions',
        'php_admin_value[open_basedir]', 'php_admin_value[disable_functions]',
        'php_admin_value[upload_tmp_dir]', 'php_admin_value[sys_temp_dir]', 'php_admin_value[session.save_path]',
    ];

    public static function render(string $subscription, string $user, string $version, int $maxChildren): string
    {
        $root = Ops\SubscriptionProvision::VHOSTS.'/'.$subscription;
        $socket = PhpVersions::socket($version, $user);
        $disabled = implode(',', self::DISABLED_FUNCTIONS);
        $basedir = implode(':', array_merge([$root.'/'], self::SHARED_PATHS));

        return <<<CONF
        ; Von srvpanel-agentd erzeugt. Änderungen von Hand werden beim nächsten
        ; Lauf überschrieben.

        [{$user}]

        user = {$user}
        group = {$user}

        ; Der Sockel gehört www-data, damit nginx hineinschreiben darf — und
        ; niemandem sonst. 0660 schliesst jedes andere Abonnement aus.
        listen = {$socket}
        listen.owner = www-data
        listen.group = www-data
        listen.mode = 0660

        ; „ondemand": Ein Abonnement ohne Besucher hält keinen Prozess. Bei
        ; hundert Abonnements auf einem Server ist das der Unterschied zwischen
        ; einem wartenden Prozess je Abonnement und keinem.
        pm = ondemand
        pm.max_children = {$maxChildren}
        pm.process_idle_timeout = 30s
        pm.max_requests = 500

        chdir = {$root}

        ; Ein Skript, das länger läuft als nginx wartet, ist ein Prozess ohne
        ; Empfänger. Ohne diese Zeile bliebe er bis zum Ende laufen und belegte
        ; einen der gedeckelten Plätze.
        request_terminate_timeout = 300s

        ; Nur .php wird ausgeführt. Die Voreinstellung erlaubt zusätzlich
        ; .phar — ein Archiv, das PHP ausführt, und damit ein zweiter Weg an
        ; jeder Prüfung vorbei, die auf die Endung .php sieht.
        security.limit_extensions = .php

        ; **Die Abschottung.** Alles darunter ist php_admin_*: weder ini_set()
        ; im Skript noch PHP_VALUE aus dem Server-Block kommen daran.
        php_admin_value[open_basedir] = {$basedir}
        php_admin_value[disable_functions] = {$disabled}

        ; Eigenes tmp und eigene Sitzungsablage (§4.5). Ohne sie lägen die
        ; Sitzungsdateien aller Abonnements in /var/lib/php/sessions, lesbar
        ; für jeden, der dort hinein darf — der klassische Weg, die Sitzung
        ; eines fremden Kunden zu übernehmen.
        php_admin_value[upload_tmp_dir] = {$root}/tmp
        php_admin_value[sys_temp_dir] = {$root}/tmp
        php_admin_value[session.save_path] = {$root}/tmp

        ; Fehler ins Protokoll des Abonnements, nicht in die Antwort. Ob sie
        ; zusätzlich im Browser erscheinen, entscheidet die Domain über
        ; display_errors — deshalb steht das hier bewusst nicht.
        php_admin_flag[log_errors] = on
        php_admin_value[error_log] = {$root}/logs/php-error.log

        CONF;
    }
}
