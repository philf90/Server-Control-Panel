<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\NginxApply;
use SrvPanel\Agent\Op;

/**
 * Die Rotation der Protokolle eines Abonnements.
 *
 * **Ohne sie füllt das Zugriffsprotokoll die Quota des Kunden.** Die
 * Protokolle liegen nach §4.5 im Verzeichnis des Abonnements und zählen damit
 * auf seinen Speicherplatz. Eine Website mit ein wenig Verkehr schreibt im
 * Monat mehrere Gigabyte — und der Kunde bekäme eine volle Quota für Dateien,
 * die er nie angelegt hat und nicht löschen würde.
 *
 * **Eine Datei je Abonnement, nicht je Domain.** Der Ausdruck deckt alle
 * Domains ab, auch die, die es morgen gibt: `logs/&#42;/&#42;.log`. Eine Datei je
 * Domain hiesse, dass eine vergessene Domain nicht rotiert — und das fiele
 * erst auf, wenn die Quota voll ist.
 *
 * **Der `postrotate`-Abschnitt enthält keinen einzigen übergebenen Wert.** Er
 * ist ein Shell-Fragment, das als root läuft; alles Veränderliche steht im
 * Pfad darüber, und der ist aus dem geprüften Namen des Abonnements gebaut.
 *
 * **Hier stand einmal `nocreate` neben `create`.** Gemessen am 20. September
 * 2026 gegen logrotate 3.21.0 (`docs/128` M4): Die neue Datei entsteht mit
 * `-rw-r----- <benutzer>:adm`, also nach `create`. Die Zeile darüber tat
 * nichts — und eine Zeile, die nichts tut, liest der Nächste als Begründung.
 *
 * > **Zwei Anweisungen, die einander widersprechen, sind keine Vorsicht — eine
 * > von beiden ist tot, und man sieht ihr nicht an, welche.**
 *
 * **Nach der Rotation wird nginx neu geladen und nicht bloss angestossen.**
 * Hier stand `systemctl kill --signal=USR1 nginx.service`, und das hat auf
 * `cloudsrv24` in keiner gemessenen Nacht gewirkt (Befund 7 des B2-Laufs,
 * `docs/134`). Beim `USR1` öffnet der Master die Dateien neu und danach jeder
 * Arbeiter **selbst, über den Pfad** — als `www-data`. Die Verzeichnisse
 * `logs/` und `logs/<domain>` tragen aber `<benutzer>:adm 02750`
 * ({@see SubscriptionProvision::LOG_MODE}), und `www-data` ist weder Eigentümer
 * noch in `adm`. Jeder Arbeiter meldete `(13: Permission denied)`, behielt die
 * umbenannte Datei und schrieb bis zum nächsten Neustart in `access.log.1`;
 * das neue `access.log` blieb leer, und `notifempty` drehte es nie wieder.
 *
 * Beim Neuladen öffnet der Master die Dateien als root, und die neuen Arbeiter
 * erben sie. Nachgebaut am 25. September 2026 mit nginx 1.24.0 und systemd 255
 * gegen diese Vorlage, die Zeile wörtlich ausgeführt
 * (`tests/wiederoeffnen-nachbauen.sh`): Mit `USR1` landet die Anfrage nach der
 * Rotation in `access.log.1`, mit dem Neuladen in `access.log` — und mit
 * `0755` statt `02750` trägt auch `USR1`. Die Ursache ist das Verzeichnis.
 *
 * **Der Preis ist ein Neuladen je Abonnement und Nacht**, und nur, wenn etwas
 * gedreht wurde: `sharedscripts` führt den Abschnitt einmal je Datei aus und
 * gar nicht, wenn `notifempty` alles übersprungen hat. Die Verzeichnisse für
 * `www-data` zu öffnen hätte die Ursache behoben und dafür eine Grenze
 * verschoben; entschieden hat der Betreiber am 25. September das Neuladen.
 *
 * **Und die neue Datei bleibt, wie `create` sie anlegt** — `<benutzer>:adm
 * 0640`. Beim `USR1` hatte der Master sie auf `www-data` umgeschrieben; wer
 * nach der Behebung `ls -l` liest, sieht den Benutzer des Abonnements.
 */
final class WebLogrotate implements Op
{
    public const DIRECTORY = '/etc/logrotate.d';

    /**
     * Was nach der Rotation läuft.
     *
     * **`try-reload-or-restart` und nicht `reload`**, gemessen an einer
     * angehaltenen Unit (systemd 255): `reload` endet dort mit rc=1 — „is not
     * active, cannot reload" —, und logrotate meldete in jeder Nacht, in der
     * nginx steht, einen Fehler. `try-reload-or-restart` tut dann nichts, gibt
     * 0 und startet nichts, genau wie die `USR1`-Zeile davor. Bei laufender
     * Unit lädt es neu: `SIGHUP` an denselben Master. Neu gestartet würde nur
     * eine Unit ohne `ExecReload`, und die von nginx hat eine.
     *
     * **Eine Konstante und kein Wortlaut in der Vorlage**, weil zwei Nachbauten
     * sie ersetzen müssen — die Zeile ruft systemd, und im Container ist es
     * nicht PID 1. Ein `sed` über einen abgeschriebenen Wortlaut meldet Erfolg,
     * auch wenn er nichts mehr trifft; die Nachbauten lesen die Zeile deshalb
     * hier und prüfen, dass sie genau einmal ersetzt ist.
     */
    public const RELOAD = '/usr/bin/systemctl try-reload-or-restart nginx.service';

    public static function name(): string
    {
        return 'web.logrotate.apply';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);

        $path = self::DIRECTORY.'/srvpanel-'.$subscription;

        if (! is_dir(self::DIRECTORY)) {
            // logrotate fehlt auf schlanken Installationen. Das ist kein Grund
            // zum Abbruch des Vorgangs, der die Website anlegt — aber der
            // Betreiber soll es erfahren.
            return ['path' => $path, 'written' => false, 'reason' => 'logrotate ist nicht installiert.'];
        }

        NginxApply::write($path, self::template($subscription, $user));

        return ['path' => $path, 'written' => true, 'reason' => null];
    }

    public static function template(string $subscription, string $user): string
    {
        $root = SubscriptionProvision::VHOSTS.'/'.$subscription;
        $reload = self::RELOAD;

        return <<<CONF
        # Von srvpanel-agentd erzeugt. Änderungen von Hand werden beim nächsten
        # Lauf überschrieben.

        {$root}/logs/*/*.log {$root}/logs/*.log {
            daily
            rotate 14
            missingok
            notifempty
            compress
            delaycompress
            sharedscripts

            # Ohne `su` weigert sich logrotate, in ein Verzeichnis zu
            # schreiben, das einem Nutzer gehört und für die Gruppe schreibbar
            # ist — eine Vorsichtsmassnahme gegen untergeschobene Verweise.
            su root adm

            create 0640 {$user} adm

            # Neu laden und nicht USR1: Die Arbeiter von nginx laufen als
            # www-data und kommen nicht in diese Verzeichnisse. Beim Neuladen
            # öffnet der Master die Dateien, und die neuen Arbeiter erben sie.
            postrotate
                {$reload}
            endscript
        }

        CONF;
    }
}
