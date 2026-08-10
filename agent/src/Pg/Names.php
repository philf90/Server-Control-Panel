<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Pg;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Db\Names as DbNames;
use SrvPanel\Agent\Guard;

/**
 * Die Namen von Datenbanken und Rollen in PostgreSQL.
 *
 * **Das Präfix sagt nichts, und das ist die Abschottung** (`docs/38 §4`). In P5
 * ist es der Systembenutzer — `p1001_shop` —, und das trägt dort, weil MariaDB
 * einem Kunden fremde Schemata gar nicht erst anzeigt. PostgreSQL zeigt sie
 * (gemessen, `docs/38 §2.2`), und `p1002_shop` verrät damit jedem Kunden des
 * Servers, dass es ein Abonnement 1002 mit einem Shop gibt.
 *
 * Also `x7f3a91c2b40e15d6_shop`: sechzehn Hexziffern aus `random_bytes`, je
 * Abonnement einmal vergeben und in `system_users.db_prefix` aufbewahrt.
 *
 * **Warum ein Präfix und nicht ein durchweg zufälliger Name.** Das Präfix ist
 * in P5 nicht nur Anzeige: {@see DbNames::belongsTo()} prüft im *Agenten*, ob
 * ein Name zu dem Abonnement gehört, in dessen Auftrag die Operation läuft — und
 * die Selbstprobe hängt daran. Ein Name ohne Präfix nähme dem Agenten diese
 * Prüfung ersatzlos, denn er führt keinen Zustand, aus dem er sie
 * rekonstruieren könnte. **Ein Präfix, das nichts verrät, behält den Wächter und
 * verliert nur die Auskunft.**
 *
 * Was ein Fremder danach noch sieht: dass es N Datenbanken gibt, wie viele
 * davon dasselbe Präfix teilen, und die Zusätze. Nicht, zu wem sie gehören. Der
 * Rest ist in `docs/38 §22` als Risiko benannt statt weggeredet.
 *
 * **Das Präfix kommt aus der abgelegten Zeile, der Zusatz aus dem Formular** —
 * dieselbe Regel wie in `docs/36 §3`. Der Teil des Namens, der über die
 * Mandantengrenze entscheidet, erreicht den Agenten nicht aus einer Anfrage.
 */
final class Names
{
    /**
     * Die Grenze für jeden Bezeichner in PostgreSQL.
     *
     * `NAMEDATALEN - 1`, am 9. August 2026 auf PostgreSQL 16.13 nachgelesen
     * (`max_identifier_length`) statt aus dem Kopf geschrieben. Ein längerer
     * Name wird **nicht abgewiesen, sondern abgeschnitten** — und zwei Namen,
     * die sich erst nach Zeichen 63 unterscheiden, wären danach derselbe. Genau
     * deshalb steht die Zahl hier und wird geprüft.
     *
     * Ausgeschöpft wird sie nicht: 17 + 1 + 16 sind höchstens 34.
     */
    public const MAX_IDENTIFIER = 63;

    /**
     * Die Form des Präfixes.
     *
     * `x` voran, damit der Name mit einem Buchstaben beginnt und ohne Anführung
     * gültig ist — eine Kennung, die mit einer Ziffer anfängt, müsste an jeder
     * Stelle in Anführungszeichen stehen, und „an jeder Stelle" ist die
     * Formulierung, aus der Lücken entstehen (`docs/36 §3`, Grund 2).
     *
     * Sechzehn Hexziffern sind 64 Bit. Das ist nicht als Geheimnis gedacht — das
     * Präfix steht in jeder Verbindungszeichenkette des Kunden —, sondern gegen
     * das Erraten eines *fremden*: Der Ratekanal aus `docs/38 §2.2` (M3) bleibt
     * offen, und was ihn unbrauchbar macht, ist die Grösse des Raums.
     */
    private const PREFIX = '/^x[0-9a-f]{16}$/D';

    /**
     * Der Teil hinter dem Unterstrich, den der Kunde wählt.
     *
     * Wortgleich die Regel aus `docs/36 §3`, und aus denselben Gründen:
     * Kleinbuchstaben, weil PostgreSQL unangeführte Bezeichner klein schreibt
     * und `Shop` damit still zu `shop` würde; kein Punkt, weil er in
     * `schema.tabelle` trennt; kein Bindestrich und kein Anführungszeichen, weil
     * beide zur Anführung zwängen.
     */
    private const SUFFIX = '/^[a-z][a-z0-9_]{0,15}$/D';

