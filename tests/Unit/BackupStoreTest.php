<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Was nur für Sicherungen gilt — der Rest steht in `DumpAccessTest`.
 *
 * **Die Rechteregel steht hier ausdrücklich nicht.** Sie ist dieselbe wie bei
 * den Dumps, und `DumpAccessTest` führt sie seit P8 über beide Ablagen. Sie
 * hier ein zweites Mal hinzuschreiben wäre die zweite Fassung derselben Regel,
 * und die zweite ist die, die veraltet.
 *
 * Was dieser Wächter hält, hat die Sicherung und der Dump nicht:
 *
 * 1. **Der Ablageort liegt nicht im Raum des Kunden.** Eine Sicherung enthält
 *    den ganzen Baum des Abonnements — läge sie darin, sicherte die nächste
 *    Sicherung die vorige mit, und das Verzeichnis verdoppelte seine Grösse mit
 *    jedem Lauf. Und sie kann den privaten Schlüssel eines hochgeladenen
 *    Zertifikats tragen (`docs/117 §4`), also läge er im Chroot des
 *    SFTP-Zugangs.
 * 2. **Ein Pfad aus einem Verzeichnis bricht nicht aus.** Gehalten an der
 *    Wirkung und in beide Richtungen: Ein gewöhnlicher Pfad kommt durch, ein
 *    `..` fliegt — auch mitten im Pfad, auch mit führendem Schrägstrich.
 * 3. **Rechte reisen als Oktalzahl und kommen als dieselbe zurück.** `0644`
 *    und nicht `420`: Wer die Dezimalzahl in ein `chmod` tippt, setzt `0420`.
 */
final class BackupStoreTest extends TestCase
{
    /**
     * Der Ablageort liegt nicht im Raum des Kunden — gemessen am Pfad.
     *
     * Nicht an einer Zeichenkette im Quelltext, sondern an dem, was
     * `Store::directory()` für ein echtes Abonnement zurückgibt. Ein Wächter,
     * der `'/var/www/vhosts'` im Text von `Store.php` sucht, bliebe grün,
     * sobald jemand die Wurzel über eine Konstante zusammensetzt.
     */
    public function test_a_backup_never_lands_in_the_customers_tree(): void
    {
        $directory = Store::directory('shop');

        $this->assertStringStartsNotWith(
            SubscriptionProvision::VHOSTS,
            $directory,
            'Eine Sicherung im Kundenverzeichnis sichert beim nächsten Lauf sich selbst — '
            .'und ein hochgeladener privater Schlüssel läge im Chroot des SFTP-Zugangs.',
        );

        $this->assertStringStartsWith(Store::ROOT.'/', $directory);
    }

    /** Und die Datei liegt in genau diesem Verzeichnis und nirgends sonst. */
    public function test_the_file_lies_under_the_directory_of_its_subscription(): void
    {
        $this->assertSame(
            Store::directory('shop').'/nacht-20260916.zip',
            Store::path('shop', 'nacht-20260916'),
        );
    }

    /**
     * Ein Name, den niemand von aussen erfindet.
     *
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'leer' => [''];
        yield 'Punkt' => ['nacht.zip'];
        yield 'Schrägstrich' => ['a/b'];
        yield 'Aufstieg' => ['..'];
        yield 'führender Bindestrich' => ['-nacht'];
        yield 'Grossbuchstabe' => ['Nacht'];
    }

    #[DataProvider('badNames')]
    public function test_a_storage_name_comes_from_a_positive_list(string $name): void
    {
        $this->expectException(AgentException::class);

        Store::storageName($name);
    }

    /**
     * Und die Gegenprobe: Ein gewöhnlicher Name kommt durch.
     *
     * Ohne sie belegte die Liste oben nur, dass die Methode wirft — nicht, dass
     * sie zwischen den Fällen unterscheidet.
     */
    public function test_an_ordinary_storage_name_passes(): void
    {
        $this->assertSame('nacht-20260916_1', Store::storageName('nacht-20260916_1'));
    }

    /**
     * Ein Pfad mit `..` kommt nicht in eine Sicherung — an jeder Stelle nicht.
     *
     * @return iterable<string, array{string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'davor' => ['../etc/passwd'];
        yield 'mittendrin' => ['httpdocs/../../etc/passwd'];
        yield 'absolut mit Aufstieg' => ['/httpdocs/../..'];
        yield 'leer' => [''];
        yield 'Punkt' => ['.'];
    }

    #[DataProvider('escapingPaths')]
    public function test_a_path_never_leaves_the_backup(string $path): void
    {
        $this->expectException(AgentException::class);

        Manifest::relative($path);
    }

    /**
     * Und der gewöhnliche Fall kommt durch — ohne führenden Schrägstrich.
     *
     * Der ist die eigentliche Zusage: Ein absoluter Pfad in einem Archiv ist
     * die Einladung, beim Entpacken irgendwohin zu schreiben.
     */
    public function test_an_ordinary_path_loses_its_leading_slash(): void
    {
        $this->assertSame('httpdocs/index.php', Manifest::relative('/httpdocs/index.php'));
        $this->assertSame('httpdocs/index.php', Manifest::relative('httpdocs/index.php'));
    }

