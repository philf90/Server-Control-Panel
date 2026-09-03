<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Keys;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Sources;

/**
 * Die Paketquellen dieses Servers — was apt benutzt und was konfiguriert ist.
 *
 * **Zwei Sichten und nicht eine**, weil sie verschiedene Fragen beantworten:
 * `apt-get indextargets` sagt, womit apt tatsächlich arbeitet; die Dateien
 * sagen, was dasteht. Eine abgeschaltete Quelle ist in der ersten Sicht fort —
 * und eine, für die apt keinen Index geholt hat, ebenfalls.
 *
 * **Deshalb steht neben jedem Eintrag beides**: ob er eingeschaltet ist (aus
 * der Datei) und ob apt ein Ziel dazu führt (aus `indextargets`). Erst die
 * beiden zusammen unterscheiden „der Betreiber hat sie abgeschaltet" von „apt
 * kommt nicht an sie heran" — von einer Seite allein sehen die zwei gleich aus.
 *
 * > **Zwei Zustände, die von einer Seite gleich aussehen, brauchen die zweite
 * > Seite — nicht eine Vermutung.**
 *
 * **Diese Operation fasst apt an und fragt die Sperre nicht** — aus demselben
 * gemessenen Grund wie {@see SystemPackagesList}: `apt-get indextargets` liest
 * und läuft bei gehaltener dpkg-Sperre durch. `AptLockReachTest::EXCEPTIONS`
 * trägt sie mit diesem Grund.
 *
 * **Gelesen wird über {@see Sources} und nicht hier**, weil `Runner` und
 * `Context` `final` sind: Stünde der Leser in dieser Klasse, wäre er nur über
 * einen echten Server prüfbar — also gar nicht.
 */
final class SystemSourcesList implements Op
{
    /** @var list<string> */
    public const INDEXTARGETS = ['indextargets'];

    public static function name(): string
    {
        return 'system.sources.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $lauf = $context->runner->run('apt-get', self::INDEXTARGETS, 60);

        if (! $lauf->successful()) {
            throw AgentException::execFailed(
                'Die Paketquellen liessen sich nicht ermitteln: '.$lauf->message(),
                ['code' => $lauf->code],
            );
        }

        $ziele = Sources::targets($lauf->stdout);

        return [
            'targets' => $ziele,
            'files' => $this->files($ziele, $context),
        ];
    }

    /**
     * Was konfiguriert ist, je Datei — mit dem Verbund zu den Zielen.
     *
     * @param  list<array{file: null|string, stanza: null|int, fields: array<string, string>}>  $ziele
     * @return list<array{path: string, format: string, entries: list<array<string, mixed>>}>
     */
    private function files(array $ziele, Context $context): array
    {
        // Wie viele Ziele hängen an welchem Eintrag? Gezählt und nicht nur
        // „ja/nein": Eine Stanza mit `Suites: noble noble-updates` wird zu
        // mehreren Zielen, und die Zahl sagt dem Betreiber, dass sie
        // aufgefächert wurde.
        $verbund = [];

        foreach ($ziele as $ziel) {
            if ($ziel['file'] === null || $ziel['stanza'] === null) {
                continue;
            }

            $schluessel = $ziel['file'].':'.$ziel['stanza'];
            $verbund[$schluessel] = ($verbund[$schluessel] ?? 0) + 1;
        }

        $dateien = [];

        foreach ($this->paths() as $pfad) {
            $inhalt = @file_get_contents($pfad);

            if ($inhalt === false) {
                continue;
            }

            $einzeiler = str_ends_with($pfad, '.list');
            $eintraege = [];

            foreach ($einzeiler ? Sources::oneliners($inhalt) : Sources::stanzas($inhalt) as $eintrag) {
                $felder = $eintrag['fields'];

                $eintraege[] = [
                    'stanza' => $eintrag['stanza'],
                    'enabled' => $eintrag['enabled'],
                    'targets' => $verbund[$pfad.':'.$eintrag['stanza']] ?? 0,
                    'types' => $felder['Types'] ?? '',
                    'uris' => $felder['URIs'] ?? '',
                    'suites' => $felder['Suites'] ?? '',
                    'components' => $felder['Components'] ?? '',
                    'key' => Keys::inspect($context, $felder, $eintrag['block']),

                    /*
                     * **Ob geschaltet werden darf, sagt der Agent und nicht
                     * die Seite.** Eine zweite Fassung der Liste im Panel wäre
                     * die, die beim nächsten Eintrag vergessen wird — und sie
                     * stünde vor der Stelle mit den Systemrechten.
                     *
                     * `AbilityReachTest` verlangt ohnehin, dass ein Knopf, den
                     * der Betrachter nicht drücken darf, gar nicht gezeigt
                     * wird. Hier ist es dieselbe Regel eine Ebene tiefer:
                     * nicht wer darf, sondern was darf.
                     */
                    'owned' => ! $einzeiler && Sources::isOwned($pfad),
                ];
            }

            $dateien[] = [
                'path' => $pfad,
                'format' => $einzeiler ? 'oneline' : 'deb822',
                'entries' => $eintraege,
            ];
        }

        return $dateien;
    }

    /**
     * Die Dateien, die apt liest.
     *
     * **Der Ausdruck stand bis zum 27. August 2026 hier**, und das war
     * `docs/86` Befund 13: Er war richtig und von nichts gehalten.
     * `SourceListTest` prüft zehn Fragen über das **Zerlegen** einer Datei und
     * keine darüber, welche Dateien überhaupt gelesen werden — hier in einer
     * Ops-Datei erreichte ihn kein Wächter, weil `Context` `final` ist.
     *
     * {@see Sources::files()} ist die Naht, `SourceFileTest` der Wächter, und
     * sein Prüfkörper heisst `ubuntu.sources.curtin.orig` — nach der Datei, die
     * auf `cloudsrv24` seit der Installation liegt.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        return Sources::files(Sources::MAIN, Sources::PARTS);
    }
}
