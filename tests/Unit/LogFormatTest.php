<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Der Name eines Protokollformats zeigt auf eine Erklärung, die es gibt.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Das ist der Fehler, den `CLAUDE.md` als den wiederkehrenden beschreibt: *eine
 * Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein
 * Werkzeug den Bezug prüft.* Hier ist der Preis ungewöhnlich hoch — gemessen
 * am 20. September 2026 gegen nginx 1.24.0:
 *
 * | | |
 * |---|---|
 * | `log_format` im `server`-Block | *„directive is not allowed here"* |
 * | dieselbe Zeile auf http-Ebene | angenommen |
 * | `access_log … <name>;` ohne Erklärung | **`nginx -t` rot** |
 *
 * Der dritte Fall nimmt nicht eine Domain herunter, sondern **den ganzen
 * Webserver**: `nginx -t` urteilt über alle Blöcke zugleich, und ein Verweis
 * ins Leere lässt die Konfiguration als Ganzes durchfallen.
 *
 * > **Ein Verweis auf ein Format, das niemand erklärt hat, ist kein Fehler an
 * > einer Datei, sondern an allen.**
 *
 * ## Und die Reihenfolge trägt mit
 *
 * **Gemessen, und sie hat beim Bauen einmal zugeschlagen:** Stand das
 * `include` der Server-Blöcke **vor** der Erklärung, gab nginx
 * `unknown log format "srvpanel"` — es löst beim Einlesen auf und nicht am
 * Ende. Die erste Fassung von {@see SiteTemplate::httpConfig()} hatte genau
 * diese Reihenfolge.
 *
 * > **Eine Erklärung, die nach ihrem Gebrauch steht, ist keine.**
 *
 * ## Was er nicht kann
 *
 * Er liest Text und startet kein nginx. Dass die **Felder** des Formats das
 * hergeben, was ein Zähler braucht, hält er an ihren Namen fest — ob nginx sie
 * kennt, sagt erst ein Lauf. Der steht in `docs/128` und in
 * `tests/zugriffsprotokoll-messen.sh`.
 */
final class LogFormatTest extends TestCase
{
    /**
     * Die vier Formen, die {@see SiteTemplate::render()} unterscheidet.
     *
     * Wortgleich zu `PromiseReachTest::formArgs()` — und das ist Absicht: Ein
     * Wächter, der nur die Form prüft, an die man gerade denkt, prüft das
     * Erinnerungsvermögen.
     *
     * @return array<string, array<string, mixed>>
     */
    private function formArgs(): array
    {
        $basis = [
            'subscription' => 'beispiel.de',
            'user' => 'p1001',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
            'php_version' => '8.4',
            'certificate' => null,
        ];

        return [
            SiteTemplate::FORM_PHP => $basis,
            SiteTemplate::FORM_STATIC => ['php_version' => null] + $basis,
            SiteTemplate::FORM_SUSPENDED => ['suspended' => true] + $basis,
            SiteTemplate::FORM_REDIRECT => ['redirect_target' => 'https://ziel.de/'] + $basis,
        ];
    }

    /**
     * Die Formatnamen, die ein gerenderter Block nennt.
     *
     * Gesucht wird die **Anweisung** und nicht das Wort: `access_log <pfad>
     * <name>;`. Ein `access_log off;` nennt kein Format und zählt nicht mit.
     *
     * @return list<string>
     */
    private function referenced(string $config): array
    {
        preg_match_all('/^\s*access_log\s+(\S+)\s+([A-Za-z0-9_]+)\s*;/m', $config, $treffer);

        return array_values(array_unique($treffer[2]));
    }

