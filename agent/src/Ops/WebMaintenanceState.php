<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\Op;

/**
 * Nachsehen, ob der Wartungsmodus wirklich an ist (`docs/911 §2`, M8).
 *
 * ## Warum es diese Operation gibt
 *
 * Bis zum 12. September 2026 führte `agent/src/Ops/` genau **eine**
 * Wartungsoperation: {@see WebMaintenanceSet}, und die verlangt `enabled` als
 * Pflichtfeld. Das Panel konnte den Zustand also nicht erfragen, ohne ihn zu
 * setzen — und was es anzeigt, kam aus `Settings::maintenance()`, einer Ablage.
 *
 * > **Ein Zustand, den man nur durch Setzen erfahren kann, ist von aussen nicht
 * > lesbar — und die Anzeige daneben liest zwangsläufig eine Ablage.**
 *
 * Nichts glich die beiden ab: `MaintenanceWindow` liest dieselbe Ablage und
 * meldet nur eine überschrittene Endzeit, und die Wache in den Vhost-Dateien
 * steht seit A12 **dauerhaft** dort, gleich ob der Modus an ist. Verschwand die
 * Flagdatei von Hand, behauptete das Panel weiter „Wartung läuft".
 *
 * ## Sie ist für den Nachtlauf gebaut und nicht für die Anzeige
 *
 * Das Band im Panel liest weiter die Ablage — es steht auf **jeder** Seite, und
 * ein Sockelaufruf je Seitenaufbau wäre der Fehler, den `docs/904` für
 * `/updates` gerade behoben hat. Diese Operation beantwortet die Frage einmal
 * pro Nacht, und der Abgleich wird zu einem Befund.
 *
 * > **Eine Anzeige, die teurer wird, je öfter man sie ansieht, wird genau dann
 * > langsam, wenn jemand arbeitet.**
 *
 * ## Was sie ausdrücklich nicht tut
 *
 * Sie schaltet nicht (`mutating() === false`) und liest **nur** die Datei — die
 * Vhost-Dateien, die Wache und `nginx` fasst sie nicht an. Ihre Antwort trägt
 * denselben Schlüssel wie die von {@see WebMaintenanceSet}, damit beide
 * dieselbe Sprache sprechen und ein Leser nicht zwei Formen kennen muss.
 */
final class WebMaintenanceState implements Op
{
    /**
     * Der Ablageort ist einsetzbar, damit er messbar ist.
     *
     * **Die Vorgabe ist die Wahrheit** — derselbe Pfad, den die Wache im
     * Server-Block nennt, und derselbe, den {@see WebMaintenanceSet} schreibt.
     * Ein Prüfkörper gegen einen eigenen Pfad prüfte seine eigene Erfindung.
     */
    public function __construct(private readonly string $flag = Maintenance::FLAG) {}

    public static function name(): string
    {
        return 'web.maintenance.state';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{enabled: bool, flag: string}
     */
    public function execute(array $args, Context $context): array
    {
        /*
         * **Der Zwischenspeicher wird geleert, und das ist nicht Vorsicht.**
         * Ein langlebiger Agentenprozess hat denselben Pfad in derselben
         * Sekunde vielleicht schon einmal gefragt; `is_file()` antwortete dann
         * aus `stat`, und ein Schalten dazwischen wäre unsichtbar. Derselbe
         * Griff wie in `WebMaintenanceSet::execute()`.
         */
        clearstatcache(true, $this->flag);

        return ['enabled' => is_file($this->flag), 'flag' => $this->flag];
    }
}
