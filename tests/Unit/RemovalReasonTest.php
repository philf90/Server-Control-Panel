<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Ops\BackupRemove;
use SrvPanel\Agent\Ops\DbDumpRemove;

/**
 * Jeder Ausgang des Abräumens hat sein eigenes Wort und seinen eigenen Satz.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Befund 5 des Nachlaufs zu P8 (`docs/121 §9`), gemessen am 18. September 2026
 * auf `cloudsrv24`: Ein Verzeichnis mit einer Datei darin ergab die Meldung
 * **„nichts zu entfernen"** — und der Satz ist für diesen Fall falsch. Es *gab*
 * etwas zu entfernen, und genau deshalb blieb es liegen.
 *
 * `Store::removeDirectory()` gab `false` für **drei** Zustände zurück: Das
 * Verzeichnis gibt es nicht, es ist ein **Verweis**, oder `rmdir(2)` scheitert
 * an seinem Inhalt.
 *
 * > **Zwei Gründe, die dasselbe Ergebnis erzeugen, sind nicht derselbe Grund —
 * > und die Abhilfe für den einen lässt den anderen stehen.** (`docs/914`)
 *
 * **Der schwerste war der mittlere.** Sich zu weigern, einem Verweis zu folgen,
 * ist eine Sicherheitsentscheidung — und sie las sich als „da war nichts". Im
 * selben Rumpf stand die Gegenprobe: Die Abweichung von `realpath()` wirft mit
 * einer Begründung.
 *
 * > **Ein Griff, der sich weigert, und einer, der nichts zu tun findet, geben
 * > dieselbe Antwort — und nur der erste ist eine Auskunft, die jemand
 * > braucht.**
 *
 * ## Zwei Paare und nicht eines
 *
 * Dieselbe Zeile stand in {@see Dump::removeDirectory()}, eine Datei weiter,
 * mit derselben Lücke in kleiner: `false` für „gibt es nicht" **und** für
 * „ist ein Verweis". Ein Wächter über nur eines der beiden Paare wäre grün,
 * während der Befund eine Datei weiter offenstünde — genau das war
 * `LogFooterTest` im September (`docs/921`).
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Was er nicht hält
 *
 * Ob der Griff auf einem Server das Richtige tut. `Store::ROOT` ist ein fester
 * Pfad, also lässt sich `removeDirectory()` hier nicht an einem Wegwerfbaum
 * fahren; die Wirkung belegt der Abnahmelauf (`docs/121 §4`). Gehalten ist,
 * dass jeder Ausgang unterscheidbar ist und einen Satz hat.
 */
final class RemovalReasonTest extends TestCase
{
    /**
     * Die Paare aus Ablageort und Operation.
     *
     * @return iterable<string, array{class-string, string, class-string}>
     */
    public static function paare(): iterable
    {
        yield 'Sicherungen' => [Store::class, 'removeDirectory', BackupRemove::class];
        yield 'Dumps' => [Dump::class, 'removeDirectory', DbDumpRemove::class];
    }

    /**
     * Drei Wörter und nicht zwei Wörter und ein Schweigen.
     *
     * **Die Untergrenze dieses Wächters**: Wären zwei davon gleich, wäre jede
     * Frage darunter beantwortet und nichts geprüft.
     */
    public function test_every_outcome_has_its_own_word(): void
    {
        $woerter = [Filesystem::REMOVED, Filesystem::ABSENT, Filesystem::NOT_EMPTY];

        $this->assertCount(
            count($woerter),
            array_unique($woerter),
            'Zwei Ausgänge tragen dasselbe Wort — dann sind sie von aussen derselbe.',
        );
    }

    /**
     * Jeder Ausgang, den der Ablageort zurückgeben kann, hat einen Satz.
     *
     * **Gelesen wird der Rumpf und nicht die Marke `@return`.** Eine Marke ist
     * eine Zusage über den Code; hier soll gelten, was er wirklich tut.
     */
    #[DataProvider('paare')]
    public function test_every_outcome_has_a_sentence(string $ort, string $methode, string $operation): void
    {
        $ausgaenge = $this->ausgaengeVon($ort, $methode);

        $this->assertNotSame([], $ausgaenge, sprintf(
            '%s::%s() gibt keinen benannten Ausgang zurück — dann prüft dieser Test nichts.',
            $ort,
            $methode,
        ));

        $saetze = $this->meldungenVon($operation);

        foreach ($ausgaenge as $ausgang) {
            $this->assertArrayHasKey($ausgang, $saetze, sprintf(
                '%s kann `%s` zurückgeben, und %s hat dafür keinen Satz. '
                .'Der Zugriff bricht dann zur Laufzeit — was besser ist als ein Rückfall auf den '
                .'harmlosesten Satz, aber niemandem hilft.',
                $ort,
                $ausgang,
                $operation,
            ));
        }
    }

