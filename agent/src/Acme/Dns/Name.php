<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Ein Name im Drahtformat — die einzige Stelle, die ihn schreibt und überliest.
 *
 * **Weil es die Stelle ist, an der ein handgeschriebener DNS-Code danebengeht.**
 * Ein Name in einer Antwort steht selten ausgeschrieben da; meistens sind es
 * zwei Bytes, die auf eine frühere Stelle zeigen (RFC 1035 §4.1.4). Wer das
 * nicht erkennt, liest die folgenden Felder um einige Bytes verschoben und
 * bekommt Werte, die fast stimmen — und im Protokoll steht nichts, was darauf
 * hindeutet.
 *
 * **Und weil es inzwischen drei Aufrufer gibt.** {@see Packet} fragt, die
 * TSIG-Unterschrift in {@see Tsig} rechnet mit, {@see UpdateMessage} schreibt.
 * Der Fehler, der dieses Projekt am häufigsten kostet, ist derselbe Gedanke an
 * zwei Orten — beim Rechnernamen viermal (`Names::fqdn()`), und die Regel
 * dazu steht in CLAUDE.md. Hier gibt es sie einmal, und
 * `DnsNameSourceTest` besteht darauf.
 */
final class Name
{
    /** Die Marke, die einen Zeiger von einer Beschriftung unterscheidet. */
    public const POINTER_MASK = 0xC0;

    /** Länger darf eine Beschriftung nicht sein (RFC 1035 §2.3.4). */
    public const MAX_LABEL = 63;

    /**
     * Ein Name als Folge von Beschriftungen, abgeschlossen mit einer Null.
     *
     * **Nie zusammengefasst.** Zeiger zu schreiben wäre erlaubt, spart ein paar
     * Bytes und macht die Unterschrift eines TSIG-Satzes ungültig, weil sie
     * über den ausgeschriebenen Namen rechnet (RFC 8945 §4.3.3). Gespart wird
     * hier nichts.
     */
    public static function encode(string $name): string
    {
        $encoded = '';

        foreach (explode('.', trim($name, '.')) as $label) {
            if ($label === '') {
                continue;
            }

            $encoded .= chr(min(strlen($label), self::MAX_LABEL)).substr($label, 0, self::MAX_LABEL);
        }

        return $encoded."\0";
    }

    /**
     * Derselbe Name in der Form, über die eine Unterschrift rechnet.
     *
     * Kleingeschrieben — so verlangt es die kanonische Form (RFC 4034 §6.2),
     * und daran hängt, ob ein Nameserver die Unterschrift annimmt.
     */
    public static function canonical(string $name): string
    {
        return self::encode(strtolower($name));
    }

    /**
     * Einen Namen überspringen — auch einen zusammengefassten.
     *
     * Siehe die Klassenbeschreibung: Das ist die Stelle, an der es schiefgeht.
     * Der Zeiger wird nicht verfolgt, weil niemand hier den Namen selbst
     * braucht; gebraucht wird die Stelle dahinter.
     */
    public static function skip(string $message, int &$offset): void
    {
        $length = strlen($message);

        while ($offset < $length) {
            $marker = ord($message[$offset]);

            if ($marker === 0) {
                $offset++;

                return;
            }

            if (($marker & self::POINTER_MASK) === self::POINTER_MASK) {
                // Ein Zeiger ist zwei Bytes lang und beendet den Namen.
                $offset += 2;

                return;
            }

            $offset += 1 + $marker;
        }
    }

    /**
     * Liegt `$name` in `$zone` — oder ist er die Zone selbst?
     *
     * Verglichen wird beschriftungsweise und nicht als Zeichenkette:
     * `bösexample.de` endet auf `example.de` und liegt trotzdem nicht darin.
     */
    public static function within(string $name, string $zone): bool
    {
        $name = strtolower(trim($name, '. '));
        $zone = strtolower(trim($zone, '. '));

        return $name === $zone || ($zone !== '' && str_ends_with($name, '.'.$zone));
    }
}
