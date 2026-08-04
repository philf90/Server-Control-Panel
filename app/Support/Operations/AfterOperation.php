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
     * Der Vorgang ist durchgelaufen.
     *
     * Jede Umsetzung prüft zuerst, ob der Vorgang sie überhaupt betrifft, und
     * tut sonst nichts. Ein Vorgang gehört genau einer von ihnen.
     */
    public function afterSuccess(Operation $operation): void;
}
