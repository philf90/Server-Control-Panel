<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\MaintenanceFlag;
use App\Support\Web\MaintenanceMode;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Das Band des Wartungsmodus — die Naht, die Dauer und das Urteil
 * (`docs/911`).
 *
 * **Warum es diesen Wächter gibt.** Das Band steht in `PanelLayout.vue`, also
 * in der Hülle **jeder** Seite — Kundenseiten eingeschlossen; das
 * Impersonationsband steht genau dort. Wer es sehen darf, entscheidet allein
 * `HandleInertiaRequests::share()`: Ohne `operate-server` kommt der Wert gar
 * nicht erst an, und das `v-if` darauf ist dieselbe Grenze, einen Schritt
 * früher gezogen.
 *
 * **Und `OperatorControlTest` kann diese Naht nicht halten** — gemessen am
 * 12. September 2026. Nimmt man dem Verschluss seine Fähigkeitsprüfung, findet
 * seine Zuordnung „Fähigkeit → Wächtervariable" für das Layout nichts mehr,
 * und er überspringt die Datei zu Recht: Wo keine Variable Betrachter
 * unterscheidet, gibt es für ihn nichts zu verstecken. Der Eingriff blieb
 * grün.
 *
 * > **Ein Wächter, der beim Fehlen seiner Voraussetzung überspringt, meldet
 * > das Fehlen der Voraussetzung nicht.**
 *
 * Deshalb steht sie hier, und zwar als das, was sie ist: eine Zusage über
 * zwei Dateien, die einzeln in Ordnung aussehen.
 *
 * **Was er nicht kann:** Er sagt nicht, ob der Satz im Band gut gewählt ist,
 * und er misst keine Pixel — die Lage der Bänder steht in `docs/911 §2` M4.
 */
