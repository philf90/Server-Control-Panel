<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\DomainName;

/**
 * Der Schlüssel, unter dem ein Zertifikat abgelegt wird.
 *
 * **Warum ein Platzhalter nicht heissen darf, wie er heisst.** Ein Zertifikat
 * über `*.example.de` müsste nach der bisherigen Regel in einem Verzeichnis
 * dieses Namens liegen. Ein Stern im Dateisystem ist aber ein Muster für jede
 * Shell, für `find`, für `rm` — und dieser Pfad landet in einer nginx-Datei,
 * die als root gelesen wird. Ein Name, der irgendwo unterwegs expandiert,
 * bezeichnet dann etwas anderes als das, was gemeint war.
 *
 * **Deshalb `_wildcard.example.de`.** Der Unterstrich am Anfang einer
 * Beschriftung ist in einem Domainnamen nicht zulässig — {@see DomainName}
 * weist ihn ab —, und damit kann kein echter Name mit diesem Schlüssel
 * kollidieren. Die Umschreibung ist umkehrbar und trifft genau eine Form.
 *
 * **Und sie steht an einer Stelle.** Wo ein Zertifikat liegt, beantwortet der
 * Agent; die Anwendung merkt sich nur, was er ihr gemeldet hat
 * (`certificates.storage_name`). Stünde die Regel auch dort, gäbe es zwei
 * Fassungen davon — und die zweite ist die, die veraltet.
 *
 * **Was hier nicht gelöst ist, und das gehört zu Schritt 3.** Zwei
 * verschiedene Zertifikate mit demselben ersten Namen ergeben denselben
 * Schlüssel: Ein hochgeladenes für `example.de` und ein bestelltes für
 * `example.de` würden einander überschreiben. Solange jede Domain genau ein
 * Zertifikat hat und die Erneuerung es an Ort und Stelle ersetzt, ist das
 * richtig so — sobald man zwischen zweien wählen kann, ist es das nicht mehr.
 */
final class CertificateName
{
    /**
     * Was aus dem Stern wird.
     *
     * Mit Punkt am Ende, damit die Beschriftung vollständig ist: `_wildcard`
     * ist eine eigene Beschriftung und kein Vorsatz an `example`.
     */
    public const WILDCARD = '_wildcard.';

    /**
     * Und was ein hochgeladenes Zertifikat kennzeichnet.
     *
     * **Warum es das braucht.** Der Schlüssel entsteht aus dem ersten Namen —
     * zwei verschiedene Zertifikate mit demselben ersten Namen ergäben also
     * denselben Ablageort. Solange jede Domain genau eines hat und die
     * Erneuerung es an Ort und Stelle ersetzt, ist das richtig; sobald neben
     * dem bestellten ein hochgeladenes liegen kann, überschriebe eines das
     * andere — und welches gerade dort liegt, hinge davon ab, was zuletzt lief.
     *
     * Unterschieden wird deshalb nach der **Quelle** und nicht nach dem Namen:
     * `_uploaded.example.de` neben `example.de`. Zwei je Domain, und mehr
     * braucht es nicht — ein zweites hochgeladenes ersetzt das erste, und das
     * ist gemeint.
     */
    public const UPLOADED = '_uploaded.';

    /**
     * Der Schlüssel zu einem Namen — geprüft wie jeder Domainname.
     *
     * Angenommen wird beides: der Name, wie er im Zertifikat steht
     * (`*.example.de`), und der Schlüssel, wie ihn die Anwendung später wieder
     * nennt (`_wildcard.example.de`). Heraus kommt in beiden Fällen der
     * Schlüssel — die Umformung ist damit mehrfach anwendbar, und das ist
     * Absicht: Sie steht auf dem Weg zur Ablage und auf dem Weg zurück.
     *
     * Der Rest ist die gewohnte Prüfung. `*.example.de` wird zu
     * `_wildcard.` + `example.de`, und `example.de` geht unverändert durch
     * {@see DomainName::normalize()} — mit allem, was dort steht: Länge,
     * zulässige Zeichen, mindestens zwei Bestandteile, Buchstaben in der
     * letzten Beschriftung.
     */
    public static function normalize(mixed $value, string $field = 'certificate'): string
    {
        $name = is_string($value) ? strtolower(trim($value)) : $value;

        if (! is_string($name)) {
            return DomainName::normalize($value, $field);
        }

        // Die Quelle steht aussen, der Platzhalter innen — in einer festen
        // Reihenfolge. Ohne sie wären `_uploaded._wildcard.x` und
        // `_wildcard._uploaded.x` zwei Schreibweisen für denselben Ort.
        $source = '';

        if (str_starts_with($name, self::UPLOADED)) {
            $source = self::UPLOADED;
            $name = substr($name, strlen(self::UPLOADED));
        }

        foreach ([self::WILDCARD, '*.'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                // Der Rest muss ein gewöhnlicher Domainname sein. Damit sind
                // `*.*.example.de` und `_wildcard.*.example.de` ausgeschlossen,
                // ohne dass es dafür eine eigene Zeile braucht: Der zweite
                // Stern fällt bei der Prüfung der Beschriftungen durch.
                return $source.self::WILDCARD.DomainName::normalize(substr($name, strlen($prefix)), $field);
            }
        }

        return $source.DomainName::normalize($name, $field);
    }

    /**
     * Derselbe Schlüssel, aber für ein hochgeladenes Zertifikat.
     *
     * Mehrfach anwendbar wie {@see self::normalize()}: Wer einen Schlüssel
     * hineingibt, der die Kennzeichnung schon trägt, bekommt ihn zurück und
     * nicht `_uploaded._uploaded.`.
     */
    public static function uploaded(mixed $value, string $field = 'certificate'): string
    {
        $key = self::normalize($value, $field);

        return str_starts_with($key, self::UPLOADED) ? $key : self::UPLOADED.$key;
    }
}
