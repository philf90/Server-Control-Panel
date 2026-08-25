<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die zwei Rollen innerhalb der Admin-Ebene.
 *
 * ## Warum das eine zweite Achse ist und kein vierter `AccountType`
 *
 * `AccountType` beantwortet **„wen sieht dieses Konto"** — die Mandantenfrage.
 * Für Betreiber und Administrator lautet die Antwort **gleich**: den ganzen
 * Server. Verschieden ist nur, was sie tun dürfen.
 *
 * Wer die beiden in ein Feld legt, macht `isAdmin()` an 52 Stellen zweideutig.
 * Ein vierter Fall `Superadmin` wäre augenblicklich `isAdmin() === false` und
 * `belongsToCustomer() === true`; die Mandantenklammer setzte ihn auf
 * `whereRaw('0 = 1')`, und der neue Betreiber sähe eine **leere Kundenliste**.
 *
 * > **Ein Fehler, der zur sicheren Seite fällt, fällt trotzdem — und er fällt
 * > leise.**
 *
 * {@see AccountType} bleibt deshalb für beide Rollen `Admin`, und
 * `AccountTypeAxisTest` steht als Stolperdraht davor.
 *
 * ## Kein Rechte-Baukasten
 *
 * Zwei feste Rollen, keine Matrix. `docs/82 §4` trägt die Begründung; die
 * Kurzform: Die Trennlinie ist eine **Sicherheitszusage** und keine Vorliebe —
 * sie lautet *verleiht root auf Dauer · nimmt alle Kunden mit · zeigt ein
 * Geheimnis*. Ein Kästchen, das einem Administrator „DNS-Zugangsdaten sehen"
 * gibt, macht das nicht sicher.
 *
 * Und ein Baukasten müsste in **jeder** Kombination stimmen. Genau die aus
 * `docs/82 §3` Falle 1 könnte ein Betreiber darin selbst herstellen — die
 * DNS-Seite verbergen und die Zertifikatsbestellung erlauben, die dieselben
 * Zugangsdaten benutzt —, und das Panel könnte nicht warnen, weil es nicht
 * weiss, welche Fähigkeit welche impliziert.
 *
 * **Wenn zwei Rollen zu grob werden**, ist die ehrliche Erweiterung eine dritte
 * **benannte** Rolle mit fester Zuordnung. Dann bleibt jede Kombination eine,
 * über die jemand nachgedacht hat.
 *
 * ## Warum die Werte hier stehen und nicht in `AdminAbility`
 *
 * Sie standen dort, seit es die Fähigkeiten gibt — als zwei Konstanten, weil es
 * den Enum noch nicht gab. Zwei Stellen für denselben Namen sind der Fehler,
 * den dieses Repo am häufigsten macht; `AdminAbility` zeigt seitdem hierher.
 *
 * **Ohne `{@see}` mit vollem Namen, und das ist Absicht:** Pints
 * `fully_qualified_strict_types` macht daraus einen Import, und der liest sich
 * dann wie eine Abhängigkeit des Enums auf die Autorisierung — die es nicht
 * gibt und in dieser Richtung auch nicht geben soll.
 */
enum AdminRole: string
{
    /** Dem `root` dieses Servers nahe. Darf alles. */
    case Operator = 'operator';

    /**
     * Verwaltet Kunden, Abonnements, Domains, Datenbanken, Dateien, Cron und
     * das Protokoll. **Kritisches weder sehen noch bedienen.**
     */
    case Administrator = 'administrator';

    /**
     * Wie die Rolle in der Oberfläche heisst.
     *
     * Am Enum und nicht in der Vorlage: Sie steht in der Kontenliste, im
     * Formular und in der Meldung des Aussperrschutzes, und drei Fassungen
     * desselben Wortes laufen auseinander.
     */
    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Betreiber',
            self::Administrator => 'Administrator',
        };
    }

    /**
     * Darf diese Rolle, was jene Rolle verlangt?
     *
     * **Eine Rangfolge und keine Menge.** Der Betreiber darf alles, was der
     * Administrator darf, und mehr — das ist die ganze Ordnung, und sie steht
     * hier statt in jedem Gate.
     *
     * Geschrieben als Fallunterscheidung über `$this` und nicht als Zahl:
     * Ein Rang wie `level >= 2` verführt zum dritten Wert, und der wäre eine
     * dritte Rolle ohne Entscheidung darüber, was sie darf.
     */
    public function covers(self $required): bool
    {
        return match ($this) {
            self::Operator => true,
            self::Administrator => $required === self::Administrator,
        };
    }
}
