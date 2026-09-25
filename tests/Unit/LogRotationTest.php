<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Ops\WebLogrotate;
use Tests\Support\WithoutHashComments;
use Tests\Support\WithoutPhpComments;

/**
 * Nach der Rotation geht es weiter: nginx schreibt in die neue Datei, und
 * logrotate kann packen, was es gedreht hat.
 *
 * **Der Anlass sind zwei Befunde des B2-Laufs (`docs/134`).** Befund 7: Das
 * `USR1` aus dem postrotate-Abschnitt lässt jeden Arbeiter von nginx die
 * Dateien selbst über den Pfad öffnen, als `www-data` — und in die
 * Protokollverzeichnisse kommt er nicht. Auf `cloudsrv24` schrieb nginx nach
 * jeder Rotation in die umbenannte Datei weiter. Befund 1: php-fpm legte sein
 * Protokoll mit `0600 root:root` in ein Verzeichnis, das logrotate als
 * `srvpanel` dreht; ab der zweiten Rotation stand die ganze Rotation dieser
 * Datei still, mit rc=1 in jeder Nacht.
 *
 * **Die Regeln stehen zwischen Dateien**, und jede war für sich in Ordnung:
 * die Rechte in `SubscriptionProvision` und `postinstall.sh`, die Zeile in
 * `WebLogrotate` und in `packaging/etc/logrotate`, das Ziel in `fpm.conf`. Kein
 * Wächter stand dazwischen.
 *
 * **Gefragt wird, ob ein Arbeiter hineinkommt**, und nicht, ob bestimmte Rechte
 * dastehen. Dass `www-data` nicht in `adm` ist, entscheidet die Distribution;
 * der Wächter nimmt es an, wie es auf Debian, Ubuntu und `cloudsrv24` ist, und
 * `tests/wiederoeffnen-nachbauen.sh` bricht ab, wenn es dort nicht stimmt.
 *
 * **Was er nicht kann: sagen, ob die Zeile wirkt.** Das misst
 * `tests/wiederoeffnen-nachbauen.sh` gegen echtes nginx und echtes systemd. Ein
 * Wächter über den Wortlaut hielte `reload` und `try-reload-or-restart` für
 * gleichwertig — gemessen sind sie es bei angehaltenem nginx nicht.
 */
final class LogRotationTest extends TestCase
{
    use WithoutHashComments;
    use WithoutPhpComments;

    /** Unter diesem Benutzer laufen die Arbeiter von nginx auf Debian und Ubuntu. */
    private const WORKER = 'www-data';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Kommt ein Arbeiter in ein Verzeichnis mit diesen Angaben? */
    private static function workerEnters(string $owner, string $group, int $mode): bool
    {
        if ($owner === self::WORKER) {
            return ($mode & 0o100) !== 0;
        }

        if ($group === self::WORKER) {
            return ($mode & 0o010) !== 0;
        }

        return ($mode & 0o001) !== 0;
    }

    /**
     * Die Abschnitte einer logrotate-Datei: Muster und Rumpf.
     *
     * @return list<array{patterns: list<string>, body: string}>
     */
    private function stanzas(string $conf): array
    {
        preg_match_all('/^([^{}\n]+)\{\n(.*?)^\s*\}/ms', $this->withoutHashComments($conf), $matches, PREG_SET_ORDER);

        $stanzas = [];

        foreach ($matches as $match) {
            $stanzas[] = [
                'patterns' => array_values(array_filter(preg_split('/\s+/', trim($match[1])) ?: [])),
                'body' => $match[2],
            ];
        }

        return $stanzas;
    }

