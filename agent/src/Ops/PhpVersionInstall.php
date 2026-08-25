<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Apt;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;

/**
 * Eine PHP-Version installieren.
 *
 * **Kein Freitext erreicht apt.** Der Paketname entsteht aus zwei
 * Positivlisten — der Version aus {@see PhpVersions::CATALOG} und der
 * Erweiterung aus {@see PhpVersions::EXTENSIONS}. `php8.2-mysql` wird gebaut,
 * nicht entgegengenommen. Das ist dieselbe Regel wie überall im Agenten, und
 * sie ist hier besonders wichtig: `apt-get install` mit einem Namen aus einer
 * Anfrage wäre eine Fernsteuerung für beliebige Pakete.
 *
 * **Der Standard-Pool der Distribution wird abgeschaltet.** `phpX.Y-fpm`
 * bringt `www.conf` mit: ein geteilter Pool, der als `www-data` läuft, ohne
 * `open_basedir` und ohne `disable_functions`. Er ist genau das Loch, das P3
 * zumacht — ein Skript darin läge ausserhalb jeder Abschottung. Er wird
 * umbenannt und nicht gelöscht: Wer nachsehen will, was die Distribution
 * vorgesehen hatte, findet es neben der Datei.
 *
 * **Die Unit bleibt danach stehen, wenn es keinen Pool gibt.** Ein PHP-FPM
 * ohne Pool startet nicht — es meldet „no pool defined". Sie zu starten,
 * bevor das erste Abonnement einen Pool hat, ergäbe eine rote Zeile in
 * `systemctl`, die nach einem Fehler aussieht und keiner ist. Der erste
 * `php.pool.apply` startet sie.
 */
final class PhpVersionInstall implements Op
{
    /** Die Endung, unter der der Pool der Distribution weiterlebt. */
    public const DISABLED_SUFFIX = '.srvpanel-disabled';

