<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SrvPanel\Agent\Ops\PgDatabaseCreate;

/**
 * Die Sortierung einer Kundendatenbank kommt aus dem Cluster, nicht aus einer Zeile.
 *
 * **Der Anlass ist der fünfte Fehler derselben Bauform an einem Tag — und er
 * stand in der Behebung des vierten.** Seit `docs/39` Punkt 3 schickt das Panel
 * für PostgreSQL kein Gebietsschema mehr mit; damit griff im Agenten
 * `$args['locale'] ?? 'C.UTF-8'`, eine Zeile, die vorher nie erreicht wurde.
 *
 * Gemessen auf `cloudsrv24` am 10. August 2026:
 *
 *     postgres / template0 / template1   de_DE.UTF-8
 *     x90d271df69287335_kundendatenbank  C.UTF-8      ← die erste Kundendatenbank
 *
 * `C.UTF-8` sortiert nach Bytes. In `ORDER BY name` steht „Äpfel" damit **hinter**
 * „Zebra" — für einen deutschen Kunden sichtbar falsch, und anders als das, was
 * er in MariaDB bekommt.
 *
 * > **Ein Vorgabewert, den niemand überschreibt, ist kein Vorgabewert — er ist
 * > die Antwort.**
 *
 * **Gefragt statt angenommen**, entschieden vom Betreiber: Das Gebietsschema
 * kommt aus `template0`, also aus dem, was `initdb` gesetzt hat. Und aus
 * `template0` und nicht aus `template1`, weil daraus auch angelegt wird — ein
 * Gebietsschema, das zur Vorlage passt, ist immer zulässig.
 *
 * **Ohne Antwort wird nichts erfunden.** Ein Ersatzwert an dieser Stelle wäre
 * derselbe Fehler noch einmal: Er stünde still da und würde eines Tages die
 * Antwort.
 */
final class PgLocaleTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(PgDatabaseCreate::class))->getFileName();

        return (string) file_get_contents((string) $file);
    }

    /**
     * Kein festes Gebietsschema mehr als Ersatzwert.
     */
    public function test_the_locale_is_not_a_literal(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$args\['locale'\] \?\? '/",
            $this->source(),
            'Das Gebietsschema hat wieder einen festen Ersatzwert. Solange das Panel eines '.
            'mitschickt, fällt das nicht auf — und sobald es keines mehr schickt, ist der '.
            'Ersatzwert die Antwort. Genau so kam C.UTF-8 auf cloudsrv24 in eine '.
            'Kundendatenbank.',
        );
    }

    /**
     * Gefragt wird der Cluster — und zwar `template0`.
     */
    public function test_the_cluster_is_asked(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "SELECT datcollate FROM pg_database WHERE datname = 'template0'",
            $source,
            'Der Agent fragt den Cluster nicht mehr nach seiner Sortierung.',
        );

        $this->assertStringContainsString(
            '$this->clusterLocale($context)',
            $source,
            'Die Antwort des Clusters wird nicht mehr benutzt.',
        );
    }

    /**
     * Und die Anweisung schreibt sie weiterhin hin.
     *
     * **Die Untergrenze.** Fiele `LC_COLLATE` aus der Anweisung, wäre der
     * Wächter oben zufrieden — und die Datenbank bekäme das Gebietsschema der
     * Vorlage, was hier zufällig dasselbe wäre. Bis jemand `template0` ändert.
     */
    public function test_the_statement_still_writes_it(): void
    {
        $statement = PgDatabaseCreate::statement('probe', 'UTF8', 'de_DE.UTF-8');

        $this->assertStringContainsString('TEMPLATE template0', $statement);
        $this->assertStringContainsString("LC_COLLATE 'de_DE.UTF-8'", $statement);
        $this->assertStringContainsString("LC_CTYPE 'de_DE.UTF-8'", $statement);
    }

    /**
     * Das Muster lässt durch, was ein Cluster wirklich meldet.
     *
     * **Gemessen und nicht ausgedacht.** `C.UTF-8` stammt aus dem Wegwerf-Cluster
     * dieses Containers, `de_DE.UTF-8` von `cloudsrv24`; beide sind am
     * 10. August 2026 aus `pg_database.datcollate` gelesen worden. Ein Muster,
     * das die Antwort des Clusters abweist, machte das Anlegen unmöglich — und
     * zwar erst auf dem Server, an dem es zum ersten Mal anders heisst.
     */
    public function test_the_pattern_accepts_what_a_cluster_reports(): void
    {
        $pattern = (new ReflectionClass(PgDatabaseCreate::class))->getConstant('LOCALE');

        $this->assertIsString($pattern);

        foreach (['C.UTF-8', 'de_DE.UTF-8', 'C', 'en_US.UTF-8'] as $locale) {
            $this->assertSame(1, preg_match($pattern, $locale), sprintf(
                'Das Muster weist „%s" ab — ein Cluster mit dieser Sortierung liesse keine '
                .'Datenbank mehr anlegen.',
                $locale,
            ));
        }
    }
}
