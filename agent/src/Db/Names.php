<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Die Namen von Datenbanken und Datenbankbenutzern.
 *
 * **Das Präfix ist der Systembenutzer des Abonnements** — `p1001`, nicht der
 * Abonnementname (`docs/36 §3`). Vier Gründe, und der erste ist der, den es
 * ohne `docs/35` nicht gäbe:
 *
 * 1. **Eine Nummer wird nie zweimal vergeben.** Seit `docs/35` steht die
 *    Reservierung in `system_users`, und `Lifecycle::claim()` verbraucht sie
 *    endgültig. Damit kann der Schemaname eines neuen Abonnements niemals auf
 *    ein Verzeichnis in `/var/lib/mysql` treffen, das ein zurückgebautes
 *    hinterlassen hat. Mit dem Abonnementnamen als Präfix wäre genau das
 *    möglich: Namen dürfen wiederverwendet werden, seit ein zurückgebautes
 *    Abonnement hart gelöscht wird.
 * 2. `p` plus vier bis neun Ziffern ist bereits ein gültiger Bezeichner ohne
 *    Anführung. Ein Abonnementname ist ein Domainname und müsste an *jeder*
 *    Stelle in Backticks stehen — und „an jeder Stelle" ist die Formulierung,
 *    aus der Lücken entstehen.
 * 3. Er ist kurz: höchstens zehn Zeichen. Damit bleibt der zusammengesetzte
 *    Name unter allen drei Grenzen (§ {@see self::MAX_DATABASE},
 *    {@see self::MAX_USER}).
 * 4. **Die Regel steht schon da.** Geprüft wird mit
 *    {@see SubscriptionProvision::systemUser()} und nicht mit einem zweiten
 *    Ausdruck — dieselbe Entscheidung wie in `docs/26 §3`, wo der
 *    Abonnementname mit der Funktion des Agenten selbst geprüft wird. Zwei
 *    Formulierungen derselben Regel sind zwei, und die eine, die beim nächsten
 *    Mal nachgezogen wird, ist erfahrungsgemäss nicht beide.
 *
 * **Der Zusatz kommt aus dem Formular, das Präfix nie.** Der Browser schickt
 * `shop`; `p1001` liest die Anwendung aus der abgelegten Zeile des Abonnements,
 * das durch die Mandantenklammer gekommen ist. Der Teil des Namens, der über
 * die Mandantengrenze entscheidet, erreicht den Agenten nicht aus einer
 * Anfrage.
 */
final class Names
{
    /**
     * MariaDB nimmt 64 Zeichen für einen Schemanamen.
     *
     * Ausgeschöpft wird das nicht — die Zusatzregel deckelt weit darunter. Die
     * Zahl steht trotzdem da, weil sie die Grenze ist, gegen die geprüft wird,
     * und nicht die, die wir uns wünschen.
     */
    public const MAX_DATABASE = 64;

    /**
     * Und 32 für einen Benutzernamen — die Zahl von MySQL.
     *
     * MariaDB lässt seit 10.6 achtzig zu. **Die engere Zahl gilt**, weil ein
     * Name, der auf einem der beiden Systeme nicht anlegbar ist, ein Name ist,
     * der auf diesem Server irgendwann nicht anlegbar ist.
     */
    public const MAX_USER = 32;

    /**
     * Der Teil hinter dem Unterstrich, den der Kunde wählt.
     *
     * Kleinbuchstaben, Ziffern, Unterstrich; beginnend mit einem Buchstaben,
     * höchstens sechzehn Zeichen. Kein Punkt (er trennt in `db.tabelle`), kein
     * Backtich, kein Anführungszeichen, kein Bindestrich (er zwänge zur
     * Anführung), keine Grossbuchstaben (`lower_case_table_names` entscheidet
     * sonst je nach System, ob `Shop` und `shop` dasselbe sind).
     */
    private const SUFFIX = '/^[a-z][a-z0-9_]{0,15}$/D';

    /**
     * Das Präfix eines Abonnements — geprüft mit der Regel des Agenten.
     *
     * Nicht `^p[0-9]{4,9}$` von Hand: Diese Regel gehört
     * {@see SubscriptionProvision}, und dort steht auch, warum sie so eng ist.
     */
    public static function prefix(mixed $systemUser): string
    {
        return SubscriptionProvision::systemUser($systemUser);
    }

