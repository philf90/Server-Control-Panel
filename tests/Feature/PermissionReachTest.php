<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Support\Plans\Feature;
use Tests\TestCase;

/**
 * Jedes Recht dieses Katalogs wird von einer Policy auch gefragt.
 *
 * ## Der Befund, der diesen Wächter ausgelöst hat
 *
 * Beim Bau von P8 Schritt 5: `Permission::Backups` und `Feature::Backups` gibt
 * es **seit P0**, beide sind aufeinander abgebildet — und **keine Policy hat
 * sie je gefragt.** Ein Plan konnte „Sicherungen" freigeben oder verweigern,
 * ein Konto das Recht bekommen oder nicht, und es bedeutete nichts.
 *
 * > **Ein Recht, das keine Policy fragt, ist von aussen nicht von einem zu
 * > unterscheiden, das es nicht gibt.**
 *
 * Das ist dieselbe Familie wie `context` im Protokoll (`docs/66`, geschrieben
 * und von keiner Oberfläche gelesen), `subject_type` (`docs/94`) und
 * `Settings::saveDnsAddresses()` (`docs/74`, gebaut und von nichts gerufen) —
 * nur an einer Berechtigung, wo es am teuersten ist: Ein Recht, das nichts
 * durchsetzt, sieht aus wie Sicherheit.
 *
 * **Und die Frage hat gleich einen zweiten Fall gefunden**, den niemand gesucht
 * hat: `Permission::Statistics`. Er steht in {@see self::WITHOUT_POLICY} mit
 * seinem Grund.
 *
 * ## Gefragt wird der Quelltext der Policies und nicht eine Liste hier
 *
 * Eine Liste „diese Rechte werden gefragt" müsste jemand pflegen, und das Recht,
 * das jemand zu ergänzen vergisst, ist genau das, das dieser Test finden soll.
 *
 * ## Beide Richtungen
 *
 * Die zweite ist die, an der ein toter Eintrag wirklich entsteht: Wird ein Recht
 * aus {@see self::WITHOUT_POLICY} später doch gefragt, gehört der Eintrag
 * entfernt — sonst nimmt er es dauerhaft von der Prüfung aus. Dieselbe
 * Sperrklinke wie in `AgentOperationReachTest`.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
 * > und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 */
final class PermissionReachTest extends TestCase
{
    /**
     * Rechte, die keine Policy fragt — mit Grund.
     *
     * @var array<string, string>
     */
    private const WITHOUT_POLICY = [
        /*
         * **Gemessen am 16. September 2026 beim Bau von P8 Schritt 5**, und es
         * ist ein Befund ausserhalb von P8 (`docs/117 §9`):
         *
         * - keine Policy fragt es,
         * - `App\Support\Plans\Feature` führt es **nicht**, ein Plan kann es
         *   also gar nicht freigeben,
         * - und keine Oberfläche bietet es an.
         *
         * Es ist damit eine Reservierung für eine Statistikseite, die es nicht
         * gibt. Stehen bleibt es, weil sein Wert `statistics` in der Ablage
         * eines Kontos stehen kann — ihn zu entfernen hiesse, eine Zahl
         * umzudeuten, die jemand vergeben hat.
         *
         * > **Eine Aufzählung, die einen Wert kennt, den niemand fragt, ist
         * > eine Zusage über eine Zukunft — und die nächste Fassung ist nicht
         * > verpflichtet, sie einzulösen.**
         */
        'statistics' => 'Reserviert für eine Statistikseite, die es nicht gibt: kein Feature im Plan, keine Policy, keine Oberfläche. Der Wert kann in der Rechteablage eines Kontos stehen, deshalb bleibt er.',
    ];

    /** Der Quelltext aller Policies in einem Stück. */
    private function policySource(): string
    {
        $quelle = '';

        foreach ((array) glob(dirname(__DIR__, 2).'/app/Policies/*.php') as $pfad) {
            if (is_string($pfad)) {
                $quelle .= (string) file_get_contents($pfad);
            }
        }

        return $quelle;
    }

