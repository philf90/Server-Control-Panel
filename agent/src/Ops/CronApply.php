<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\Cron\Schedule;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Den Zeitplan eines Abonnements neu schreiben — den **ganzen**.
 *
 * ## Sollzustand, nicht Fortschreibung
 *
 * Der Aufruf trägt jeden Job, den es geben soll, und die Operation stellt genau
 * das her: die Datei unter `/etc/cron.d`, die Befehlsdateien daneben, und das
 * Wegräumen dessen, was nicht mehr genannt ist. Dieselbe Bauart wie
 * {@see SftpKeyApply} und {@see SftpAccess} — und aus demselben Grund:
 *
 * > **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
 * > anderen Vorgänge derselben Reihe nicht.**
 *
 * ## Die Reihenfolge, und warum sie so ist
 *
 * Erst die Befehlsdateien, dann die Cron-Datei. Ein Befehl ohne Zeile ist ein
 * Rest, der nichts tut; eine Zeile ohne Befehl ist ein Job, der jede Minute mit
 * „Zu Job 1234 gibt es keinen Befehl" fehlschlägt. Von zwei unvollständigen
 * Zuständen ist der stumme der bessere.
 *
 * ## Was hier geprüft wird, bevor irgendetwas geschrieben wird
 *
 * `docs/60 §9` hat gemessen, dass cron eine Datei mit **einem** Fehler ganz
 * verwirft — und dass ein Benutzername, den es nicht gibt, denselben Ausfall
 * auslöst wie eine kaputte Zeitangabe. Beides trifft nicht den einen Job,
 * sondern alle Jobs des Kunden, und cron sagt es nur seinem Protokoll.
 *
 * Ein `cron -t` gibt es nicht. Deshalb steht die Prüfung hier, vor dem ersten
 * Schreibzugriff, und sie hat drei Teile: die fünf Felder ({@see Schedule}), den
 * Dateinamen ({@see CronFile::name()}) und die **Existenz des Systembenutzers**.
 *
 * > **Ein Fehler, der die ganze Datei verwirft, ist kein Fehler an einem Job.**
 */
final class CronApply implements Op
{
    /**
     * Wie viele Jobs ein Abonnement haben darf.
     *
     * Das Kontingent des Plans (`Quota::CronJobs`) entscheidet im Panel; hier
     * steht die Wand, die auch dann hält, wenn das Panel sich irrt. Sie liegt
     * bewusst weit darüber — gemessen (`docs/60 §5`) greift die
     * 10000-Zeilen-Grenze von cron für `/etc/cron.d` **nicht**, es gibt hier
     * also sonst gar keine.
     */
    public const MAX_JOBS = 256;

    /** Wie lang ein Befehl werden darf. */
    public const COMMAND_MAX = 8192;

    /**
     * Wo die Aufzeichnungen liegen — je Abonnement ein Verzeichnis.
     *
     * **Unter `/var/spool` und nicht unter `/var/lib/srvpanel`**, und das ist
     * kein Geschmack. `/var/lib/srvpanel` liefert das Paket als `0750
     * srvpanel:srvpanel` aus; `cron-run` läuft als der Abo-Benutzer und käme
     * dort nicht einmal hindurch. Die Alternative wäre gewesen, dem
     * Zustandsverzeichnis des Panels ein `o+x` zu geben — also die Rechte des
     * Panels aufzuweichen, damit ein Fremder an einem Unterverzeichnis vorbei
     * darf.
     *
     * > **Wer ein Verzeichnis öffnet, damit ein anderer durchkommt, öffnet es
     * > für alle, die vorbeikommen.**
     *
     * `/var/spool` ist der Ort, den es für genau diesen Zweck gibt: eine Ablage,
     * die entsteht, eingesammelt wird und wieder verschwindet.
     */
    public const SPOOL_DIR = '/var/spool/srvpanel/cron';