    /**
     * Die Form eines befristeten Benutzers — als Muster, damit sie sich sperren
     * lässt.
     *
     * Sie steht hier oben, weil sie an zwei Stellen gebraucht wird: zum
     * Erkennen ({@see self::isEphemeral()}) und zum **Reservieren**
     * ({@see self::suffix()}).
     */
    private const EPHEMERAL_SUFFIX = '/^r[0-9a-f]{8}$/D';

    /**
     * Der Zusatz, geprüft.
     *
     * **`r` plus acht Hexziffern ist gesperrt, und der Grund ist eine
     * Verwechslung mit Folgen.** Ein befristeter Benutzer heisst
     * `p1001_r3f9a20c1` (§ {@see self::ephemeral()}); `db.server.info` meldet
     * jeden, der älter als eine Stunde ist, als Rest eines abgebrochenen
     * Zurückspielens, und `srvpanel db prune` wirft ihn weg. Ein Kunde, der
     * seinen Zugang `r3f9a20c1` nennt, verliert ihn damit nach einer Stunde —
     * ohne dass irgendetwas falsch programmiert wäre.
     *
     * Aufgefallen beim Schreiben von `DbNameTest`, nicht im Betrieb. Die
     * Alternative wäre gewesen, den befristeten Namen unkenntlicher zu machen;
     * das verschiebt die Kollision nur und macht sie unwahrscheinlicher statt
     * unmöglich. **Eine Regel, die zufällig gilt, gilt bis zur nächsten
     * Änderung an einer ganz anderen Stelle** — dieselbe Begründung wie bei der
     * Maskierung in {@see Sql::grantTarget()}.
     */
    public static function suffix(mixed $value): string
    {
        $suffix = Guard::string($value, 'suffix');

        if (! preg_match(self::SUFFIX, $suffix)) {
            throw AgentException::badRequest(
                'Unzulässiger Name — erwartet werden Kleinbuchstaben, Ziffern und Unterstrich, '
                .'beginnend mit einem Buchstaben, höchstens sechzehn Zeichen.',
                ['suffix' => $suffix],
            );
        }

        if (preg_match(self::EPHEMERAL_SUFFIX, $suffix)) {
            throw AgentException::badRequest(
                'Dieser Name ist für die Zugänge reserviert, die das Zurückspielen einer Sicherung '
                .'für ein paar Minuten anlegt — er würde danach von selbst wieder verschwinden.',
                ['suffix' => $suffix],
            );
        }

        return $suffix;
    }

    /** `p1001` + `shop` → `p1001_shop`. */
    public static function database(mixed $systemUser, mixed $suffix): string
    {
        return self::compose(self::prefix($systemUser), self::suffix($suffix), self::MAX_DATABASE, 'Datenbankname');
    }

    /** `p1001` + `web` → `p1001_web`. */
    public static function user(mixed $systemUser, mixed $suffix): string
    {
        return self::compose(self::prefix($systemUser), self::suffix($suffix), self::MAX_USER, 'Benutzername');
    }

    /**
     * Ein vollständiger Name, wie er aus der abgelegten Zeile kommt.
     *
     * Die Anwendung schickt beim Entfernen und beim Berechtigen den ganzen
     * Namen und nicht die zwei Hälften — er steht so in der Datenbank, und ihn
     * hier neu zusammenzusetzen hiesse, dass zwei Stellen entscheiden, wie er
     * lautet. Geprüft wird er trotzdem, und zwar gegen dieselbe Form, die
     * {@see self::compose()} erzeugt: **Es gibt keinen Weg, einen Namen in
     * diesen Agenten zu bringen, der nicht dem Muster entspricht.** Das ist die
     * Zeile, an der ein `DROP DATABASE mysql` scheitert.
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
     * **Dieselbe Frage wie in {@see self::existing()}, nur ohne Ausnahme** —
     * und deshalb steht das Muster jetzt einmal statt zweimal. Der Anlass ist
     * `db.usage`: `information_schema` gibt jedes Schema des Servers aus, auch
     * `mysql` und das der Panel-Datenbank, und herausgegeben wird nur, was dem
     * Panel gehört. Wer diese Frage dort mit einem eigenen Ausdruck beantwortet
     * hätte, hätte die Regel ein zweites Mal geschrieben — und die zweite
     * Fassung ist die, die veraltet. Genau dieser Fehler steht in CLAUDE.md
     * unter `srvpanel dns`, das nur RFC 2136 kannte.
     *
     * Der Unterschied zu {@see self::belongsTo()}: Dort ist bekannt, *wem* der
     * Name gehören soll. Hier ist nur die Frage, ob überhaupt jemand aus diesem
     * Panel dahintersteht.
     */
    public static function isPanelName(string $name): bool
    {
        return preg_match('/^p[0-9]{4,9}_[a-z][a-z0-9_]{0,15}$/D', $name) === 1;
    }

