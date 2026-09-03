<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Keys;

/**
 * Der Aufruf, mit dem der Agent einen Signaturschlüssel liest, schreibt nichts —
 * und braucht kein Heimverzeichnis.
 *
 * ## Der Fehler, den es dafür gebraucht hat
 *
 * Am 3. September 2026 meldete die Bestandsdiagnose auf `cloudsrv24`
 * (`docs/99`, Punkt 1) `apt.key` als **nicht gemessen**. Der Aufruf von Hand
 * sagt, warum:
 *
 *     gpg: keyblock resource '/var/lib/srvpanel/gnupg/pubring.kbx': No such file or directory
 *     gpg: Fatal: /var/lib/srvpanel/gnupg: directory does not exist!
 *     rc=2
 *
 * Diesen Ort legt niemand an — in der Paketierung steht er nicht. Im Kopf von
 * {@see Keys::HOME} stand dafür der Satz „`gpg` legt sein Heimverzeichnis an,
 * auch wenn es nur liest", und der ist falsch herum: `gpg` legt es nicht an, es
 * stirbt daran. Die Messung, aus der der Satz entstand, stimmte sogar; nur der
 * Schluss war verkehrt.
 *
 * > **Eine Messung und der Schluss daraus sind zwei Dinge — und aufgeschrieben
 * > wird der Schluss.**
 *
 * Damit gab `Keys::inspect()` auf **jedem** Server `readable: false` zurück: Die
 * Diagnose sah den Schlüssel nie, und die Quellenseite kannte zu keiner Quelle
 * einen. Ein Schlüssel, der in vier Wochen abläuft, wäre unbemerkt abgelaufen —
 * also genau der Fall, für den es diese Prüfung gibt.
 *
 * ## Und die zweite Hälfte: eine lesende Frage, die schreibt
 *
 * `--show-keys` liest, und trotzdem legt `gpg` neben der Antwort seinen Hausrat
 * an — `pubring.kbx` und `trustdb.gpg`. Für A10 ist das kein Schönheitsfehler:
 * Die erste Regel dieser Stufe lautet, dass die Diagnose **nichts schreibt**.
 *
 * > **Ein Programm, das seinen Gegenstand nur liest, schreibt trotzdem — sein
 * > Arbeitsverzeichnis legt es beim ersten Mal an.**
 *
 * `DiagnoseWriteTest` konnte das nicht sehen: Er hält jedes gerufene Programm
 * samt Schaltern, und auf dieser Ebene ist `--show-keys` eine lesende Frage.
 *
 * > **Ein Wächter über die Schalter eines Programms sieht nicht, was das
 * > Programm neben seinem Gegenstand anlegt.**
 *
 * ## Warum dieser Wächter das Programm wirklich fährt
 *
 * Weil beides Wirkungen sind und keine Zeichenketten. Ob ein Schalter das
 * Anlegen verhindert, steht in keinem Quelltext dieses Repos — es steht im
 * Dateisystem, nachdem der Aufruf durch ist. Ein Wächter, der die Schalterliste
 * vergliche, wäre eine zweite Fassung von {@see Keys::ARGUMENTS}, und die
 * zweite ist die, die veraltet.
 *
 * Der Prüfkörper ist `packaging/srvpanel-archive-keyring.gpg` — der Bund, den
 * `Signed-By` auf einem echten Server nennt. Er liegt im Repo, es entsteht also
 * kein Schlüsselmaterial für diesen Test.
 *
 * Framework-frei.
 */
final class AptKeyReadTest extends TestCase
{
    private const GPG = '/usr/bin/gpg';

    /** Der Bund, den `Signed-By` auf einem Server nennt — er liegt im Repo. */
    private function keyring(): string
    {
        return dirname(__DIR__, 2).'/packaging/srvpanel-archive-keyring.gpg';
    }

    /**
     * Ein Heimverzeichnis, das es nicht gibt.
     *
     * Nicht angelegt und nicht aufgeräumt: Der Punkt dieser Prüfung ist, dass
     * hinterher nichts dasteht.
     */
    private function absentHome(): string
    {
        return sys_get_temp_dir().'/srvpanel-gpg-'.bin2hex(random_bytes(8));
    }

