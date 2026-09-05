<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Account;

/**
 * Wer eine Ankündigung sieht (A14, `docs/103 §2`, Entscheidung 3).
 *
 * ## Je Ankündigung wählbar, mehrfach ankreuzbar
 *
 * Eine Wartungsmeldung geht an alle, ein Hinweis zur Verwaltung nur an Admins.
 * Der Betreiber hat das am 5. September 2026 so entschieden.
 *
 * ## Ein Konto gehört genau **einem** Publikum an
 *
 * Das ist die tragende Eigenschaft und keine Vereinfachung: Damit ist der
 * Filter eine Frage („enthält die Liste das Publikum dieses Kontos?") und keine
 * Schnittmenge über eine Menge, die jemand später anders bildet.
 *
 * {@see self::of()} ist die **einzige** Stelle, die ein Konto einem Publikum
 * zuordnet. Läge die Zuordnung an den Aufrufstellen, wäre sie dort so oft
 * geschrieben, wie es Aufrufstellen gibt — und die vergessene fiele niemandem
 * auf, weil eine falsche Zuordnung nur *zu wenig* zeigt.
 *
 * > **Was überall dasselbe ist, gehört an eine Stelle — und die muss eine sein,
 * > an der niemand vorbeikommt.**
 *
 * ## Die beiden Achsen aus A9
 *
 * `docs/20 §6.1` teilt die Admin-Ebene in Betreiber und Administrator, und
 * {@see Account::isOperator()} fragt dafür **beide** Achsen: Typ *und* Rolle.
 * Diese Aufzählung übernimmt das, statt eine zweite Fassung derselben Regel zu
 * bauen.
 */
enum AnnouncementAudience: string
{
    /** Wem der Server gehört. */
    case Operator = 'operator';

    /** Wer die Kunden verwaltet, aber nicht den Server dreht. */
    case Administrator = 'administrator';

    /** Wer Kunde ist — einschliesslich der Zusatzbenutzer eines Kunden. */
    case Customer = 'customer';

    /** Das Wort im Formular. */
    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Betreiber',
            self::Administrator => 'Administrator',
            self::Customer => 'Kunde',
        };
    }

    /**
     * Welchem Publikum dieses Konto angehört.
     *
     * **Ein Zusatzbenutzer ist ein Kunde.** Er arbeitet in den Abonnements
     * seines Kunden und sieht dieselben Seiten; eine eigene Stufe wäre ein
     * viertes Publikum, das niemand bestellt hat und das jede Ankündigung
     * einzeln beantworten müsste.
     *
     * **Ein Adminkonto ohne Rolle fällt auf `Administrator`.** Das ist die
     * sichere Richtung, dieselbe wie in {@see Account::fulfils()}: Wer die
     * Migration aus A9 nicht gefahren hat, sieht die Ankündigungen für
     * Administratoren — und nicht die, die für den Betreiber gedacht sind.
     */
    public static function of(Account $account): self
    {
        if (! $account->isAdmin()) {
            return self::Customer;
        }

        return $account->isOperator() ? self::Operator : self::Administrator;
    }

    /** Alle drei, für das Formular und für die Prüfung. */
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