    public static function name(): string
    {
        return 'php.version.install';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $version = PhpVersions::normalize($args['php_version'] ?? null);

        /*
         * **Gefragt wird der Paketsatz und nicht der Handler.**
         *
         * Hier stand bis zum 9. August 2026 `if (PhpVersions::installed(…))`,
         * also `is_executable('/usr/sbin/php-fpm8.2')`, und darauf ein `return
         * ['already' => true]`. Das ist ein **Stellvertreter**: Geprüft wurde
         * der Handler, gemeint war der Paketsatz. Solange
         * {@see PhpVersions::EXTENSIONS} sich nie ändert, sind die beiden
         * dasselbe — in dem Augenblick, in dem sie sich ändert, gehen sie
         * auseinander, und niemand merkt es, weil die Operation Erfolg meldet.
         *
         * Genau das ist passiert: `pgsql` kam mit P5b dazu, und auf jedem
         * Server, auf dem PHP schon lag, wäre es nie angekommen. Ein Kunde
         * hätte seine PostgreSQL-Datenbank bekommen und keine Verbindung
         * dazu (`docs/38 §24.2`).
         *
         * Diese Operation läuft deshalb auf den gewünschten Satz **zu**, statt
         * auf eine Ja/Nein-Frage zu antworten. Fehlt nichts, ist sie in
         * Millisekunden fertig — und das `already` heisst dann, was es sagt.
         */
        $wanted = PhpVersions::packages($version);
        $packages = $this->missing($context, $wanted);

        if ($packages === []) {
            // Wiederholbar: Der gewünschte Zustand ist schon da. Der Pool der
            // Distribution wird trotzdem geprüft — er kann aus einer
            // Installation von Hand stammen.
            return [
                'php_version' => $version,
                'installed' => true,
                'already' => true,
                'distribution_pool' => $this->disableDistributionPool($version),
                'packages' => [],
                'available' => PhpVersions::available(),
            ];
        }

        // **Erst hier und nicht am Anfang der Operation.** Oben steht ein
        // Ausstieg für den Fall, dass nichts fehlt — der ruft kein apt, und
        // eine Ablehnung dafür wäre eine für einen Lauf, der nie kollidiert
        // hätte.
        AptLock::ensureFree($context);

        $context->progress(10, 'Paketlisten auffrischen');
        $refresh = Apt::refresh($context);

        if (! $refresh->result->successful()) {
            throw AgentException::execFailed('apt-get update ist fehlgeschlagen: '.$refresh->result->message());
        }

        /*
         * **Der Rückgabewert oben ist nicht die Prüfung, sondern ihre Hälfte.**
         *
         * `apt-get update` endet mit 0, auch wenn jede Quelle unerreichbar war
         * — die alten Listen bleiben liegen, und das ist für apt ein
         * benutzbarer Zustand (M5, `docs/81 §2.1`). Was danach geschah, stand
         * bis zum 24. August 2026 so da:
         *
         *   Sury unerreichbar → `apt-get install php8.4-fpm` findet nichts →
         *   Abbruch mit *„Die Installation ist fehlgeschlagen: Unable to locate
         *   package php8.4-fpm"*.
         *
         * Der **Zustand** war damit richtig gemeldet und die **Ursache**
         * falsch: Der Betreiber sucht am Paket, der Fehler sitzt an der Quelle.
         *
         * > **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts
         * > gesagt — und der Leser sucht dort, wohin die Meldung zeigt.**
         *
         * Schlimmer war der stille Fall daneben: Mit einer alten Liste
         * *gelingt* die Installation, und der Kunde bekommt die Fassung von
         * vorletzter Woche, ohne dass irgendwo etwas davon steht.
         *
         * Gefragt werden die Adressen aus der Quelldatei und keine hier
         * hingeschriebene: Debian und Ubuntu bekommen verschiedene, und ein
         * Betreiber darf einen Spiegel eintragen.
         */
        $unreachable = $refresh->hitting(PhpVersions::sourceUris());

        if ($unreachable !== null) {
            throw AgentException::execFailed(
                sprintf(
                    'Die PHP-Paketquelle %s ist nicht erreichbar: %s. Ohne sie kennt apt nur die alten '
                    .'Paketlisten — PHP %s käme veraltet oder gar nicht. Die Installation wurde deshalb '
                    .'nicht begonnen.',
                    $unreachable['base'],
                    $unreachable['reason'],
                    $version,
                ),
                ['source' => $unreachable['base'], 'reason' => $unreachable['reason']],
            );
        }

        $context->progress(30, 'Pakete installieren');
        $install = $context->stream(
            'apt-get',
            array_merge(['install', '-y', '--no-install-recommends'], $packages),
            900,
        );

        if (! $install->successful()) {
            throw AgentException::execFailed(
                'Die Installation ist fehlgeschlagen: '.$install->message()
                // Eine fremde Quelle liefert die PHP-Pakete nicht, kann eine
                // Abhängigkeit aber sehr wohl zurückhalten. Sie steht deshalb
                // dabei — als Hinweis und nicht als Urteil.
                .($refresh->reachedEverything() ? '' : ' Nicht erreichbar war ausserdem: '.$refresh->summary().'.'),
                ['packages' => $packages, 'sources_unreachable' => $refresh->unreachable],
            );
        }

        /*
         * **Gefragt wird der Bestand, nicht noch einmal dieselbe Bedingung.**
         * Oben stand schon eine Frage nach fehlenden Paketen, und für einen
         * Prüfer, der das Dateisystem nicht kennt, kann eine Frage, die eben
         * beantwortet wurde, kein zweites Ergebnis haben — er hielt diesen
         * Wurf für unausweichlich und alles darunter für toten Code.
         * Dazwischen lag `apt-get install`.
         *
         * **Und gefragt wird derselbe Satz und nicht nur der Handler.** Bis
         * P5b stand hier `available()`, also wieder der Stellvertreter: `apt`
         * konnte `php8.2-pgsql` stillschweigend auslassen, und die Prüfung
         * war trotzdem zufrieden, weil `/usr/sbin/php-fpm8.2` dalag.
         */
        $fehlend = $this->missing($context, $wanted);

        if ($fehlend !== []) {
            throw AgentException::execFailed(
                sprintf('apt meldet Erfolg, %s fehlt trotzdem.', implode(', ', $fehlend)),
                ['packages' => $fehlend],
            );
        }

        $context->progress(80, 'Standard-Pool abschalten');
        $disabled = $this->disableDistributionPool($version);

        $context->progress(90, 'Handler übernehmen lassen');
        $restarted = $this->handOver($context, $version);

        $context->progress(100, 'fertig');

        return [
            'php_version' => $version,
            'installed' => true,
            'already' => false,
            'distribution_pool' => $disabled,
            'packages' => $packages,
            'restarted' => $restarted,
            'available' => PhpVersions::available(),
        ];
    }

