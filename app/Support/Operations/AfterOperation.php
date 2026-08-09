<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Models\Operation;

/**
 * Was nach einem erfolgreichen Vorgang am Bestand geschieht.
 *
 * **Die zweite Grenze aus CLAUDE.md, als Vertrag.** Ein Vorgang ändert den
 * Zustand erst, *nachdem* der Agent geantwortet hat — und diese Schnittstelle
 * ist die Stelle, an der das passiert. Sie läuft im Arbeiter, ohne
 * angemeldetes Konto und damit im Grundzustand der Mandantenklammer; jede
 * Umsetzung braucht deshalb ein ausdrückliches `withoutRestriction`.
 *
 * Bis P2 gab es genau eine Umsetzung, und der Arbeiter rief sie unmittelbar
 * auf. Mit P3 kommt die zweite dazu — und ab da ist „der Arbeiter ruft die
 * Klasse auf, die man ihm gegeben hat" der Weg, auf dem die dritte vergessen
 * wird. Deshalb der Vertrag und die Liste in {@see Lifecycles}, die ein Test
 * gegen alle Umsetzungen hält.
 */
interface AfterOperation
{
    /**
     * Welche Aufgaben dieser Lebenslauf beantwortet.
     *
     * **Die Liste steht hier, weil sie sonst nirgends steht.** Ohne sie
     * verteilt sich die Antwort auf ein `str_starts_with` und ein `match` im
     * Rumpf — lesbar, aber für nichts prüfbar. Und die Frage, die sich stellt,
     * sobald jemand eine Aufgabe dazunimmt, ist genau die: Beantwortet sie
     * überhaupt jemand? Eine Aufgabe ohne Lebenslauf läuft durch, der Agent tut
     * seine Arbeit, und im Panel ändert sich nichts. Ohne Fehler, ohne Meldung.
     *
     * `AgentOperationReachTest` hält drei Dinge zusammen: was hier steht, was
     * die Registratur des Agenten kennt, und was das Panel tatsächlich
     * abschickt.
     *
     * @return list<string>
     */
    public static function handles(): array;

    /**
     * Der Vorgang ist durchgelaufen.
     *
     * Jede Umsetzung prüft zuerst, ob der Vorgang sie überhaupt betrifft, und
     * tut sonst nichts.
     */
    public function afterSuccess(Operation $operation): void;

    /**
     * Der Vorgang ist gescheitert.
     *
     * **Diese Richtung hat bis zum 9. August 2026 gefehlt, und das war teurer
     * als es aussieht** (`docs/36 §22.3u` und `§22.3w`). Ein Vertrag, der nur
     * den Erfolg kennt, lässt jeden Fehlschlag den Bestand unberührt — die
     * Zeile einer gescheiterten Sicherung stand für immer auf „läuft", ihr
     * `last_error` blieb leer, obwohl die Oberfläche eine Spalte dafür hat, und
     * die hochgeladene Datei blieb in der Übergabe liegen. Bis zu 512 MB je
     * Versuch, ausgelöst von einem Kunden, und nichts im System hat sie je
     * wieder angefasst.
     *
     * **Sie ist Pflicht und nicht optional**, obwohl drei der vier Umsetzungen
     * heute nichts zu tun haben. Eine zweite, freiwillige Schnittstelle wäre
     * die, an die beim fünften Lebenslauf niemand denkt — dieselbe Begründung,
     * aus der `handles()` hier steht und nicht in einem `match` im Rumpf.
     *
     * Ein *Abbruch* zählt nicht dazu: Wer abbricht, weiss, was er tut, und der
     * Bestand soll dabei stehen bleiben, wie er ist.
     */
    public function afterFailure(Operation $operation): void;
}