    /**
     * Die Form einer befristeten Rolle — als Muster, damit sie sich sperren lässt.
     *
     * Steht oben, weil sie an zwei Stellen gebraucht wird: zum Erkennen und zum
     * **Reservieren** in {@see self::suffix()}. Der Grund dafür ist ein Fund aus
     * P5, der im Betrieb nie aufgefallen wäre: Ein Kunde, der seinen Zugang
     * `r3f9a20c1` nennt, verlöre ihn nach einer Stunde, weil der Aufräumlauf ihn
     * für den Rest eines abgebrochenen Zurückspielens hält.
     */
    private const EPHEMERAL_SUFFIX = '/^r[0-9a-f]{8}$/D';

    /**
     * Ein neues Präfix — **einmal je Abonnement**.
     *
     * Vergeben wird es von der Anwendung und in `system_users` abgelegt, nicht
     * hier bei jedem Aufruf. Diese Methode erzeugt es; wer sie mit
     * {@see self::prefix()} verwechselt, vergibt zweimal dasselbe — genau die
     * Verwechslung, die `docs/35` zwischen `claim()` und `nextSystemUser()`
     * teuer gelernt hat.
     */
    public static function newPrefix(): string
    {
        return 'x'.bin2hex(random_bytes(8));
    }

    /**
     * Ein vorhandenes Präfix, geprüft.
     */
    public static function prefix(mixed $value): string
    {
        $prefix = Guard::string($value, 'prefix');

        if (preg_match(self::PREFIX, $prefix) !== 1) {
            throw AgentException::badRequest(
                'Unzulässiges Präfix — erwartet werden ein x und sechzehn Hexziffern.',
                ['prefix' => $prefix],
            );
        }

        return $prefix;
    }

    /**
     * Der Zusatz, geprüft.
     */
    public static function suffix(mixed $value): string
    {
        $suffix = Guard::string($value, 'suffix');

        if (preg_match(self::SUFFIX, $suffix) !== 1) {
            throw AgentException::badRequest(
                'Unzulässiger Name — erwartet werden Kleinbuchstaben, Ziffern und Unterstrich, '
                .'beginnend mit einem Buchstaben, höchstens sechzehn Zeichen.',
                ['suffix' => $suffix],
            );
        }

        if ($suffix === self::OWNER_SUFFIX) {
            throw AgentException::badRequest(
                'Dieser Name gehört der Eigentümerrolle des Abonnements — ihr gehört alles, was in '
                .'seinen Datenbanken steht, und ein zweiter Träger dieses Namens nähme ihr das.',
                ['suffix' => $suffix],
            );
        }

        if (preg_match(self::EPHEMERAL_SUFFIX, $suffix) === 1) {
            throw AgentException::badRequest(
                'Dieser Name ist für die Zugänge reserviert, die das Zurückspielen einer Sicherung '
                .'für ein paar Minuten anlegt — er würde danach von selbst wieder verschwinden.',
                ['suffix' => $suffix],
            );
        }

        return $suffix;
    }

    /** `x7f3a…` + `shop` → `x7f3a…_shop`. */
    public static function database(mixed $prefix, mixed $suffix): string
    {
        return self::compose(self::prefix($prefix), self::suffix($suffix), 'Datenbankname');
    }

    /**
     * `x7f3a…` + `web` → `x7f3a…_web`.
     *
     * **Eine Rolle und kein Benutzer je Wirt.** In MariaDB sind
     * `'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'` zwei Benutzer
     * mit zwei Passwörtern; in PostgreSQL ist es eine Rolle mit einem Passwort,
     * und von wo sie kommen darf, steht in `pg_hba.conf` (`docs/38 §14.3`).
     * Deshalb nimmt diese Methode keinen Wirt entgegen — und deshalb gibt es
     * hier kein Gegenstück zu `Db\Names::host()`.
     */
    public static function role(mixed $prefix, mixed $suffix): string
    {
        return self::compose(self::prefix($prefix), self::suffix($suffix), 'Rollenname');
    }

    /**
     * Der Zusatz der Eigentümerrolle — reserviert, damit ihn kein Zugang bekommt.
     */
    public const OWNER_SUFFIX = 'owner';

