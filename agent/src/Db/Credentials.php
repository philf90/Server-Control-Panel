<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\PanelProvision;

/**
 * Zugangsdaten für einen einzelnen Lauf — als Optionsdatei, nicht als Argument.
 *
 * **Ein Passwort in der Kommandozeile steht für jeden in der Prozessliste.**
 * `mysql --password=…` ist deshalb kein Weg; `MYSQL_PWD` in der Umgebung wäre
 * der zweite und ist nur unwesentlich besser (`/proc/<pid>/environ` ist für den
 * Eigentümer lesbar, und der Runner setzt eine feste Umgebung, in die nichts
 * hineingereicht wird). Bleibt die Optionsdatei mit `0600`.
 *
 * **Sie wird geschrieben, benutzt und im `finally` entfernt.** Nicht am Ende
 * des Erfolgspfads: Ein abgebrochener Lauf, der eine Datei mit einem Passwort
 * stehenlässt, ist genau die Sorte Rest, die dieses Projekt sonst überall
 * einsammelt.
 *
 * Gebraucht wird das an genau zwei Stellen, und beide arbeiten mit einem
 * **befristeten** Benutzer (`docs/36 §10.2`): das Zurückspielen eines Dumps und
 * die Gegenprobe zur Mandantentrennung. Das Passwort eines Kunden liegt
 * nirgends (`docs/36 §4`, Entscheidung 3) und kann hier gar nicht ankommen.
 */
final class Credentials
{
    /**
     * Das Verzeichnis für die Optionsdatei.
     *
     * Unter `/run` und nicht unter `/tmp`: Es liegt im Arbeitsspeicher, gehört
     * root, und der Inhalt überlebt keinen Neustart. Ein Passwort, das nach
     * einem Absturz auf der Platte liegt, ist ein Passwort auf der Platte.
     */
    public const DIRECTORY = '/run/srvpanel';

    public function __construct(
        private readonly string $user,
        private readonly string $password,
        private readonly string $host = 'localhost',
    ) {
        /*
         * **Eine Optionsdatei hat eine eigene Syntax, und sie ist überraschend
         * schmal.** `#` beginnt einen Kommentar, führende und abschliessende
         * Leerzeichen fallen weg, `\` maskiert. Ein Passwort mit einem dieser
         * Zeichen käme anders an, als es gemeint war — und der Fehlschlag
         * hiesse „Access denied", also genau nicht „dein Passwort hat ein
         * Doppelkreuz".
         *
         * Geprüft wird gegen eine Positivliste und nicht gegen eine Liste der
         * Sonderzeichen; die Passwörter dieses Projekts entstehen ohnehin aus
         * genau diesem Alphabet ({@see PanelProvision::secret()}).
         */
        if (! preg_match('/^[A-Za-z0-9]{8,128}$/D', $password)) {
            throw AgentException::badRequest('Dieses Passwort lässt sich nicht in eine Optionsdatei schreiben.');
        }
    }

    /**
     * Die Datei schreiben und ihren Pfad zurückgeben.
     *
     * Der Aufrufer entfernt sie — siehe {@see Session}, wo das im `finally`
     * steht.
     */
    public function write(): string
    {
        if (! is_dir(self::DIRECTORY) && ! @mkdir(self::DIRECTORY, 0700, true) && ! is_dir(self::DIRECTORY)) {
            throw AgentException::execFailed('Verzeichnis für die Optionsdatei fehlt.', ['path' => self::DIRECTORY]);
        }

        $path = self::DIRECTORY.'/mysql-'.bin2hex(random_bytes(8)).'.cnf';

        // **Erst anlegen, dann füllen.** `touch` + `chmod` vor dem Schreiben:
        // Zwischen einem `file_put_contents` und einem nachträglichen `chmod`
        // liegt ein Moment, in dem die Datei mit der Maske des Prozesses
        // dasteht — und in dem sie schon das Passwort enthält.
        if (@touch($path) === false || @chmod($path, 0600) === false) {
            throw AgentException::execFailed('Optionsdatei liess sich nicht anlegen.');
        }

        $written = @file_put_contents($path, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\n",
            $this->user,
            $this->password,
            $this->host,
        ));

        if ($written === false) {
            @unlink($path);

            throw AgentException::execFailed('Optionsdatei liess sich nicht schreiben.');
        }

        return $path;
    }
}