    public static function name(): string
    {
        return 'cron.apply';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $jobs = $this->jobs($args['jobs'] ?? []);

        $context->progress(10, 'Zeitpläne prüfen');

        /*
         * Der Benutzer muss es geben, **bevor** die Datei entsteht. Gemessen:
         * Ein unbekannter Name in einer Zeile lässt cron die ganze Datei mit
         * `Syntax error` verwerfen — also auch die neun anderen Jobs desselben
         * Kunden. Das ist kein Laborfall: Es ist genau der Zustand, den ein
         * halb zurückgebautes Abonnement hinterlässt.
         */
        if (posix_getpwnam($user) === false) {
            throw AgentException::badRequest(
                sprintf('Den Systembenutzer %s gibt es auf diesem Server nicht.', $user),
                ['user' => $user],
            );
        }

        // Wirft, wenn cron die Datei wegen ihres Namens übergehen würde — der
        // einzige der vier Ablehnungsgründe ohne Protokolleintrag.
        $path = CronFile::path($user);

        $context->progress(30, 'Befehle ablegen');

        /*
         * `0751` und nicht `0750`: `cron-run` läuft als der Kunde und muss das
         * Verzeichnis **durchschreiten**, um an seine Befehlsdatei zu kommen —
         * dafür braucht es `x`. Lesen braucht es nicht, und bekommt es auch
         * nicht: Ohne `r` kann niemand das Verzeichnis auflisten und damit die
         * Jobnummern der anderen Abonnements einsammeln.
         *
         * Auch das ist ein Fund des Ende-zu-Ende-Laufs und keiner des Nachdenkens
         * — mit `0750` startet cron den Wrapper brav jede Minute, und es
         * entsteht keine einzige Aufzeichnung.
         */
        $this->ensureDirectory(CronFile::COMMAND_DIR, 'root', 0o751);
        $this->ensureSpool($user);

        $written = [];

        foreach ($jobs as $job) {
            $this->writeCommand($user, $job['id'], $job['command']);
            $written[] = $job['id'];
        }

        $context->progress(70, 'Zeitplan schreiben');

        /*
         * Nur aktive Jobs kommen in die Datei. Ein pausierter Job ist damit
         * genau das — keine Zeile —, und nicht eine auskommentierte: Ein `#` vor
         * einer Zeile wäre ein zweiter Zustand für dieselbe Sache, und cron
         * unterscheidet ihn ohnehin nicht von „gibt es nicht".
         */
        $active = array_values(array_filter($jobs, static fn (array $job): bool => $job['active']));

        if ($active === []) {
            $removed = CronFile::remove($user);
        } else {
            CronFile::write($user, CronFile::render($user, array_map(
                static fn (array $job): array => ['id' => $job['id'], 'schedule' => $job['schedule']],
                $active,
            )));
            $removed = false;
        }

        $context->progress(90, 'Reste wegräumen');

        $orphans = self::purgeCommands($user, $written);

        $context->progress(100, 'fertig');

        return [
            'user' => $user,
            'path' => $path,
            'jobs' => count($jobs),
            'active' => count($active),
            'file_removed' => $removed,
            'commands_removed' => $orphans,
            /*
             * **Wann es gilt, und nicht nur dass es geschrieben ist.** Gemessen
             * (`docs/60 §4`): cron liest `/etc/cron.d` ohne inotify neu, und
             * zwischen dem Schreiben und dem ersten möglichen Lauf liegen bis zu
             * 60 Sekunden. Diese Zahl entsteht hier und wird weitergegeben —
             * `docs/59` Befund 13 und 21 sind zweimal derselbe Fehler an zwei
             * Übergängen desselben Weges.
             *
             * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so
             * > gut wie keine.**
             */
            'effective_within_seconds' => 60,
        ];
    }

