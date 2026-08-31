<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * Von welcher Seite aus der Vorgang ausgelöst wurde — als Pfad.
 *
 * ## Warum es diese Klasse gibt und nicht bloss eine Methode
 *
 * **Weil sie von genau einer Stelle gerufen wird, und die ist das Modell.**
 * Vorgänge entstehen an **sechzehn** Stellen; fünfzehn davon legen ihre Zeile
 * unmittelbar mit `Operation::query()->create()` an. Der erste Wurf setzte die
 * Herkunft in `Operations::dispatch()` — also an einer von sechzehn.
 *
 * Gemessen auf `cloudsrv24` am 31. August 2026 (`docs/94 §6`): Vorgang 727
 * (`system.packages.upgrade`, über `Operations::dispatch()`) trug
 * `← /updates`; Vorgang 729 (`db.dump.create`, über `Dumps::dispatch()`) trug
 * nichts. Beide waren von einer Seite aus ausgelöst worden.
 *
 * > **Ein Wächter, der prüft, dass *eine* Stelle es tut, hat nicht geprüft,
 * > dass es *nur eine* Stelle gibt.**
 *
 * ## Warum am Modell und nicht am Aufrufer
 *
 * Der Unterschied zu `subject_type` ist der Punkt:
 *
 * - `subject_type` weiss **jede Stelle anders** — nur der Aufrufer kennt den
 *   Gegenstand seines Vorgangs. Es gehört an die Stelle.
 * - `origin` ist **überall dasselbe** — die Sitzung weiss es, unabhängig davon,
 *   wer gerade anlegt.
 *
 * > **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
 * > ist, gehört an eine — und die muss eine sein, an der niemand vorbeikommt.**
 *
 * Das Modell ist diese Stelle, und es gibt dafür einen Präzedenzfall im selben
 * `booted()`: `subscription_name` wird dort abgeschrieben, mit derselben
 * Begründung. Eine siebzehnte anlegende Stelle bekommt die Herkunft, ohne dass
 * jemand daran denken muss.
 *
 * ## Warum ein Pfad und keine volle Adresse
 *
 * Das Panel ist unter mehreren Namen erreichbar, und eine gespeicherte Adresse
 * mit Rechnernamen wäre unter dem zweiten falsch.
 *
 * ## Was diese Klasse nicht kann
 *
 * **Eine Navigation sehen, die der Server nicht sieht.** `previousUrl()` steht
 * auf der letzten Seite, die das Panel gerendert hat. Geht jemand mit dem
 * Zurück-Knopf des Browsers, stellt Inertia aus dem History-Zustand her, es
 * kommt keine Anfrage, und die Herkunft veraltet (`docs/94 §5`).
 *
 * > **Eine Herkunft, die der Server führt, veraltet bei jeder Navigation, die
 * > der Server nicht sieht.**
 *
 * Das ist benannt und nicht behoben: Die Behebung hiesse, die Adresse der
 * absetzenden Seite vom Browser mitzuschicken, und das ist eine Entscheidung
 * über die Übertragung und nicht über diese Klasse.
 */
final class Origin
{
    /**
     * Die längste Herkunft, die gespeichert wird.
     *
     * Sie entspricht der Spaltenbreite. Was länger ist, wird **verworfen und
     * nicht abgeschnitten**: Ein halber Pfad führt irgendwohin, und irgendwohin
     * ist schlechter als nirgendwohin.
     */
    public const MAX = 255;

    /**
     * Der Pfad, von dem die laufende Anfrage kam — oder `null`.
     *
     * `null` heisst „von keiner Seite" und ist die Wahrheit für die Konsole,
     * die Warteschlange und jeden Lauf der Automatik. Ein Wert, den man dort
     * erfände, sähe aus wie eine Auskunft.
     */
    public static function current(): ?string
    {
        $request = request();

        // Ohne Sitzung gibt es keine Herkunft, und `session()` würfe hier.
        if (! $request->hasSession()) {
            return null;
        }

        $previous = $request->session()->previousUrl();

        if (! is_string($previous) || $previous === '') {
            return null;
        }

        $pfad = parse_url($previous, PHP_URL_PATH);

        if (! is_string($pfad) || ! str_starts_with($pfad, '/')) {
            return null;
        }

        $frage = parse_url($previous, PHP_URL_QUERY);
        $ganz = is_string($frage) && $frage !== '' ? $pfad.'?'.$frage : $pfad;

        return mb_strlen($ganz) > self::MAX ? null : $ganz;
    }
}
