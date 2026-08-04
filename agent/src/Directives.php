<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Eigene nginx-Direktiven je Domain — gegen eine Positivliste geprüft.
 *
 * **Das ist die einzige Stelle in P3, an der Text eines Kunden in einer Datei
 * landet, die als root gelesen wird.** §4.2 des Plans lässt sie ausdrücklich
 * zu („Freitextfelder sind auf wenige Stellen begrenzt und werden gegen eine
 * Positivliste erlaubter Direktiven geprüft"), und der ganze Rest dieser Datei
 * ist die Einlösung dieses Halbsatzes.
 *
 * **Geprüft wird der Name gegen eine Liste, nicht der Wert gegen Verbotenes.**
 * Wer „gefährliche Zeichen" herausfiltert, hat immer eines vergessen — das
 * steht so schon in {@see Guard} und gilt hier doppelt. Erlaubt ist, was in
 * der Liste unten steht; alles andere wird abgewiesen, auch wenn es harmlos
 * aussieht.
 *
 * **Keine Blöcke.** `{` und `}` sind ausgeschlossen, und damit auch
 * `location`. Das ist eine echte Einschränkung: Wer eine eigene
 * `location`-Regel braucht, bekommt sie in dieser Version nicht. Der Grund ist
 * die Reichweite — ein eigener Block kann `root`, `alias` oder `fastcgi_pass`
 * enthalten, und damit liefert die Domain eines Kunden jedes Verzeichnis des
 * Servers aus oder schickt Anfragen an den Pool eines anderen Abonnements.
 * Eine Positivliste innerhalb eines fremden Blocks wäre eine zweite Prüfung
 * mit derselben Angriffsfläche.
 *
 * **Kein `include`, kein `root`, kein `alias`, kein `*_pass`.** Sie stehen
 * nicht auf der Liste und werden deshalb schon vom Namen her abgewiesen; die
 * Aufzählung hier ist nur der Hinweis darauf, welche Namen niemals dazukommen
 * dürfen.
 */
final class Directives
{
    /** Mehr als das nimmt keine Domain, und mehr will auch niemand lesen. */
    public const MAX_COUNT = 20;

    public const MAX_LENGTH = 200;

    /**
     * Die erlaubten Direktiven.
     *
     * Jede davon wirkt ausschliesslich auf die Auslieferung dieser einen
     * Domain. Was den Ort der Dateien, den Empfänger einer Anfrage oder die
     * Einbindung weiterer Konfiguration bestimmt, steht nicht darin und wird
     * auch nicht dazukommen.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'add_header',
        'autoindex',
        'charset',
        'client_body_timeout',
        'client_max_body_size',
        'error_page',
        'expires',
        'gzip',
        'gzip_comp_level',
        'gzip_types',
        'keepalive_timeout',
        'limit_rate',
        'limit_rate_after',
        'send_timeout',
        'server_tokens',
    ];

    /**
     * Prüft eine Liste von Direktiven und gibt sie zurück.
     *
     * @return list<string>
     */
    public static function check(mixed $value, string $field = 'directives'): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw AgentException::badRequest('Direktiven müssen eine Liste sein.', [$field => 'kein Array']);
        }

        if (count($value) > self::MAX_COUNT) {
            throw AgentException::badRequest(
                sprintf('Höchstens %d eigene Direktiven.', self::MAX_COUNT),
                [$field => count($value)],
            );
        }

        $checked = [];

        foreach (array_values($value) as $index => $line) {
            $checked[] = self::one($line, $field.'['.$index.']');
        }

        return $checked;
    }

    /** Eine einzelne Direktive, in ihrer Normalform: eine Zeile, ein Semikolon. */
    public static function one(mixed $value, string $field): string
    {
        $line = trim(Guard::string($value, $field));

        if ($line === '' || strlen($line) > self::MAX_LENGTH) {
            throw self::rejected($field, $line, 'leer oder zu lang');
        }

        // **Eine Zeile.** Ein Umbruch wäre eine zweite Direktive, die nur die
        // erste Prüfung durchläuft — der klassische Weg, an einer Positivliste
        // vorbeizukommen. Zeilenumbruch, Wagenrücklauf und Null zählen alle.
        if (preg_match('/[\r\n\0]/', $line) === 1) {
            throw self::rejected($field, $line, 'mehr als eine Zeile');
        }

        // Blöcke, Kommentare und weitere Anweisungen. Das Semikolon darf genau
        // einmal vorkommen, nämlich am Ende.
        if (preg_match('/[{}#`]/', $line) === 1) {
            throw self::rejected($field, $line, 'Block, Kommentar oder Befehlszeichen');
        }

        if (! str_ends_with($line, ';') || substr_count($line, ';') !== 1) {
            throw self::rejected($field, $line, 'genau ein Semikolon, und zwar am Ende');
        }

        if (preg_match('/^([a-z_]+)[ \t]+([^;]+);$/D', $line, $match) !== 1) {
            throw self::rejected($field, $line, 'Name, Leerzeichen, Wert, Semikolon');
        }

        if (! in_array($match[1], self::ALLOWED, true)) {
            throw AgentException::badRequest(
                sprintf('Die Direktive %s steht nicht auf der Positivliste.', $match[1]),
                [$field => $line, 'allowed' => self::ALLOWED],
            );
        }

        return $line;
    }

    private static function rejected(string $field, string $line, string $why): AgentException
    {
        return AgentException::badRequest(
            sprintf('Unzulässige Direktive (%s).', $why),
            [$field => substr($line, 0, 80)],
        );
    }
}
