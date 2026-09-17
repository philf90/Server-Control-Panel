<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\BackupRestore;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Der Eigentümerwechsel nach dem Auspacken folgt keinem Verweis.
 *
 * ## Der Befund, für den es diesen Wächter gibt
 *
 * Gemessen am 16. September 2026 (`docs/117 §15` M12), bevor eine Zeile
 * entstand:
 *
 * | Griff | der Verweis | sein Ziel |
 * |---|---|---|
 * | `chown()` | bleibt, wie er war | **bekommt den neuen Eigentümer** |
 * | `lchown()` | bekommt ihn | bleibt, wie es war |
 *
 * `Backup\Unpacker` legt Verweise an und prüft ihr Ziel **mit Absicht nicht** —
 * was ein Kunde in seinem eigenen Baum anlegen darf, darf eine
 * Wiederherstellung ihm zurückgeben. Ein `chown -R` danach machte daraus einen
 * Weg nach draussen: ein Verweis auf `/etc/shadow` im Archiv, und nach der
 * Wiederherstellung gehört die Datei dem Kunden.
 *
 * > **Ein Verweis, dessen Ziel man nicht prüft, ist harmlos, solange niemand
 * > ihm folgt — und ein rekursiver Griff folgt ihm, ohne es zu sagen.**
 *
 * ## Warum er einen echten Baum baut
 *
 * Weil die Frage an den Kernel geht und nicht an unseren Quelltext. Ein Wächter,
 * der `lchown` als Wort im Code suchte, wäre grün, sobald es irgendwo steht —
 * und das ist in diesem Repo eine bezahlte Erfahrung.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht.**
 *
 * ## Und warum er seine Frage ohne Rechte stellt
 *
 * Hier stand bis zum 16. September 2026 „Er läuft als root" als benannte
 * Grenze. Der Satz war für diesen Container wahr und für die CI falsch: Dort
 * läuft der Lauf als `runner`, und ein `chown` auf einen **anderen** Benutzer
 * ist einem unprivilegierten Aufrufer verwehrt. Alle vier Fälle waren hier
 * grün und dort rot — und rot für einen Grund, der mit der Regel nichts zu tun
 * hat.
 *
 * > **Ein Wächter, der in einer Umgebung entsteht und nur dort gefahren wird,
 * > hält seine Umgebung für die Regel.**
 *
 * Gefragt wird deshalb, was **jeder** Aufrufer fragen darf. Gemessen am
 * 16. September 2026, als root und als `nobody`, mit identischer Antwort:
 *
 * | Griff auf einen **hängenden** Verweis | root | nobody |
 * |---|---|---|
 * | `chown()` | `false` | `false` |
 * | `lchown()` | `true` | `true` |
 *
 * Das ist dieselbe Unterscheidung wie oben — `chown` löst den Verweis auf,
 * `lchown` nicht —, nur an einem Ziel, das es nicht gibt. Ein
 * Eigentümerwechsel, der ihr folgte, scheiterte daran; einer, der es nicht
 * tut, kommt durch. Die Reichweite misst daneben der **Zähler**: Was der
 * Rundlauf nicht betreten hat, zählt er nicht.
 *
 * ## Was er nicht kann
 *
 * Die Kennung selbst. Dass der Kunde seine Dateien danach wirklich **besitzt**,
 * braucht zwei Identitäten und damit root; die beiden Fälle unten stehen
 * deshalb mit einem Grund daneben. Gemessen wird sie auf einem echten Server —
 * `docs/118` Punkt 4 liest den Eigentümer nach der Wiederherstellung, und seit
 * heute auch den einer Datei, auf die ein Verweis aus dem Baum hinauszeigt.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */
final class BackupRestoreTest extends TestCase
{
    private string $scratch = '';

