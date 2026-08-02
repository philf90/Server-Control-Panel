<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\EnvFile;
use SrvPanel\Agent\Op;

/**
 * Richtet die Grundlage des Panels ein: Datenbank, Datenbankbenutzer,
 * Anwendungsschlüssel und die Umgebungsdatei.
 *
 * **Warum die Geheimnisse den Socket nie überqueren.** Datenbankpasswort und
 * APP_KEY entstehen hier, im Agenten, und werden von hier aus in eine Datei
 * geschrieben, die nur root schreiben und nur die Gruppe des Panels lesen
 * kann. Die Anwendung erfährt sie nie über das Protokoll — damit können sie
 * auch in keinem Protokoll, keiner Fehlermeldung und keinem Speicherabzug der
 * Anwendung auftauchen.
 *
 * **Warum die Umgebungsdatei nicht im Auslieferungsverzeichnis liegt.**
 * `/opt/srvpanel/releases/<version>/` wird bei jedem Update ersetzt. Eine
 * `.env` darin wäre nach dem ersten Update weg — samt Schlüssel, mit dem alle
 * verschlüsselten Werte in der Datenbank lesbar sind. Sie liegt deshalb unter
 * `/etc/srvpanel/panel.env`, und die Anwendung liest sie von dort.
 *
 * Der Lauf ist wiederholbar: Bestehende Werte bleiben stehen. Ein zweites
 * `srvpanel setup` darf keinen Schlüssel wechseln — sonst ist die Datenbank
 * danach unlesbar.
 */
final class PanelProvision implements Op
{
    private readonly EnvFile $env;

    public function __construct(?EnvFile $env = null)
    {
        $this->env = $env ?? new EnvFile('/etc/srvpanel/panel.env');
    }

    public static function name(): string
    {
        return 'panel.provision';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $existing = $this->env->read();
        $isNew = $existing === [];

        $database = $existing['DB_DATABASE'] ?? 'srvpanel';
        $user = $existing['DB_USERNAME'] ?? 'srvpanel';
        $password = $existing['DB_PASSWORD'] ?? $this->secret(24);
        $key = $existing['APP_KEY'] ?? 'base64:'.base64_encode(random_bytes(32));
        $port = $existing['PANEL_PORT'] ?? (string) (is_int($args['port'] ?? null) ? $args['port'] : 8443);

        $context->progress(20, 'Datenbank anlegen');
        $this->database($context, $database, $user, $password);

        $context->progress(60, 'Umgebungsdatei schreiben');
        $this->env->write(array_merge($existing, [
            'APP_NAME' => 'SrvPanel',
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://'.php_uname('n').':'.$port,
            'PANEL_PORT' => $port,
            'LOG_CHANNEL' => 'stack',
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
            'SESSION_DRIVER' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'SRVPANEL_AGENT_SOCKET' => '/run/srvpanel/agent.sock',
            'SRVPANEL_METRICS_DIR' => '/var/lib/srvpanel/metrics',
        ]));

        $context->progress(100, 'fertig');

        return [
            'database' => $database,
            'user' => $user,
            'env' => $this->env->path(),
            'port' => (int) $port,
            'created' => $isNew,
        ];
    }

    /**
     * Datenbank und Benutzer anlegen.
     *
     * Das SQL geht über die Standardeingabe und nicht als Argument: Ein
     * Passwort in der Kommandozeile stünde für jeden sichtbar in der
     * Prozessliste. Angemeldet wird über den Unix-Socket als root — deshalb
     * braucht dieser Lauf kein Datenbankpasswort.
     */
    private function database(Context $context, string $database, string $user, string $password): void
    {
        foreach ([$database, $user] as $identifier) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,30}$/', $identifier)) {
                throw AgentException::badRequest('Unzulässiger Datenbank- oder Benutzername.');
            }
        }

        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            ."CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';\n"
            ."ALTER USER '%s'@'localhost' IDENTIFIED BY '%s';\n"
            ."GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'localhost';\n"
            ."FLUSH PRIVILEGES;\n",
            $database,
            $user,
            $this->escape($password),
            $user,
            $this->escape($password),
            $database,
            $user,
        );

        $result = $context->runner->run('mysql', ['--protocol=socket', '--batch'], 60, null, $sql);

        if (! $result->successful()) {
            throw AgentException::execFailed('Die Datenbank ließ sich nicht anlegen: '.$result->message());
        }
    }

    /** Das Passwort kommt aus dieser Klasse und enthält nie ein Sonderzeichen — der Schutz ist trotzdem da. */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function secret(int $bytes): string
    {
        // Ohne Sonderzeichen: Das Passwort steht später in einer Umgebungsdatei
        // und in einer SQL-Anweisung. Zeichen, die in einer der beiden
        // Bedeutung haben, sind hier kein Gewinn an Stärke, sondern eine
        // Fehlerquelle.
        return substr(rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'xy'), '='), 0, 32);
    }
}
