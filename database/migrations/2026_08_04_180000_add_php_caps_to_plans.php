<?php

declare(strict_types=1);

use App\Support\Plans\Quota;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Die drei PHP-Deckel in die vorhandenen Pläne nachtragen.
 *
 * **Ohne das wären sie in jedem bestehenden Plan schlicht nicht da.** Die
 * Kontingente liegen als JSON, und ein fehlender Schlüssel heisst nach der
 * Festlegung in `docs/23 §2` „unbegrenzt". Für einen Deckel ist das die
 * genaue Umkehrung dessen, was gemeint ist: Ein Plan ohne
 * `php_memory_mb` liesse jede Domain `memory_limit` frei setzen — und die
 * Prüfung im Dienst hätte nichts, woran sie messen könnte.
 *
 * Die drei Werte sind die Vorgaben aus dem Katalog. Wer engere will, ändert
 * sie im Plan; wer nichts tut, bekommt dieselben Grenzen, die ein neuer Plan
 * heute mitbringt.
 *
 * **Verträglich mit der vorigen Version** (§8.1): Eine ältere Fassung des
 * Panels liest die drei Schlüssel nicht und stört sich nicht an ihnen. Der
 * Rückweg beim Update legt nur den Symlink um; diese Migration bleibt gültig.
 */
return new class extends Migration
{
    /** @var list<Quota> */
    private const CAPS = [Quota::PhpMemoryMb, Quota::PhpUploadMb, Quota::PhpExecutionSeconds];

    public function up(): void
    {
        // Ohne Modell und ohne Mandantenklammer: Eine Migration läuft im
        // Grundzustand, und `Plan` hat zwar keine Klammer, aber ein Modell in
        // einer Migration bindet sie an eine Fassung des Codes, die es in
        // einem Jahr anders gibt.
        foreach (DB::table('plans')->select('id', 'quotas')->get() as $plan) {
            $quotas = json_decode((string) $plan->quotas, true);

            if (! is_array($quotas)) {
                continue;
            }

            $changed = false;

            foreach (self::CAPS as $cap) {
                if (! array_key_exists($cap->value, $quotas)) {
                    $quotas[$cap->value] = $cap->default();
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode($quotas)]);
            }
        }
    }

    /**
     * Zurück heisst: die drei Schlüssel wieder heraus.
     *
     * Sie in einer älteren Fassung stehen zu lassen wäre folgenlos — sie liest
     * sie nicht. Die Rückmigration räumt trotzdem auf, damit ein Plan nach
     * einem Hin und Her nicht mit Schlüsseln dasteht, die keine Oberfläche
     * mehr zeigt und niemand mehr ändern kann.
     */
    public function down(): void
    {
        foreach (DB::table('plans')->select('id', 'quotas')->get() as $plan) {
            $quotas = json_decode((string) $plan->quotas, true);

            if (! is_array($quotas)) {
                continue;
            }

            foreach (self::CAPS as $cap) {
                unset($quotas[$cap->value]);
            }

            DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode($quotas)]);
        }
    }
};
