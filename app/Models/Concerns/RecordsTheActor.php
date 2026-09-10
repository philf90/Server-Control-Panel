<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * Die Zeile behält den Namen dessen, der gehandelt hat (`docs/901 §3.2`).
 *
 * ## Warum eine Abschrift und nicht der Fremdschlüssel allein
 *
 * `audit_events.account_id` und `operations.account_id` stehen auf
 * `nullOnDelete()`. Der **Eintrag** bleibt damit stehen, der **Handelnde**
 * nicht: Ein gelöschtes Adminkonto zöge seine ganze Geschichte auf `null`.
 *
 * > **Ein Protokoll, aus dem sich der Handelnde nachträglich entfernen lässt,
 * > ist kein Protokoll — es ist eine Liste von Ereignissen.**
 *
 * Genau das war bis `docs/82 §9` der Grund, Adminkonten zu sperren statt sie zu
 * löschen. Mit der Abschrift daneben ist er es nicht mehr.
 *
 * ## Geschrieben beim Anlegen und nicht beim Löschen
 *
 * Drei Gründe, und der zweite ist der tragende:
 *
 * 1. Ein Sammelname beim Löschen wäre ein `UPDATE` über die ganze Historie — in
 *    einer Anfrage, über womöglich sechsstellig viele Zeilen.
 * 2. **Namen ändern sich.** `AccountController::update()` erlaubt das
 *    Umbenennen. Wer erst beim Löschen schreibt, stempelt den **letzten** Namen
 *    auf Zeilen, die unter einem früheren entstanden sind. Die Abschrift beim
 *    Anlegen hält fest, was damals galt — das ist der Unterschied zwischen
 *    einer Abschrift und einer nachträglichen Behauptung.
 * 3. Sechzehn anlegende Stellen wären sechzehn Gelegenheiten, es zu vergessen
 *    (`docs/94 §6b`), und die vergessene fiele niemandem auf: Eine fehlende
 *    Abschrift sieht aus wie ein Eintrag der Automatik.
 *
 * > **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
 * > ist, gehört an eine — und die muss eine sein, an der niemand vorbeikommt.**
 *
 * ## Und `System` ist kein Sammelname für Gelöschte
 *
 * `account_id = NULL` trägt hier **schon** eine Bedeutung:
 * `App\Console\Commands\Access` schreibt seinen Eintrag ohne Konto, und
 * `Operations::dispatch()` tut dasselbe für jede Automatik. Deshalb entscheidet
 * nicht die Kennung allein, sondern das Paar aus Kennung und Abschrift.
 *
 * > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen —
 * > die beiden Fälle sehen danach gleich aus.**
 *
 * ## Zwei Namen stehen hier ohne `{@see}`, und das ist Absicht
 *
 * `Access` und `LastOperator` kommen in diesem Trait als **Prosa** vor und
 * nicht als Marke: Pint zöge daraus je einen `use`-Eintrag, und damit zeigte
 * ein Modell-Concern auf ein Konsolenkommando und auf die Autorisierung — zwei
 * Abhängigkeiten, die es in Wahrheit nicht gibt. `AccountMutationTest` hält es
 * aus demselben Grund so.
 *
 * @property int|null $account_id
 * @property string|null $account_name
 */
trait RecordsTheActor
{
    /** Was dasteht, wenn niemand angemeldet war — Automatik oder Kommandozeile. */
    public const NOBODY = 'System';

    /**
     * Die Abschrift entsteht mit der Zeile.
     *
     * **Ohne Mandantenklammer nachgeschlagen und ohne `withoutRestriction()`:**
     * `Account` trägt keine (nachgesehen am 9. September 2026, festgehalten im
     * Kopf von `LastOperator::active()`). Wer
     * ihm eine gibt, ändert auch diese Stelle — sonst schriebe sie aus der
     * Sicht des Fragenden ab, und die ist hier genau das Falsche.
     *
     * **Nur wenn die Abschrift noch leer ist.** Ein Aufrufer, der sie
     * ausdrücklich mitgibt — ein Test, der einen bestimmten Fall herstellt —,
     * weiss mehr als diese Abfrage; ihn zu überschreiben hiesse, seine Angabe
     * für weniger zu halten als eine nachgeschlagene.
     */
    public static function bootRecordsTheActor(): void
    {
        static::creating(function (Model $model): void {
            /*
             * **Über `getAttribute()` und nicht über die magische
             * Eigenschaft.** Der Rückruf bekommt ein `Model`, und das kennt
             * weder `account_id` noch `account_name` — dieselbe Bauart wie in
             * {@see BelongsToSubscription}. Mit `$model->account_id` meldet
             * PHPStan vier undefinierte Eigenschaften, und zwar erst in der CI.
             */
            $id = $model->getAttribute('account_id');

            if ($id === null || $model->getAttribute('account_name') !== null) {
                return;
            }

            $name = Account::query()->whereKey($id)->value('name');

            /*
             * `is_string` und nicht `(string)`: Findet die Abfrage nichts — ein
             * Konto, das zwischen zwei Anfragen verschwunden ist —, käme aus der
             * Umwandlung ein leerer Name heraus. Der sieht aus wie eine
             * Abschrift und ist keine.
             */
            $model->setAttribute('account_name', is_string($name) ? $name : null);
        });
    }

    /**
     * Wer gehandelt hat, als ein Satz für die Anzeige.
     *
     * **Der Satz entsteht hier und nicht auf der Seite**, aus demselben Grund
     * wie bei `AuditQuery::details()`: Liste, Ausfuhr und Vorgangsseite gehen
     * durch dieselbe Stelle, und zwei Formulierungen laufen auseinander.
     *
     * Drei Zustände, drei Antworten — und die Spalte gibt es, damit die ersten
     * beiden nicht zusammenfallen:
     *
     * | Kennung | Abschrift | Antwort |
     * |---|---|---|
     * | leer | leer | `System` |
     * | leer | gesetzt | `Anna Berger (gelöscht)` |
     * | gesetzt | gesetzt | `Anna Berger` |
     *
     * **Die Abschrift steht vor der Beziehung**, und das spart nicht nur eine
     * Abfrage je Zeile: Sie trägt den Namen, der **zum Zeitpunkt der Handlung**
     * galt, und der ist die Auskunft, für die man ein Protokoll aufschlägt. Die
     * Beziehung antwortet nur dort, wo die Abschrift fehlt — bei einer Zeile,
     * deren Konto zwischen Anfrage und Schreiben verschwunden ist.
     */
    public function actor(): string
    {
        $name = $this->account_name ?? $this->account?->name;

        if ($name === null) {
            return self::NOBODY;
        }

        return $this->account_id === null ? $name.' (gelöscht)' : $name;
    }
}
