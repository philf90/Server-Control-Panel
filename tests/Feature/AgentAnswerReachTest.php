<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Was der Agent über sein eigenes Scheitern sagt, kommt an.
 *
 * **Dreimal an einem Tag derselbe Fehler, und keiner davon sah wie einer aus.**
 * Am 10. August 2026 sind drei Antworten aufgefallen, die der Agent berechnet,
 * zurückgibt — und die in `app/` niemand liest:
 *
 * | Antwort | was sie meldete | was das Panel zeigte |
 * |---|---|---|
 * | `handed_over` | die Rolle für das Panel fehlt | einen Hinweis, immer |
 * | `stale_roles` | eine befristete Rolle blieb stehen | „Nichts liegengeblieben." |
 * | `quota.enforced` | `setquota` ist gescheitert | „15360 MB" |
 * | `usage.available` | das Dateisystem führt keine Quota | „noch nicht gemessen" |
 *
 * Alle drei haben **eine Gemeinsamkeit**, und die macht sie so teuer: Sie melden
 * einen Fehlschlag **innerhalb eines erfolgreichen Vorgangs**. Der Vorgang ist
 * grün, die Fortschrittsleiste steht auf 100 %, und die Auskunft darüber, dass
 * die Arbeit nicht getan wurde, liegt in einem Feld daneben.
 *
 * > **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**
 *
 * ## Warum eine Liste und kein Ausdruck über alle Schlüssel
 *
 * Ein Wächter, der jeden Schlüssel jeder Antwort einfordert, wäre in einer Woche
 * abgeschaltet: Die meisten sind Belege für das Protokoll und gehören in keine
 * Oberfläche. Was hier steht, ist die **kleine, ehrliche Menge** — Antworten, mit
 * denen der Agent sagt „ich habe nicht getan, worum du gebeten hast".
 *
 * Die Begründung steht **im Eintrag** und nicht in einem Kommentar daneben —
 * dieselbe Form wie `Pg\Shielding::EXEMPT` und `RemovalPathTest::WITHOUT_REMOVAL`.
 * Eine Liste ohne Begründung je Eintrag wächst, bis sie alles enthält.
 */
