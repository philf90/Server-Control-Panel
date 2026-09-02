<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use Illuminate\Support\Carbon;

/**
 * Wann die Bestandsdiagnose zuletzt gelaufen ist (A10 Schritt 7).
 *
 * ## Warum das nicht aus den Befunden kommt
 *
 * Ein `ok` erzeugt keine Zeile (`docs/98 §4`); auf einem heilen Server ist die
 * Tabelle leer, und dann gäbe es kein `measured_at`, aus dem sich „zuletzt
 * gemessen" ablesen liesse. Punkt 1 des Abnahmekriteriums verlangt die Angabe
 * aber genau für diesen Fall: Eine Seite, die nichts meldet, muss sagen, ob sie
 * geschwiegen oder nicht gemessen hat.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.**
 *
 * ## Warum eine Schnittstelle und nicht `Settings`
 *
 * Die Ablage ist dort, und {@see SettingsRunLog} benutzt sie. Aber `Settings`
 * ist `final` und lässt sich in keinem Test ersetzen — und was hinter einer
 * solchen Klasse liegt, ist ungeprüft:
 *
 * > **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der Weg
 * > dahinter auch nicht.** (`docs/64`, an `SrvPanel\Agent\Client` bezahlt)
 *
 * Die Regel, die hier einen Wächter braucht, ist nicht „der Wert steht in der
 * Datenbank", sondern **wann** er geschrieben wird: nach den Prüfungen, und
 * auch dann, wenn eine von ihnen gescheitert ist. Dieselbe Naht wie bei
 * {@see Host} und {@see Wire}.
 */
interface RunLog
{
    /**
     * Einen gefahrenen Lauf festhalten.
     *
     * Der Zeitpunkt ist der, den die Befunde tragen, und nicht `now()`: Sonst
     * stünde neben einer Zeile von 03:00:07 ein „zuletzt gemessen 03:00:09",
     * und die beiden wären dieselbe Messung.
     */
    public function record(Carbon $ranAt): void;

    /**
     * Wann zuletzt gefahren wurde — `null`, wenn noch nie.
     *
     * `null` heisst „noch nie gemessen" und nicht „nichts gefunden". Vor dem
     * ersten Lauf schweigt die Seite, statt Entwarnung zu geben.
     */
    public function lastRunAt(): ?string;
}
