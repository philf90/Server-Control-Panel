<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Cron;

/**
 * Welchen Dateinamen cron in `/etc/cron.d` überhaupt liest.
 *
 * **Diese Klasse gibt es, weil die Regel zwei Seiten hat.** Sie stand seit P6
 * als `preg_match` in {@see CronFile}, also auf der **Schreib**seite: Aus einem
 * Systembenutzer entsteht ein Dateiname, und wenn cron ihn übergeht, läuft der
 * Cronjob des Kunden nie. A6 ist die **Lese**seite derselben Frage — welche
 * Dateien in `/etc/cron.d` cron liest und welche es wortlos übergeht.
 *
 * > **Zwei Fassungen derselben Regel sind zwei, und die zweite ist die, die
 * > veraltet.**
 *
 * Herausgelöst nach dem Vorbild von `App\Support\Net\Cidr`, das aus `Pg\Hba`
 * gehoben wurde, nachdem eine zweite Stelle dieselbe Rechnung brauchte.
 *
 * ## Die Regel selbst, gemessen
 *
 * `docs/60 §10` für die Schreibseite und `docs/81 §2.3t` M3 für die Leseseite:
 * `srvpanel.punkt` und `srvpanel+plus` werden übergangen,
 * `srvpanel_unterstrich` nicht — **und keiner der beiden Fälle steht im
 * Protokoll**. cron nennt einen falschen Eigentümer und unsichere Rechte beim
 * Namen; einen Punkt im Dateinamen nennt es nicht.
 *
 * > **Zwei von drei Fehlern nennt cron beim Namen. Den dritten übergeht es
 * > wortlos, und das ist der, den ein Admin am ehesten macht.**
 *
 * ## Warum `run-parts` hier nicht gefragt wird
 *
 * Für die `cron.*`-**Verzeichnisse** fragt A6 `run-parts --test`, weil das
 * Werkzeug seine Regeln besser kennt als jeder Nachbau. Für `/etc/cron.d` ist
 * es das falsche Werkzeug: `run-parts` hat eigene Regeln, cron hat seine, und
 * beide sind nur zufällig ähnlich.
 *
 * > **Ein Werkzeug, das eine ähnliche Frage beantwortet, beantwortet nicht
 * > dieselbe.**
 */
final class CronName
{
    /**
     * Liest cron eine Datei dieses Namens?
     *
     * Gefragt wird der **Name** und nicht der Pfad: Ein Verzeichnisanteil
     * enthält Schrägstriche, und die erfüllen die Regel nie — ein Aufrufer, der
     * versehentlich den ganzen Pfad übergibt, bekäme sonst wortlos „nein" für
     * jede Datei.
     */
    public static function readable(string $name): bool
    {
        if ($name === '' || str_contains($name, '/')) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9_-]+$/D', $name) === 1;
    }

    /**
     * Warum nicht — oder `null`, wenn cron ihn liest.
     *
     * **Eine geschlossene Grundmenge und kein erklärender Satz.** Der Satz
     * gehört auf die Seite und in ihre Sprache; hier steht der Grund als
     * Schlüssel, damit das Panel ihn übersetzt und nicht durchreicht — dieselbe
     * Naht wie bei `system.diagnose` und `system.ports`.
     */
    public static function reason(string $name): ?string
    {
        if (self::readable($name)) {
            return null;
        }

        return str_contains($name, '.') ? 'dot' : 'character';
    }
}
