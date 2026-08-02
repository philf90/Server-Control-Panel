<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Eine Operation des Agenten.
 *
 * Der Zuschnitt ist der Kern der Trennlinie: Eine Operation ist eine *Absicht*
 * mit geprüften Parametern, kein Befehl und kein Dateipfad. Die Anwendung
 * schickt „Zustand dieser Unit", nicht „führe systemctl show aus". Was daraus
 * wird, entscheidet allein diese Klasse — und sie liegt hier, im Code, der als
 * root läuft und geprüft wurde.
 */
interface Op
{
    /** Der Name, unter dem die Anwendung die Operation aufruft. */
    public static function name(): string;

    /** Verändert die Operation den Zustand des Systems? Steuert die Protokollierung. */
    public static function mutating(): bool;

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed> Nutzdaten für das Ergebnis
     */
    public function execute(array $args, Context $context): array;
}
