<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SftpCheck;

/**
 * Beurteilt wird das Verzeichnis, das gilt — nicht das, das gelten soll.
 *
 * **Der Fund aus dem Abnahmelauf** (`docs/59`, Befund 10). Punkt 7 trug
 * oberhalb des verwalteten Bereichs ein `ChrootDirectory /var/www` des
 * Betreibers ein; `sshd -T -C user=p1136` sagte `/var/www`, und die Seite
 * schrieb **„Verzeichnis und Rechte in Ordnung"**. Der Satz war wahr — über
 * `/var/www/vhosts/p6-b.invalid`, also über ein Verzeichnis, das gerade
 * niemand benutzt.
 *
 * > **Eine Kette, die am Sollzustand hängt, sagt nichts über den Zugang, der
 * > gerade nicht ihm folgt.**
 *
 * Gefunden hat es kein Test, sondern der Punkt, der etwas anderes prüfen
 * wollte: Er hat die Abweichung hergestellt, und die Zusage daneben war die
 * Auskunft, die niemand bestellt hatte.
 */
final class SftpCheckTest extends TestCase
{
    private const ROOT = '/var/www/vhosts/p6-b.invalid';

    /** Was der Betreiber setzt, wird beurteilt. */
    public function test_an_override_is_the_directory_that_gets_judged(): void
    {
        $this->assertSame(
            '/var/www',
            SftpCheck::applied(self::ROOT, ['chrootdirectory' => '/var/www']),
            'Ein Eintrag des Betreibers gilt — und was gilt, wird geprüft.',
        );
    }

    /**
     * `none` ist die Abwesenheit einer Angabe und keine andere.
     *
     * Derselbe Wert hat in Punkt 3 die Seite eine fremde Angabe melden lassen
     * (`docs/59`, Befund 3) — und ein `Chain::of('none')` hätte hier „gibt es
     * nicht" gesagt, für einen Server, auf dem alles in Ordnung ist.
     */
    public function test_none_and_nothing_leave_the_subscription_root(): void
    {
        foreach ([['chrootdirectory' => 'none'], ['chrootdirectory' => ''], []] as $effective) {
            $this->assertSame(self::ROOT, SftpCheck::applied(self::ROOT, $effective));
        }
    }

    /**
     * Ein Pfad mit einer Marke darin ist kein Pfad.
     *
     * OpenSSH lässt in `ChrootDirectory` die Marken `%h`, `%u` und `%%` zu, und
     * `sshd -T` gibt sie **unaufgelöst** aus. Ein Urteil über `%h/sftp` wäre
     * „gibt es nicht" — also eine falsche Aussage statt einer fehlenden, und
     * das ist die schlechtere der beiden.
     *
     * > **Ein Pfad mit einer Marke darin ist kein Pfad, und ein Urteil darüber
     * > ist keines.**
     */
    public function test_a_token_is_not_a_path(): void
    {
        foreach (['%h', '%h/sftp', '/srv/%u', 'relativ/pfad', 'none/aber/anders'] as $wert) {
            $this->assertSame(
                self::ROOT,
                SftpCheck::applied(self::ROOT, ['chrootdirectory' => $wert]),
                'Über „'.$wert.'" darf kein Urteil gefällt werden.',
            );
        }

        // Die Gegenprobe: Ein gewöhnlicher absoluter Pfad wird beurteilt.
        // Ohne sie hiesse „alles fällt zurück" auch, dass nie etwas geprüft wird.
        $this->assertSame('/srv/sftp', SftpCheck::applied(self::ROOT, ['chrootdirectory' => '/srv/sftp']));
    }
}
