<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Backups\BackupLifecycle;

/**
 * Was aus einer Sicherung geworden ist.
 *
 * **Drei Zustände und nicht zwei** — dieselbe Aufteilung wie bei
 * {@see DumpStatus}, und aus demselben Grund: „läuft noch" und „ist
 * fehlgeschlagen" sehen in einer Liste sonst gleich aus, und das ist genau der
 * Unterschied, auf den es ankommt.
 *
 * Der Zustand folgt dem **Agenten** und nicht dem Klick (`docs/20 §4`, die
 * zweite Grenze): `Ready` setzt `BackupLifecycle::afterSuccess()`, nachdem der
 * Agent geantwortet hat.
 *
 * ## Und seit dem 18. September ein vierter
 *
 * **Das Entfernen hatte keinen Zustand**, und damit hatte es keine Anzeige.
 * Befund 4 des Nachlaufs zu P8 (`docs/121 §9`), gemeldet vom Betreiber beim
 * Benutzen: „/backups aktualisiert sich nicht automatisch wenn das Backup
 * entfernt wurde." Die Zeile blieb stehen, mitsamt ihrem Knopf, bis jemand von
 * Hand neu lud.
 *
 * Das **Anlegen** war verfolgt: `Pending` steht in der Zeile, die Seite fragt
 * alle drei Sekunden nach, solange eine Zeile so dasteht. Für das Entfernen gab
 * es nichts, woran ein Takt hätte hängen können — `Backups::remove()` reihte
 * den Vorgang ein und liess die Zeile auf `Ready`.
 *
 * > **Ein Vorgang ohne Zustand in seiner Zeile ist von einem, den niemand
 * > ausgelöst hat, nicht zu unterscheiden.**
 *
 * `Removing` ist deshalb kein Merker der Seite, sondern der Zustand der Sache:
 * Auch eine Entfernung aus dem nächtlichen Lauf der Aufbewahrung oder aus einem
 * zweiten Reiter steht damit in der Zeile — genau die Begründung, aus der
 * `Backups.vue` seinen Takt an die Zeilen hängt und nicht an einen eigenen
 * Merker.
 */
enum BackupStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Der Agent ist gefragt, die Datei zu löschen — und hat noch nicht
     * geantwortet.
     *
     * **Kein Endzustand.** Gelingt es, verschwindet die Zeile
     * ({@see BackupLifecycle::afterSuccess()}); gelingt
     * es nicht, geht sie auf `Ready` zurück, denn die Datei liegt dann noch da
     * und die Zeile beschreibt sie weiterhin richtig.
     */
    case Removing = 'removing';

    /**
     * Lässt sich diese Sicherung benutzen?
     *
     * **Nur `Ready`.** Eine, die noch läuft, ist eine halb geschriebene Datei;
     * eine gescheiterte ist gar keine oder eine, von der niemand weiss, wie
     * weit sie kam. Beide herunterzugeben hiesse, etwas auszuliefern, das wie
     * eine Sicherung aussieht.
     *
     * Und eine, die gerade entfernt wird, ist da und gleich nicht mehr: Ein
     * Zurückspielen daraus liefe gegen eine Datei, die unter ihm verschwindet.
     */
    public function usable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Ändert sich diese Zeile gerade von selbst?
     *
     * **Die eine Stelle, an der beide Seiten fragen.** Zwei Listen zeigen
     * Sicherungen — die eines Abonnements und die ohne —, und beide brauchen
     * denselben Takt. Zwei Bedingungen wären zwei Fassungen derselben Regel,
     * und die zweite ist die, die veraltet.
     */
    public function running(): bool
    {
        return $this === self::Pending || $this === self::Removing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'wird erstellt',
            self::Ready => 'vorhanden',
            self::Failed => 'fehlgeschlagen',
            self::Removing => 'wird entfernt',
        };
    }
}