    /**
     * Der Aufruf, wie {@see Keys::inspect()} ihn für einen Pfad zusammensetzt.
     *
     * @return array{0: int, 1: string} Rückgabewert und Standardausgabe
     */
    private function invoke(string $home, string $target): array
    {
        $command = array_map(escapeshellarg(...), [
            self::GPG,
            ...Keys::ARGUMENTS,
            '--homedir',
            $home,
            $target,
        ]);

        $output = [];
        $status = 0;
        exec(implode(' ', $command).' 2>/dev/null', $output, $status);

        return [$status, implode("\n", $output)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Dieselbe Bedingung wie in `RunnerSignalTest`: Der Agent hat `gpg` auf
        // seiner Positivliste, auf einem Zielsystem ist es also da. Fehlt es,
        // sagt der Wächter das, statt eine Wirkung zu behaupten, die er nicht
        // gemessen hat.
        if (! is_executable(self::GPG)) {
            $this->markTestSkipped('Der Test braucht gpg unter '.self::GPG.'.');
        }
    }

    /**
     * Der Aufruf kommt ohne Heimverzeichnis aus.
     *
     * **Das ist der Befund vom 3. September, umgedreht.** Ohne die Schalter aus
     * {@see Keys::ARGUMENTS} endet derselbe Aufruf hier mit `rc=2`, und die
     * Prüfung `apt.key` hat auf keinem Server je eine Antwort bekommen.
     */
    public function test_the_call_needs_no_home_directory(): void
    {
        $home = $this->absentHome();

        $this->assertDirectoryDoesNotExist($home, 'Der Prüfkörper ist ein Pfad, den es nicht gibt — dieser hier gibt es.');

        [$status, $output] = $this->invoke($home, $this->keyring());

        $this->assertSame(0, $status, implode("\n", [
            'gpg kommt mit einem fehlenden Heimverzeichnis nicht mehr zurecht.',
            'Auf einem Server gibt es diesen Ort nicht: Die Paketierung legt ihn nicht an,',
            'und gpg legt ihn — entgegen dem, was hier bis September 2026 stand — nicht selbst an.',
            'Ohne --no-keyring und --trust-model always endet der Aufruf mit rc=2.',
        ]));

        $this->assertGreaterThanOrEqual(1, substr_count($output, "\npub:") + (str_starts_with($output, 'pub:') ? 1 : 0), implode("\n", [
            'Der Aufruf ist durchgelaufen und hat keinen Schlüssel gemeldet.',
            'Dann misst dieser Wächter einen Erfolg, in dem nichts steht.',
        ]));

        $this->assertDirectoryDoesNotExist($home, implode("\n", [
            'gpg hat sich sein Heimverzeichnis doch angelegt.',
            'Für A10 ist das ein Schreibvorgang der Diagnose, und die schreibt nichts.',
        ]));
    }

    /**
     * Und in ein Heimverzeichnis, das es gibt, schreibt er nichts hinein.
     *
     * Die zweite Hälfte, und sie ist die, die ohne diesen Wächter still bliebe:
     * Ein Server, auf dem der Ort einmal entstanden ist, bekäme eine Antwort —
     * und die Diagnose schriebe bei jedem Lauf in ein Verzeichnis, von dem sie
     * behauptet, sie fasse nichts an.
     */
    public function test_the_call_writes_nothing_into_an_existing_home_directory(): void
    {
        $home = $this->absentHome();

        try {
            mkdir($home, 0o700);

            [$status, $output] = $this->invoke($home, $this->keyring());

            $this->assertSame(0, $status);
            $this->assertStringContainsString('pub:', $output, 'Ohne gelesenen Schlüssel sagt ein leeres Verzeichnis nichts.');

            $this->assertSame([], array_values(array_diff((array) scandir($home), ['.', '..'])), implode("\n", [
                'gpg hat in sein Heimverzeichnis geschrieben.',
                'Ohne --no-keyring entsteht dort pubring.kbx, ohne --trust-model always die trustdb.gpg.',
                'Die Bestandsdiagnose schreibt nichts — auch nicht neben ihrer Antwort.',
            ]));
        } finally {
            foreach (glob($home.'/*') ?: [] as $rest) {
                @unlink($rest);
            }

            @rmdir($home);
        }
    }

    /**
     * Und dieselbe Antwort wie ohne die beiden Schalter.
     *
     * **Die Frage, die ein Schalterpaar immer aufwirft:** Nimmt es neben dem
     * Schreiben auch etwas von der Auskunft weg? `--show-keys` prüft keine
     * Signatur, und {@see Keys::read()} liest Kennung, Fingerabdruck und Ablauf
     * — nicht die Gültigkeitsspalte, auf die ein Vertrauensmodell wirkt. Das
     * ist die Begründung; hier steht die Messung daneben.
     */
    public function test_the_switches_do_not_change_the_answer(): void
    {
        // **Beide Läufe bekommen ein Heimverzeichnis, das es gibt.** Sonst
        // misst dieser Wächter zwei Dinge auf einmal: ob die Schalter die
        // Ausgabe ändern, und ob der Aufruf ohne Heimverzeichnis überhaupt
        // durchkommt. Das zweite ist die Frage von
        // `test_the_call_needs_no_home_directory`, und ein Wächter, der beides
        // zusammenwirft, belegt keines von beiden.
        $ohneHeim = $this->absentHome();
        $mitHeim = $this->absentHome();

        try {
            mkdir($ohneHeim, 0o700);
            mkdir($mitHeim, 0o700);

            $ohne = array_map(escapeshellarg(...), [
                self::GPG, '--batch', '--no-tty', '--show-keys', '--with-colons',
                '--homedir', $ohneHeim, $this->keyring(),
            ]);

            $zeilen = [];
            $status = 0;
            exec(implode(' ', $ohne).' 2>/dev/null', $zeilen, $status);

            [$mitStatus, $mit] = $this->invoke($mitHeim, $this->keyring());

            $this->assertSame(0, $status, 'Der Vergleichslauf ist gescheitert — dann vergleicht dieser Wächter nichts.');
            $this->assertNotSame([], $zeilen, 'Der Vergleichslauf hat nichts geliefert — dann vergleicht dieser Wächter zwei leere Listen.');
            $this->assertSame(0, $mitStatus);

            $this->assertSame(implode("\n", $zeilen), $mit, implode("\n", [
                'Die beiden Schalter ändern die Ausgabe von gpg.',
                'Sie dürfen das Schreiben abstellen und nicht die Auskunft.',
            ]));
        } finally {
            foreach ([$ohneHeim, $mitHeim] as $verzeichnis) {
                foreach (glob($verzeichnis.'/*') ?: [] as $rest) {
                    @unlink($rest);
                }

                @rmdir($verzeichnis);
            }
        }
    }
}
