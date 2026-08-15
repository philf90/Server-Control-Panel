<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Entry;
use SrvPanel\Agent\Files\Scheme;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die Rechte einer Datei oder eines Verzeichnisses setzen.
 *
 * **Setuid, setgid und das Sticky-Bit gehen nicht.** Sie stehen ausserhalb der
 * zwölf Bits, die diese Operation annimmt — und zwar nicht, weil der Kunde
 * damit ausbrechen könnte (seine eigenen Dateien gehören ihm, und ein
 * setuid-Bit auf einer Datei, die ihm gehört, verschafft ihm nichts), sondern
 * weil `chmod` als der Kunde sie auf fremden Dateien ohnehin nicht setzen kann
 * und eine Oberfläche, die ein Kästchen dafür anbietet, eine Zusage macht, die
 * das System nicht einlöst.
 *
 * **Der Verweis wird nicht verfolgt.** `chmod` folgt Symlinks — es gibt kein
 * `lchmod` in PHP. Auf einen Verweis angewandt änderte es also die Rechte
 * seines Ziels, und das ist nie das, was jemand meint, der in einer Liste auf
 * eine Zeile klickt. Verweise werden deshalb abgewiesen.
 */
final class FilesChmod implements Op
{
    public static function name(): string
    {
        return 'files.chmod';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $workspace = Workspace::fromArgs($args);
        $path = Workspace::path($args['path'] ?? null);
        $mode = Guard::int($args['mode'] ?? null, 'mode');

        if ($mode < 0 || $mode > 0o777) {
            throw AgentException::badRequest('Unzulässige Rechte — erlaubt sind 0 bis 0777.', ['mode' => $mode]);
        }

        /*
         * **Und der Fall, der weniger offensichtlich ist als das Entfernen.**
         *
         * `httpdocs` trägt seit `docs/51` Schritt 6c das setgid-Bit — daran
         * hängt, dass alles darin der Gruppe `www-data` gehört und der
         * Webserver es lesen kann. Diese Operation nimmt nur neun Bits entgegen
         * (`> 0o777` fliegt oben heraus), ein `chmod` des Kunden auf `httpdocs`
         * nähme das zehnte also **lautlos** weg. Die nächste hochgeladene Datei
         * trüge wieder die falsche Gruppe, und das ist Befund 3 aus `docs/53`
         * noch einmal, nur mit dem Kunden als Verursacher.
         */
        Scheme::protect($path, 'in seinen Rechten geändert');

        return $workspace->run(static function () use ($path, $mode): array {
            $entry = Entry::of($path);

            if ($entry === null) {
                throw new AgentException(AgentException::NOT_FOUND, 'Den Eintrag gibt es nicht.', ['path' => $path]);
            }

            if ($entry['type'] === 'link') {
                throw AgentException::badRequest(
                    'Ein Verweis hat keine eigenen Rechte — gemeint wäre sein Ziel.',
                    ['path' => $path],
                );
            }

            /*
             * **Das setgid-Bit eines Verzeichnisses überlebt den Griff.**
             *
             * Der Kommentar über `Scheme::protect()` nennt diese Gefahr für
             * `httpdocs` — und sie gilt für **jedes** Verzeichnis darin.
             * Gemessen auf `cloudsrv24` am 15. August 2026 (`docs/55`,
             * Befund 13): Ein über den Dateimanager angelegtes
             * `httpdocs/p6-bit` erbt `2750`; ein `chmod 755` aus dem
             * Rechte-Editor macht daraus `755`, und jede Datei, die der Kunde
             * danach darin anlegt, trägt wieder die Gruppe des Abonnements.
             *
             * > **Eine Begründung, die für einen Fall aufgeschrieben ist, gilt
             * > oft für mehr — und wird trotzdem nur dort angewandt.**
             *
             * `chmod(2)` setzt den Modus vollständig; GNU-`chmod` bewahrt das
             * Bit bei Verzeichnissen von sich aus, PHPs `chmod()` nicht. Hier
             * wird es deshalb ausdrücklich mitgeführt.
             *
             * **Nur bei Verzeichnissen.** Auf einer Datei bedeutet dasselbe Bit
             * etwas anderes — Ausführung unter fremder Gruppe —, und dieses
             * Panel setzt es dort nirgends. Was es nicht setzt, muss es auch
             * nicht bewahren.
             *
             * Der Rechte-Editor bietet die neun Bits an (`docs/51 §8.2`), und
             * genau die ändert dieser Griff. **Ein Griff, der neun Bits
             * anbietet, darf das zehnte nicht anfassen.**
             */
            $geerbt = $entry['type'] === 'directory'
                ? ($entry['mode'] & 0o2000)
                : 0;

            if (! @chmod($path, $mode | $geerbt)) {
                throw AgentException::denied('Die Rechte liessen sich nicht setzen.');
            }

            clearstatcache(true, $path);

            return ['entry' => Entry::of($path)];
        });
    }
}
