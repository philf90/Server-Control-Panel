<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Die Eingabeprüfung von `subscription.provision`.
 *
 * **Warum gerade sie so viele Tests bekommt.** Diese Operation läuft als root
 * und ruft `useradd`, `groupadd` und `setquota` auf. Der Name des Abonnements
 * wird zu einem Verzeichnisnamen unterhalb von `/var/www/vhosts`, der
 * Benutzername landet in drei Kommandozeilen. Beides kommt aus dem Panel —
 * also aus einer Anwendung, die nicht als root läuft und genau deshalb nicht
 * die letzte Schranke sein darf.
 *
 * Die Regel dahinter ist die aus `Guard`: geprüft wird gegen eine
 * Positivliste. Wer „gefährliche Zeichen" herausfiltert, hat immer eines
 * vergessen.
 *
 * Die Operation nimmt ausserdem **keinen Pfad** entgegen, sondern baut ihn aus
 * dem geprüften Namen. Ein Ausbruch müsste also schon durch den Namen selbst
 * gehen — und dagegen stehen die Fälle unten.
 */
final class SubscriptionProvisionTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function badNames(): array
    {
        return [
            'Pfadtrenner' => ['beispiel/../etc'],
            'nur Schrägstrich' => ['/etc/passwd'],
            'Punkt am Anfang' => ['.ssh'],
            'zwei Punkte' => ['..'],
            'Punkt Punkt im Namen' => ['a..b'],
            'Bindestrich am Anfang' => ['-beispiel.de'],
            'Bindestrich am Ende' => ['beispiel.de-'],
            'Grossbuchstabe' => ['Beispiel.de'],
            'Leerzeichen' => ['beispiel de'],
            'Tilde' => ['~root'],
            'Dollarzeichen' => ['$(id)'],
            'Semikolon' => ['a;id'],
            'Zeilenumbruch' => ["a\nb"],
            'leer' => [''],
            'zu lang' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('badNames')]
    public function test_a_bad_subscription_name_is_refused(string $name): void
    {
        $this->expectException(AgentException::class);

        SubscriptionProvision::subscriptionName($name);
    }

    /** @return array<string, array{0: string}> */
    public static function goodNames(): array
    {
        return [
            'Domain' => ['beispiel.de'],
            'Subdomain' => ['shop.beispiel.de'],
            'mit Bindestrich' => ['mein-shop.de'],
            'mit Ziffern' => ['shop24.de'],
            'kurz' => ['a'],
            'genau 63' => [str_repeat('a', 63)],
        ];
    }

    #[DataProvider('goodNames')]
    public function test_a_good_subscription_name_passes(string $name): void
    {
        $this->assertSame($name, SubscriptionProvision::subscriptionName($name));
    }

    /** @return array<string, array{0: string}> */
    public static function badUsers(): array
    {
        return [
            // Die drei, um die es wirklich geht: Ein frei wählbarer Name wäre
            // ein Weg, über useradd/usermod ein bestehendes Konto zu berühren.
            'root' => ['root'],
            'www-data' => ['www-data'],
            'das Panel selbst' => ['srvpanel'],

            'ohne p' => ['1001'],
            'zu kurz' => ['p123'],
            'zu lang' => ['p1234567890'],
            'Buchstaben dahinter' => ['p1001x'],
            'Grossbuchstabe' => ['P1001'],
            'mit Bindestrich' => ['p-1001'],
            'Pfad' => ['../root'],
            'leer' => [''],
        ];
    }

    #[DataProvider('badUsers')]
    public function test_a_bad_system_user_is_refused(string $user): void
    {
        $this->expectException(AgentException::class);

        SubscriptionProvision::systemUser($user);
    }

    public function test_a_good_system_user_passes(): void
    {
        $this->assertSame('p1001', SubscriptionProvision::systemUser('p1001'));
        $this->assertSame('p123456789', SubscriptionProvision::systemUser('p123456789'));
    }

    public function test_the_operation_declares_itself_as_changing_the_system(): void
    {
        // Steuert die Protokollierung im Agenten. Eine Operation, die
        // Systembenutzer anlegt und sich als lesend ausgibt, stünde im
        // Journal ohne die Aufmerksamkeit, die sie braucht.
        $this->assertSame('subscription.provision', SubscriptionProvision::name());
        $this->assertTrue(SubscriptionProvision::mutating());
    }

    public function test_the_root_of_all_subscriptions_is_fixed_in_code(): void
    {
        // Sie kommt nicht aus der Anfrage und nicht aus der Konfiguration.
        // Stünde sie in einer Datei, wäre die Frage, wer die Datei schreiben
        // darf — und die Antwort wäre eine weitere Schranke, die stimmen muss.
        $this->assertSame('/var/www/vhosts', SubscriptionProvision::VHOSTS);
    }

    public function test_the_welcome_page_reveals_nothing_about_the_server(): void
    {
        /*
         * Sobald eine Domain hierher zeigt, ist die Seite öffentlich. Ein
         * Platzhalter, auf dem „Abonnement kunde-example.de, Systembenutzer
         * p1003" steht, ist eine Einladung, in der Suchmaschine nach weiteren
         * zu suchen.
         */
        $html = SubscriptionProvision::welcomePage();

        foreach (['p1000', 'vhosts', '/var/www', 'SrvPanel', 'srvpanel'] as $verboten) {
            $this->assertStringNotContainsString($verboten, $html, sprintf(
                'Die Willkommensseite nennt „%s". Sie ist öffentlich, sobald eine Domain hierher zeigt.',
                $verboten,
            ));
        }
    }

    public function test_the_welcome_page_asks_no_one_for_anything(): void
    {
        // Keine Schrift, kein Bild, kein Stylesheet von aussen: Ein
        // Platzhalter, der beim ersten Aufruf eine fremde Adresse kontaktiert,
        // ist ein Platzhalter, der etwas verrät — dem Betreiber der fremden
        // Adresse nämlich, dass es diese Domain gibt.
        $html = SubscriptionProvision::welcomePage();

        $this->assertSame(0, preg_match_all('#https?://#', $html));
        $this->assertSame(0, preg_match_all('/<(script|img|iframe|link)\b/i', $html));

        // Und sie gehört nicht in den Index einer Suchmaschine.
        $this->assertStringContainsString('name="robots" content="noindex"', $html);
    }

    public function test_the_welcome_page_is_written_only_into_an_empty_document_root(): void
    {
        /*
         * **Die Bedingung, unter der diese Operation wiederholbar bleiben
         * darf.** Ein zweiter Lauf — nach einem abgebrochenen Vorgang, nach
         * einer Kontingentänderung — träfe sonst auf eine fertige Webseite und
         * legte eine `index.html` daneben, die vor `index.php` gefunden wird.
         * Der Kunde sähe statt seiner Seite wieder den Platzhalter.
         */
        $root = sys_get_temp_dir().'/srvpanel-doc-'.bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);

        $welcome = new ReflectionMethod(SubscriptionProvision::class, 'welcome');

        $this->assertTrue($welcome->invoke(new SubscriptionProvision, $root, 'root'));
        $this->assertFileExists($root.'/index.html');

        // Der Kunde legt seine eigene Seite ab.
        file_put_contents($root.'/index.php', '<?php echo "meins";');
        unlink($root.'/index.html');

        $this->assertFalse($welcome->invoke(new SubscriptionProvision, $root, 'root'));
        $this->assertFileDoesNotExist($root.'/index.html');

        foreach (glob($root.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($root);
    }
}