    /** Ein Benutzer, den es hier gibt und der nicht root ist. */
    private const WER = 'nobody';

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/backup-restore-'.bin2hex(random_bytes(6));
        mkdir($this->scratch.'/baum', 0700, true);
        mkdir($this->scratch.'/draussen', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->scratch));

        parent::tearDown();
    }

    private function uidOf(string $wer): int
    {
        $eintrag = posix_getpwnam($wer);

        $this->assertIsArray($eintrag, sprintf('Den Benutzer %s gibt es hier nicht — dann misst dieser Fall nichts.', $wer));

        return (int) $eintrag['uid'];
    }

    /** Der Name des Kontos, unter dem dieser Lauf läuft — root hier, `runner` in der CI. */
    private function ich(): string
    {
        $eintrag = posix_getpwuid(posix_geteuid());

        $this->assertIsArray($eintrag, 'Das eigene Konto hat keinen Eintrag in der Passwortdatei.');

        return (string) $eintrag['name'];
    }

    /**
     * **Der Fall, um dessentwillen es diesen Wächter gibt.**
     *
     * Ein **hängender** Verweis im Baum. `chown()` löst ihn auf und scheitert
     * an einem Ziel, das es nicht gibt; `lchown()` fasst den Verweis selbst an
     * und kommt durch. Ein Eigentümerwechsel, der dem Verweis folgte, meldete
     * hier also einen Fehlschlag — und genau deshalb ist das Ausbleiben der
     * Ausnahme hier eine Messung und keine Abwesenheit.
     *
     * Die Gegenprobe steht **im selben Fall**: Ohne sie bliebe offen, ob die
     * beiden Griffe in dieser Umgebung überhaupt verschieden antworten. Täten
     * sie es nicht, wäre der Fall grün aus einem Grund, der mit seiner Regel
     * nichts zu tun hat.
     */
    public function test_the_owner_change_never_follows_a_link(): void
    {
        $ich = $this->ich();

        $verweis = $this->scratch.'/baum/haengt';
        symlink($this->scratch.'/gibtsnicht', $verweis);

        $this->assertTrue(is_link($verweis), 'Der Prüfkörper trägt keinen Verweis.');
        $this->assertFileDoesNotExist($this->scratch.'/gibtsnicht', 'Das Ziel gibt es — dann hängt der Verweis nicht.');

        // Die Gegenprobe: In dieser Umgebung antworten die beiden Griffe
        // verschieden. Sie läuft **vor** dem Prüfling, weil sie nichts
        // verändert, was er später misst — beide Griffe setzen denselben
        // Eigentümer, den der Verweis ohnehin schon hat.
        $this->assertFalse(@chown($verweis, $ich), 'chown() kommt hier an einem hängenden Verweis durch — dann misst der Fall darunter nichts.');
        $this->assertTrue(@lchown($verweis, $ich), 'lchown() scheitert hier an einem hängenden Verweis — dann ist der Fall darunter nicht herstellbar.');

        $gezaehlt = BackupRestore::own($this->scratch.'/baum', $ich);

        $this->assertSame(1, $gezaehlt, 'Der Verweis wird nicht gezählt — dann hat der Rundlauf ihn gar nicht gesehen.');
    }

    /**
     * Und der Rundlauf steigt nicht in ein verwiesenes Verzeichnis hinab.
     *
     * Dieselbe Familie, eine Ebene höher: `FOLLOW_SYMLINKS` am Iterator führte
     * ihn aus dem Baum hinaus, und dann träfe `chown()` jede Datei dahinter —
     * ganz ohne dass ein einziger Verweis gechownt würde.
     *
     * Gemessen wird das am **Zähler** und nicht am Eigentümer: Was der Rundlauf
     * nicht betreten hat, zählt er nicht, und diese Zahl darf jeder lesen. Die
     * Gegenprobe legt dieselben drei Einträge in den Baum selbst — ohne sie
     * bliebe offen, ob der Zähler überhaupt zählt, was unter ihm liegt.
     */
    public function test_the_walk_does_not_descend_into_a_linked_directory(): void
    {
        $ich = $this->ich();

        mkdir($this->scratch.'/draussen/unterordner', 0700, true);
        file_put_contents($this->scratch.'/draussen/unterordner/tief.txt', 'gehoert jemand anderem');
        file_put_contents($this->scratch.'/draussen/flach.txt', 'auch');

        symlink($this->scratch.'/draussen', $this->scratch.'/baum/hinaus');

        $this->assertSame(
            1,
            BackupRestore::own($this->scratch.'/baum', $ich),
            'Der Rundlauf zählt mehr als den Verweis — dann ist er in ein verwiesenes Verzeichnis hinabgestiegen.',
        );

        // Die Gegenprobe: dieselben drei Einträge, diesmal im Baum. Zählte der
        // Rundlauf sie auch hier nicht, sagte die Zeile darüber nichts über
        // den Verweis, sondern über den Zähler.
        mkdir($this->scratch.'/baum/drin/unterordner', 0700, true);
        file_put_contents($this->scratch.'/baum/drin/unterordner/tief.txt', 'gehoert dem Kunden');
        file_put_contents($this->scratch.'/baum/drin/flach.txt', 'auch');

        $this->assertSame(
            5,
            BackupRestore::own($this->scratch.'/baum', $ich),
            'Der Zähler zählt nicht, was unter ihm liegt — dann belegt die Zeile darüber nichts.',
        );
    }

    /**
     * **Die Kennung selbst — und dieser Fall braucht root.**
     *
     * Er trägt, was die beiden Fälle darüber nicht können: dass der neue
     * Eigentümer wirklich ankommt, dass die Wurzel aussen vor bleibt, und dass
     * ein Verweis aus dem Baum hinaus sein Ziel **nicht** mitnimmt. Dafür
     * braucht es zwei Identitäten — ein unprivilegierter Aufrufer darf eine
     * Datei niemandem sonst geben.
     *
     * Er ist damit in der CI still. Die Regel, um die es geht, ist es nicht:
     * Sie steht in den beiden Fällen darüber, und die laufen überall.
     */
    public function test_the_new_owner_really_lands(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Nur root darf eine Datei einem anderen Benutzer geben — der Prüfkörper stellt seinen Zustand nicht her.');
        }

        $opfer = $this->scratch.'/draussen/opfer.txt';
        file_put_contents($opfer, 'gehoert root');
        chown($opfer, 'root');

        $verweis = $this->scratch.'/baum/verweis';
        symlink($opfer, $verweis);

        mkdir($this->scratch.'/baum/httpdocs', 0755, true);
        file_put_contents($this->scratch.'/baum/httpdocs/index.php', '<?php');

        // Ohne diese Zeile misst der Fall nichts: Folgte `chown()` dem Verweis
        // hier gar nicht, wäre jedes Ergebnis darunter richtig.
        $this->assertTrue(is_link($verweis), 'Der Prüfkörper trägt keinen Verweis.');

        $gezaehlt = BackupRestore::own($this->scratch.'/baum', self::WER);

        $neu = $this->uidOf(self::WER);

        $this->assertSame($neu, lstat($verweis)['uid'], 'Der Verweis selbst hat den neuen Eigentümer nicht bekommen.');
        $this->assertSame(
            0,
            stat($opfer)['uid'],
            'Der Eigentümerwechsel ist dem Verweis gefolgt — damit gehört eine Datei ausserhalb des Abonnements dem Kunden.',
        );

        $this->assertSame($neu, stat($this->scratch.'/baum/httpdocs')['uid']);
        $this->assertSame($neu, stat($this->scratch.'/baum/httpdocs/index.php')['uid']);

        // Die Wurzel selbst bleibt aussen vor — sie gehört root, und das Schema
        // setzt sie gleich noch einmal.
        $this->assertSame(0, stat($this->scratch.'/baum')['uid']);

        $this->assertSame(3, $gezaehlt, 'Es werden nicht alle drei Einträge gezählt — dann misst der Fall wenig.');
    }

    /**
     * **Die Gegenprobe zu dem Fall darüber, und auch sie braucht root.**
     *
     * Ein `chown()` an derselben Stelle **muss** das Ziel treffen. Täte es das
     * nicht, wäre oben nicht `lchown` der Grund, sondern die Umgebung.
     */
    public function test_a_plain_chown_really_would_have_followed_it(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Nur root darf eine Datei einem anderen Benutzer geben — der Prüfkörper stellt seinen Zustand nicht her.');
        }

        $opfer = $this->scratch.'/draussen/opfer.txt';
        file_put_contents($opfer, 'gehoert root');
        chown($opfer, 'root');

        symlink($opfer, $this->scratch.'/baum/verweis');

        chown($this->scratch.'/baum/verweis', self::WER);

        $this->assertSame(
            $this->uidOf(self::WER),
            stat($opfer)['uid'],
            'Ein gewöhnliches chown() folgt dem Verweis hier nicht — dann misst der Fall darüber etwas anderes als gedacht.',
        );
    }

    /**
     * Das Schema entscheidet die Gruppe — und `httpdocs` ist der Fall.
     *
     * **Der Ausfall von Punkt 4 des Abnahmelaufs** (17. September 2026): Nach
     * einer Wiederherstellung trugen die Dateien unter `httpdocs` die primäre
     * Gruppe des Benutzers statt `www-data`, und der Webserver konnte sie nicht
     * mehr lesen — gemessen `HTTP 403` an einer echten Domain.
     *
     * Dieser Fall fragt die Auskunft, aus der `own()` seine Kennung zieht, und
     * er läuft **ohne Rechte**: Er liest das Schema und fasst keine Datei an.
     */
    public function test_the_scheme_names_a_foreign_group_for_the_document_root(): void
    {
        $this->assertSame(
            ['p1141', 'www-data'],
            SubscriptionProvision::area('httpdocs', 'p1141'),
            'Das Dokumentenverzeichnis gehört nicht mehr www-data — dann kommt der Webserver nicht mehr an die Dateien des Kunden.',
        );

        $this->assertSame(['p1141', 'adm'], SubscriptionProvision::area('logs', 'p1141'));
        $this->assertSame(['root', 'root'], SubscriptionProvision::area('conf', 'p1141'));

        // `%g` löst auf den Benutzer auf — dieselbe Kennung wie ohne Schema,
        // und trotzdem eine Antwort und kein `null`.
        $this->assertSame(['p1141', 'p1141'], SubscriptionProvision::area('tmp', 'p1141'));

        $this->assertNull(
            SubscriptionProvision::area('httpdocs2', 'p1141'),
            'Ein Name, den das Schema nicht führt, bekommt keine Kennung — sonst trüge jedes Kundenverzeichnis eine fremde Gruppe.',
        );
    }

    /**
     * **Die Untergrenze, und sie kommt vor der Wirkung.**
     *
     * Trüge kein Bereich des Schemas eine fremde Gruppe, wäre die ganze Regel
     * gegenstandslos: `own()` dürfte dann überall die Kennung des Benutzers
     * setzen, und der Fall darunter bliebe grün, ohne etwas zu messen.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_at_least_one_area_carries_a_foreign_identity(): void
    {
        $fremd = 0;

        foreach (['httpdocs', 'logs', 'tmp', 'conf', '.ssh', 'mail'] as $teil) {
            $schema = SubscriptionProvision::area($teil, 'p1141');

            $this->assertIsArray($schema, sprintf('Den Bereich %s führt das Schema nicht mehr.', $teil));

            if ($schema !== ['p1141', 'p1141']) {
                $fremd++;
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $fremd,
            'Weniger als drei Bereiche mit fremder Kennung — dann prüft der Fall über die Wirkung eine Regel ohne Gegenstand.',
        );
    }

    /**
     * Und die Wirkung: Der Rundlauf setzt unter `httpdocs` die fremde Gruppe.
     *
     * **Nur als root**, und das ist keine Bequemlichkeit: Ein `chgrp` auf eine
     * Gruppe, der man nicht angehört, ist einem unprivilegierten Aufrufer
     * verwehrt. Der Fall stellt seinen Zustand sonst nicht her — und ihn
     * trotzdem laufen zu lassen hiesse, eine Fähigkeit zu messen statt der
     * Regel.
     *
     * Die Auskunft darüber hält der Fall ohne Rechte; **hier** steht, dass
     * `own()` sie auch benutzt.
     */
    public function test_the_walk_gives_the_document_root_its_own_group(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Ein chgrp auf eine fremde Gruppe braucht root — der Prüfkörper stellt seinen Zustand nicht her.');
        }

        $sippe = posix_getgrnam('www-data');

        $this->assertIsArray($sippe, 'Die Gruppe www-data gibt es hier nicht — dann misst dieser Fall nichts.');

        $ich = $this->ich();
        $eigen = posix_getpwnam($ich);

        $this->assertIsArray($eigen);

        /*
         * **Die Gegenprobe, und sie kommt zuerst.** Wären die beiden Gruppen
         * dieselbe, zeigte der Vergleich darunter auch dann keinen Unterschied,
         * wenn `own()` das Schema gar nicht fragt.
         */
        $this->assertNotSame(
            (int) $eigen['gid'],
            (int) $sippe['gid'],
            'Die eigene Gruppe ist www-data — dann sagt der Vergleich darunter nichts über das Schema.',
        );

        mkdir($this->scratch.'/baum/httpdocs', 0700, true);
        mkdir($this->scratch.'/baum/eigenes', 0700, true);
        file_put_contents($this->scratch.'/baum/httpdocs/seite.html', 'x');
        file_put_contents($this->scratch.'/baum/eigenes/notiz.txt', 'x');

        BackupRestore::own($this->scratch.'/baum', $ich);

        $this->assertSame(
            (int) $sippe['gid'],
            stat($this->scratch.'/baum/httpdocs/seite.html')['gid'],
            'Die Datei unter httpdocs trägt nicht www-data — der Webserver kann sie dann nicht lesen.',
        );

        $this->assertSame(
            (int) $eigen['gid'],
            stat($this->scratch.'/baum/eigenes/notiz.txt')['gid'],
            'Ein Verzeichnis ausserhalb des Schemas hat eine fremde Gruppe bekommen — die Regel greift zu weit.',
        );
    }

    /** Die Zusage von Form A: Die Operation ändert etwas, und sie heisst so. */
    public function test_it_is_a_mutating_operation(): void
    {
        $this->assertTrue(BackupRestore::mutating());
        $this->assertSame('backup.restore', BackupRestore::name());
    }
}
