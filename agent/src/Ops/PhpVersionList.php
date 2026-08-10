<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;

/**
 * Welche PHP-Versionen liegen auf diesem Server?
 *
 * **Die Antwort ist der Unterschied zwischen „der Plan erlaubt es" und „der
 * Kunde kann es wählen".** Das Panel rechnet aus drei Mengen: dem Katalog
 * (was das Panel kennt), dem Plan (was der Vertrag hergibt) und dieser hier
 * (was installiert ist). Nur der Schnitt steht im Auswahlfeld; eine Version,
 * die der Plan erlaubt und die hier fehlt, erscheint abgeblendet mit dem
 * Grund — der Kunde sieht damit, dass die Lücke am Server liegt und nicht an
 * seinem Vertrag.
 *
 * Fragt jemand ohne Zutun des Betreibers nach — das Auswahlfeld einer Domain
 * etwa —, kommt die Antwort aus dem Zwischenspeicher des Panels. Diese
 * Operation ist die Quelle, die ihn füllt, und läuft auf der Betreiberseite:
 * beim Öffnen von `/settings/php` und nach jeder Installation.
 */
final class PhpVersionList implements Op
{
    public static function name(): string
    {
        return 'php.versions';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $versions = [];

        foreach (PhpVersions::CATALOG as $version) {
            $installed = PhpVersions::installed($version);

            // Ein Aufruf je Version, nicht zwei: `missing` und `present` sind
            // zwei Hälften derselben Antwort, und zweimal zu fragen hiesse,
            // zwei Antworten zu bekommen, die auseinandergehen können. Der
            // Kommentar unten sagt das — und der erste Wurf tat es trotzdem.
            $packages = $installed
                ? $this->packages($context, $version)
                : ['missing' => [], 'present' => []];

            $versions[] = [
                'version' => $version,
                'installed' => $installed,
                'unit' => PhpVersions::unit($version),
                'active' => $installed && $this->active($context, PhpVersions::unit($version)),
                'pools' => $installed ? count(PhpPoolRemove::pools($version)) : 0,
                'release' => $installed ? $this->release($context, $version) : null,

                /*
                 * **Was einer installierten Version fehlt** (`docs/38 §24.2`).
                 *
                 * Der Anlass: `pgsql` kam mit P5b in
                 * {@see PhpVersions::EXTENSIONS}, und auf jedem Server, auf dem
                 * PHP schon lag, wäre es nie angekommen — `php.version.install`
                 * hielt eine Version für vollständig, sobald ihr Handler dalag.
                 * Ohne diese Zeile bliebe die Lücke unsichtbar: Der Betreiber
                 * sähe „installiert", der Kunde bekäme *„could not find
                 * driver"*.
                 *
                 * **Nur für installierte Versionen.** Bei einer, die es nicht
                 * gibt, fehlt alles — das ist keine Auskunft, sondern
                 * dieselbe wie `installed: false` in einer zweiten
                 * Schreibweise.
                 */
                'missing' => $packages['missing'],

                /*
                 * **Und die andere Hälfte.** „Fehlt: pgsql" sagt, was zu tun
                 * ist, und verschwindet, sobald es getan ist — danach steht
                 * nirgends mehr, was die Version überhaupt kann. Der Betreiber
                 * hat es am 9. August 2026 auf `cloudsrv24` verlangt, und er
                 * hat recht: Eine Zustandsspalte, die nur den Mangel kennt,
                 * ist bei jedem gesunden Zustand leer.
                 *
                 * Beide kommen aus **einem** Aufruf und derselben Auswertung;
                 * zwei Fragen an dpkg wären zwei Antworten, die auseinander
                 * gehen können.
                 */
                'present' => $packages['present'],
            ];
        }

        // `available` steht neben `versions`, weil das Panel genau diese
        // Liste in seinen Zwischenspeicher legt. Sie aus `versions`
        // herauszurechnen wäre dieselbe Aussage an einer zweiten Stelle.
        return ['versions' => $versions, 'available' => PhpVersions::available()];
    }

    /**
     * Die Pakete dieser Version — was fehlt und was da ist, in einem Aufruf.
     *
     * **Dieselbe Frage und dieselbe Auswertung wie in
     * {@see PhpVersionInstall}** — hier lesend, dort handelnd. Sie zweimal
     * verschieden zu stellen wäre der Fall, den `docs/36 §10.3` festhält: Zwei
     * Fassungen einer Regel, und die zweite ist die, die veraltet.
     *
     * **`present` wird gerechnet und nicht gefragt.** Was nicht fehlt, ist da —
     * ein zweiter Durchgang durch die Ausgabe wäre eine zweite Gelegenheit,
     * anders zu antworten als die erste.
     *
     * @return array{missing: list<string>, present: list<string>}
     */
    private function packages(Context $context, string $version): array
    {
        $wanted = PhpVersions::packages($version);

        // Der Rückgabewert bleibt ungelesen — er ist 1, sobald eines der
        // genannten Pakete unbekannt ist, also genau dann, wenn diese Frage
        // etwas zu melden hat. Die Begründung steht bei
        // PhpVersions::missing().
        $result = $context->runner->run(
            'dpkg-query',
            array_merge(PhpVersions::DPKG_ARGUMENTS, $wanted),
            30,
        );

        $missing = PhpVersions::missing($wanted, $result->stdout);

        return [
            'missing' => $missing,
            'present' => array_values(array_diff($wanted, $missing)),
        ];
    }

    private function active(Context $context, string $unit): bool
    {
        return trim($context->runner->run('systemctl', ['is-active', $unit], 15)->stdout) === 'active';
    }

    /**
     * Die genaue Version, wie sie das Paket mitbringt.
     *
     * `php-fpm8.4 -v` schreibt „PHP 8.4.13 (fpm-fcgi) …". Der Betreiber will
     * das sehen: „8.4" sagt nicht, ob die Sicherheitsaktualisierung von letzter
     * Woche schon drauf ist.
     */
    private function release(Context $context, string $version): ?string
    {
        $result = $context->runner->run(PhpVersions::program($version), ['-v'], 15);

        if (preg_match('/^PHP (\d+\.\d+\.\d+)/m', $result->stdout, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
