<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SrvPanel\Agent\Ops\SubscriptionResume;

/**
 * Entsperrt wird ein Passwort nur, wenn es eines gibt.
 *
 * **Der Anlass ist eine Warnung, die bei jeder Freigabe erschien.** Der
 * Systembenutzer eines Abonnements hat kein Passwort — er wird ohne eines
 * angelegt, seine Shell ist `nologin`, der Zugang läuft über SFTP mit Schlüssel.
 * `usermod --unlock` weigert sich dann und schreibt:
 *
 *     usermod: unlocking the user's password would result in a passwordless
 *     account. You should set a password with usermod -p to unlock this user's
 *     password.
 *
 * Die Weigerung ist richtig, die Meldung auch. Nur erschien sie **immer** —
 * gemeldet vom Betreiber am 10. August 2026 aus Vorgang 492 (`docs/39`,
 * Punkt 6).
 *
 * > **Ein Hinweis, der immer erscheint, erzieht dazu, die Ausgabe nicht zu
 * > lesen.**
 *
 * Und die Ausgabe eines Vorgangs ist die Stelle, an der ein echter Fehler
 * auffallen soll. Dasselbe Muster wie ein Melder, der grundlos Alarm gibt —
 * `docs/36 §22.3r` hat es für `srvpanel db` schon einmal aufgeschrieben.
 *
 * **Die Sperre wird dadurch kein Stück schwächer.** `--lock` beim Sperren
 * bleibt, `--expiredate` steht auf beiden Seiten ohne Bedingung — und *das* ist
 * die Schranke, die SSH und SFTP prüfen. Entsperrt wird nur, wo etwas steht.
 */
final class AccountUnlockTest extends TestCase
{
    /**
     * Ein Konto ohne Passwort wird nicht entsperrt.
     *
     * **Gemessen und nicht ausgedacht.** `!` ist das Feld auf `cloudsrv24`, wo
     * die Warnung entstand; `!!` legt `useradd` an, wenn nie ein Passwort
     * gesetzt wurde; `!*` ist das ausdrückliche „keines". In allen dreien gäbe
     * es nichts zu entsperren.
     */
    public function test_an_account_without_a_password_is_left_alone(): void
    {
        foreach (['!', '!!', '!*', '*', ''] as $secret) {
            $this->assertFalse(
                SubscriptionResume::unlocks("p1118:{$secret}:20000:0:99999:7:::", 'p1118'),
                sprintf('Das Feld „%s" wird als entsperrbar gelesen — usermod widerspricht.', $secret),
            );
        }
    }

    /**
     * Ein gesperrtes echtes Passwort schon.
     *
     * **Die Gegenrichtung, und ohne sie wäre die erste eine Falle.** Ein
     * `unlocks()`, das nie `true` sagt, liesse ein Konto mit Passwort für immer
     * gesperrt — die Freigabe nähme das Ablaufdatum zurück und das Passwort
     * nicht, und niemand merkte es, weil dieses Panel keine Passwörter für
     * Systembenutzer vergibt. Bis jemand eines von Hand setzt.
     */
    public function test_a_locked_real_password_is_unlocked(): void
    {
        $this->assertTrue(SubscriptionResume::unlocks(
            'p1118:!$6$abcdefgh$xyz:20000:0:99999:7:::',
            'p1118',
        ));
    }

    /**
     * Ein anderes Konto beantwortet die Frage nicht.
     *
     * Der Fehler, den ein Ausdruck über die ganze Datei machte: Er fände das
     * Passwort von `root` und entsperrte damit `p1118`.
     */
    public function test_another_account_does_not_answer_for_this_one(): void
    {
        $shadow = "root:\$6\$root\$hash:20000:0:99999:7:::\np1118:!:20000:0:99999:7:::";

        $this->assertFalse(SubscriptionResume::unlocks($shadow, 'p1118'));
        $this->assertTrue(SubscriptionResume::unlocks($shadow, 'root'));
    }

    /**
     * Ohne lesbare Datei wird nichts entsperrt.
     *
     * `file_get_contents()` gibt `false` zurück, wenn `/etc/shadow` nicht
     * lesbar ist. **„Nicht nachgesehen" ist dann kein „ja"** — die Freigabe
     * bleibt beim Ablaufdatum, und das ist die Schranke, auf die es ankommt.
     * Derselbe Satz wie bei `handed_over` und beim Kernel.
     */
    public function test_an_unreadable_file_claims_nothing(): void
    {
        $this->assertFalse(SubscriptionResume::unlocks(false, 'p1118'));
        $this->assertFalse(SubscriptionResume::unlocks(null, 'p1118'));
    }

    /**
     * Und das Ablaufdatum steht ohne Bedingung da.
     *
     * **Die Untergrenze dieser Änderung.** Rutschte `--expiredate` in denselben
     * Zweig wie `--unlock`, bliebe ein freigegebenes Abonnement abgelaufen —
     * und zwar auf jedem Server, denn kein Systembenutzer hat ein Passwort. Aus
     * einer stillen Warnung würde eine stille Sperre.
     */
    public function test_the_expiry_is_lifted_unconditionally(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SubscriptionResume::class))->getFileName()
        );

        $this->assertStringContainsString(
            "\$args = ['--expiredate', '', \$user];",
            $source,
            'Das Ablaufdatum hängt an einer Bedingung. Es ist die Schranke, die SSH und SFTP '.
            'prüfen — sie muss bei jeder Freigabe fallen, ob es ein Passwort gibt oder nicht.',
        );
    }
}
