<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Models\FindingResolution;
use App\Support\Notify\Notices;
use Illuminate\Support\Carbon;

/**
 * Die einzige Stelle, die Befunde schreibt (A10, `docs/98 §2`).
 *
 * ## Warum es sie gibt
 *
 * Weil „derselbe Schaden über zwei Nächte erzeugt eine Zeile und nicht zwei"
 * (Punkt 8 des Abnahmekriteriums) keine Eigenschaft der Datenbank sein kann und
 * keine des Aufrufers sein darf. Der `unique`-Index hält, dass es die Zeile nur
 * einmal gibt; **dass `first_seen_at` dabei stehenbleibt**, hält diese Klasse.
 *
 * > **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
 * > ist, gehört an eine.**
 *
 * ## `replace()` und nicht `record()` je Befund
 *
 * Ein Lauf meldet **je Prüfung** alles, was er gefunden hat — auch nichts. Nur
 * so lässt sich ein Befund wieder loswerden: Was der Lauf nicht mehr nennt, ist
 * behoben und wird gelöscht. Punkt 2 des Abnahmekriteriums verlangt genau das.
 *
 * **Und deshalb ruft eine Prüfung, die nicht gelaufen ist, hier gar nichts.**
 * Ein `replace()` mit einer leeren Liste heisst „geprüft, nichts gefunden" und
 * löscht die alten Befunde. Wer bei einem Fehlschlag dasselbe täte, machte aus
 * „nicht gemessen" ein „alles in Ordnung" — der Fehler aus `docs/44`, und der
 * Grund, aus dem es {@see FindingCheck::UNREACHABLE} gibt.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.** Hier bedeutet sie genau eines, und die andere Bedeutung hat einen
 * > eigenen Grund bekommen.
 */
final class FindingLog
{
    /**
     * Was eine Prüfung gefunden hat — ganz, und an die Stelle der alten Zeilen.
     *
     * Der Zeitpunkt kommt von aussen, damit alle Prüfungen eines Laufs
     * denselben tragen: Sonst stünden auf einer Seite vierzehn Zeitstempel, die
     * sich um Millisekunden unterscheiden, und „zuletzt gemessen" wäre eine
     * Frage mit vierzehn Antworten.
     *
     * @param  list<array{subject: string, reason: string, detail?: string|null}>  $findings
     * @return int wieviele Zeilen danach zu dieser Prüfung stehen
     */
    public function replace(FindingCheck $check, array $findings, Carbon $measuredAt): int
    {
        $seen = [];

        foreach ($findings as $finding) {
            // Wirft, wenn die Prüfung den Grund nicht kennt. Das ist ein
            // Programmierfehler und keine Eingabe — der Grund kommt aus dem
            // Code, der den Befund anlegt, und nie von aussen.
            $check->state($finding['reason']);

            $this->record($check, $finding['subject'], $finding['reason'], $finding['detail'] ?? null, $measuredAt);
            $seen[] = $finding['subject'].'|'.$finding['reason'];
        }

        $this->forgetMissing($check, $seen, $measuredAt);

        return count($seen);
    }

    /**
     * Eine Prüfung, die nicht gelaufen ist, meldet einen Befund und löscht keinen.
     *
     * **Der Gegenstand bleibt der, um den es ging.** Ein `unreachable` ohne Ort
     * wäre eine Zeile, mit der niemand etwas anfangen kann; steht sie an der
     * Domain, sieht der Betreiber, worüber gerade nichts bekannt ist.
     *
     * Die alten Befunde derselben Prüfung bleiben stehen: Sie sind nicht
     * widerlegt, sondern ungeprüft.
     *
     * @param  list<string>  $subjects
     */
    public function unreachable(FindingCheck $check, array $subjects, Carbon $measuredAt, ?string $detail = null): void
    {
        foreach ($subjects as $subject) {
            $this->record($check, $subject, FindingCheck::UNREACHABLE, $detail, $measuredAt);
        }
    }

