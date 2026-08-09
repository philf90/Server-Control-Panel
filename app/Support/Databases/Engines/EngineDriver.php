<?php

declare(strict_types=1);

namespace App\Support\Databases\Engines;

use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Subscription;

/**
 * Was an einem Datenbanksystem eigen ist — Namen und Nutzlast, sonst nichts.
 *
 * **Das ist die eine Verzweigung aus `docs/38 §8`.** Der Plan verlangt, dass
 * `Databases` an *einer* Stelle über `engine` entscheidet; alles Weitere —
 * Kontingent, Namensprüfung, Mandantenklammer, das Schreiben der Zeile — steht
 * dort genau einmal und gilt für beide Systeme. Diese Stelle ist die Wahl der
 * Umsetzung, `Databases::driver()`.
 *
 * **Warum keine `match` in jeder Methode.** Fünf Methoden mit je zwei Zweigen
 * sind fünf Gelegenheiten, einen zu vergessen — und `CLAUDE.md` sagt über
 * zweite Fassungen derselben Regel, dass die zweite die ist, die veraltet. Eine
 * Schnittstelle mit zwei Umsetzungen macht daraus eine Liste, die vollständig
 * sein *muss*: Wer eine Methode ergänzt, bekommt vom Übersetzer gesagt, dass
 * die andere Umsetzung fehlt.
 *
 * ## Was hier **nicht** steht
 *
 * Kein Zustand, keine Zeile, keine Prüfung des Kontingents. Ein Treiber baut
 * einen Aufruf und gibt zurück, was der Agent geantwortet hat. Er entscheidet
 * nicht, *ob* etwas geschehen darf — das tut die Policy, und der Bestand
 * gehört `Databases`.
 *
 * ## Die drei Unterschiede, die es überhaupt gibt
 *
 * | | MariaDB | PostgreSQL |
 * |---|---|---|
 * | Präfix | `p1001` — der Systembenutzer | `x7f3a…` — nichtssagend (`docs/38 §4`) |
 * | Sortierung | `charset` + `collation` | `encoding` + `locale` |
 * | Zugang | `name` **und** `host` sind der Schlüssel | die Rolle allein, clusterweit |
 *
 * Alles andere aus der Tabelle in `docs/38 §8` liegt **unterhalb** des
 * Agentenprotokolls und kommt hier nie vor.
 */
interface EngineDriver
{
    public static function engine(): DatabaseEngine;

    /**
     * Das Präfix dieses Abonnements — aus der Datenbank und nie aus einer Anfrage.
     *
     * Der Teil des Namens, der über die Mandantengrenze entscheidet, kommt aus
     * der abgelegten Zeile. Dieselbe Regel wie in `Lifecycle::payload()`.
     */
    public function prefix(Subscription $subscription): string;

    public function databaseName(string $prefix, string $label): string;

    public function userName(string $prefix, string $label): string;

    /**
     * Eine Datenbank anlegen.
     *
     * Was zurückkommt, ist die Antwort des Agenten und nicht das Bestellte.
     *
     * @return array{name: string, charset: string, collation: string}
     */
    public function createDatabase(string $prefix, string $label, string $collation): array;

    /**
     * Einen Zugang anlegen und ihm die genannten Datenbanken freigeben.
     *
     * @param  list<string>  $databases
     * @return array{name: string, host: string}
     */
    public function createUser(string $prefix, string $label, array $databases, string $host, string $password): array;

    /**
     * Ein neues Passwort für einen vorhandenen Zugang.
     *
     * `$databases` steht dabei, weil PostgreSQL dafür dieselbe Operation nimmt
     * wie zum Anlegen — sie ist wiederholbar und setzt das Passwort auch an
     * einer Rolle, die es schon gibt. Ohne die Liste verlöre der Zugang beim
     * Zurücksetzen seine Freigaben.
     *
     * @param  list<string>  $databases
     */
    public function setPassword(string $prefix, DbUser $user, array $databases, string $password): void;

    public function grant(string $prefix, DbUser $user, string $database, bool $granted): void;

    /**
     * Einen Zugang entfernen.
     *
     * `$databases` sind die, in denen er noch etwas hat — PostgreSQL braucht
     * sie, um vor `DROP ROLE` aufzuräumen (`agent/src/Ops/PgRoleRemove.php`).
     *
     * @param  list<string>  $databases
     */
    public function removeUser(string $prefix, DbUser $user, array $databases): void;

    /** Der Name der Aufgabe, die den Rückbau einer Datenbank ausführt. */
    public function removalTask(): string;

    /**
     * Die Nutzlast dazu.
     *
     * **Beide Listen kommen aus dem Bestand des Panels**, weil der Agent keinen
     * führt. `$doomed` hängt nur an dieser Datenbank und geht mit; `$staying`
     * hängt auch an anderen und bleibt — was mit ihm geschieht, ist der
     * Unterschied zwischen den Systemen und steht in der jeweiligen Umsetzung.
     *
     * @param  list<DbUser>  $doomed
     * @param  list<DbUser>  $staying
     * @return array<string, mixed>
     */
    public function removalPayload(string $prefix, Database $database, array $doomed, array $staying): array;
}
