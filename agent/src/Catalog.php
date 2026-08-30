<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\SftpAccess;

/**
 * Welche systemd-Units dieses Panel betreibt oder braucht.
 *
 * ## Warum es diese Liste gibt
 *
 * Bis zum 30. August 2026 standen Unitnamen in **zehn** Dateien. Der
 * `OverviewController` kannte drei fest verdrahtete, `ServiceAction` vier
 * Muster, und die Paketierung lieferte zwölf. Neun der eigenen zwölf tauchten
 * nirgends auf — `srvpanel-worker` stand still, und die Übersicht zeigte es
 * nicht.
 *
 * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
 * > beiden ist der Ort, an dem man nachsieht.**
 *
 * Derselbe Satz wie bei der doppelten Argumentliste in `Db\Session`, und
 * dieselbe Antwort: eine Stelle.
 *
 * ## Kandidaten und keine Zusagen
 *
 * Die fremden Units stehen hier als **Kandidaten**. `ssh.service` und
 * `sshd.service` sind dieselbe Unit unter zwei Namen, je nach System;
 * `mariadb.service` und `mysql.service` sind zwei Geschmacksrichtungen
 * derselben Aufgabe. Welche davon es gibt, entscheidet nicht diese Liste —
 * `systemctl show` beantwortet eine unbekannte Unit mit `LoadState=not-found`,
 * und {@see Units} macht daraus `present: false`.
 *
 * > **Eine Unit, die es nicht gibt, beantwortet sich selbst.** Der Katalog muss
 * > nicht wissen, was installiert ist — nur, was in Frage kommt.
 *
 * Deshalb steht hier auch keine zweite Erkennung der Geschmacksrichtung neben
 * der in `DbRemoteAccess` und keine zweite Auflösung von `ssh` gegen `sshd`
 * neben der in {@see SftpAccess}. Die Namen für SFTP kommen von dort, wo sie
 * gemessen wurden (`docs/50`); diese Klasse liest sie, statt sie abzuschreiben.
 *
 * ## Was hier nicht steht
 *
 * **Die versionierten Units.** `php8.3-fpm.service` und
 * `postgresql@16-main.service` hängen daran, was installiert ist; ihre Namen
 * baut {@see PhpVersions::unit()} beziehungsweise {@see Pg\Clusters::unit()},
 * und wer sie zeigen will, hängt sie an diese Liste an. Sie hier festzuschreiben
 * hiesse, eine dritte Fassung derselben Regel zu bauen.
 *
 * ## Steuern ist etwas anderes als Zeigen
 *
 * Jeder Eintrag sagt, ob `service.action` ihn anfassen darf. Das ist **keine**
 * zweite Fassung der Positivliste in {@see ServiceAction} — die bleibt dort, wo
 * eine Sicherheitsgrenze hingehört, nämlich an einer Stelle, die man am Stück
 * liest. Hier steht die Absicht, dort die Durchsetzung, und `UnitCatalogTest`
 * hält beide in beiden Richtungen aneinander.
 *
 * > **Eine Liste, die etwas erlaubt, und eine, die etwas zeigt, sind nicht
 * > dieselbe Liste — aber sie dürfen einander nicht widersprechen.**
 *
 * `ssh` darf nie gesteuert werden: Damit liesse sich der Zugang zum Server
 * abschalten. `cron` ebensowenig — dort laufen die Jobs der Kunden.
 */
final class Catalog
{
    /**
     * Die eigenen Units, wie das Paket sie ausliefert.
     *
     * Acht Dienste und vier Timer. Gehalten wird die Liste gegen
     * `packaging/systemd/` — in **beiden** Richtungen, weil ein toter Eintrag
     * sonst genau dann entsteht, wenn jemand eine Unit umbenennt und den neuen
     * Namen nachträgt.
     *
     * @var list<string>
     */
    public const OWN = [
        'srvpanel-agentd.service',
        'srvpanel-web.service',
        'srvpanel-worker.service',
        'srvpanel-metrics.service',
        'srvpanel-usage.service',
        'srvpanel-usage.timer',
        'srvpanel-tls.service',
        'srvpanel-tls.timer',
        'srvpanel-cron.service',
        'srvpanel-cron.timer',
        'srvpanel-dns.service',
        'srvpanel-dns.timer',
    ];