    /**
     * Und die Gegenrichtung: kein Satz für einen Ausgang, den es nicht gibt.
     *
     * **So entsteht ein toter Eintrag wirklich** — bei einer Umbenennung trägt
     * man den neuen Namen nach, die erste Richtung ist wieder grün, und der
     * alte bleibt liegen. Er deckt danach ein Wort, das nie kommt, und beim
     * nächsten gleichnamigen Ausgang wäre die Regel still abgeschaltet.
     *
     * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts
     * > gesagt.**
     */
    #[DataProvider('paare')]
    public function test_no_sentence_outlives_its_outcome(string $ort, string $methode, string $operation): void
    {
        $ausgaenge = $this->ausgaengeVon($ort, $methode);

        foreach (array_keys($this->meldungenVon($operation)) as $wort) {
            $this->assertContains((string) $wort, $ausgaenge, sprintf(
                '%s hat einen Satz für `%s`, und %s::%s() gibt das nie zurück.',
                $operation,
                (string) $wort,
                $ort,
                $methode,
            ));
        }
    }

    /**
     * Der Verweis ist eine Weigerung und kein Ausgang.
     *
     * Zwei Dinge in einem Fall, und beide sind nötig:
     *
     * 1. Er **wirft**, statt einen der Ausgänge zurückzugeben — sonst stünde
     *    die Sicherheitsentscheidung neben „da war nichts".
     * 2. Er steht **vor** der Frage nach dem Verzeichnis. Ein Verweis auf eine
     *    Datei liesse `is_dir()` falsch werden, und der Fall käme als
     *    `absent` heraus — also wieder als der harmlose.
     */
    #[DataProvider('paare')]
    public function test_a_link_is_refused_loudly_and_first(string $ort, string $methode, string $operation): void
    {
        $rumpf = $this->rumpfVon($ort, $methode);

        $link = strpos($rumpf, 'is_link(');
        $dir = strpos($rumpf, 'is_dir(');

        $this->assertIsInt($link, sprintf('%s::%s() fragt nicht mehr nach einem Verweis.', $ort, $methode));
        $this->assertIsInt($dir, sprintf('%s::%s() fragt nicht mehr nach einem Verzeichnis.', $ort, $methode));

        $this->assertLessThan($dir, $link, sprintf(
            '%s::%s() fragt nach dem Verzeichnis, bevor es nach dem Verweis fragt — '
            .'ein Verweis auf eine Datei käme dann als `absent` heraus.',
            $ort,
            $methode,
        ));

        $zweig = substr($rumpf, $link, $dir - $link);

        $this->assertStringContainsString('denied(', $zweig, sprintf(
            '%s::%s() gibt für einen Verweis still einen Ausgang zurück, statt zu werfen. '
            .'Die Weigerung liest sich dann wie „da war nichts".',
            $ort,
            $methode,
        ));
    }

    /**
     * Die Ausgänge, die im Rumpf wirklich vorkommen.
     *
     * @return list<string>
     */
    private function ausgaengeVon(string $ort, string $methode): array
    {
        preg_match_all('/Filesystem::([A-Z][A-Z_]*)/', $this->rumpfVon($ort, $methode), $treffer);

        $woerter = [];

        foreach ($treffer[1] as $name) {
            $konstante = new ReflectionClassConstant(Filesystem::class, $name);
            $wert = $konstante->getValue();

            if (is_string($wert)) {
                $woerter[] = $wert;
            }
        }

        return array_values(array_unique($woerter));
    }

    /**
     * Die Sätze der Operation — über Reflexion, weil sie privat sind.
     *
     * Privat ist richtig: Niemand ausser der Operation braucht sie. Ein Wächter
     * darf trotzdem hineinsehen; die Regel gilt dem Inhalt und nicht der
     * Sichtbarkeit.
     *
     * @return array<string, string>
     */
    private function meldungenVon(string $operation): array
    {
        $konstante = new ReflectionClassConstant($operation, 'MELDUNG');
        $wert = $konstante->getValue();

        $this->assertIsArray($wert, sprintf('%s::MELDUNG ist keine Abbildung.', $operation));

        /** @var array<string, string> $wert */
        return $wert;
    }

    /**
     * Der Rumpf einer Methode, ohne Kommentare.
     *
     * **Ohne sie sucht dieser Wächter in dem Absatz, der die Behebung
     * erklärt** — und dieses Repo hält in jeder Behebung ihren Vorzustand
     * wörtlich fest.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht — und ein Kommentar, der die entfernte Zeile zitiert,
     * > stellt sie für ihn wieder her.**
     */
    private function rumpfVon(string $klasse, string $methode): string
    {
        $datei = (new ReflectionClass($klasse))->getFileName();

        $this->assertIsString($datei);

        $quelle = file_get_contents($datei);

        $this->assertIsString($quelle);

        $ohne = '';

        foreach (token_get_all($quelle) as $token) {
            $ohne .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        $ab = strpos($ohne, 'function '.$methode.'(');

        $this->assertIsInt($ab, sprintf('%s kennt keine Methode %s() mehr.', $klasse, $methode));

        $auf = strpos($ohne, '{', $ab);

        $this->assertIsInt($auf, sprintf('%s::%s() hat keinen Rumpf.', $klasse, $methode));

        $tiefe = 0;

        for ($i = $auf; $i < strlen($ohne); $i++) {
            $tiefe += $ohne[$i] === '{' ? 1 : ($ohne[$i] === '}' ? -1 : 0);

            if ($tiefe === 0) {
                return substr($ohne, $auf, $i - $auf + 1);
            }
        }

        $this->fail(sprintf('Der Rumpf von %s::%s() hört nicht auf.', $klasse, $methode));
    }
}
