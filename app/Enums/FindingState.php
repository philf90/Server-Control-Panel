<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie es um einen geprüften Gegenstand steht — das Urteil eines Befundes.
 *
 * ## Vier Zustände und nicht drei
 *
 * Der vierte ist der wichtigste, und er ist keine Abstufung der anderen:
 * {@see self::Unknown} heisst **„die Messung hat nichts ergeben"** und nicht
 * „es ist nichts". Das Vorbild steht im eigenen Repo — {@see DnsRecordState}
 * führt `Unreachable` ausdrücklich als „kein Zustand der Zone, sondern einer
 * der Messung", und ohne ihn meldete die Anzeige „der Eintrag fehlt", während
 * in Wahrheit der Nameserver schwieg.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.**
 *
 * Für A10 heisst das wörtlich: Antwortet der Agent nicht, steht **jede**
 * Prüfung auf `Unknown` und keine auf `Ok`. Ein Diagnoselauf, der bei totem
 * Agenten Entwarnung gibt, ist schlimmer als keiner — das ist derselbe Fehler
 * wie das `catch (Throwable) { return []; }` aus `docs/44`, das aus „nicht
 * erreichbar" ein „der Betreiber bietet es nicht an" gemacht hat.
 *
 * ## `Warn` gegen `Fail` trennt nicht nach Gefühl
 *
 * Gefragt wird: **ist gerade etwas kaputt, oder wird es das?** Ein Zertifikat
 * mit zwölf Tagen Restlaufzeit ist `Warn`, ein abgelaufenes `Fail`. Ein Timer
 * ohne nächsten Termin ist `Fail` — er ist abgeschaltet und sieht aus wie
 * eingeschaltet.
 *
 * ## Warum dieser Zustand nirgends gespeichert wird
 *
 * Er ist **vollständig durch `check` und `reason` bestimmt**
 * ({@see FindingCheck::state()}). Eine Spalte daneben wäre die zweite Fassung
 * derselben Regel, und die zweite ist die, die veraltet — derselbe Grund, aus
 * dem `Db\Session::CLIENT` seine Argumentliste nur einmal führt.
 *
 * > **Wenn zwei Fälle denselben Grund und verschiedene Schwere haben, sind es
 * > zwei Gründe.** Die Antwort auf einen solchen Fall ist ein feinerer
 * > `reason` und keine gespeicherte Schwere.
 */
enum FindingState: string
{
    /** Geprüft, und es ist in Ordnung. Erzeugt keine Zeile. */
    case Ok = 'ok';

    /** Geprüft, und es wird kaputtgehen, wenn niemand hinsieht. */
    case Warn = 'warn';

    /** Geprüft, und es ist kaputt. */
    case Fail = 'fail';

    /** Nicht geprüft — über den Gegenstand ist damit nichts gesagt. */
    case Unknown = 'unknown';

    /**
     * Die vier Zustände, wie sie auf der Seite und in der Konsole stehen.
     *
     * **`Warn` hiess bis zum 3. September 2026 „Sieht jemand hin".** Gemeldet
     * hat es der Betreiber beim Lesen einer Zusammenfassung auf dem Server, und
     * der Einwand trifft die Form und nicht den Geschmack: Die drei anderen
     * benennen einen **Zustand des Gegenstands**, dieser eine eine Handlung —
     * und dazu eine, die jemand vielleicht tut.
     *
     * > **Ein Zustand, der als Handlung benannt ist, steht in einer Spalte, in
     * > der sonst Zustände stehen — und liest sich als Aufforderung, wo eine
     * > Auskunft gemeint ist.**
     *
     * **„Auffällig" und nicht „Warnung":** Eine Warnung ist eine Art Meldung
     * und kein Zustand; in einer Spalte neben „In Ordnung" und „Kaputt" wechselt
     * sie die Ebene. Und nicht „Läuft ab" oder „Wird kaputtgehen": Von den fünf
     * Gründen, die auf `Warn` fallen, sind nur zwei eine Vorhersage. Die
     * anderen drei — eine fehlende Regel im verwalteten Bereich, eine
     * unbekannte Unit, eine verwaiste Zeile — gehen nie kaputt; sie weichen ab.
     *
     * > **Ein Wort für eine Schwere muss jeden Grund tragen, der auf sie fällt,
     * > und nicht den, an den man beim Benennen gerade denkt.**
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'In Ordnung',
            self::Warn => 'Auffällig',
            self::Fail => 'Kaputt',
            self::Unknown => 'Nicht gemessen',
        };
    }

    /**
     * Was der Betrachter daraufhin tun kann — oder dass er nichts tun kann.
     *
     * **Der letzte Satz ist der wichtigste**, und er ist derselbe wie bei
     * {@see DnsRecordState::hint()}: Ein Hinweis, der bei „nicht gemessen" zum
     * Handeln auffordert, schickt jemanden dorthin, wo nichts zu ändern ist.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Ok => 'Der Gegenstand ist geprüft und in Ordnung.',
            self::Warn => 'Nichts davon ist kaputt — es weicht vom Sollzustand ab. Wer jetzt hinsieht, hat Zeit.',
            self::Fail => 'Hier ist gerade etwas kaputt.',
            self::Unknown => 'Diese Prüfung ist nicht durchgelaufen. Über den Gegenstand ist damit nichts gesagt — weder im Guten noch im Schlechten.',
        };
    }

    /**
     * Der Rang der Zustandsmarke.
     *
     * **Hier und nicht in der Vue-Datei**, denn dort wäre es eine `v-if`-Kette
     * neben der Aufzählung — also eine zweite Fassung derselben Regel, und die
     * zweite ist die, die beim nächsten Zustand vergessen wird.
     *
     * **`neutral` für „nicht gemessen" ist die eigentliche Entscheidung.** Die
     * Marke sagt laut `Badge` „kein Zustand, eine Abwesenheit" — und genau das
     * ist gemeint. Ein rotes Signal behauptete, es sei etwas kaputt, und
     * schickte den Betreiber auf die Suche nach einem Schaden, den niemand
     * gemessen hat.
     *
     * @return 'ok'|'warn'|'critical'|'neutral'
     */
    public function badge(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Warn => 'warn',
            self::Fail => 'critical',
            self::Unknown => 'neutral',
        };
    }

    /**
     * Wie dringend, für die Sortierung der Liste.
     *
     * **`Unknown` steht über `Warn` und unter `Fail`.** Eine Prüfung, die nicht
     * gelaufen ist, kann alles verbergen — auch ein `Fail`; sie gehört deshalb
     * weit nach oben. Sie steht trotzdem unter dem, was gemessen kaputt ist:
     * Ein belegter Schaden geht einer Vermutung vor.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Fail => 3,
            self::Unknown => 2,
            self::Warn => 1,
            self::Ok => 0,
        };
    }

    /** Erzeugt dieser Zustand eine Zeile auf der Seite? */
    public function reportable(): bool
    {
        return $this !== self::Ok;
    }
}
