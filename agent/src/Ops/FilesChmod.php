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

            if (! @chmod($path, $mode)) {
                throw AgentException::denied('Die Rechte liessen sich nicht setzen.');
            }

            clearstatcache(true, $path);

            return ['entry' => Entry::of($path)];
        });
    }
}
