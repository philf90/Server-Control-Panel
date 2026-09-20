<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ApplyVhost;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Site;

/**
 * Sucht das Messmittel dort, wo die Zugriffsprotokolle wirklich liegen?
 *
 * `tests/plattenkurve-messen.sh` findet die Protokolle mit `find` über eine
 * feste Tiefe und einen festen Namen. Das ist eine **Kopie** der Regel, die
 * {@see Site::accessLog()} aufstellt — und eine Kopie, die niemand nachzieht,
 * ist der häufigste Fehler dieses Repositorys. Er steht so schon im Kopf von
 * {@see ApplyVhost}: die ausgelieferte Datei ist eine Kopie der Vorlage, und
 * nach einem Update bringt sie niemand nach.
 *
 * **Was der Fehler hier anrichtet, ist schlimmer als ein Absturz.** Zieht der
 * Pfad um, findet `find` null Dateien. Das Skript bricht nicht ab — es misst
 * einen Tag lang eine Kurve, in der die Protokolle nicht vorkommen, und liefert
 * eine flache Linie ab. Eine flache Linie sieht aus wie ein Ergebnis.
 *
 * > **Ein Messmittel, das am falschen Ort sucht, meldet keinen Fehler, sondern
 * > eine Null — und eine Null liest sich wie eine Messung.**
 *
 * Geprüft wird deshalb beides gegeneinander und keines gegen eine Zahl im
 * Test: Die erwartete Tiefe wird aus einem echten {@see Site} ausgerechnet,
 * der erwartete Name aus demselben Pfad genommen.
 */
final class DiskCurveLayoutTest extends TestCase
{
    private const SKRIPT = __DIR__.'/../plattenkurve-messen.sh';

    private function skript(): string
    {
        $inhalt = file_get_contents(self::SKRIPT);

        $this->assertIsString($inhalt, 'Das Messmittel ist nicht lesbar.');

        return $inhalt;
    }

    /**
     * Der Pfad eines echten Zugriffsprotokolls, relativ zur Protokollwurzel.
     *
     * @return list<string> die Bestandteile, z. B. ['abo', 'logs', 'domain', 'access.log']
     */
    private function teile(): array
    {
        $site = Site::fromArgs([
            'subscription' => 'beispiel.de',
            'user' => 'p1001',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
        ]);

        $unter = substr($site->accessLog(), strlen(SubscriptionProvision::VHOSTS) + 1);

        return explode('/', $unter);
    }

    public function test_the_instrument_looks_at_the_real_vhost_root(): void
    {
        $this->assertStringContainsString(
            'VHOSTS=${VHOSTS:-'.SubscriptionProvision::VHOSTS.'}',
            $this->skript(),
            'Die Protokollwurzel des Messmittels ist nicht die des Agenten.',
        );
    }

    public function test_the_instrument_looks_at_the_real_depth(): void
    {
        $tiefe = count($this->teile());

        preg_match_all('/-mindepth (\d+) -maxdepth (\d+)/', $this->skript(), $treffer, PREG_SET_ORDER);

        $this->assertNotEmpty($treffer, 'Das Messmittel sucht ohne Tiefenangabe — es liefe über den ganzen Baum.');

        foreach ($treffer as $satz) {
            $this->assertSame((string) $tiefe, $satz[1], 'mindepth trifft die Protokolle nicht.');
            $this->assertSame((string) $tiefe, $satz[2], 'maxdepth trifft die Protokolle nicht.');
        }
    }

    public function test_the_instrument_looks_for_the_real_file(): void
    {
        $teile = $this->teile();
        $name = end($teile);

        preg_match_all('/-name (\S+)/', $this->skript(), $treffer);

        $this->assertNotEmpty($treffer[1], 'Das Messmittel sucht ohne Namen.');

        foreach ($treffer[1] as $gesucht) {
            $this->assertSame($name, $gesucht, 'Das Messmittel sucht eine Datei, die so nicht heisst.');
        }
    }

    /**
     * **Und es muss sagen, wenn es nichts gefunden hat.**
     *
     * Die drei Prüfungen darüber halten die Kopie auf dem Laufenden. Sie
     * greifen aber nur bei einem Umzug im Code — nicht auf einem Server, auf
     * dem schlicht noch keine Domain steht. Auch dort ist die Null keine
     * Messung, und auch dort muss sie sich als Null zu erkennen geben.
     */
    public function test_the_instrument_says_when_it_finds_nothing(): void
    {
        $this->assertStringContainsString(
            'KEINE Zugriffsprotokolle gefunden',
            $this->skript(),
            'Ohne diesen Satz sähe ein Server ohne Domains aus wie eine ruhige Platte.',
        );
    }
}