    /** Gehört dieser Name zu diesem Abonnement? */
    public static function belongsTo(string $name, string $systemUser): bool
    {
        return str_starts_with($name, $systemUser.'_');
    }

    /**
     * Der Wirt eines Datenbankbenutzers.
     *
     * `localhost` ist der Grundfall; für den Fernzugriff (`docs/36 §12`) steht
     * hier eine IP-Adresse oder ein Netz in der Schreibweise von MariaDB
     * (`203.0.113.0/255.255.255.0`).
     *
     * **`%` wird abgewiesen, und das ist keine Vorsicht, sondern die Regel.**
     * Ein Datenbankbenutzer, der von überall erreichbar ist, ist die Vorlage
     * für den nächsten Vorfallsbericht. Wer das will, tippt es in `mysql` —
     * dann ist es seine Entscheidung und nicht ein Feld, das wir angeboten
     * haben.
     */
    public static function host(mixed $value): string
    {
        $host = Guard::string($value, 'host');

        if ($host === 'localhost') {
            return $host;
        }

        if (str_contains($host, '%') || str_contains($host, '_')) {
            throw AgentException::badRequest(
                'Ein Wirt mit Platzhaltern wird nicht angelegt — erlaubt sind localhost, eine IP-Adresse oder ein Netz.',
                ['host' => $host],
            );
        }

        // Eine IP-Adresse, wahlweise mit Netzmaske in der Schreibweise von
        // MariaDB. Kein Rechnername: Er löst zur Verbindungszeit auf, und was
        // dann gilt, entscheidet der DNS-Server und nicht diese Zeile.
        [$address, $mask] = array_pad(explode('/', $host, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw AgentException::badRequest('Unzulässiger Wirt.', ['host' => $host]);
        }

        if ($mask !== null && filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw AgentException::badRequest(
                'Die Netzmaske wird in der Schreibweise von MariaDB erwartet, etwa 203.0.113.0/255.255.255.0.',
                ['host' => $host],
            );
        }

        return $host;
    }

    /**
     * Der Name eines befristeten Benutzers für einen einzelnen Lauf.
     *
     * Er trägt dasselbe Präfix wie alles andere des Abonnements — damit findet
     * ihn der Rückbau, wenn ein abgebrochener Vorgang ihn stehenlässt, und
     * damit gehört er sichtbar zu jemandem. Das `r` grenzt ihn von den Namen
     * ab, die ein Kunde wählen kann: Der Zusatz muss mit einem Buchstaben
     * beginnen und darf danach Ziffern tragen, aber `r` gefolgt von acht
     * Hexziffern ist ein Name, den niemand tippt (`docs/36 §10.2`).
     */
    public static function ephemeral(string $systemUser): string
    {
        return self::compose(
            self::prefix($systemUser),
            'r'.bin2hex(random_bytes(4)),
            self::MAX_USER,
            'Benutzername',
        );
    }

    /**
     * Trägt dieser Name die Form eines befristeten Benutzers?
     *
     * Die Frage ist eindeutig beantwortbar, weil {@see self::suffix()} die Form
     * sperrt — ein Kundenzugang kann nicht so heissen. Ohne die Sperre wäre
     * diese Methode eine Vermutung, und `srvpanel db prune` räumte danach auf.
     */
    public static function isEphemeral(string $name): bool
    {
        $parts = explode('_', $name, 2);

        return count($parts) === 2
            && preg_match('/^p[0-9]{4,9}$/D', $parts[0]) === 1
            && preg_match(self::EPHEMERAL_SUFFIX, $parts[1]) === 1;
    }

    private static function compose(string $prefix, string $suffix, int $limit, string $what): string
    {
        $name = $prefix.'_'.$suffix;

        if (strlen($name) > $limit) {
            throw AgentException::badRequest(
                sprintf('%s zu lang: %d Zeichen, erlaubt sind %d.', $what, strlen($name), $limit),
                ['name' => $name],
            );
        }

        return $name;
    }
}
