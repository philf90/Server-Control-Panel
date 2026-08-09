<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Credentials as DbCredentials;
use SrvPanel\Agent\Ops\PanelProvision;
use SrvPanel\Agent\Ops\PgRestore;
use SrvPanel\Agent\Runner;

/**
 * Zugangsdaten für einen einzelnen Lauf — als Passwortdatei, nicht als Argument.
 *
 * Das Gegenstück zu {@see DbCredentials}, und die Begründung ist wortgleich:
 * **Ein Passwort in der Kommandozeile steht für jeden in der Prozessliste.**
 * `psql "postgresql://rolle:passwort@…"` ist deshalb kein Weg.
 *
 * ## Zwei Unterschiede zur Optionsdatei aus P5
 *
 * **Das Format ist ein anderes.** `psql` liest keine `[client]`-Abschnitte,
 * sondern eine Zeile `host:port:datenbank:benutzer:passwort` — die Datei, die
 * sonst `~/.pgpass` heisst. Der Doppelpunkt trennt, und ein Doppelpunkt *im*
 * Passwort müsste maskiert werden; hier gilt dieselbe Positivliste wie in P5
 * ({@see PanelProvision::secret()} erzeugt ohnehin nichts anderes), also gibt es
 * den Fall nicht.
 *
 * **Der Weg dorthin ist eine Umgebungsvariable und kein Argument.** `psql` kennt
 * keinen Schalter für die Passwortdatei; es liest `PGPASSFILE`. Das sieht nach
 * dem zurück, was `DbCredentials` ausdrücklich verwirft — `MYSQL_PWD` in der
 * Umgebung —, ist aber etwas anderes: In der Umgebung steht der **Pfad**, und
 * das Passwort liegt in einer Datei mit `0600` unter `/run`. Wer
 * `/proc/<pid>/environ` lesen kann, erfährt daraus einen Dateinamen.
 *
 * **Hier stand, `putenv()` genüge dafür — und das war am eigentlichen Weg
 * vorbeigemessen.** Gemessen worden war ein nacktes `proc_open()`, das die
 * Umgebung des Elternprozesses erbt; {@see Runner} setzt aber
 * eine **feste** Umgebung, und in die reicht `putenv()` nichts hinein. Der
 * erste Lauf endete mit `fe_sendauth: no password supplied`. Das ist derselbe
 * Fehler wie dreimal zuvor in P5b: **den Stellvertreter gemessen statt die
 * Sache.**
 *
 * Der Runner hat deshalb eine Positivliste für Umgebungsvariablen bekommen
 * ({@see Runner::ENVIRONMENT_ALLOWED}), auf der `PGPASSFILE`
 * der einzige Eintrag ist.
 *
 * **Sie wird geschrieben, benutzt und im `finally` entfernt**, wie in P5: Ein
 * abgebrochener Lauf, der eine Datei mit einem Passwort stehenlässt, ist genau
 * die Sorte Rest, die dieses Projekt sonst überall einsammelt.
 */
final class Credentials
{
    /** Der Name der Umgebungsvariable, die `psql` liest. */
    public const VARIABLE = 'PGPASSFILE';

    public function __construct(
        private readonly string $role,
        private readonly string $password,
    ) {
        if (! preg_match('/^[A-Za-z0-9]{8,128}$/D', $password)) {
            throw AgentException::badRequest('Dieses Passwort lässt sich nicht in eine Passwortdatei schreiben.');
        }
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * Die Datei schreiben und ihren Pfad zurückgeben.
     *
     * Der Aufrufer entfernt sie — das steht in {@see PgRestore::feed()} im
     * `finally`. Die Umgebungsvariable gibt es nur für die Dauer des Kindes;
     * der Agent selbst bekommt sie nie zu sehen.
     */
    public function write(): string
    {
        $directory = DbCredentials::DIRECTORY;

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw AgentException::execFailed('Verzeichnis für die Passwortdatei fehlt.', ['path' => $directory]);
        }

        $path = $directory.'/pgpass-'.bin2hex(random_bytes(8));

        // **Erst anlegen, dann füllen** — wortgleich die Begründung aus P5:
        // Zwischen einem `file_put_contents` und einem nachträglichen `chmod`
        // liegt ein Moment, in dem die Datei mit der Maske des Prozesses
        // dasteht und schon das Passwort enthält. `psql` weist eine
        // Passwortdatei mit weiteren Rechten ausserdem zurück.
        if (@touch($path) === false || @chmod($path, 0600) === false) {
            throw AgentException::execFailed('Passwortdatei liess sich nicht anlegen.');
        }

        /*
         * `localhost` als Wirt und nicht `*`: Über den Unix-Socket sucht `psql`
         * die Zeile unter genau diesem Namen. Ein Stern täte es auch — er
         * passte aber ebenso auf eine Verbindung nach aussen, und diese Datei
         * ist für einen Lauf über den Socket geschrieben.
         *
         * Die Datenbank steht als `*`, weil der Name des Laufs erst im Aufrufer
         * feststeht; erreichbar ist ohnehin nur die eine, für die
         * {@see Ephemeral} `CONNECT` vergibt.
         */
        $written = @file_put_contents($path, sprintf(
            "localhost:*:*:%s:%s\n",
            $this->role,
            $this->password,
        ));

        if ($written === false) {
            @unlink($path);

            throw AgentException::execFailed('Passwortdatei liess sich nicht schreiben.');
        }

        return $path;
    }
}
