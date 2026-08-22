<?php

declare(strict_types=1);

namespace App\Support\Dns;

/**
 * Darf unsere Zertifizierungsstelle für diesen Namen ausstellen?
 *
 * **Das Panel setzt kein CAA und fordert keines** (`docs/72 §2.4`). Kein CAA
 * ist der richtige Zustand: Dann darf jede Stelle ausstellen, und es gibt
 * nichts zu melden. Gelesen wird trotzdem, denn ein Satz, der uns **nicht**
 * nennt, lässt jede Bestellung scheitern — und jeder Fehlversuch zählt bei
 * Let's Encrypt fünf je Konto und Stunde, für **jeden** Kunden dieses Servers.
 *
 * > **Ein Hinweis vorher ist keine Bequemlichkeit, sondern
 * > Schadensbegrenzung.**
 *
 * **Was hier umgesetzt ist und was nicht** (RFC 8659 §4.2):
 *
 * - `issue` entscheidet über gewöhnliche Zertifikate, `issuewild` über
 *   Platzhalter. **Ist `issuewild` vorhanden, gilt für Platzhalter nur das** —
 *   `issue` zählt dann nicht mit. Das ist die Stelle, an der eine naive
 *   Umsetzung falsch liegt, und P4 bestellt Platzhalter.
 * - Ein Wert ohne Namen (`;`) erlaubt niemandem etwas.
 * - Hinter dem Namen dürfen Angaben stehen (`letsencrypt.org;
 *   validationmethods=dns-01`). Verglichen wird nur der Teil davor.
 * - Ein **kritischer** Satz mit einer Marke, die niemand kennt, verbietet die
 *   Ausstellung. Das Bit steht im Flag und ist das oberste.
 * - **Kein Aufstieg zur Elternzone.** Findet sich am Namen kein CAA, klettert
 *   eine Zertifizierungsstelle nach oben; dieses Panel fragt nur die Namen,
 *   die es ohnehin prüft. Für eine Domain und ihre Aliasse reicht das —
 *   für `a.b.c.example.de` mit einem CAA an `c.example.de` nicht. Deshalb
 *   meldet ein leerer Satz hier **nicht** „darf", sondern „nichts gefunden".
 *
 * > **Ein Urteil, das eine Regel nur halb kennt, gehört als halbes
 * > gekennzeichnet und nicht als ganzes ausgegeben.**
 */
final class Authority
{
    /** Es gibt keinen CAA-Satz — hier ist nichts eingeschränkt und nichts gesagt. */
    public const NONE = 'none';

    /** Unsere Stelle ist genannt. */
    public const ALLOWED = 'allowed';

    /** Es gibt Sätze, und unsere Stelle steht nicht darunter. */
    public const REFUSED = 'refused';

    /** Die Marken, die eine Zertifizierungsstelle kennen muss (RFC 8659, RFC 9495). */
    private const KNOWN_TAGS = ['issue', 'issuewild', 'iodef', 'issuemail', 'contactemail', 'contactphone'];

    /** Das oberste Bit im Flag: „wer mich nicht versteht, stellt nicht aus". */
    private const CRITICAL = 128;

    /**
     * Das Urteil über einen Satz von CAA-Einträgen.
     *
     * @param  list<array{flags: int, tag: string, value: string}>  $records
     * @param  string|null  $ca  Unsere Kennung; `null` heisst „unbekannt"
     * @param  bool  $wildcard  Geht es um einen Platzhalter?
     * @return array{state: string, reason: string|null, issuers: list<string>}
     */
    public static function judge(array $records, ?string $ca, bool $wildcard = false): array
    {
        if ($records === []) {
            return ['state' => self::NONE, 'reason' => null, 'issuers' => []];
        }

        /*
         * **Der kritische unbekannte Satz steht vor allem anderen.** Er
         * verbietet die Ausstellung, ganz gleich was in `issue` steht — wer
         * ihn übersieht, meldet „darf" für eine Zone, die jede Bestellung
         * abweist.
         */
        foreach ($records as $record) {
            if (($record['flags'] & self::CRITICAL) === self::CRITICAL
                && ! in_array(strtolower($record['tag']), self::KNOWN_TAGS, true)) {
                return [
                    'state' => self::REFUSED,
                    'reason' => sprintf(
                        'Ein als zwingend gekennzeichneter Eintrag trägt die unbekannte Marke „%s". '
                        .'Eine Zertifizierungsstelle, die sie nicht versteht, darf dann nicht ausstellen.',
                        $record['tag'],
                    ),
                    'issuers' => [],
                ];
            }
        }

        /*
         * **Bei einem Platzhalter zählt `issuewild` allein — wenn es da ist.**
         * Fehlt es, tritt `issue` an seine Stelle. Wer beide zusammenwürfe,
         * hielte eine Zone für erlaubt, die Platzhalter ausdrücklich
         * ausschliesst.
         */
        $wild = self::valuesOf($records, 'issuewild');
        $tag = ($wildcard && $wild !== []) ? 'issuewild' : 'issue';
        $values = $tag === 'issuewild' ? $wild : self::valuesOf($records, 'issue');

        if ($values === []) {
            // Sätze gibt es, aber keinen, der die Ausstellung einschränkt.
            return ['state' => self::NONE, 'reason' => null, 'issuers' => []];
        }

        $issuers = [];

        foreach ($values as $value) {
            $issuer = self::issuer($value);

            if ($issuer !== null && ! in_array($issuer, $issuers, true)) {
                $issuers[] = $issuer;
            }
        }

        if ($ca !== null && in_array(strtolower($ca), $issuers, true)) {
            return ['state' => self::ALLOWED, 'reason' => null, 'issuers' => $issuers];
        }

        return [
            'state' => self::REFUSED,
            'reason' => $issuers === []
                ? 'Ein CAA-Eintrag verbietet jede Ausstellung für diesen Namen.'
                : sprintf(
                    'CAA erlaubt nur %s. Solange %s nicht dabeisteht, scheitert jede Bestellung.',
                    implode(', ', $issuers),
                    $ca ?? 'die verwendete Zertifizierungsstelle',
                ),
            'issuers' => $issuers,
        ];
    }

    /**
     * Die Werte einer Marke.
     *
     * @param  list<array{flags: int, tag: string, value: string}>  $records
     * @return list<string>
     */
    private static function valuesOf(array $records, string $tag): array
    {
        $values = [];

        foreach ($records as $record) {
            if (strtolower($record['tag']) === $tag) {
                $values[] = $record['value'];
            }
        }

        return $values;
    }

    /**
     * Der Name der Stelle aus einem Wert — oder `null`, wenn keiner dasteht.
     *
     * `letsencrypt.org; validationmethods=dns-01` nennt `letsencrypt.org`;
     * `;` nennt niemanden, und das ist eine Aussage und kein Versehen.
     */
    private static function issuer(string $value): ?string
    {
        $name = strtolower(trim(strtok($value, ';') ?: ''));

        return $name === '' ? null : $name;
    }
}