    public function test_every_referenced_log_format_is_declared(): void
    {
        $http = SiteTemplate::httpConfig();
        $gesehen = 0;

        foreach ($this->formArgs() as $form => $args) {
            $config = SiteTemplate::render(Site::fromArgs($args));
            $namen = $this->referenced($config);

            // Eine Form ohne Verweis misst nichts — jede nennt ihr Format.
            $this->assertNotSame([], $namen, sprintf(
                'Die Form %s nennt kein Protokollformat; dann prüft dieser Fall an ihr nichts.',
                $form,
            ));

            foreach ($namen as $name) {
                $gesehen++;

                $this->assertMatchesRegularExpression(
                    '/^\s*log_format\s+'.preg_quote($name, '/').'\s/m',
                    $http,
                    sprintf(
                        'Die Form %s nennt das Protokollformat %s; httpConfig() erklärt es nicht. '
                        .'nginx weist damit die **ganze** Konfiguration ab, nicht nur diese Domain.',
                        $form,
                        $name,
                    ),
                );
            }
        }

        $this->assertGreaterThan(3, $gesehen, 'Es wurden kaum Verweise gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Und die Gegenrichtung: keine Erklärung ohne Gebrauch.
     *
     * Ein Format, das niemand nennt, ist keine Gefahr — aber es ist eine
     * Aussage über den Bestand, die niemand mehr nachprüft.
     */
    public function test_every_declared_log_format_is_used(): void
    {
        preg_match_all('/^\s*log_format\s+([A-Za-z0-9_]+)\s/m', SiteTemplate::httpConfig(), $treffer);

        $this->assertNotSame([], $treffer[1], 'httpConfig() erklärt kein einziges Format.');

        $benutzt = [];

        foreach ($this->formArgs() as $args) {
            $benutzt = array_merge($benutzt, $this->referenced(SiteTemplate::render(Site::fromArgs($args))));
        }

        foreach ($treffer[1] as $name) {
            $this->assertContains($name, $benutzt, sprintf(
                'httpConfig() erklärt das Format %s, und kein Server-Block nennt es.',
                $name,
            ));
        }
    }

    /**
     * Die Erklärung steht vor dem `include` der Server-Blöcke.
     *
     * nginx löst den Formatnamen beim **Einlesen** auf. Steht das `include`
     * davor, sind die Blöcke schon gelesen, wenn die Erklärung kommt — und
     * jeder von ihnen ist dann ein „unknown log format".
     */
    public function test_the_declaration_comes_before_the_include(): void
    {
        $http = SiteTemplate::httpConfig();

        $format = preg_match('/^\s*log_format\s/m', $http, $m, PREG_OFFSET_CAPTURE);
        $include = preg_match('/^\s*include\s/m', $http, $i, PREG_OFFSET_CAPTURE);

        $this->assertSame(1, $format, 'httpConfig() erklärt kein Format — dann prüft dieser Fall nichts.');
        $this->assertSame(1, $include, 'httpConfig() bindet die Server-Blöcke nicht ein — dann prüft dieser Fall nichts.');

        $this->assertLessThan(
            $i[0][1],
            $m[0][1],
            'Das `include` der Server-Blöcke steht vor der Erklärung des Formats. '
            .'nginx löst beim Einlesen auf: Jeder Block wäre ein „unknown log format".',
        );
    }

    /**
     * Das Format trägt die Felder, derentwegen es `combined` ersetzt.
     *
     * Ohne `$bytes_sent` zählt ein wiederkehrender Besucher als nichts
     * (gemessen: `body_bytes_sent` 0 bei einem `304`, während 189 Byte
     * hinausgehen), ohne `$request_length` fehlt die Gegenrichtung. Wer das
     * Format „vereinfacht", nimmt der Messung ihren Gegenstand — und keine
     * Zahl beschwert sich.
     */
    public function test_the_format_carries_what_a_counter_needs(): void
    {
        $http = SiteTemplate::httpConfig();

        foreach (['$bytes_sent', '$request_length', '$status', '$request'] as $feld) {
            $this->assertStringContainsString($feld, $http, sprintf(
                'Dem Protokollformat fehlt %s — damit lässt sich der Verkehr einer Domain nicht zählen.',
                $feld,
            ));
        }
    }
}