    /**
     * Welche der gewünschten Pakete auf diesem System fehlen.
     *
     * **Der Rückgabewert von `dpkg-query` wird nicht angesehen**, und das ist
     * kein Versehen: Er ist 1, sobald eines der genannten Pakete unbekannt ist
     * — also genau in dem Fall, für den diese Frage gestellt wird. Die Namen,
     * die dpkg kennt, stehen vollständig auf `stdout`; die unbekannten meldet
     * es auf `stderr`. Wer den Code als Fehlschlag liest, bekommt eine
     * Operation, die immer dann abbricht, wenn sie etwas zu tun hätte.
     *
     * @param  list<string>  $wanted
     * @return list<string>
     */
    private function missing(Context $context, array $wanted): array
    {
        $result = $context->runner->run(
            'dpkg-query',
            array_merge(PhpVersions::DPKG_ARGUMENTS, $wanted),
            30,
        );

        return PhpVersions::missing($wanted, $result->stdout);
    }

    /**
     * Den Handler übernehmen lassen — und was das mit einer Erweiterung zu tun
     * hat.
     *
     * **Ein laufender FPM lädt eine neu installierte Erweiterung nicht von
     * selbst.** Das Paket der Distribution ruft in seinem `postinst`
     * `phpenmod` und stösst über einen dpkg-Trigger einen Neustart an — meistens.
     * „Meistens" ist in diesem Projekt kein Zustand: Bleibt der Neustart aus,
     * ist `pgsql` installiert, im Panel steht „vollständig", und die Website
     * des Kunden bekommt weiter *„could not find driver"*. Ein Fehler, den
     * niemand sucht, weil alles grün aussieht.
     *
     * Deshalb wird hier ausdrücklich neu gestartet, **wenn die Unit läuft** —
     * und nur dann. Das kostet die Anfragen, die in diesem Moment unterwegs
     * sind; die Alternative kostet jede Anfrage danach.
     *
     * Läuft sie nicht, gilt die Regel von vorher: Ohne eigenen Pool bleibt sie
     * stehen (ein FPM ohne Pool meldet „no pool defined" und sähe in
     * `systemctl` nach einem Fehler aus), mit einem wird sie gestartet.
     *
     * @return bool Wurde ein laufender Handler neu gestartet?
     */
    private function handOver(Context $context, string $version): bool
    {
        $unit = PhpVersions::unit($version);

        if (trim($context->runner->run('systemctl', ['is-active', $unit], 15)->stdout) === 'active') {
            $context->runner->run('systemctl', ['restart', $unit], 60);

            return true;
        }

        if (PhpPoolRemove::pools($version) === []) {
            $context->runner->run('systemctl', ['disable', '--now', $unit], 60);
        } else {
            $context->runner->run('systemctl', ['enable', '--now', $unit], 60);
        }

        return false;
    }

    /** @return bool Wurde der Pool der Distribution in diesem Lauf abgeschaltet? */
    private function disableDistributionPool(string $version): bool
    {
        $pool = PhpVersions::distributionPool($version);

        if (! is_file($pool)) {
            return false;
        }

        return rename($pool, $pool.self::DISABLED_SUFFIX);
    }
}
