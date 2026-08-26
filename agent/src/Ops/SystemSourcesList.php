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
                    'key' => $this->key($context, $felder, $eintrag['block']),
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
     * Der Schlüssel einer Stanza, mit Fingerabdruck und Ablauf.
     *
     * **Zwei Wege in dasselbe `gpg`.** Ein Pfad geht als Argument hinein, ein
     * eingebetteter Block über stdin — beide gemessen (`docs/81 §2.3b`, Q7).
     * Der Aufruf ist derselbe und steht als {@see Keys::ARGUMENTS} einmal da.
     *
     * **Ein Fehlschlag ist kein leeres Ergebnis.** Ein Pfad, den es nicht gibt,
     * endet mit `rc=2` — und „keine Schlüssel gefunden" sähe aus wie „dieser
     * Quelle fehlt der Schlüssel", was etwas ganz anderes heisst. Er kommt
     * deshalb als `unreadable` zurück und nicht als leere Liste.
     *
     * > **Eine leere Liste, die „nicht nachgesehen" bedeutet, sieht aus wie
     * > „nichts da".**
     *
     * @param  array<string, string>  $felder
     * @return array<string, mixed>
     */
    private function key(Context $context, array $felder, string $block): array
    {
        $art = Sources::key($felder);

        if ($art['kind'] === 'none') {
            return $art + ['keys' => [], 'readable' => true];
        }

        $lauf = $art['kind'] === 'path'
            ? $context->runner->run('gpg', [...Keys::ARGUMENTS, '--homedir', Keys::HOME, (string) $art['path']], 20)
            : $context->runner->run(
                'gpg',
                [...Keys::ARGUMENTS, '--homedir', Keys::HOME],
                20,
                input: Keys::unfold($felder['Signed-By'] ?? '', Sources::folded($block, 'Signed-By')),
            );

        if (! $lauf->successful()) {
            return $art + ['keys' => [], 'readable' => false];
        }

        $jetzt = time();
        $schluessel = [];

        foreach (Keys::read($lauf->stdout) as $eine) {
            $schluessel[] = $eine + ['state' => Keys::state($eine['expires'], $jetzt)];
        }

        return $art + ['keys' => $schluessel, 'readable' => true];
    }

    /**
     * Die Dateien, die apt liest — in derselben Reihenfolge.
     *
     * **`sources.list` gehört dazu, auch wenn es hier nur Kommentar ist.**
     * Gemessen auf diesem Abbild: vier Zeilen, alle Kommentar, null Einträge.
     * Auf einem älteren System steht dort der ganze Bestand, und wer die Datei
     * weglässt, zeigt dem Betreiber eine leere Liste für einen Server, der
     * Quellen hat.
     *
     * > **Eine Datei, die auf dem eigenen System leer ist, ist damit nicht
     * > überall leer.**
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $teile = glob(Sources::PARTS.'/*.{list,sources}', GLOB_BRACE) ?: [];
        sort($teile);

        return array_values(array_filter(
            [Sources::MAIN, ...$teile],
            static fn (string $p): bool => is_file($p),
        ));
    }
}