    /**
     * Eine Zeile anlegen oder auffrischen — und `first_seen_at` dabei in Ruhe lassen.
     *
     * **Hier stand ein `updateOrCreate` mit `first_seen_at` in der zweiten
     * Liste, und ein Kommentar daneben, der behauptete, der Wert wirke „nur
     * beim Anlegen".** Das ist falsch: `updateOrCreate($schlüssel, $werte)`
     * macht `firstOrNew($schlüssel)->fill($werte)->save()` und nimmt `$werte`
     * damit für **beide** Wege. Jeder Lauf hätte „steht seit" auf heute
     * gezogen, jede Nacht aufs Neue, und Punkt 8 des Abnahmekriteriums wäre
     * genau an der Stelle gescheitert, für die es ihn gibt.
     *
     * > **Ein Kommentar, der eine Zusage des Frameworks behauptet, ist keine
     * > Prüfung — er ist eine Zeile, die aussieht wie eine.**
     *
     * Deshalb steht das Anlegen jetzt ausgeschrieben da, und der Wächter misst
     * die **Wirkung** über zwei Läufe und nicht diesen Quelltext.
     *
     * `detail` ist ausdrücklich **nicht** Teil der Kennung. Wäre er es, ergäbe
     * derselbe Schaden über zwei Nächte zwei Zeilen — der Wortlaut der Werkzeuge
     * trägt Datum und Prozessnummer (`docs/81 §2.3o` M9).
     */
    private function record(FindingCheck $check, string $subject, string $reason, ?string $detail, Carbon $measuredAt): void
    {
        $finding = Finding::query()->firstOrNew([
            'check' => $check,
            'subject' => $subject,
            'reason' => $reason,
        ]);

        if (! $finding->exists) {
            $finding->first_seen_at = $measuredAt;
        }

        $finding->detail = Finding::trimDetail($detail);
        $finding->measured_at = $measuredAt;
        $finding->save();
    }

    /**
     * Was diese Prüfung nicht mehr nennt, ist behoben.
     *
     * ## Wem es gemeldet wurde, wird **vor** dem Löschen gelesen
     *
     * Die Zeilen in `finding_notifications` hängen am Befund
     * (`cascadeOnDelete`). Nach dem `delete()` gibt es sie nicht mehr — und mit
     * ihnen die einzige Auskunft darüber, wer von diesem Schaden überhaupt
     * erfahren hat. Deshalb steht die Abschrift hier und nicht in einem
     * Aufräumer, der später nachsieht: Später gibt es nichts mehr nachzusehen.
     *
     * > **Ein Zustand, der mit seinem Gegenstand verschwindet, wird vor dem
     * > Verschwinden gelesen oder gar nicht.**
     *
     * ## Und was nie gemeldet wurde, wird nicht abgemeldet
     *
     * Ein Befund, der innerhalb der Haltezeit wieder verschwindet, hat
     * niemanden erreicht; {@see Notices::HOLD_HOURS} ist
     * genau dafür da. Eine Entwarnung dafür ginge an einen Empfänger, der von
     * dem Vorfall nie gehört hat.
     *
     * > **Eine Entwarnung ohne vorangegangene Warnung ist eine Meldung über
     * > nichts.**
     *
     * ## Ob ein Kanal überhaupt entwarnt, entscheidet diese Stelle nicht
     *
     * Hier steht, **wem** gemeldet wurde. Was daraus wird, ist Sache von
     * {@see Notices}: Ein Kanal, der keine Entwarnung
     * kennt, verbraucht seine Zeilen dort. Andersherum müsste der Schreiber der
     * Befunde die Kanäle kennen — und dann stünde dieselbe Entscheidung an zwei
     * Stellen.
     *
     * > **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist
     * > die, die veraltet.**
     *
     * **Der Zeitpunkt ist der der Messung und nicht `now()`.** Behoben war es,
     * als der Lauf es nicht mehr fand; eine Entwarnung, die morgen zugestellt
     * wird, nennt sonst den morgigen Zeitpunkt.
     *
     * @param  list<string>  $seen  je Eintrag `subject|reason`
     */
    private function forgetMissing(FindingCheck $check, array $seen, Carbon $measuredAt): void
    {
        Finding::query()
            ->where('check', $check->value)
            ->with('notifications')
            ->get()
            ->reject(fn (Finding $finding): bool => in_array($finding->subject.'|'.$finding->reason, $seen, true))
            ->each(function (Finding $finding) use ($measuredAt): void {
                foreach ($finding->notifications as $notification) {
                    FindingResolution::record($finding, $notification->channel, $measuredAt);
                }

                $finding->delete();
            });
    }
}
