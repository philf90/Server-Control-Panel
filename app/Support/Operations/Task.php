<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Models\Account;
use InvalidArgumentException;
use SrvPanel\Agent\PhpVersions;

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

    /*
     * P3 — Web und PHP. Die ersten Aufgaben mit einem Argument.
     *
     * Der Kommentar über dieser Aufzählung hat sie angekündigt: „Sobald es
     * Websites gibt, brauchen Aufgaben Argumente (welche Domain, welches
     * Zertifikat), und dann muss dieser Katalog auch beschreiben, welche Werte
     * zulässig sind und woher sie stammen dürfen." Genau das steht jetzt
     * darunter — und die Antwort auf „woher" ist dieselbe wie bei allem
     * anderen: aus einer festen Liste im Quelltext, nie aus der Anfrage.
     */
    case WebserverDetect = 'webserver.detect';
    case PhpVersionList = 'php.versions';
    case PhpVersionInstall = 'php.version.install';
    case PhpVersionRemove = 'php.version.remove';

    /**
     * Beschriftung und Beschreibung sind Text, den ein Browser anzeigt — für
     * sie gilt docs/19: technisch vor literarisch.
     *
     * Hier stand vorher „Agent ansprechen" und „Fragt den Agenten nach seiner
     * Fassung. Der kürzeste Weg festzustellen, ob der Weg vom Panel bis in das
     * System offen ist." Beides ist korrektes Deutsch und sagt einer Fachperson
     * nichts: Weder welche Operation läuft, noch gegen welche Unit, noch was
     * danach anders ist. „Fassung" steht dazu auf der Liste der verbrauchten
     * Wörter in docs/19 §3 — es heißt Version.
     *
     * Der Maßstab für jede Zeile hier: Wer sie liest, weiß, welcher Befehl auf
     * dem Server ankommt.
     */
    public function label(): string
    {
        return match ($this) {
            self::AgentPing => 'Agent anpingen',
            self::AgentStatus => 'Status srvpanel-agentd',
            self::WorkerStatus => 'Status srvpanel-worker',
            self::WebserverStatus => 'Status nginx',
            self::WebserverCheck => 'nginx-Konfiguration prüfen',
            self::WebserverReload => 'nginx neu laden',
            self::WebserverDetect => 'Webserver erkennen',
            self::PhpVersionList => 'PHP-Versionen nachsehen',
            self::PhpVersionInstall => 'PHP-Version installieren',
            self::PhpVersionRemove => 'PHP-Version entfernen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AgentPing => 'agent.ping über den Unix-Socket des Agenten. Antwortet er mit seiner Version, ist die Kette Panel → Warteschlange → Worker → Agent durchgängig.',
            self::AgentStatus => 'service.status auf srvpanel-agentd.service. Liefert ActiveState, SubState und PID des Agenten — ohne ihn führt kein Vorgang etwas aus.',
            self::WorkerStatus => 'service.status auf srvpanel-worker.service. Der Worker holt die Vorgänge aus der Queue „operations"; steht er, bleiben sie im Status „wartet".',
            self::WebserverStatus => 'service.status auf nginx.service. Liefert ActiveState, SubState und PID.',
            self::WebserverCheck => 'nginx -t gegen /etc/nginx/nginx.conf. Prüft Syntax und alle per include eingebundenen Dateien, ohne die Konfiguration zu aktivieren.',
            self::WebserverReload => 'systemctl reload nginx.service. Der Master-Prozess liest die Konfiguration neu und startet neue Worker; bestehende Verbindungen laufen auf den alten aus.',
            self::WebserverDetect => 'webserver.detect. Sucht nach Apache, lighttpd und Caddy und meldet, ob srvpanel arbeiten darf — ein laufender fremder Webserver wird nicht angefasst.',
            self::PhpVersionList => 'php.versions. Liest, welche PHP-Versionen installiert sind, ob ihr FPM läuft und wie viele Pools daran hängen. Füllt zugleich die Auswahl in den Domainformularen.',
            self::PhpVersionInstall => 'apt-get install phpX.Y-fpm samt Erweiterungen aus deb.sury.org, danach wird der geteilte Standard-Pool der Distribution abgeschaltet.',
            self::PhpVersionRemove => 'apt-get remove phpX.Y-*. Wird abgewiesen, solange ein Abonnement einen Pool in dieser Version hat.',
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
            self::WebserverDetect => 'webserver.detect',
            self::PhpVersionList => 'php.versions',
            self::PhpVersionInstall => 'php.version.install',
            self::PhpVersionRemove => 'php.version.remove',
        };
    }

    /**
     * Die Argumente — fest im Quelltext, nicht aus der Anfrage.
     *
     * **Das Argument ist ein Wert aus {@see self::choices()}, kein Freitext.**
     * Der Browser schickt „8.2"; was daraus wird, entsteht hier. Zwischen
     * beidem liegt die Prüfung im Steuerungscode, und sie prüft gegen dieselbe
     * Liste, aus der die Oberfläche ihr Auswahlfeld baut — es gibt keinen
     * zweiten Weg, einen Wert hierher zu bringen.
     *
     * @return array<string, mixed>
     */
    public function payload(?string $argument = null): array
    {
        return match ($this) {
            self::AgentPing, self::WebserverDetect, self::PhpVersionList => [],
            self::AgentStatus => ['unit' => 'srvpanel-agentd.service'],
            self::WorkerStatus => ['unit' => 'srvpanel-worker.service'],
            self::WebserverStatus => ['unit' => 'nginx.service'],
            self::WebserverCheck => ['kind' => 'nginx', 'path' => '/etc/nginx/nginx.conf'],
            self::WebserverReload => ['unit' => 'nginx.service', 'action' => 'reload'],

            self::PhpVersionInstall, self::PhpVersionRemove => [
                'php_version' => $this->choice($argument),
            ],
        };
    }

    /**
     * Braucht diese Aufgabe ein Argument — und wie heißt es in der Oberfläche?
     *
     * `null` heisst: keines. Die Oberfläche zeigt dann einen Knopf und sonst
     * nichts.
     */
    public function argumentLabel(): ?string
    {
        return match ($this) {
            self::PhpVersionInstall, self::PhpVersionRemove => 'PHP-Version',
            default => null,
        };
    }

    /**
     * Die zulässigen Werte des Arguments.
     *
     * **Der Katalog des Agenten und nichts sonst.** Für jede Version darin
     * gibt es Vorlage, Paketname und Handler (`docs/23 §7`); eine Version, die
     * nicht darin steht, hat keine davon. Die Liste ist bewusst nicht auf „was
     * gerade installiert ist" eingeschränkt: Installieren soll gerade das, was
     * fehlt, und Entfernen ist wiederholbar — der Agent antwortet auf beides
     * mit „war schon so", statt zu scheitern.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        return match ($this) {
            self::PhpVersionInstall, self::PhpVersionRemove => PhpVersions::CATALOG,
            default => [],
        };
    }

    /**
     * Das Argument, geprüft.
     *
     * Ein unbekannter Wert kommt hier nicht mehr an — der Steuerungscode weist
     * ihn vorher ab. Die Prüfung steht trotzdem da: Sie ist die letzte vor dem
     * Agenten, und sie kostet eine Zeile.
     */
    private function choice(?string $argument): string
    {
        if ($argument === null || ! in_array($argument, $this->choices(), true)) {
            throw new InvalidArgumentException(sprintf(
                '%s braucht ein Argument aus dem Katalog.',
                $this->value,
            ));
        }

        return $argument;
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
        return in_array($this, [
            self::WebserverReload,
            self::PhpVersionInstall,
            self::PhpVersionRemove,
        ], true);
    }

    /**
     * Wer diese Aufgabe auslösen darf.
     *
     * **Weiterhin ausschließlich Betreiber, auch in P3.** In P1 stand hier,
     * alle sechs Aufgaben beträfen den Server als Ganzes — es gebe noch nichts,
     * was einem einzelnen Kunden gehöre. Für die vier neuen gilt dasselbe, und
     * für die PHP-Versionen ist es eine ausdrückliche Festlegung: Installieren
     * und Entfernen sind Betreiberhandlungen. Ein Kunde sieht, welche
     * Versionen er wählen kann und welche sein Plan hergibt, ohne dass es sie
     * auf dem Server gibt; anfordern kann er nichts, weil ein Knopf ohne
     * Empfänger schlechter ist als keiner.
     *
     * Was ein Kunde an *seinen* Domains auslöst, läuft nicht über diesen
     * Katalog, sondern über den Dienst (App\Support\Web\Domains): Dort ist der
     * Gegenstand ein Objekt, das durch die Mandantenklammer gekommen ist, und
     * nicht ein Schlüssel aus einer Liste.
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
