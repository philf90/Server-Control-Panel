<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\PanelUpdate;

/**
 * Die Positivliste der Protokolle, die das Panel zeigen darf.
 *
 * ## Kein Pfad kommt von aussen
 *
 * Das ist die eine Regel dieser Datei, und sie ist derselbe Satz wie bei
 * {@see Ops\WebLogsTail}: Übergeben wird ein **Schlüssel** aus dieser Liste,
 * nie ein Pfad. Eine Operation „lies diese Datei" mit einem Pfad als Argument
 * wäre der kürzeste Weg von einem angemeldeten Konto zu `/etc/shadow` — und
 * jede nachträgliche Prüfung des Pfades hätte irgendwann eine Lücke.
 *
 * **Auch keine Unit von aussen.** Für das Journal gilt dasselbe: `journalctl
 * -u <was der Benutzer schickt>` gäbe die Ausgabe jedes Dienstes auf diesem
 * Server heraus, und darunter sind welche, die Zugangsdaten protokollieren.
 *
 * ## Warum die Pfade hier stehen und nicht anderswo
 *
 * Drei von ihnen gibt es schon: {@see PanelUpdate::LOG} wird von dort geholt,
 * und die beiden nginx-Protokolle des Panels entstehen in
 * {@see Ops\PanelVhost}, das Protokoll des Agenten in {@see Config}. Sie hier
 * ein zweites Mal hinzuschreiben wäre der Fehler, den dieses Repo am
 * häufigsten macht — eine Zeichenkette, die auf etwas verweist, ohne dass
 * etwas den Bezug prüft.
 *
 * Wo ein Wert als Konstante zu haben ist, wird er geholt. Wo er es nicht ist —
 * weil er in einer Vorlage oder einer Vorgabe steckt —, hält `LogSourceTest`
 * die beiden Seiten gegeneinander.
 *
 * ## Was hier bewusst **nicht** steht
 *
 * Die Protokolle der Kundendomains. Die haben ihre eigene Operation
 * ({@see Ops\WebLogsTail}), weil sie zu einem Abonnement gehören und damit
 * unter die Mandantenklammer fallen. Eine Liste, die beides führte, wäre der
 * Ort, an dem ein Kunde das Protokoll eines anderen bekommt.
 */
final class Logs
{
    /** Eine Datei auf der Platte. */
    public const FILE = 'file';

    /** Das Journal einer systemd-Unit. */
    public const JOURNAL = 'journal';

    /**
     * Die Units, deren Journal gezeigt wird.
     *
     * **Konkrete Namen, keine Muster.** `ServiceAction::ALLOWED_UNITS` führt
     * `srvpanel-*` — das ist richtig, um einen Namen zu *prüfen*, und
     * unbrauchbar, um eine Liste zu *zeigen*: Ein Muster lässt sich nicht
     * aufzählen. Zwei Listen mit verschiedenem Zweck sind hier keine zwei
     * Fassungen derselben Regel.
     *
     * `LogSourceTest` hält jede gegen `packaging/systemd/` — eine Unit, die
     * das Paket nicht ausliefert, gibt es nicht.
     *
     * @var array<string, string> Unit => Beschriftung
     */
    private const UNITS = [
        'srvpanel-web' => 'Weboberfläche',
        'srvpanel-worker' => 'Warteschlange',
        'srvpanel-agentd' => 'Agent',
        'srvpanel-cron' => 'Cron-Einsammler',
        'srvpanel-metrics' => 'Kennzahlen',
        'srvpanel-tls' => 'Zertifikate',
        'srvpanel-dns' => 'DNS-Abgleich',
        'srvpanel-usage' => 'Verbrauch',
    ];

    /**
     * Jede Quelle mit ihrer Art, ihrem Ort und ihrer Beschriftung.
     *
     * Die Beschriftung steht hier und nicht im Panel: Sie gehört zu dem, was
     * die Quelle **ist**, und eine zweite Liste im Panel wäre die, die beim
     * nächsten Eintrag vergessen wird.
     *
     * @return array<string, array{kind: string, label: string, path: null|string, unit: null|string}>
     */
    public static function sources(): array
    {
        $sources = [
            'panel' => self::file('Panel', '/var/lib/srvpanel/storage/logs/laravel.log'),
            'panel-update' => self::file('Update des Panels', PanelUpdate::LOG),
            'packages-upgrade' => self::file('Aktualisierungen installieren', Ops\SystemPackagesUpgrade::LOG),
            'agent' => self::file('Agent (Datei)', Config::DEFAULT_LOG_FILE),
            'panel-error' => self::file('Weboberfläche — Fehler', Ops\PanelVhost::ERROR_LOG),
            'panel-access' => self::file('Weboberfläche — Zugriffe', Ops\PanelVhost::ACCESS_LOG),
            'apt-history' => self::file('Paketverwaltung', '/var/log/apt/history.log'),
            'auth' => self::file('Anmeldungen am Server', '/var/log/auth.log'),
        ];

        foreach (self::UNITS as $unit => $label) {
            $sources['journal-'.substr($unit, strlen('srvpanel-'))] = [
                'kind' => self::JOURNAL,
                'label' => 'Journal: '.$label,
                'path' => null,
                'unit' => $unit,
            ];
        }

        return $sources;
    }

    /**
     * Die Schlüssel, gegen die eine Anfrage geprüft wird.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::sources());
    }

    /**
     * Eine Quelle zu ihrem Schlüssel — oder eine Ablehnung.
     *
     * Der Schlüssel geht durch {@see Guard::enum()} und nicht durch ein
     * Muster: Eine Positivliste kann nicht durchlässig werden, ein Muster
     * schon.
     *
     * @return array{kind: string, label: string, path: null|string, unit: null|string}
     */
    public static function source(mixed $key): array
    {
        return self::sources()[Guard::enum($key, self::keys(), 'source')];
    }

    /**
     * @return array{kind: string, label: string, path: null|string, unit: null|string}
     */
    private static function file(string $label, string $path): array
    {
        return ['kind' => self::FILE, 'label' => $label, 'path' => $path, 'unit' => null];
    }
}
