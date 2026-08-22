<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme\Dns;

/**
 * Was von aussen zu sehen ist — ohne Zwischenspeicher.
 *
 * **Drei Fragen:** Wer führt diese Zone, was liefert dieser eine Server für
 * diesen Namen aus, und welche Ausstellungsregeln stehen dort. Die Umsetzung
 * ist {@see Resolver}; die Schnittstelle steht hier, damit ein Durchgang die
 * Antworten vorgeben kann, ohne einen Nameserver zu brauchen. Ohne sie hinge
 * jeder Test an einer Zone, die es wirklich gibt — und wäre damit langsam,
 * unzuverlässig und von aussen beeinflussbar.
 *
 * **Seit P7 gibt es `null` als Antwort, und das ist der Punkt.** Bis dahin gab
 * `txt()` eine leere Liste zurück, wenn der Server nicht erreichbar war, wenn
 * die Antwort nicht passte und wenn es den Satz nicht gab. Für ACME war das
 * richtig — dort heisst alles drei „noch nicht, frag gleich nochmal". Der
 * Abgleich aus `docs/72 §2.3` muss „es gibt keinen Eintrag" von „ich weiss es
 * nicht" unterscheiden, sonst meldet er dem Kunden einen fehlenden Eintrag,
 * wenn in Wahrheit sein Nameserver schweigt.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.**
 */
interface Lookup
{
    /**
     * Die Adressen der autoritativen Nameserver für diesen Namen.
     *
     * @return list<string>
     */
    public function nameservers(string $name): array;

    /**
     * Die Werte, die dieser Server für diesen Namen und Typ ausliefert.
     *
     * @param  int  $type  {@see Packet::TYPE_A}, `TYPE_AAAA` oder `TYPE_TXT`
     * @return list<string>|null `null` heisst: keine brauchbare Antwort
     */
    public function records(string $server, string $name, int $type): ?array;

    /**
     * Die CAA-Sätze, die dieser Server für diesen Namen ausliefert.
     *
     * **Eine eigene Frage, weil ein CAA-Satz kein Wert ist, sondern drei** —
     * Flag, Marke und Wert. Sie in {@see records} zu quetschen hiesse, eine
     * Aufzählung zu haben, deren Form vom Typ abhängt; die liest man einmal
     * falsch aus.
     *
     * @return list<array{flags: int, tag: string, value: string}>|null
     */
    public function authorities(string $server, string $name): ?array;
}
