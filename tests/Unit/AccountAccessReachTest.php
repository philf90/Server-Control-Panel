<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Die Frage „darf dieses Konto herein" steht an **einer** Stelle, und die
 * Mittelschicht, die sie bei jeder Anfrage stellt, ist eingetragen.
 *
 * ## Warum es diesen Wächter neben `AccountAccessTest` gibt
 *
 * Der misst die Wirkung an der Tür und braucht dafür Laravel — er läuft in der
 * CI und nirgends sonst. Was hier steht, ist ohne Framework prüfbar und deckt
 * die beiden Wege ab, auf denen die Regel still verschwindet:
 *
 * 1. Jemand schreibt `status->canSignIn()` wieder irgendwo hin. Dann gibt es
 *    zwei Fassungen, und die zweite ist die, die veraltet — genau so ist
 *    Befund 6 entstanden: Der `LoginController`
 *    fragte nach dem zurückgezogenen Kunden, die beiden anderen Türen nicht.
 * 2. Die Mittelschicht fällt aus `bootstrap/app.php`. Dann ist die Klasse da,
 *    der Test in der CI grün, und **niemand ruft sie auf** — die Fehlerklasse
 *    dieses Projekts in Reinform: eine Zeichenkette, die auf etwas verweist,
 *    ohne dass jemand den Bezug prüft.
 *
 * > **Ein Wächter, der die Klasse prüft, hat über ihren Aufruf nichts gesagt.**
 *
 * ## Kommentare werden nicht mitgelesen
 *
 * Der erste Lauf dieses Wächters meldete den eigenen Kommentar: Im
 * `TwoFactorChallengeController` steht `status->canSignIn()` im Dokumentblock —
 * als Begründung dafür, dass es dort *nicht mehr* steht.
 *
 * > **Ein Wächter, der Kommentare mitliest, findet seine eigene Begründung.**
 *
 * Geschnitten wird über `WithoutPhpComments`, und nicht mit einer eigenen
 * Fassung: Genau dagegen gibt es den Baustein. Sein Kopf erzählt, wie drei
 * Wächter dieselbe kaputte Zeile abgeschrieben hatten — und dass einer davon
 * grün blieb, während seine Regel gebrochen war.
 *
 * ## Warum hier keine `{@see}`-Verweise stehen
 *
 * Pint macht aus einem vollqualifizierten `{@see}` im Dokumentblock einen
 * `use`-Eintrag. Damit wäre dieser framework-freie Wächter framework-abhängig
 * und liefe genau dort nicht mehr, wofür es ihn gibt — im Container, ohne
 * `vendor/`. Gemessen: Nach dem ersten `pint`-Lauf meldete das Gestell
 * „braucht Laravel".
 *
 * > **Ein Wächter, den man vor dem Formatierer prüft, ist nicht der, der ins
 * > Repo geht.**
 */
final class AccountAccessReachTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * `canSignIn()` wird nur aus `AccountAccess`
     * gerufen.
     *
     * Das Enum selbst darf die Methode natürlich deklarieren; gemeint sind die
     * **Aufrufe**.
     */
    public function test_only_one_class_asks_whether_an_account_may_sign_in(): void
    {
        $erlaubt = 'app/Support/Authorization/AccountAccess.php';
        $funde = [];
        $treffer = 0;

        foreach ($this->sources() as $pfad => $quelle) {
            // Die Deklaration im Enum ist keine Frage, sondern die Antwort.
            if ($pfad === 'app/Enums/AccountStatus.php') {
                continue;
            }

            $anzahl = preg_match_all('/->\s*canSignIn\s*\(/', $this->withoutComments($quelle));

            if ($anzahl === 0) {
                continue;
            }

            $treffer += $anzahl;

            if ($pfad !== $erlaubt) {
                $funde[] = sprintf('%s (%dx)', $pfad, $anzahl);
            }
        }

        /*
         * **Die Untergrenze.** Ohne sie wäre dieser Test auch dann grün, wenn
         * der Ausdruck ins Leere liefe — und genau dann sagt er nichts.
         *
         * > Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
         * > Null steht.
         */
        $this->assertGreaterThan(0, $treffer,
            'Kein einziger Aufruf von canSignIn() gefunden — der Ausdruck greift nicht mehr.');

        $this->assertSame([], $funde, sprintf(
            "Diese Stellen fragen den Anmeldezustand selbst, statt AccountAccess zu fragen:\n  %s\n\n".
            'Zwei Eingänge zu derselben Einstellung teilen ihre Prüfung, oder die Einstellung '
            .'hat zwei Bedeutungen — ein zurückgezogener Kunde kam so über den zweiten Faktor herein.',
            implode("\n  ", $funde),
        ));
    }

    /**
     * Die Mittelschicht steht in `bootstrap/app.php`, und zwar **vor** der
     * Netzbeschränkung.
     *
     * Die Reihenfolge ist kein Geschmack: Ein Konto, das gar nicht mehr da sein
     * darf, wird nicht zuerst nach seiner Adresse gefragt — sonst stünde im
     * Protokoll „Netz nicht mehr zugelassen" über einem gesperrten Konto.
     */
    public function test_the_middleware_is_registered_before_the_network_check(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap/app.php');

        $zustand = strpos($quelle, 'EnforceAccountAccess::class');
        $netz = strpos($quelle, 'EnforceAdminNetwork::class');

        $this->assertNotFalse($zustand,
            'EnforceAccountAccess steht nicht in bootstrap/app.php — die Klasse gibt es, '
            .'und niemand ruft sie auf.');
        $this->assertNotFalse($netz,
            'EnforceAdminNetwork steht nicht in bootstrap/app.php — dieser Wächter misst dann nichts.');

        $this->assertLessThan($netz, $zustand,
            'EnforceAccountAccess steht hinter EnforceAdminNetwork. Ein gesperrtes Konto bekäme '
            .'dann „Netz nicht mehr zugelassen" zu lesen — den Grund, der nicht zutrifft.');
    }

    /**
     * Alle PHP-Quellen unter `app/`, Pfad zu Inhalt.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/app', FilesystemIterator::SKIP_DOTS)
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $pfad = substr($datei->getPathname(), strlen($wurzel) + 1);
                $dateien[$pfad] = (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }
}