    /**
     * Welche Rechte im Quelltext der Policies vorkommen.
     *
     * Gesucht wird `Permission::Fall` — die einzige Schreibweise, in der ein
     * Recht dort auftaucht. **Kommentare fallen vorher weg**: Jede Behebung in
     * diesem Repo hält ihren Vorzustand im Kommentar fest, und ein Kommentar,
     * der die entfernte Zeile zitiert, stellte sie für einen Ausdruck wieder
     * her.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht.**
     *
     * @return list<string>
     */
    private function askedInAPolicy(): array
    {
        $quelle = $this->withoutPhpComments($this->policySource());

        preg_match_all('/\bPermission::([A-Za-z][A-Za-z0-9]*)\b/', $quelle, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    /** Kommentare entfernen — über den Parser und nicht über einen Ausdruck. */
    private function withoutPhpComments(string $quelltext): string
    {
        $ohne = '';

        foreach (token_get_all($quelltext) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $ohne .= is_array($token) ? $token[1] : $token;
        }

        return $ohne;
    }

    public function test_every_permission_is_asked_by_a_policy(): void
    {
        $gefragt = $this->askedInAPolicy();

        // Ohne diese Zeile meldete ein kaputter Leser dieselbe leere Liste wie
        // eine Anwendung, in der alles gefragt wird.
        $this->assertGreaterThan(
            5,
            count($gefragt),
            'Es werden kaum Rechte im Quelltext der Policies gefunden — dann prüft dieser Test nichts.',
        );

        $ungefragt = [];

        foreach (Permission::cases() as $permission) {
            $name = $this->nameOf($permission);

            if (in_array($name, $gefragt, true)) {
                continue;
            }

            if (array_key_exists($permission->value, self::WITHOUT_POLICY)) {
                continue;
            }

            $ungefragt[] = $permission->value;
        }

        $this->assertSame([], $ungefragt, sprintf(
            "Diese Rechte kennt der Katalog, und keine Policy fragt sie:\n  %s\n\n".
            'Ein Recht, das keine Policy fragt, ist von aussen nicht von einem zu unterscheiden, '.
            'das es nicht gibt — und ein Plan, der es freigibt, verspricht nichts. Entweder fragt '.
            'es eine Policy, oder es steht mit Grund in WITHOUT_POLICY.',
            implode("\n  ", $ungefragt),
        ));
    }

    /**
     * **Die Gegenrichtung.** Eine Ausnahme, die überholt ist, wird entfernt.
     *
     * So entsteht ein toter Eintrag wirklich: Jemand baut die Policy nach, der
     * Eintrag bleibt stehen, und das Recht ist dauerhaft von der Prüfung
     * ausgenommen — ausgerechnet das, das gerade erst scharf wurde.
     */
    public function test_an_exemption_does_not_outlive_its_reason(): void
    {
        $gefragt = $this->askedInAPolicy();

        foreach (self::WITHOUT_POLICY as $wert => $grund) {
            $permission = Permission::tryFrom($wert);

            $this->assertNotNull($permission, sprintf(
                'WITHOUT_POLICY führt %s, und dieses Recht gibt es nicht mehr. Der Eintrag gehört entfernt.',
                $wert,
            ));

            $this->assertNotContains(
                $this->nameOf($permission),
                $gefragt,
                sprintf('%s steht in WITHOUT_POLICY und wird wieder gefragt. Der Eintrag gehört entfernt.', $wert),
            );

            $this->assertNotSame('', trim($grund), 'Eine Ausnahme ohne Grund ist eine Lücke mit Überschrift.');
        }
    }

    /**
     * Und ein Recht, das ein **Plan** anbietet, braucht seine Policy immer.
     *
     * **Diese Richtung duldet keine Ausnahme**, und das ist der Unterschied zu
     * der oben: Ein Recht ohne Plan und ohne Policy ist eine Reservierung. Ein
     * Recht, das im Plan eines Kunden steht und das niemand fragt, ist eine
     * **Zusage an den Kunden**, die nichts durchsetzt.
     *
     * > **Ein Plan, der eine Fähigkeit verkauft, die keine Prüfung kennt, hat
     * > sie nicht freigegeben — er hat sie nie eingeschränkt.**
     */
    public function test_a_permission_a_plan_can_grant_is_always_asked(): void
    {
        $gefragt = $this->askedInAPolicy();
        $offen = [];

        foreach (Feature::cases() as $feature) {
            $permission = $feature->permission();

            if (! in_array($this->nameOf($permission), $gefragt, true)) {
                $offen[] = sprintf('%s → %s', $feature->value, $permission->value);
            }
        }

        // Die Untergrenze: Gäbe es keine Features, prüfte dieser Fall nichts.
        $this->assertGreaterThan(0, count(Feature::cases()));

        $this->assertSame([], $offen, sprintf(
            "Diese Plan-Funktionen geben ein Recht frei, das keine Policy fragt:\n  %s",
            implode("\n  ", $offen),
        ));
    }

    /** Der Name des Falls, so wie er im Quelltext hinter `Permission::` steht. */
    private function nameOf(Permission $permission): string
    {
        return $permission->name;
    }
}
