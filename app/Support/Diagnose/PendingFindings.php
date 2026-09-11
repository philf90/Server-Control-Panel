<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Models\Finding;

/**
 * Wieviele Befunde der Bestandsdiagnose gerade auffällig sind.
 *
 * **Warum es diese Stelle gibt.** Der Nachtlauf schreibt seine Befunde in eine
 * Tabelle, und niemand sieht sie, der nicht hingeht — `docs/81 §11` nennt das
 * bei A13 beim Namen:
 *
 * > **Ein Befund, der niemanden erreicht, ist eine Zeile in einer Tabelle.**
 *
 * Am 11. September 2026 standen auf `cloudsrv24` zwei Befunde seit Tagen da,
 * und gefunden hat sie ein Abnahmelauf, der zufällig vorbeikam.
 *
 * ## Gezählt wird und nicht abgelegt — und das ist gemessen
 *
 * Das Abzeichen am Menüpunkt „Updates" liest eine **Ablage**, weil seine Quelle
 * 3033 ms kostet (`docs/907`). Hier ist es umgekehrt (`docs/910 §2`, M8):
 *
 * | | warm |
 * |---|---|
 * | ein abgelegter Wert aus `settings` | 0,211 ms |
 * | diese Zählung aus `findings` | **0,073 ms** |
 *
 * > **Eine Ablage, die einen Wert schneller liefern sollte als seine Quelle,
 * > ist hier langsamer als sie** — und dazu eine zweite Fassung derselben
 * > Wahrheit.
 *
 * ## Die Abkürzung über `reason` ist beweisbar und nicht geraten
 *
 * Es gibt **keine Zustandsspalte**: Der Zustand eines Befundes kommt aus
 * {@see FindingCheck::state()}, also aus einer Abbildung über das Paar
 * `(check, reason)`. Eine Zählung müsste demnach 38 Paare aufzählen — gemessen
 * 1,06 bis 1,36 ms.
 *
 * Sie muss es nicht, **solange kein Grundname zugleich auffällig und nicht
 * auffällig ist**. Gemessen sind das 28 auffällige Namen gegen einen einzigen
 * nicht auffälligen (`unreachable`), Überschneidung leer. Damit ist ein Filter
 * über `reason` allein gleichwertig — und die Voraussetzung dafür hält
 * `DiagnoseBadgeTest`, statt dass sie hier als Annahme steht.
 *
 * > **Eine Abkürzung, deren Voraussetzung ein Wächter hält, ist keine Abkürzung
 * > mehr — sie ist ein Sonderfall mit Beleg.**
 *
 * ## Was nicht mitgezählt wird
 *
 * `unknown` — und das sind gemessen **elf** Gründe, alle `unreachable`: „der
 * Agent hat nicht geantwortet". Das ist keine Aussage über den Server, sondern
 * darüber, dass nicht nachgesehen werden konnte, und der Betreiber hat es am
 * 11. September aus dem Abzeichen herausgehalten. Dass der Agent schweigt,
 * sagt ohnehin jede Seite.
 */
final class PendingFindings
{
    /**
     * Die Zustände, die ein Abzeichen wert sind.
     *
     * `FindingState::Ok` steht bewusst nicht hier und kommt auch nicht vor:
     * Eine Zeile in `findings` ist immer schon eine Abweichung.
     */
    private const AUFFAELLIG = [FindingState::Fail, FindingState::Warn];

    /** Wieviele Befunde auffällig sind — `0`, wenn keiner. */
    public function count(): int
    {
        $ruhig = self::quietReasons();

        return $ruhig === []
            ? Finding::query()->count()
            : Finding::query()->whereNotIn('reason', $ruhig)->count();
    }

    /**
     * Die Grundnamen, die **kein** Abzeichen wert sind.
     *
     * **Abgeleitet und nicht aufgeschrieben.** Eine Liste hier wäre die zweite
     * Fassung dessen, was `FindingCheck` schon weiss — und die zweite ist die,
     * die veraltet. Bekommt eine Prüfung einen neuen Grund mit `unknown`, trägt
     * er sich von selbst ein.
     *
     * @return list<string>
     */
    public static function quietReasons(): array
    {
        $ruhig = [];

        foreach (FindingCheck::cases() as $pruefung) {
            foreach ($pruefung->reasons() as $grund => $eintrag) {
                if (! in_array($eintrag['state'], self::AUFFAELLIG, true)) {
                    $ruhig[] = $grund;
                }
            }
        }

        return array_values(array_unique($ruhig));
    }

    /**
     * Die Grundnamen, die eines **sind** — für den Wächter über die
     * Voraussetzung aus `docs/910 §2` M6.
     *
     * @return list<string>
     */
    public static function loudReasons(): array
    {
        $laut = [];

        foreach (FindingCheck::cases() as $pruefung) {
            foreach ($pruefung->reasons() as $grund => $eintrag) {
                if (in_array($eintrag['state'], self::AUFFAELLIG, true)) {
                    $laut[] = $grund;
                }
            }
        }

        return array_values(array_unique($laut));
    }
}