    /**
     * Was im postrotate-Abschnitt eines Rumpfes steht, Zeile für Zeile.
     *
     * @return list<string>
     */
    private static function postrotate(string $body): array
    {
        if (preg_match('/^\s*postrotate\n(.*?)^\s*endscript$/ms', $body, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $match[1])), static fn (string $zeile): bool => $zeile !== ''));
    }

    /**
     * Die Rechte der Protokollverzeichnisse eines Abonnements stehen an einer
     * Stelle — und beide Wege, die sie anlegen, lesen sie dort.
     *
     * Sonst misst dieser Wächter die Konstanten und nicht die Verzeichnisse:
     * Stünde in `TREE` oder in `WebSiteApply` wieder ein eigener Wert, sagte
     * er „kein Arbeiter kommt hinein" über Rechte, die keiner mehr setzt.
     */
    public function test_the_log_directories_of_a_subscription_come_from_one_place(): void
    {
        $tree = (new ReflectionClass(SubscriptionProvision::class))->getReflectionConstant('TREE');
        $this->assertNotFalse($tree, 'SubscriptionProvision führt kein TREE mehr — dieser Wächter liest an der falschen Stelle.');

        $logs = $tree->getValue()['logs'] ?? null;

        $this->assertSame(
            ['%u', SubscriptionProvision::LOG_GROUP, SubscriptionProvision::LOG_MODE],
            $logs,
            'TREE legt logs/ nicht mit LOG_GROUP und LOG_MODE an.',
        );

        $source = $this->withoutComments((string) file_get_contents(self::root().'/agent/src/Ops/WebSiteApply.php'));

        $this->assertMatchesRegularExpression(
            '/Filesystem::directory\(\s*\$site->logDir\(\),\s*\$site->user,\s*SubscriptionProvision::LOG_GROUP,\s*SubscriptionProvision::LOG_MODE,?\s*\)/',
            $source,
            'WebSiteApply legt logs/<domain> nicht mit LOG_GROUP und LOG_MODE an.',
        );
    }

    /**
     * Heute kommt kein Arbeiter in die Protokollverzeichnisse eines
     * Abonnements — und das ist eine Entscheidung und kein Zufall.
     *
     * **Sie zu öffnen hätte Befund 7 auch behoben**, und der Betreiber hat sich
     * am 25. September 2026 dagegen entschieden: Es verschiebt eine Grenze.
     * Wer sie doch öffnet, sieht diesen Fall rot und liest vorher, warum die
     * Rotation neu lädt.
     */
    public function test_the_workers_stay_out_of_a_subscriptions_logs(): void
    {
        $this->assertFalse(
            self::workerEnters('%u', SubscriptionProvision::LOG_GROUP, SubscriptionProvision::LOG_MODE),
            sprintf(
                "Die Protokollverzeichnisse (%s, %04o) lassen %s hinein.\n"
                .'Das hätte Befund 7 behoben und eine Grenze verschoben; entschieden war das Neuladen (docs/134).',
                SubscriptionProvision::LOG_GROUP,
                SubscriptionProvision::LOG_MODE,
                self::WORKER,
            ),
        );
    }

    /**
     * Kommt kein Arbeiter hinein, lädt die Rotation eines Abonnements nginx
     * neu — und schickt kein `USR1`.
     *
     * Gemessen am 25. September 2026 (`tests/wiederoeffnen-nachbauen.sh`): Mit
     * `USR1` und `02750` landet die Anfrage nach der Rotation in
     * `access.log.1`, mit der Zeile der Vorlage in `access.log`.
     */
    public function test_a_subscription_rotation_reloads_nginx(): void
    {
        $stanzas = $this->stanzas(WebLogrotate::template('beispiel.de', 'p1001'));

        $this->assertCount(1, $stanzas, 'Die Vorlage schreibt nicht genau einen Abschnitt — dieser Wächter liest an der falschen Stelle.');

        if (self::workerEnters('%u', SubscriptionProvision::LOG_GROUP, SubscriptionProvision::LOG_MODE)) {
            return;
        }

        $this->assertSame(
            [WebLogrotate::RELOAD],
            self::postrotate($stanzas[0]['body']),
            "Nach der Rotation eines Abonnements steht nicht das Neuladen von nginx.\n"
            .'Die Arbeiter kommen nicht in logs/ und öffnen bei USR1 nichts neu — nginx schriebe in die umbenannte Datei weiter.',
        );
    }

    /**
     * Das Neuladen lässt ein angehaltenes nginx in Ruhe.
     *
     * Gemessen gegen systemd 255: `reload` endet bei angehaltener Unit mit
     * rc=1, und logrotate meldete in jeder Nacht, in der nginx steht, einen
     * Fehler. `try-reload-or-restart` gibt 0 und startet nichts; bei laufender
     * Unit lädt es neu, weil die Unit von nginx ein `ExecReload` hat.
     */
    public function test_the_reload_leaves_a_stopped_nginx_alone(): void
    {
        $this->assertSame(
            ['/usr/bin/systemctl', 'try-reload-or-restart', 'nginx.service'],
            preg_split('/\s+/', WebLogrotate::RELOAD),
            "Die Zeile nach der Rotation ist nicht `systemctl try-reload-or-restart nginx.service`.\n"
            .'`reload` scheitert bei angehaltenem nginx mit rc=1, `reload-or-restart` und `restart` starteten es.',
        );
    }

    /**
     * Das Panel selbst: seine nginx-Protokolle liegen in einem Verzeichnis, in
     * das kein Arbeiter kommt — also lädt auch seine Rotation neu.
     *
     * Bis zum 25. September 2026 stand in `packaging/etc/logrotate` gar kein
     * postrotate-Abschnitt; neu geöffnet wurde nur als Nebenwirkung der
     * Rotation eines Abonnements, und auf `cloudsrv24` scheiterten
     * `panel-access.log` und `panel-error.log` dabei genauso mit `(13)`.
     */
    public function test_the_panel_rotation_reloads_nginx_too(): void
    {
        $directory = dirname(PanelVhost::ACCESS_LOG);
        $this->assertSame($directory, dirname(PanelVhost::ERROR_LOG), 'Die beiden Protokolle der Oberfläche liegen in verschiedenen Verzeichnissen.');

        $postinstall = $this->withoutHashComments((string) file_get_contents(self::root().'/packaging/scripts/postinstall.sh'));
        $found = preg_match('#install -d -o (\S+) -g (\S+) -m (\d+) '.preg_quote($directory, '#').'$#m', $postinstall, $match);
        $this->assertSame(1, $found, "postinstall.sh legt {$directory} nicht an — dieser Wächter liest an der falschen Stelle.");

        if (self::workerEnters($match[1], $match[2], (int) octdec($match[3]))) {
            return;
        }

        $covering = array_values(array_filter(
            $this->stanzas((string) file_get_contents(self::root().'/packaging/etc/logrotate')),
            static fn (array $stanza): bool => array_filter(
                $stanza['patterns'],
                static fn (string $pattern): bool => fnmatch($pattern, PanelVhost::ACCESS_LOG),
            ) !== [],
        ));

        $this->assertCount(1, $covering, 'Nicht genau ein Abschnitt in packaging/etc/logrotate erfasst panel-access.log.');

        $this->assertSame(
            [WebLogrotate::RELOAD],
            self::postrotate($covering[0]['body']),
            "Nach der Rotation der Panel-Protokolle steht nicht das Neuladen von nginx.\n"
            ."{$directory} ist {$match[3]} {$match[1]}:{$match[2]} — die Arbeiter kommen nicht hinein.",
        );
    }

    /**
     * Der Master von php-fpm schreibt ins Journal und nicht in eine Datei,
     * die logrotate nicht packen kann.
     *
     * php-fpm legt sein Protokoll mit `0600 root:root` an (gemessen mit 8.3),
     * und `/var/log/srvpanel` dreht logrotate als `srvpanel`. Nach der ersten
     * Rotation liess sich `fpm.log.1` nicht öffnen, und danach bewegte sich
     * nichts mehr: keine Rotation, kein Packen, rc=1 in jeder Nacht — gemessen
     * über sechs Nächte im Container, gesehen vom 22. bis 25. September auf
     * `cloudsrv24`.
     */
    public function test_the_fpm_master_logs_to_the_journal(): void
    {
        $conf = (string) file_get_contents(self::root().'/packaging/etc/fpm.conf');
        $conf = (string) preg_replace('/^\s*;.*$/m', '', $conf);

        $found = preg_match('/^\[global\]\n(.*?)(?=^\[)/ms', $conf, $global);
        $this->assertSame(1, $found, 'fpm.conf trägt keinen Abschnitt [global] — dieser Wächter liest an der falschen Stelle.');

        preg_match_all('/^\s*error_log\s*=\s*(\S+)\s*$/m', $global[1], $targets);

        $this->assertSame(
            ['syslog'],
            $targets[1],
            "Der Master von php-fpm schreibt nicht ins Journal.\n"
            .'Eine Datei legt er mit 0600 root:root an, und logrotate kommt als srvpanel nicht an sie heran.',
        );
    }

    /**
     * Die Reste von vorher bekommt `srvpanel` — ohne einem Verweis zu folgen.
     *
     * `postinstall.sh` läuft als root in einem Verzeichnis, das `srvpanel`
     * gehört. Ein `chown` ohne `-h` folgte einem Verweis, den der Dienst dort
     * gelegt hätte, und ein `find` ohne `-type f` führte ihn überhaupt erst
     * hin — gemessen mit einem Verweis auf eine Datei mit `0600 root`, die
     * dabei unberührt blieb.
     */
    public function test_the_repair_follows_no_link(): void
    {
        $postinstall = $this->withoutHashComments((string) file_get_contents(self::root().'/packaging/scripts/postinstall.sh'));
        $postinstall = str_replace("\\\n", ' ', $postinstall);

        $found = preg_match_all('#^\s*find /var/log/srvpanel\b.*$#m', $postinstall, $matches);
        $this->assertSame(1, $found, 'postinstall.sh räumt die Reste des alten fpm.log nicht (oder nicht genau einmal) auf.');

        $command = $matches[0][0];

        $this->assertStringContainsString("-name 'fpm.log*'", $command, 'Die Reparatur fasst mehr an als die Reste von fpm.log.');
        $this->assertMatchesRegularExpression('/\s-type f\s/', $command, 'Die Reparatur sucht nicht nur Dateien — ein Verweis käme mit.');
        $this->assertMatchesRegularExpression('/-exec chown -h\s/', $command, 'Die Reparatur folgt mit chown einem Verweis.');
        $this->assertDoesNotMatchRegularExpression('/\s-(L|follow)\b/', $command, 'find folgt Verweisen.');
    }
}