final class MaintenanceBandTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const MIDDLEWARE = __DIR__.'/../../app/Http/Middleware/HandleInertiaRequests.php';

    private const LAYOUT = __DIR__.'/../../resources/js/Layouts/PanelLayout.vue';

    /**
     * Die Naht: Der Wert reist als Verschluss und nur an den, der schalten
     * darf.
     *
     * Beide Hälften in **einem** Fall, weil sie zusammen die Zusage ergeben.
     * Ein fertiger Wert liefe auch bei einem partiellen Nachladen, das ihn gar
     * nicht mitschickt (`docs/103`); ein Verschluss ohne Fähigkeit schickte das
     * Band an jeden Kunden.
     */
    public function test_the_band_travels_as_a_closure_and_only_to_an_operator(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(self::MIDDLEWARE));

        $bei = strpos($quelle, "'maintenanceBand' =>");
        $this->assertNotFalse($bei, implode("\n", [
            '`HandleInertiaRequests` teilt `maintenanceBand` nicht mehr.',
            '',
            'Ohne den Wert zeigt das Band nie etwas — und `v-if="maintenance"` in der',
            'Hülle wäre ein toter Zweig, den niemand bemerkt.',
        ]));

        $eintrag = substr($quelle, $bei, 260);

        $this->assertMatchesRegularExpression(
            "/'maintenanceBand' => fn \(\): \?array =>/",
            $eintrag,
            implode("\n", [
                '`maintenanceBand` wird nicht als Verschluss geteilt.',
                '',
                'Ein fertiger Wert läuft bei jeder Anfrage — auch bei einem partiellen',
                'Nachladen, das ihn gar nicht mitschickt (`docs/103`).',
            ]),
        );

        $this->assertStringContainsString(
            'AdminAbility::OPERATE_SERVER',
            $eintrag,
            implode("\n", [
                'Der geteilte Wartungszustand hängt nicht mehr an `operate-server`.',
                '',
                'Das Band steht in der Hülle **jeder** Seite, auch der eines Kunden. Ohne',
                'diese Prüfung erführe er von einer Wartung, die er weder beenden noch',
                'beeinflussen kann — und `OperatorControlTest` überspringt die Datei dann,',
                'statt sie zu melden (gemessen, siehe Kopf dieser Klasse).',
            ]),
        );
    }

    /**
     * Das Band steht hinter dem Wert und nicht daneben.
     *
     * Ohne das `v-if` rendert es für jeden, dem der Wert fehlt, einen leeren
     * Streifen mit einem Verweis, der einen 403 einbringt.
     */
    public function test_the_band_sits_behind_the_shared_value(): void
    {
        $vorlage = $this->withoutMarkupComments((string) file_get_contents(self::LAYOUT));

        $this->assertMatchesRegularExpression(
            '/<Link\s+v-if="maintenance"\s+href="\/maintenance"/',
            $vorlage,
            implode("\n", [
                'Das Wartungsband steht nicht hinter `v-if="maintenance"`.',
                '',
                'Der geteilte Wert ist `null`, wenn der Betrachter ihn nicht haben darf —',
                'ohne die Bedingung steht ein Verweis auf `/maintenance` in der Hülle jeder',
                'Seite, und für einen Kunden ist er eine Sackgasse.',
            ]),
        );

        $this->assertStringContainsString(
            'announcements.length || maintenance',
            $vorlage,
            implode("\n", [
                'Die Hülle `.bands` kennt das Wartungsband nicht in ihrer Bedingung.',
                '',
                'Dann steht bei laufender Wartung ohne Ankündigung und ohne Impersonation',
                'eine Hülle ohne Inhalt — oder gar keine, und das Band fällt weg.',
            ]),
        );
    }

    /**
     * Die Dauer überlebt eine Änderung der Endzeit.
     *
     * **Der tragende Fall.** Wer bloss die Endzeit ändert, während der Modus
     * läuft, ruft dieselbe Route mit `enabled = true` auf. Ein Zeitstempel je
     * Aufruf setzte die Dauer zurück, und das Band läse „seit einer Minute",
     * während die Wartung seit sechs Stunden läuft — also falsch in genau der
     * Richtung, die das Vergessen verdeckt.
     */
    public function test_changing_the_end_time_does_not_restart_the_duration(): void
    {
        $lief = ['enabled' => true, 'until' => '2026-09-12 00:00:00', 'since' => '2026-09-11 18:00:00'];

        $this->assertSame(
            '2026-09-11 18:00:00',
            MaintenanceMode::since($lief, true),
            'Eine Änderung der Endzeit setzt die Dauer zurück.',
        );

        $aus = ['enabled' => false, 'until' => null, 'since' => null];
        $this->assertNotNull(
            MaintenanceMode::since($aus, true),
            'Das Einschalten setzt keinen Zeitpunkt — dann hat das Band keine Dauer zu zeigen.',
        );

        $this->assertNull(
            MaintenanceMode::since($lief, false),
            'Das Ausschalten lässt die Dauer stehen; sie läse sich wie eine laufende Wartung.',
        );

        /*
         * Der Bestand aus der Zeit vor diesem Feld: eingeschaltet, aber ohne
         * Zeitpunkt. „Seit unbekannt" wäre eine Auskunft weniger als der erste
         * Augenblick, an dem es jemand gemessen hat.
         */
        $alt = ['enabled' => true, 'until' => null, 'since' => null];
        $this->assertNotNull(
            MaintenanceMode::since($alt, true),
            'Ein laufender Modus ohne abgelegten Zeitpunkt bekommt keinen.',
        );
    }

    /**
     * Das Urteil über Ablage gegen Datei — beide Richtungen und der Gleichstand.
     *
     * Der Gleichstand ist der Prüfkörper, ohne den die anderen beiden nichts
     * sagen: Eine Prüfung, die immer meldet, meldet nichts.
     */
    public function test_the_drift_verdict_names_the_direction(): void
    {
        $this->assertSame(
            [],
            MaintenanceFlag::judge(true, ['enabled' => true, 'flag' => '/var/spool/srvpanel/wartung']),
            'Ablage und Datei sagen dasselbe, und es kommt trotzdem ein Befund heraus.',
        );

        $this->assertSame(
            [],
            MaintenanceFlag::judge(false, ['enabled' => false, 'flag' => '/var/spool/srvpanel/wartung']),
            'Beide sagen „aus", und es kommt trotzdem ein Befund heraus.',
        );

        $fehlt = MaintenanceFlag::judge(true, ['enabled' => false, 'flag' => '/pfad/aus/der/antwort']);
        $this->assertCount(1, $fehlt);
        $this->assertSame('missing', $fehlt[0]['reason'], 'Die fehlende Datei heisst nicht `missing`.');
        $this->assertSame(
            '/pfad/aus/der/antwort',
            $fehlt[0]['subject'],
            'Der Gegenstand kommt nicht aus der Antwort des Agenten — dann ist er eine Behauptung über einen Pfad, den niemand gemessen hat.',
        );

        $liegt = MaintenanceFlag::judge(false, ['enabled' => true, 'flag' => '/pfad/aus/der/antwort']);
        $this->assertCount(1, $liegt);
        $this->assertSame('unexpected', $liegt[0]['reason'], 'Die unerwartete Datei heisst nicht `unexpected`.');
    }
}