final class AgentAnswerReachTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Antworten, die ankommen müssen — und wo sie ankommen.
     *
     * **Der Leseort steht mit dabei, und das ist Absicht.** Ein Wächter, der nur
     * „irgendwo in `app/`" verlangt, bleibt grün, wenn der Schlüssel bloss noch
     * in einer Erklärung darüber vorkommt — und genau daran ist diese Woche
     * dreimal ein Wächter gescheitert.
     *
     * @var array<string, array{agent: string, reader: string, key: string, why: string}>
     */
    private const ANSWERS = [
        'pg.server.info → handed_over' => [
            'agent' => 'agent/src/Pg/Server.php',
            'reader' => 'app/Http/Controllers/DatabaseSettingsController.php',
            'key' => 'handed_over',
            'why' => 'Ohne die Rolle für das Panel kann PostgreSQL nichts. Ungelesen stand der '
                .'Hinweis dazu bei jedem Betrachter, auch auf einem Server ohne PostgreSQL — '
                .'ein Hinweis, der immer erscheint, erzieht dazu, die Seite nicht zu lesen.',
        ],
        'pg.server.info → stale_roles' => [
            'agent' => 'agent/src/Ops/PgServerInfo.php',
            'reader' => 'app/Console/Commands/Databases.php',
            'key' => 'stale_roles',
            'why' => 'Eine befristete Rolle, die ein abgebrochenes Zurückspielen stehenliess, ist '
                .'ein Zugang ohne Besitzer. Ungelesen meldete `srvpanel db` „Nichts '
                .'liegengeblieben." über eine Fläche, die es nie angesehen hat.',
        ],
        'subscription.usage → available' => [
            'agent' => 'agent/src/Ops/SubscriptionUsage.php',
            'reader' => 'app/Console/Commands/MeasureUsage.php',
            'key' => 'available',
            'why' => 'Ohne Quota auf dem Dateisystem misst niemand den belegten Platz, und keine '
                .'Grenze gilt. Ungelesen stand diese Auskunft nur im Journal des Timers — die '
                .'Übersicht wusste nichts davon (`docs/41`).',
        ],
        'subscription.provision → quota.enforced' => [
            'agent' => 'agent/src/DiskQuota.php',
            'reader' => 'app/Support/Subscriptions/Lifecycle.php',
            'key' => 'enforced',
            'why' => 'Scheitert `setquota`, bricht der Agent ausdrücklich nicht ab — ein '
                .'Abonnement soll nicht an einem Dateisystem ohne Quota scheitern. Ungelesen '
                .'stand im Panel eine Speichergrenze, die nichts begrenzte.',
        ],
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function code(string $path): string
    {
        $file = $this->root().'/'.$path;

        $this->assertFileExists($file, sprintf(
            'Die Datei %s gibt es nicht mehr. Ist sie umgezogen, zeigt dieser Eintrag auf ihren '
            .'neuen Ort — ist die Antwort weggefallen, geht der Eintrag mit ihr.',
            $path,
        ));

        return $this->withoutComments((string) file_get_contents($file));
    }

    /**
     * Der Agent gibt sie, und das Panel liest sie.
     *
     * **Beide Richtungen in einem Test, und das ist der Punkt.** Fehlt die
     * Antwort im Agenten, ist der Leser ein Griff ins Leere; fehlt der Leser,
     * ist die Antwort Rechenzeit. Ein Test, der nur eine Seite prüft, bleibt bei
     * genau der Hälfte der Fehler grün.
     *
     * Gelesen wird der Quelltext **ohne Kommentare**. In diesem Projekt steht
     * über jeder dieser Antworten ein Absatz, der sie beim Namen nennt — ein
     * Wächter, der ihn mitliest, prüft die Erklärung statt des Codes.
     *
     * ## Was dieser Test **nicht** prüft, und wer es tut
     *
     * Dass die lesende Methode auch **gerufen** wird. Ein `rememberQuota()`, das
     * dasteht und das niemand aufruft, käme hier durch — der Schlüssel steht ja
     * im Quelltext.
     *
     * **Diese Lücke ist gedeckt, und zwar von PHPStan.** Alle drei Leser sind
     * private Methoden, und Stufe 6 meldet eine private Methode, die niemand
     * ruft. Das hier zu wiederholen wäre eine zweite Fassung derselben Regel —
     * und die zweite ist die, die veraltet. Wer einen Leser öffentlich macht,
     * nimmt ihm diesen Schutz und braucht einen eigenen Wächter dafür.
     */
    public function test_every_answer_about_a_failure_is_read(): void
    {
        $this->assertGreaterThanOrEqual(4, count(self::ANSWERS), 'Die Liste ist zu kurz geworden.');

        foreach (self::ANSWERS as $label => $answer) {
            $key = "'".$answer['key']."'";

            $this->assertStringContainsString($key, $this->code($answer['agent']), sprintf(
                "%s: Der Agent gibt diese Antwort nicht mehr (%s).\n\n%s",
                $label,
                $answer['agent'],
                $answer['why'],
            ));

            $this->assertStringContainsString($key, $this->code($answer['reader']), sprintf(
                "%s: Diese Antwort wird nicht mehr gelesen (%s).\n\n%s",
                $label,
                $answer['reader'],
                $answer['why'],
            ));
        }
    }

    /**
     * Und jeder Eintrag sagt, warum er dasteht.
     *
     * **Die Untergrenze gegen das Wachsen.** Eine Liste, in die man ohne Satz
     * etwas einträgt, ist in einem Jahr eine Liste aller Schlüssel — und dann
     * schaltet sie jemand ab, statt sie zu pflegen. Derselbe Zuschnitt wie bei
     * `Pg\Shielding::EXEMPT`.
     */
    public function test_every_entry_carries_its_reason(): void
    {
        foreach (self::ANSWERS as $label => $answer) {
            $this->assertGreaterThan(80, strlen($answer['why']), sprintf(
                '%s steht ohne Begründung in der Liste. Was hier fehlt, ist nicht der Satz, '
                .'sondern der Grund — und ohne ihn weiss der Nächste nicht, ob der Eintrag '
                .'noch gilt.',
                $label,
            ));
        }
    }
}
