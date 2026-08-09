<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DumpStatus;
use App\Models\DatabaseDump;
use App\Models\Subscription;
use App\Support\Databases\DumpIntegrity;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Was der Bestand über eine Sicherung sagt, wird gegen die Datei gehalten.
 *
 * **Der Anlass ist eine Zahl, die niemand je geprüft hätte** (`docs/36
 * §22.3w`). Am 9. August 2026 stand auf `cloudsrv24` in
 * `database_dumps.bytes` für eine Sicherung 69255, auf der Platte lagen 69362.
 * Woher die Abweichung dieser einen Zeile kam, war nicht mehr zu klären — der
 * Fund ist, dass es keine Rolle spielte: **`bytes` ist die Zahl, die dem Kunden
 * als „Grösse" angezeigt wird, und nichts im System hielt sie je gegen die
 * Datei.**
 *
 * Dieselbe Familie wie das `GRANT`, das sein Schema überlebte (§22.3p), und die
 * Zeile, die ihre Datei überlebte (§22.3r). Keinen der drei hat ein Test
 * gefunden, sondern ein Abnahmelauf — und alle drei sind eine Angabe im
 * Bestand, die auf etwas ausserhalb zeigt, ohne dass jemand nachsieht.
 *
 * **Zwei Ebenen, und beide sind nötig.** Der Vergleich selbst wird direkt
 * geprüft ({@see DumpIntegrity}), weil das Sicherungsverzeichnis fest unter
 * `/var/lib/srvpanel/dumps` liegt und dort kein Test schreiben darf. Dass
 * `srvpanel db` ihn auch *ruft*, wird am Kommando geprüft — mit dem Fall, der
 * keine Datei braucht: einer Zeile, deren Datei fehlt. Ein `grep` auf den
 * Quelltext bliebe grün, wenn die Prüfung dastünde und nie liefe.
 */
final class DumpSizeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Eine fertige Sicherung im Bestand — ohne Datei auf der Platte.
     *
     * Genau das ist der Fall, den das Kommando von sich aus melden muss: eine
     * Zeile, die auf eine Datei zeigt, die es nicht gibt.
     */
    private function dump(?int $bytes): DatabaseDump
    {
        return app(Tenancy::class)->withoutRestriction(function () use ($bytes): DatabaseDump {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            $dump = DatabaseDump::factory()->create([
                'subscription_id' => $subscription->id,
                'database_name' => 'p1000_shop',
                'status' => DumpStatus::Ready,
                'bytes' => $bytes,
            ]);

            return $dump;
        });
    }

    /** Der Regelfall: Zahl und Datei stimmen überein, und niemand wird gewarnt. */
    public function test_a_matching_size_is_no_finding(): void
    {
        $pfad = $this->sampleFile('zwölf Zeichen');

        $this->assertNull(DumpIntegrity::reason(14, $pfad), 'Eine passende Grösse wird als Befund gemeldet.');
    }

    /**
     * Und eine Abweichung nennt beide Zahlen.
     *
     * Beide, nicht nur „weicht ab": Der Unterschied zwischen 69255 und 69362
     * sagt einem Betreiber, dass etwas die Datei angefasst hat — „stimmt nicht"
     * sagt ihm nur, dass er selbst nachsehen muss.
     */
    public function test_a_mismatch_names_both_numbers(): void
    {
        $pfad = $this->sampleFile('zwölf Zeichen');

        $this->assertSame(
            'Bestand 4711 Byte, Datei 14 Byte',
            DumpIntegrity::reason(4711, $pfad),
            'Die Abweichung nennt nicht beide Zahlen.',
        );
    }

    /** Ohne abgelegte Grösse gibt es nichts zu vergleichen — und keinen Fehlalarm. */
    public function test_a_dump_without_a_recorded_size_is_no_finding(): void
    {
        $this->assertNull(DumpIntegrity::reason(null, $this->sampleFile('egal')));
    }

    /**
     * Eine Datei zum Vergleichen — an einem Ort, an dem ein Test schreiben darf.
     *
     * Das Sicherungsverzeichnis liegt fest unter `/var/lib/srvpanel/dumps`;
     * genau deshalb bekommt {@see DumpIntegrity::reason()} seinen Pfad
     * übergeben, statt ihn selbst zu bauen.
     */
    private function sampleFile(string $inhalt): string
    {
        $pfad = storage_path('app/private/pruefung-'.bin2hex(random_bytes(6)).'.sql.gz');
        @mkdir(dirname($pfad), 0o700, true);
        file_put_contents($pfad, $inhalt);

        return $pfad;
    }

    /**
     * Eine fertige Sicherung ohne Datei ist der schlimmere Fall und wird genannt.
     *
     * **Gefahren wird über `Artisan::call()` und nicht über `$this->artisan()`.**
     * Der erste Anlauf benutzte `expectsOutputToContain()`, und der Lauf in der
     * CI meldete, die Ausgabe enthalte den Ablagenamen nicht — obwohl er auf
     * derselben Zeile steht wie „die Datei fehlt", die gefunden wurde, und im
     * Pfad dahinter ein zweites Mal. Was `expectsOutputToContain()` dabei
     * vergleicht, war von hier aus nicht zu klären; ohne `vendor/` lässt sich
     * der Lauf lokal nicht nachstellen.
     *
     * `Artisan::output()` gibt den vollständigen Text zurück, und damit trägt
     * die Behauptung ihn im Fehlerfall bei sich. **Eine Prüfung, die nur sagt
     * „steht nicht drin", schickt einen auf die Suche; eine, die zeigt, was
     * stattdessen dastand, beantwortet die Frage.**
     */
    public function test_a_missing_file_is_reported(): void
    {
        $dump = $this->dump(4096);

        $code = Artisan::call('srvpanel:db');
        $ausgabe = Artisan::output();

        // 1, weil in diesem Container kein Agent antwortet — die Prüfung der
        // Sicherungen läuft trotzdem, und genau das ist hier der Gegenstand.
        $this->assertSame(1, $code, "Ausgabe war:\n".$ausgabe);

        $this->assertStringContainsString('die Datei fehlt', $ausgabe, implode("\n", [
            'srvpanel db meldet eine Zeile nicht, deren Datei fehlt.',
            '',
            'Ausgabe war:',
            $ausgabe,
        ]));

        $this->assertStringContainsString($dump->storage_name, $ausgabe, implode("\n", [
            'Der Befund nennt die Sicherung nicht beim Namen — dann weiss niemand, welche gemeint ist.',
            '',
            'Ausgabe war:',
            $ausgabe,
        ]));
    }

    /**
     * Eine Sicherung, die noch läuft, wird nicht gemeldet.
     *
     * Sie hat noch keine Datei. Sie hier zu nennen wäre eine Warnung für den
     * Regelfall — und die liest nach zwei Tagen niemand mehr.
     */
    public function test_a_pending_dump_is_not_reported(): void
    {
        app(Tenancy::class)->withoutRestriction(function (): void {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            DatabaseDump::factory()->create([
                'subscription_id' => $subscription->id,
                'status' => DumpStatus::Pending,
                'bytes' => null,
            ]);
        });

        Artisan::call('srvpanel:db');

        $this->assertStringNotContainsString(
            'die Datei fehlt',
            Artisan::output(),
            'Eine Sicherung, die noch läuft, wird als fehlende Datei gemeldet.',
        );
    }
}