    /**
     * Fremde Units je Aufgabe, und je Unit, ob das Panel sie steuern darf.
     *
     * **Die Flagge sitzt an der Unit und nicht an der Aufgabe**, weil
     * `mariadb.service` steuerbar ist und `mysql.service` nicht. Das ist kein
     * Entwurf, sondern der Bestand: `ServiceAction` führt seit P5 nur MariaDB,
     * und `DbRemoteAccess` startet beide auf einem anderen Weg neu. Die
     * Ungleichheit gehört benannt und nicht stillschweigend beim Umbau
     * geradegezogen — eine Sicherheitsgrenze weitet man auf Ansage und nicht
     * als Nebenwirkung.
     *
     * Eine Methode und keine Konstante, weil die SFTP-Namen von
     * {@see SftpAccess} kommen und eine Konstante keinen Aufruf enthalten darf.
     *
     * @return array<string,array<string,bool>>
     */
    public static function foreign(): array
    {
        return [
            'webserver' => ['nginx.service' => true],
            'database' => ['mariadb.service' => true, 'mysql.service' => false],
            'sftp' => array_fill_keys(SftpAccess::UNITS, false),
            'cron' => ['cron.service' => false, 'crond.service' => false],
        ];
    }

    /**
     * Alle Units, die eine Übersicht zeigen soll — die eigenen zuerst.
     *
     * @return list<array{unit:string,role:string,own:bool,controlled:bool}>
     */
    public static function all(): array
    {
        $rows = [];

        foreach (self::OWN as $unit) {
            $rows[] = ['unit' => $unit, 'role' => 'panel', 'own' => true, 'controlled' => true];
        }

        foreach (self::foreign() as $role => $units) {
            foreach ($units as $unit => $controlled) {
                $rows[] = [
                    'unit' => $unit,
                    'role' => $role,
                    'own' => false,
                    'controlled' => $controlled,
                ];
            }
        }

        return $rows;
    }

    /**
     * Die Units, ohne die gerade nichts geht.
     *
     * Sie sind das, was die Übersicht seit P0 zeigt — bis zum 30. August 2026
     * als drei fest verdrahtete Namen in `OverviewController`. Steht eine
     * davon still, ist das Panel oder das Hosting **jetzt** kaputt: ohne
     * Agenten führt kein Vorgang etwas aus, ohne Webserver antwortet keine
     * Kundenseite, ohne Datenbank keine Anwendung.
     *
     * Die übrigen sechzehn sind darum nicht unwichtig — sie gehören auf die
     * Seite, die A2 baut, und nicht auf die Titelseite. Diese Auswahl ist
     * eine Eigenschaft der Unit und keine der Seite; sonst wüsste eine Klasse
     * des Agenten, welche Ansicht das Panel gerade zeichnet.
     *
     * Zurück kommen **Kandidaten**, je Aufgabe gebündelt: Wer sie zeigt, fragt
     * alle und behält die, die es gibt.
     *
     * @return list<list<string>>
     */
    public static function essential(): array
    {
        $fremde = self::foreign();

        return [
            ['srvpanel-agentd.service'],
            array_keys($fremde['webserver']),
            array_keys($fremde['database']),
        ];
    }

    /**
     * Von mehreren Kandidaten derselben Aufgabe der, den es gibt.
     *
     * `mariadb.service` und `mysql.service` sind zwei Geschmacksrichtungen,
     * `ssh` und `sshd` dieselbe Unit unter zwei Namen. Ungefiltert stünde beides
     * doppelt auf der Seite — gemessen am 30. August: `systemctl show` mit zwei
     * Namen derselben Unit antwortet mit **zwei** Blöcken, die denselben `Id`
     * tragen.
     *
     * Gibt es keinen, gewinnt der erste. Dann meldet die Zeile „nicht
     * installiert" unter dem Namen, den ein Betreiber erwartet, statt unter dem
     * zuletzt geprüften.
     *
     * **Gefragt wird über einen Rückruf und nicht über eine fertige Liste**,
     * damit ein Aufrufer, der jede Antwort einzeln holen muss, nach dem ersten
     * Treffer aufhören kann. Die Regel steht damit einmal da, und beide
     * Aufrufer zahlen nur, was sie brauchen.
     *
     * @param  list<string>  $candidates
     * @param  callable(string):bool  $present
     */
    public static function pick(array $candidates, callable $present): ?string
    {
        foreach ($candidates as $unit) {
            if ($present($unit)) {
                return $unit;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Darf `service.action` diese Unit anfassen?
     *
     * Die Antwort ist eine **Absicht** und keine Durchsetzung: Gefragt wird
     * hier, damit ein Wächter beide Seiten aneinanderhalten kann. Wer wirklich
     * etwas startet, kommt an {@see ServiceAction} vorbei nicht herum.
     */
    public static function controls(string $unit): bool
    {
        foreach (self::all() as $row) {
            if ($row['unit'] === $unit) {
                return $row['controlled'];
            }
        }

        return false;
    }
}
