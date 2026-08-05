<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\WelcomePage;

/**
 * Die Seite, die eine eingerichtete Domain zeigt, solange nichts darin liegt.
 *
 * **Sie ist öffentlich, und das ist der Grund für zwei dieser Prüfungen.**
 * Sobald eine Domain auf das Verzeichnis zeigt, kann sie jeder abrufen — was
 * darin über den Server steht, steht damit im Netz, und was sie von aussen
 * nachlädt, verrät einem Dritten, dass es diese Domain gibt.
 *
 * **Die dritte ist die Bedingung, unter der die aufrufenden Operationen
 * wiederholbar bleiben dürfen.** Geschrieben wird nur in ein leeres
 * Verzeichnis; sonst legte ein zweiter Lauf eine `index.html` neben die Seite
 * des Kunden, die vor `index.php` gefunden wird.
 */
final class WelcomePageTest extends TestCase
{
    public function test_the_welcome_page_reveals_nothing_about_the_server(): void
    {
        $html = WelcomePage::html('httpdocs');

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
        $html = WelcomePage::html('httpdocs');

        $this->assertSame(0, preg_match_all('#https?://#', $html));
        $this->assertSame(0, preg_match_all('/<(script|img|iframe|link)\b/i', $html));

        // Und sie gehört nicht in den Index einer Suchmaschine.
        $this->assertStringContainsString('name="robots" content="noindex"', $html);
    }

    /**
     * Das genannte Verzeichnis ist das, in dem die Dateien liegen.
     *
     * Der Name stand als Wort im Text, solange nur das erste DocumentRoot eine
     * Seite bekam. Eine zweite Domain liegt woanders — ein Hinweis auf
     * `httpdocs`, das es für sie nicht gibt, schickt den Kunden ins Leere.
     */
    public function test_the_page_names_the_directory_it_was_written_into(): void
    {
        $this->assertStringContainsString('<code>httpdocs</code>', WelcomePage::html('httpdocs'));
        $this->assertStringContainsString('<code>beispiel.de</code>', WelcomePage::html('beispiel.de'));

        // Und was hineingereicht wird, bleibt Text: Der Verzeichnisname kommt
        // aus dem Bestand, aber diese Seite ist der falsche Ort, um darauf zu
        // vertrauen.
        $this->assertStringNotContainsString('<b>', WelcomePage::html('<b>'));
    }

    /**
     * Wer ein DocumentRoot anlegt, legt auch eine Seite hinein.
     *
     * **Das ist die Regel, deren Fehlen erst auf dem Server auffiel.** Die
     * Seite entstand in `subscription.provision`, und `web.site.apply` legte
     * für jede weitere Domain ein leeres Verzeichnis an — nginx antwortet
     * darauf mit „403 Forbidden". Beide Operationen legen Verzeichnisse an,
     * und nur eine schrieb hinein; nichts hielt die beiden zusammen.
     *
     * Geprüft wird am Quelltext, weil der Weg dahin nicht prüfbar ist: Ein
     * echter Lauf von `web.site.apply` verlangt `/var/www/vhosts`, einen
     * Systembenutzer und einen FPM-Pool. Was hier zählt, ist die Zeigerichtung
     * — dieselbe Sorte Wächter wie `AgentOperationReachTest`.
     */
    public function test_every_operation_that_creates_a_document_root_writes_the_page(): void
    {
        $ops = ['SubscriptionProvision.php', 'WebSiteApply.php'];
        $directory = dirname(__DIR__, 2).'/agent/src/Ops/';

        foreach ($ops as $file) {
            $source = (string) file_get_contents($directory.$file);

            $this->assertStringContainsString('WelcomePage::into(', $source, sprintf(
                '%s legt ein DocumentRoot an und schreibt keine Seite hinein. '.
                'Eine leere Domain antwortet dann mit „403 Forbidden" — „du darfst nicht" '.
                'statt „hier ist noch nichts".',
                $file,
            ));
        }

        // Die Untergrenze: Ein Ausdruck, der nichts liest, ist kein Wächter.
        $this->assertCount(2, $ops);
    }

    public function test_the_welcome_page_is_written_only_into_an_empty_document_root(): void
    {
        $root = sys_get_temp_dir().'/srvpanel-doc-'.bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);

        $this->assertTrue(WelcomePage::into($root, 'root'));
        $this->assertFileExists($root.'/index.html');

        // Der Kunde legt seine eigene Seite ab.
        file_put_contents($root.'/index.php', '<?php echo "meins";');
        unlink($root.'/index.html');

        $this->assertFalse(WelcomePage::into($root, 'root'));
        $this->assertFileDoesNotExist($root.'/index.html');

        foreach (glob($root.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($root);
    }
}
