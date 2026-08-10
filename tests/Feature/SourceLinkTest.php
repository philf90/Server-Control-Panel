<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Panel\Release;
use App\Support\Panel\Source;
use Tests\TestCase;

/**
 * Der Quelltext-Link zeigt auf den Stand, der läuft.
 *
 * **Die Auflage steht nicht im Plan, sondern in der Lizenz.** Abschnitt 13 der
 * AGPL verlangt, dass wer die Software über das Netz benutzt, an ihren
 * Quelltext kommt — an den der laufenden Version. Genau so steht die Begründung
 * über `config('srvpanel.source')`, und genau das war nicht eingelöst: Der Link
 * hing an `SRVPANEL_COMMIT`, und diese Variable setzt niemand.
 *
 * **Es ist der zweite Fund derselben Bauart an einem Tag.** Der erste war
 * `SRVPANEL_VERSION` mit `0.1.0-dev` als Vorgabe ({@see ReleaseVersionTest});
 * beide Male las eine Zeile eine Umgebungsvariable, die es nirgends gibt, und
 * beide Male sah das Ergebnis aus wie eine Auskunft. Der Unterschied: Diese
 * Stelle fiel nur auf, weil nach der ersten *gesucht* wurde.
 *
 * **Geprüft wird die Wahl und nicht das Ergebnis eines Servers.** Welchen Wert
 * `config('app.version')` gerade hat, hängt davon ab, wo die Anwendung liegt —
 * ein Test dagegen prüfte die Umgebung des Prüfers. Geprüft wird deshalb, dass
 * die Wahl im Server steht und was sie in den drei Fällen entscheidet.
 */
final class SourceLinkTest extends TestCase
{
    private const REPOSITORY = 'https://example.test/srvpanel';

    /**
     * Ein gesetzter Commit gewinnt — er ist der genauere Verweis.
     */
    public function test_a_commit_wins_when_it_is_set(): void
    {
        $this->assertSame(
            self::REPOSITORY.'/tree/abc1234',
            Source::of(self::REPOSITORY, 'abc1234', '0.5.1-rc.3'),
        );
    }

    /**
     * Ohne Commit trägt der Tag der Freigabe — und der hat ein `v`.
     *
     * **Das ist der Fall, den es auf jedem echten Server gibt.** Kein
     * Freigabelauf setzt den Commit; ohne diese Zeile zeigte die Fusszeile
     * überall auf `main`.
     */
    public function test_without_a_commit_the_release_tag_carries_it(): void
    {
        $version = Release::of('/opt/srvpanel/releases/0.5.1-rc.3');

        $this->assertSame(
            self::REPOSITORY.'/tree/v0.5.1-rc.3',
            Source::of(self::REPOSITORY, '', $version),
        );
    }

    /**
     * Und im Quellbaum steht das Repository — kein Verweis ins Leere.
     *
     * Hier läuft der Test, also ist `base_path()` der Quellbaum und
     * {@see Release::version()} liefert {@see Release::UNRELEASED}. Ein
     * `tree/vQuellbaum` wäre ein toter Link, und ein toter Link löst keine
     * Auflage ein.
     */
    public function test_the_source_tree_gets_the_repository_itself(): void
    {
        $this->assertSame(
            self::REPOSITORY,
            Source::of(self::REPOSITORY.'/', '', Release::UNRELEASED),
        );

        // Und derselbe Fall am echten Weg: Hier läuft der Test, also ist
        // base_path() der Quellbaum — die Fusszeile zeigt aufs Repository.
        config(['srvpanel.source.repository' => self::REPOSITORY, 'srvpanel.source.commit' => '']);

        $this->assertSame(self::REPOSITORY, Source::url());
    }

    /**
     * Die Wahl steht im Server und nicht in der Vorlage.
     *
     * **Die eigentliche Regel.** Solange die Vorlage aus `commit` und
     * `repository` selbst eine Adresse baut, gibt es die Entscheidung zweimal —
     * und die Fassung im Template ist die, die beim nächsten Umbau stehen
     * bleibt. Sie bekommt eine fertige Adresse.
     */
    public function test_the_template_only_shows_what_the_server_decided(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/PanelLayout.vue');

        $this->assertStringContainsString(
            ':href="source.url"',
            $layout,
            'Die Fusszeile verlinkt nicht mehr die Adresse, die der Server entschieden hat.',
        );

        $this->assertStringNotContainsString(
            'source.commit',
            $layout,
            'Die Vorlage baut die Adresse wieder selbst zusammen. Damit steht die Regel zweimal '.
            'im Code, und die Fassung im Template ist die, die veraltet.',
        );
    }
}