    /**
     * Die Eigentümerrolle eines Abonnements.
     *
     * ## Warum es sie gibt
     *
     * **Weil zwei Zugänge desselben Abonnements sonst nicht an dieselben Daten
     * kommen.** In MariaDB haben alle Zugänge eines Abonnements dieselben Rechte
     * auf dasselbe Schema; in PostgreSQL gehört eine Tabelle dem, der sie
     * angelegt hat, und ein zweiter Zugang bekommt `permission denied`. Am
     * 10. August 2026 gemessen: `x_cron` konnte weder lesen noch ändern, was
     * `x_web` angelegt hatte.
     *
     * **Und weil eine Wiederherstellung dem Kunden sonst seine Daten wegnimmt.**
     * Das Zurückspielen lief unter einer befristeten Rolle, und das Eigentum
     * ging danach an den Eigentümer der *Datenbank* — an `root`. Der Kunde stand
     * vor seinen eigenen Zeilen und bekam `permission denied for table`
     * (`docs/39`, Punkt 7).
     *
     * ## Wie sie wirkt
     *
     * Ihr gehört das Schema `public`; jeder Zugang des Abonnements ist Mitglied,
     * und **jede seiner Sitzungen läuft als sie** — `ALTER ROLE … IN DATABASE …
     * SET role`. Was ein Zugang anlegt, gehört damit der Gruppe, und jedes
     * andere Mitglied darf es lesen, ändern und löschen. `session_user` bleibt
     * der Zugang selbst; wer verbunden ist, steht weiter im Protokoll von
     * PostgreSQL.
     *
     * **Sie meldet sich nirgends an** (`NOLOGIN`) und hat kein Passwort. Sie ist
     * ein Name für „was diesem Abonnement gehört" und kein Zugang.
     */
    public static function owner(mixed $prefix): string
    {
        return self::compose(self::prefix($prefix), self::OWNER_SUFFIX, 'Rollenname');
    }

    /**
     * Ein vollständiger Name, wie er aus der abgelegten Zeile kommt.
     *
     * Die Anwendung schickt beim Entfernen und beim Berechtigen den ganzen
     * Namen und nicht die zwei Hälften — er steht so in der Datenbank, und ihn
     * hier neu zusammenzusetzen hiesse, dass zwei Stellen entscheiden, wie er
     * lautet. Geprüft wird er trotzdem: **Es gibt keinen Weg, einen Namen in
     * diesen Agenten zu bringen, der nicht dem Muster entspricht.** Das ist die
     * Zeile, an der ein `DROP DATABASE postgres` scheitert.
     */
    public static function existing(mixed $value, string $field = 'name'): string
    {
        $name = Guard::string($value, $field);

        if (! self::isPanelName($name)) {
            throw AgentException::badRequest(
                'Unzulässiger Name — erwartet wird das Präfix des Abonnements, ein Unterstrich und der Zusatz.',
                [$field => $name],
            );
        }

        return $name;
    }

    /**
     * Trägt dieser Name die Form, die dieses Panel vergibt?
     *
     * Der Anlass ist `pg.usage`: `pg_database` führt jede Datenbank des
     * Clusters, auch `postgres` und die beiden Vorlagen. Herausgegeben wird nur,
     * was dem Panel gehört — dieselbe Entscheidung wie bei `db.usage`, und mit
     * demselben Ausdruck statt einem zweiten.
     */
    public static function isPanelName(string $name): bool
    {
        $parts = explode('_', $name, 2);

        return count($parts) === 2
            && preg_match(self::PREFIX, $parts[0]) === 1
            && preg_match(self::SUFFIX, $parts[1]) === 1;
    }

    /** Gehört dieser Name zu diesem Abonnement? */
    public static function belongsTo(string $name, string $prefix): bool
    {
        return str_starts_with($name, $prefix.'_');
    }

    /**
     * Der Name einer befristeten Rolle für einen einzelnen Lauf.
     *
     * Sie trägt dasselbe Präfix wie alles andere des Abonnements — damit findet
     * sie der Rückbau, wenn ein abgebrochener Vorgang sie stehenlässt, und damit
     * gehört sie sichtbar zu jemandem (`docs/36 §10.2`).
     */
    public static function ephemeral(string $prefix): string
    {
        return self::compose(self::prefix($prefix), 'r'.bin2hex(random_bytes(4)), 'Rollenname');
    }

    /**
     * Trägt dieser Name die Form einer befristeten Rolle?
     *
     * Die Frage ist eindeutig beantwortbar, weil {@see self::suffix()} die Form
     * sperrt — ein Kundenzugang kann nicht so heissen. Ohne die Sperre wäre
     * diese Methode eine Vermutung, und `srvpanel db prune` räumte danach auf.
     */
    public static function isEphemeral(string $name): bool
    {
        $parts = explode('_', $name, 2);

        return count($parts) === 2
            && preg_match(self::PREFIX, $parts[0]) === 1
            && preg_match(self::EPHEMERAL_SUFFIX, $parts[1]) === 1;
    }

    private static function compose(string $prefix, string $suffix, string $what): string
    {
        $name = $prefix.'_'.$suffix;

        if (strlen($name) > self::MAX_IDENTIFIER) {
            throw AgentException::badRequest(
                sprintf('%s zu lang: %d Zeichen, erlaubt sind %d.', $what, strlen($name), self::MAX_IDENTIFIER),
                ['name' => $name],
            );
        }

        return $name;
    }
}