    /**
     * Rechte gehen als Oktalzahl hinaus und kommen als dieselbe zurück.
     *
     * **In beide Richtungen und an den Bits gemessen.** `0644` dezimal ist
     * `420`; ein Verzeichnis, das `420` schreibt, verleitet den, der es von
     * Hand liest, zu genau diesem `chmod` — und `0420` ist nicht `0644`.
     */
    public function test_a_mode_survives_the_round_trip_as_an_octal_word(): void
    {
        foreach ([0644, 0600, 0755, 0700, 02755, 0400] as $mode) {
            $written = Manifest::octal($mode);

            $this->assertMatchesRegularExpression('/^[0-7]{4}$/', $written);
            $this->assertSame($mode, Manifest::modeFrom($written), 'Der Rundlauf muss dieselbe Zahl ergeben.');
        }

        // Und die Schreibweise ist die, die ein Mensch erwartet.
        $this->assertSame('0644', Manifest::octal(0644));
    }

    /**
     * Die Art einer Datei gehört nicht in die Rechte.
     *
     * `stat()` liefert in `mode` beides — `0100644` für eine gewöhnliche Datei.
     * Wer das ungefiltert in ein `chmod` gibt, setzt den Wert, den PHP daraus
     * macht, und nicht den, der dastand.
     */
    public function test_the_file_type_bits_never_reach_the_manifest(): void
    {
        $this->assertSame('0644', Manifest::octal(0100644));
        $this->assertSame('0755', Manifest::octal(0040755));
    }

    /**
     * Eine Rechteangabe, die keine ist, wird abgewiesen und nicht auf 0 gedeutet.
     *
     * `octdec()` gibt für Unsinn eine `0` zurück. Eine Datei mit `0000` sähe
     * nach einer gelungenen Wiederherstellung aus, bis jemand sie öffnen will.
     *
     * @return iterable<string, array{string}>
     */
    public static function badModes(): iterable
    {
        yield 'leer' => [''];
        yield 'dezimal' => ['420'];
        yield 'Wort' => ['rw-r--r--'];
        yield 'zu lang' => ['07777777'];
        yield 'Ziffer ausserhalb' => ['0648'];
    }

    #[DataProvider('badModes')]
    public function test_an_unreadable_mode_is_refused(string $value): void
    {
        // `420` ist der gefährliche Fall: Es ist eine gültige Oktalzahl mit drei
        // Stellen und trotzdem falsch gemeint. Es kommt deshalb durch — und die
        // Behauptung hier ist, dass es als `0420` gelesen wird und nicht als
        // `0644`. Wer dezimal schreibt, bekommt dezimal zurück und merkt es.
        if ($value === '420') {
            $this->assertSame(0420, Manifest::modeFrom($value));

            return;
        }

        $this->expectException(AgentException::class);

        Manifest::modeFrom($value);
    }

    /**
     * Ein Verweis ohne Ziel gehört nicht in eine Sicherung.
     *
     * Ihn durchzulassen hiesse, den Fehler beim Entpacken zu finden — also
     * dann, wenn der Kunde schon auf seine Wiederherstellung wartet.
     */
    public function test_a_link_without_a_target_is_refused(): void
    {
        $this->expectException(AgentException::class);

        Manifest::entry('httpdocs/link', Manifest::KIND_LINK, 0777);
    }

    /** Und mit Ziel kommt es durch — sonst prüfte der Fall darüber nichts. */
    public function test_a_link_with_a_target_carries_it(): void
    {
        $entry = Manifest::entry('httpdocs/link', Manifest::KIND_LINK, 0777, '../ziel');

        $this->assertSame('../ziel', $entry['target'] ?? null);
        $this->assertSame(Manifest::KIND_LINK, $entry['kind']);
    }

    /**
     * Eine Sicherung aus einer neueren Fassung wird abgewiesen.
     *
     * Sie teilweise zu lesen hiesse, eine unvollständige Wiederherstellung als
     * vollständige auszugeben — dieselbe Familie wie „eine Anzeige, die zwei
     * verschiedene Zustände gleich aussehen lässt".
     */
    public function test_a_manifest_from_a_newer_format_is_refused(): void
    {
        $json = (string) json_encode([
            'format' => Manifest::FORMAT + 1,
            'subscription' => 'shop',
            'entries' => [],
        ]);

        $this->expectException(AgentException::class);

        Manifest::decode($json);
    }

    /**
     * Und der Rundlauf über das ganze Verzeichnis trägt, was er tragen soll.
     *
     * Der Prüfkörper ist der aus `docs/116` M1b: eine Datei mit eigenen
     * Rechten, ein Verzeichnis, ein Verweis und ein **leeres** Verzeichnis —
     * genau der Fall, den `PharData` fallen lässt.
     */
    public function test_the_round_trip_keeps_every_kind(): void
    {
        $entries = [
            Manifest::entry('httpdocs', Manifest::KIND_DIRECTORY, 0755),
            Manifest::entry('httpdocs/index.php', Manifest::KIND_FILE, 0644),
            Manifest::entry('httpdocs/geheim.env', Manifest::KIND_FILE, 0600),
            Manifest::entry('httpdocs/link', Manifest::KIND_LINK, 0777, '../ziel'),
            Manifest::entry('leer', Manifest::KIND_DIRECTORY, 0750),
        ];

        $read = Manifest::decode(Manifest::encode('shop', 1000, 'xk3f9a', '0.7.4', $entries, ['domains' => []]));

        $this->assertSame($entries, $read['entries'], 'Was hineingeht, kommt heraus — Rechte und Ziel eingeschlossen.');
        $this->assertSame('shop', $read['subscription']);
        $this->assertSame(1000, $read['system_user'], 'Die alte Nummer ist eine Auskunft und keine Anweisung.');
        $this->assertSame('xk3f9a', $read['db_prefix']);
    }
}