    /**
     * Die Jobs aus der Nutzlast — jeder ganz geprüft, bevor einer geschrieben wird.
     *
     * @return list<array{id: int, schedule: array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string}, command: string, active: bool}>
     */
    private function jobs(mixed $value): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('Die Liste der Jobs fehlt.', []);
        }

        if (count($value) > self::MAX_JOBS) {
            throw AgentException::badRequest(
                sprintf('Mehr als %d Jobs je Abonnement nimmt der Agent nicht.', self::MAX_JOBS),
                ['jobs' => count($value)],
            );
        }

        $jobs = [];
        $seen = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Ein Eintrag der Jobliste ist kein Objekt.', []);
            }

            $id = Guard::int($entry['id'] ?? null, 'id');

            if ($id < 1) {
                throw AgentException::badRequest('Die Nummer eines Jobs ist eine positive Zahl.', ['id' => $id]);
            }

            /*
             * Zwei Zeilen mit derselben Nummer sind zwei Zeitpläne für **einen**
             * Befehl — die zweite überschriebe die Befehlsdatei der ersten, und
             * beide liefen danach denselben Befehl. Ein Fehler, der im Panel
             * nach „zwei Jobs" aussieht.
             */
            if (isset($seen[$id])) {
                throw AgentException::badRequest('Die Nummer eines Jobs kommt zweimal vor.', ['id' => $id]);
            }

            $seen[$id] = true;

            $command = Guard::string($entry['command'] ?? null, 'command');

            if ($command === '' || strlen($command) > self::COMMAND_MAX) {
                throw AgentException::badRequest(
                    sprintf('Der Befehl eines Jobs ist leer oder länger als %d Zeichen.', self::COMMAND_MAX),
                    ['id' => $id],
                );
            }

            $schedule = $entry['schedule'] ?? null;

            if (! is_array($schedule)) {
                throw AgentException::badRequest('Der Zeitplan eines Jobs fehlt.', ['id' => $id]);
            }

            $jobs[] = [
                'id' => $id,
                'schedule' => Schedule::parse($schedule),
                'command' => $command,
                'active' => (bool) ($entry['active'] ?? true),
            ];
        }

        return $jobs;
    }

    /**
     * Die Befehlsdatei eines Jobs — `root:<gruppe des abos> 0640`.
     *
     * **`docs/51 §10` schreibt `root:root 0640` vor, und das ist nicht
     * lauffähig.** Gefunden beim ersten Ende-zu-Ende-Lauf, nicht beim Lesen:
     * `cron-run` läuft als der Kunde, und mit `root:root 0640` kommt es an die
     * Datei nicht heran. cron startete den Wrapper brav jede Minute, und es
     * entstand keine einzige Aufzeichnung.
     *
     * > **Eine Rechteangabe im Plan ist eine Absicht. Ob sie läuft, sagt erst
     * > der Lauf.**
     *
     * Richtig ist der Besitzer `root` und die **Gruppe des Abonnements**: Der
     * Kunde darf lesen, aber nicht schreiben. Lesen ist ohnehin keine Preisgabe
     * — der Job läuft als er, er könnte sich den Befehl also jederzeit selbst
     * ansehen. Was zählt, ist das Schreiben: Wäre die Datei für ihn schreibbar,
     * wäre sie der Weg, auf dem er bestimmt, was künftig unter seinem Namen
     * läuft — und nach einer Umstellung des Panels womöglich unter einem
     * anderen.
     */
    private function writeCommand(string $user, int $id, string $command): void
    {
        $path = CronFile::commandPath($user, $id);
        $temporary = $path.'.srvpanel.'.getmypid();

        /*
         * Mit abschliessendem Zeilenumbruch, damit `cat` und ein Blick in die
         * Datei dasselbe zeigen. Der Wrapper übergibt den Inhalt als **ein**
         * Argument an die Shell; ein Zeilenumbruch mehr oder weniger ändert
         * daran nichts, und eine Datei ohne ihn liest sich beim Fehlersuchen
         * wie eine abgeschnittene.
         */
        if (@file_put_contents($temporary, rtrim($command, "\n")."\n") === false) {
            throw AgentException::execFailed(
                sprintf('Der Befehl zu Job %d liess sich nicht schreiben.', $id),
                ['path' => $temporary],
            );
        }

        if (! @chmod($temporary, 0o640) || ! @chown($temporary, 'root') || ! @chgrp($temporary, $user)
            || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw AgentException::execFailed(
                sprintf('Der Befehl zu Job %d liess sich nicht ablegen.', $id),
                ['path' => $path],
            );
        }
    }

    /**
     * Befehlsdateien wegräumen, die zu keinem Job dieses Abonnements mehr gehören.
     *
     * **Das ist der Weg zurück, und er gehört hierher**, weil nur dieser Aufruf
     * den vollständigen Sollzustand kennt. `docs/35`:
     *
     * > **Wer etwas anlegt, das auf der Platte bleibt, baut den Weg zurück mit.**
     *
     * Gesucht wird über das **Namensmuster** der Befehlsdateien und nicht über
     * die Cron-Datei. Der erste Entwurf las die vorige Cron-Datei, und darin
     * standen zwei Fehler: Er lief nach dem Schreiben und las damit die neue
     * Datei, und ein **pausierter** Job steht in gar keiner Cron-Datei — seine
     * Befehlsdatei wäre nie wieder auffindbar gewesen.
     *
     * > **Ein Weg zurück, der den Sollzustand als Quelle nimmt, findet nur, was
     * > darin steht — und nicht, was er aufräumen soll.**
     *
     * Weil der Systembenutzer im Dateinamen steht, greift das Muster genau die
     * Dateien dieses Abonnements und keine fremde.
     *
     * @param  list<int>  $keep
     * @return list<int>
     */
    public static function purgeCommands(string $user, array $keep): array
    {
        $removed = [];
        $found = glob(CronFile::commandGlob($user));

        if ($found === false) {
            return [];
        }

        foreach ($found as $path) {
            if (preg_match('/-([0-9]+)\.cmd$/D', $path, $match) !== 1) {
                continue;
            }

            $id = (int) $match[1];

            if (in_array($id, $keep, true)) {
                continue;
            }

            if (@unlink($path)) {
                $removed[] = $id;
            }
        }

        return $removed;
    }

    /**
     * Das Verzeichnis für die Aufzeichnungen — es gehört dem Abonnement.
     *
     * `cron-run` läuft als der Kunde und muss hier ablegen können. Deshalb
     * gehört es ihm, und deshalb liegt es **ausserhalb** der Abo-Wurzel: Innen
     * käme der Kunde per SFTP heran und könnte seine eigene Laufgeschichte
     * umschreiben, während das Panel sie noch nicht eingesammelt hat.
     */
    private function ensureSpool(string $user): void
    {
        $this->ensureDirectory(self::SPOOL_DIR, 'root', 0o755);

        $path = self::SPOOL_DIR.'/'.$user;

        if (! is_dir($path) && ! @mkdir($path, 0o750, true) && ! is_dir($path)) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht anlegen.', $path),
                ['path' => $path],
            );
        }

        if (! @chown($path, $user) || ! @chgrp($path, $user) || ! @chmod($path, 0o750)) {
            throw AgentException::execFailed(
                sprintf('Die Rechte von %s liessen sich nicht setzen.', $path),
                ['path' => $path],
            );
        }
    }

    /** Ein Verzeichnis des Systems herstellen, ohne vorhandene Rechte zu ändern. */
    private function ensureDirectory(string $path, string $owner, int $mode): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, $mode, true) && ! is_dir($path)) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht anlegen.', $path),
                ['path' => $path],
            );
        }

        @chown($path, $owner);
        @chgrp($path, $owner);
        @chmod($path, $mode);
    }
}
