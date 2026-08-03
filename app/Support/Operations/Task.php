<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Models\Account;

/**
 * Was sich aus dem Panel heraus auslösen lässt.
 *
 * **Der Browser schickt einen Schlüssel, keine Anweisung.** Das ist die
 * eigentliche Entscheidung in dieser Datei. Ein Vorgang trägt `type` und
 * `payload`, und beides geht unverändert an den Agenten — an das Programm
 * also, das als root läuft. Nähme der Steuerungscode diese Werte vom Formular
 * entgegen, wäre das Panel eine Fernsteuerung für beliebige Operationen, und
 * die einzige Schranke davor wäre die Positivliste im Agenten. Sie ist gut,
 * aber sie darf nicht die einzige sein.
 *
 * Deshalb steht hier ein Katalog: Der Browser nennt einen Schlüssel aus dieser
 * Aufzählung, und die Argumente für den Agenten entstehen erst hier, im
 * Quelltext. **Kein Wert aus der Anfrage erreicht den Agenten.** Wer eine neue
 * Aufgabe braucht, trägt sie hier ein — und das ist eine Änderung am Code, die
 * jemand liest, kein Feld in einem Formular.
 *
 * Das ist bewusst eng. Sobald es Websites gibt, brauchen Aufgaben Argumente
 * (welche Domain, welches Zertifikat), und dann muss dieser Katalog auch
 * beschreiben, welche Werte zulässig sind und woher sie stammen dürfen —
 * nämlich aus dem Bestand des Aufrufers, nicht aus seiner Eingabe. Diese
 * Erweiterung gehört zu den Modulen und nicht hierher.
 */
enum Task: string
{
    case AgentPing = 'agent.ping';
    case AgentStatus = 'agent.status';
    case WorkerStatus = 'worker.status';
    case WebserverStatus = 'webserver.status';
    case WebserverCheck = 'webserver.check';
    case WebserverReload = 'webserver.reload';

    public function label(): string
    {
        return match ($this) {
            self::AgentPing => 'Agent ansprechen',
            self::AgentStatus => 'Zustand des Agenten',
            self::WorkerStatus => 'Zustand der Warteschlange',
            self::WebserverStatus => 'Zustand des Webservers',
            self::WebserverCheck => 'Konfiguration des Webservers prüfen',
            self::WebserverReload => 'Webserver neu einlesen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AgentPing => 'Fragt den Agenten nach seiner Fassung. Der kürzeste Weg festzustellen, ob der Weg vom Panel bis in das System offen ist.',
            self::AgentStatus => 'Liest den Zustand von srvpanel-agentd.service. Läuft er nicht, tut kein Vorgang etwas.',
            self::WorkerStatus => 'Liest den Zustand von srvpanel-worker.service. Steht er, bleiben Vorgänge auf „wartet" stehen.',
            self::WebserverStatus => 'Liest den Zustand von nginx.service.',
            self::WebserverCheck => 'Lässt nginx seine eigene Konfiguration prüfen, ohne sie zu übernehmen.',
            self::WebserverReload => 'Lässt nginx die Konfiguration neu einlesen. Bestehende Verbindungen laufen weiter.',
        };
    }

    /** Die Operation des Agenten, die dahintersteht. */
    public function operation(): string
    {
        return match ($this) {
            self::AgentPing => 'agent.ping',
            self::AgentStatus, self::WorkerStatus, self::WebserverStatus => 'service.status',
            self::WebserverCheck => 'config.validate',
            self::WebserverReload => 'service.action',
        };
    }

    /**
     * Die Argumente — fest im Quelltext, nicht aus der Anfrage.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return match ($this) {
            self::AgentPing => [],
            self::AgentStatus => ['unit' => 'srvpanel-agentd.service'],
            self::WorkerStatus => ['unit' => 'srvpanel-worker.service'],
            self::WebserverStatus => ['unit' => 'nginx.service'],
            self::WebserverCheck => ['kind' => 'nginx', 'path' => '/etc/nginx/nginx.conf'],
            self::WebserverReload => ['unit' => 'nginx.service', 'action' => 'reload'],
        };
    }

    /**
     * Ändert die Aufgabe etwas am System?
     *
     * Steuert nur die Rückfrage in der Oberfläche. Die Schranke, die zählt,
     * ist die Liste erlaubter Units im Agenten — eine Kennzeichnung im Panel
     * hielte niemanden auf, der die Anfrage selbst stellt.
     */
    public function mutating(): bool
    {
        return $this === self::WebserverReload;
    }

    /**
     * Wer diese Aufgabe auslösen darf.
     *
     * In P1 ausschließlich Betreiber, und das ist keine vorläufige Strenge:
     * Alle sechs Aufgaben betreffen den Server als Ganzes. Es gibt noch keine
     * Websites, keine Datenbanken, kein Postfach — also nichts, was einem
     * einzelnen Kunden gehörte und das er anfassen dürfte. Ein Kunde sieht
     * deshalb einen leeren Katalog, und das ist die richtige Auskunft.
     */
    public function allowedFor(Account $account): bool
    {
        return $account->isAdmin();
    }

    /**
     * Der Katalog für ein Konto — die Aufgaben, die es auslösen darf.
     *
     * @return list<self>
     */
    public static function for(Account $account): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $task): bool => $task->allowedFor($account),
        ));
    }
}
