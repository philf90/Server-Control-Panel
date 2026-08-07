<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

use SrvPanel\Agent\AgentException;

/**
 * XML-RPC — so wenig davon wie nötig, und an einer Stelle.
 *
 * **Warum das hier von Hand steht.** INWX ist der einzige der sieben Anbieter,
 * der kein JSON spricht; seine Schnittstelle (`api.domrobot.com/xmlrpc/`) nimmt
 * XML-RPC entgegen und sonst nichts. PHPs eigene `xmlrpc`-Erweiterung gibt es
 * seit PHP 8 nicht mehr, und der Agent hat keine Abhängigkeiten — er läuft als
 * root auf fremden Servern, und jedes Paket darin ist eine Zeile mehr, die
 * jemand anderes schreibt.
 *
 * **Gebaut ist deshalb nur, was INWX braucht:** ein Aufruf mit genau einem
 * Parameter, und der ist eine flache Ablage aus Zeichenketten und Zahlen.
 * Verschachtelte Felder als *Eingabe* gibt es nicht — was nicht gebraucht wird,
 * kann auch nicht falsch sein.
 *
 * **Beim Lesen ist es umgekehrt**, und das ist die gefährliche Richtung: Was
 * hereinkommt, bestimmt die Gegenstelle. Zwei Vorkehrungen stehen deshalb
 * fest:
 *
 * 1. **Der Parser bekommt kein Netz und keine Entitäten.** `LIBXML_NONET`
 *    verbietet das Nachladen, und externe Entitäten sind seit PHP 8 ohnehin
 *    aus — beides zusammen ist die Antwort auf XXE. Ein Prozess mit
 *    Systemrechten, der einer fremden Antwort erlaubt, `/etc/shadow` zu
 *    zitieren, ist kein Prozess mit Systemrechten, sondern eine Tür.
 * 2. **Die Verschachtelung ist gedeckelt.** Eine Antwort, die sich tausendfach
 *    ineinander schachtelt, ist keine Antwort, sondern ein Weg, den Speicher
 *    dieses Prozesses zu füllen.
 */
final class XmlRpc
{
    /** Tiefer wird nicht gelesen. */
    public const MAX_DEPTH = 20;

    /**
     * Ein Aufruf als XML.
     *
     * @param  array<string, string|int>  $params  Der eine Parameter, flach
     */
    public static function request(string $method, array $params): string
    {
        $members = '';

        foreach ($params as $name => $value) {
            $members .= '<member><name>'.self::escape((string) $name).'</name>'.
                '<value>'.(is_int($value)
                    ? '<int>'.$value.'</int>'
                    : '<string>'.self::escape($value).'</string>').
                '</value></member>';
        }

        return '<?xml version="1.0"?><methodCall>'.
            '<methodName>'.self::escape($method).'</methodName>'.
            '<params><param><value><struct>'.$members.'</struct></value></param></params>'.
            '</methodCall>';
    }

    /**
     * Und die Antwort als Ablage.
     *
     * **Ein `methodResponse` mit `fault` ist eine Antwort und kein Bruch.** Er
     * kommt hier als gewöhnliche Ablage heraus; was er bedeutet, entscheidet
     * der Aufrufer, denn nur er kennt die Nummern seines Anbieters.
     *
     * @return array<string, mixed>
     */
    public static function response(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // Siehe die Klassenbeschreibung: kein Netz, keine Entitäten.
        $document = simplexml_load_string(trim($xml), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw AgentException::execFailed('Die Antwort der Gegenstelle ist kein lesbares XML.');
        }

        $value = $document->params->param->value ?? $document->fault->value ?? null;

        if (! $value instanceof \SimpleXMLElement) {
            throw AgentException::execFailed('Die Antwort der Gegenstelle ist keine XML-RPC-Antwort.');
        }

        $decoded = self::value($value, 0);

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * Ein `<value>` — die einzige Stelle, die den Typ entscheidet.
     *
     * Ein `<value>` ohne Kind ist eine Zeichenkette; so steht es in der
     * Spezifikation, und INWX macht davon Gebrauch.
     */
    private static function value(\SimpleXMLElement $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw AgentException::execFailed('Die Antwort der Gegenstelle ist zu tief verschachtelt.');
        }

        foreach ($value->children() as $name => $child) {
            return match ((string) $name) {
                'struct' => self::struct($child, $depth + 1),
                'array' => self::list($child, $depth + 1),
                'int', 'i4' => (int) (string) $child,
                'boolean' => (string) $child === '1',
                'double' => (float) (string) $child,
                default => (string) $child,
            };
        }

        return (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function struct(\SimpleXMLElement $struct, int $depth): array
    {
        $fields = [];

        foreach ($struct->member as $member) {
            $name = (string) ($member->name ?? '');

            if ($name !== '' && isset($member->value)) {
                $fields[$name] = self::value($member->value, $depth);
            }
        }

        return $fields;
    }

    /**
     * @return list<mixed>
     */
    private static function list(\SimpleXMLElement $array, int $depth): array
    {
        $items = [];

        foreach ($array->data->value ?? [] as $entry) {
            $items[] = self::value($entry, $depth);
        }

        return $items;
    }

    /**
     * Text, der in XML stehen darf.
     *
     * **Auch die Namen werden maskiert und nicht nur die Werte.** Ein
     * Feldname kommt in diesem Panel zwar immer aus dem Code — aber eine
     * Maskierung, die von der Herkunft abhängt, ist eine, die beim nächsten
     * Aufrufer fehlt.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
