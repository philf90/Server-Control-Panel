<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wovon eine Ankündigung handelt — Info, Warnung oder Störung (A14,
 * `docs/103 §3`).
 *
 * ## Drei Kategorien, und die Wörter sind der Punkt
 *
 * Sie teilen sich die **Farbmarken** mit dem Nachtlauf der Bestandsdiagnose,
 * aber ausdrücklich **nicht die Wörter**: {@see FindingState} sagt seit dem
 * 3. September 2026 `In Ordnung · Auffällig · Kaputt · Nicht gemessen`.
 *
 * > **Zwei Skalen nebeneinander, die verschiedene Wörter für dasselbe benutzen,
 * > sind eine Fehlerquelle.** Die eine sagt, wie der Server steht; die andere,
 * > was der Betreiber mitteilt. Dieselbe Farbe, andere Frage.
 *
 * ## Der Rang steht als Wort im Streifen und nicht nur als Farbe
 *
 * Gemessen (`docs/81 §2.3q` M9): Zwischen Warnung und Störung liegen im hellen
 * Thema **ΔE 3,8** — unterscheidbar, aber das schwächste der drei Paare und
 * ausgerechnet das, auf das es ankommt. Für jemanden mit Rot-Grün-Schwäche
 * trägt die Farbe dort gar nichts (WCAG 1.4.1). Deshalb gibt es
 * {@see self::label()}, und deshalb steht das Ergebnis im Streifen.
 */
enum AnnouncementCategory: string
{
    /** Etwas ist zu wissen — keine Störung, kein bevorstehender Ausfall. */
    case Info = 'info';

    /** Etwas steht bevor, das jemanden betrifft — ein Wartungsfenster etwa. */
    case Warning = 'warning';

    /** Etwas ist gerade kaputt. */
    case Incident = 'incident';

    /** Das Wort, das im Streifen steht. */
    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warnung',
            self::Incident => 'Störung',
        };
    }

    /**
     * Die Farbmarke aus `app.css`.
     *
     * **`ok` für `Info` und nicht `neutral`, und das ist gemessen**
     * (`docs/81 §2.3q` M10). Der Einwand liegt nahe: `.notice.neutral` nennt
     * sich selbst „eine Meldung ohne Rang — sie sagt, was gleich passiert, und
     * nicht, dass etwas gut oder schlecht steht", und das ist wörtlich eine
     * Info. Grün behauptet daneben, etwas sei *gut*.
     *
     * Gemessen trägt der Einwand trotzdem nicht: Die Fläche von `neutral`
     * (`--surface`, `#fafafb`) steht im hellen Thema bei **ΔE 1,8** gegen die
     * Seite (`#ffffff`) — **unter** der Wahrnehmungsschwelle von 2,3. Ein
     * Info-Streifen wäre dort von der Seite nicht zu unterscheiden und hinge an
     * einer grauen Haarlinie. `ok` steht bei ΔE 7,0.
     *
     * > **Eine Marke, die als „ohne Rang" gedacht ist, ist im hellen Thema von
     * > der Seite nicht zu unterscheiden — sie taugt für eine Meldung im
     * > Inhalt und nicht für einen Streifen, der auffallen soll.**
     *
     * `.notice.neutral` steht im Inhalt, wo Seitenkopf und Tabelle die Kante
     * ohnehin zeichnen. Ein Band ganz oben hat diese Nachbarn nicht.
     *
     * @return 'ok'|'warn'|'critical'
     */
    public function badge(): string
    {
        return match ($this) {
            self::Info => 'ok',
            self::Warning => 'warn',
            self::Incident => 'critical',
        };
    }

    /**
     * Wie dringend, für die Reihenfolge der Streifen.
     *
     * **Hier und nicht in der Vue-Datei**, aus demselben Grund wie bei
     * {@see FindingState::rank()}: Dort wäre es eine zweite Fassung derselben
     * Regel neben der Aufzählung, und die zweite ist die, die beim nächsten
     * Zustand vergessen wird.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Incident => 2,
            self::Warning => 1,
            self::Info => 0,
        };
    }

    /**
     * Erscheint diese Kategorie auch auf der Anmeldeseite?
     *
     * **Nur `Incident`, und das ist eine Entscheidung des Betreibers**
     * (`docs/103 §2`): genau der Fall, in dem die Auskunft zählt — wenn das
     * Panel klemmt, steht sie auf der Seite, die man dann sieht.
     *
     * Der Preis steht im Plan und nicht bloss im Kopf: **Was auf der
     * Anmeldeseite steht, steht vor jedem, der die Adresse kennt.** Die
     * Beschränkung auf diese eine Kategorie begrenzt den Kreis, hebt ihn nicht
     * auf.
     */
    public function onLoginPage(): bool
    {
        return $this === self::Incident;
    }
}
