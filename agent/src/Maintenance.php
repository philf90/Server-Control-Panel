<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Acme\HttpChallenge;

/**
 * Der Wartungsmodus — die Wache im Server-Block und die Datei, die sie schaltet
 * (A12, `docs/101 §3`).
 *
 * ## Eine Datei und kein Rundlauf
 *
 * Geschaltet wird das Anlegen und Löschen **einer** Datei; die Vhost-Dateien
 * bleiben unberührt. Damit gibt es keine Warteschlange, keine halb umgestellten
 * Domains und keinen Zustand, der zwischen zwei Domains auseinanderlaufen kann.
 *
 * Der Block weiss dabei **nicht**, ob Wartung ist — er weiss nur, was er sagen
 * soll, falls sie es ist. Die Entscheidung trifft nginx bei jeder Anfrage neu.
 *
 * ## Warum diese Form und keine kürzere
 *
 * Jede Zeile ist gemessen (`docs/81 §2.3p`), und die naheliegenden Fassungen
 * sind beide falsch:
 *
 * **M24 — ein `if` auf Serverebene ohne Ausnahme nimmt die Prüfadresse mit.**
 * Es läuft in der Rewrite-Phase, also **vor** der Auswahl der `location`; die
 * eigene `location ^~` von {@see HttpChallenge} kommt gar nicht zum Zug.
 * Während einer Wartung stürbe jede Zertifikatserneuerung, lautlos.
 *
 * **M25 — ein `if` in `location /` deckt PHP nicht ab.** `location ~ \.php$`
 * steht **innerhalb** von `location /`, und ein `if` der äusseren gilt für die
 * innere nicht: statische Dateien 503, PHP weiter bedient.
 *
 * **M26 — und `nginx -t` unterscheidet die beiden nicht**, beide geben `rc=0`.
 * Der Sollzustand gehört deshalb in die Zusage der Vorlage
 * ({@see SiteTemplate::PROMISED_BY_FORM}) und nicht in den Prüfer.
 *
 * > **Ein Prüfer, der beide Fassungen für gültig hält, sagt über die Wirkung
 * > nichts — und die kaputte ist die, die man zuerst schreibt.**
 *
 * **M27 — `add_header` ist in einem `if` auf Serverebene nicht erlaubt**
 * (`nginx -t` gibt `rc=1`). Deshalb die benannte Fehler-`location`: Sie trägt
 * Rumpf und Header, und `error_page` schickt die Anfrage dorthin.
 *
 * ## Was der Ablageort mit `docs/78` zu tun hat
 *
 * `/var/spool` und nicht `/var/lib/srvpanel` — aus demselben Grund wie die
 * ACME-Prüfdatei: Das Verzeichnis des Panels ist `0750 srvpanel:srvpanel`, und
 * der nginx-Worker läuft als `www-data`. Eine Datei, die für alle lesbar ist,
 * ist damit nicht erreichbar; der Weg zu ihr entscheidet.
 */
final class Maintenance
{
    /**
     * Die Datei, deren Anwesenheit den Wartungsmodus einschaltet.
     *
     * Nicht unter `/run`: Ein Neustart des Servers löschte sie dort, und der
     * Modus wäre danach still aus — ohne dass jemand ihn ausgeschaltet hätte.
     */
    public const FLAG = '/var/spool/srvpanel/wartung';

    /**
     * Die Form, in der eine voraussichtliche Endzeit ankommen darf.
     *
     * **Die Prüfung ist die Grenze und nicht eine Höflichkeit.** Die Angabe
     * landet als Text *in* einer nginx-Zeichenkette; ein Apostroph darin
     * beendete sie, und aus einer Auskunft würde eine Konfigurationszeile. Was
     * hier durchkommt, kann nur aus Ziffern, Bindestrichen, einem Doppelpunkt
     * und einem Leerzeichen bestehen.
     *
     * `Y-m-d H:i` ist dabei kein neues Format, sondern das der Anzeige — es
     * steht so auch an jedem Zertifikat („gültig bis …").
     */
    public const UNTIL = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D';

    /**
     * Die geprüfte Endzeit — oder `null`, wenn keine mitkam.
     *
     * Eine Angabe, die die Form nicht hält, ist ein Programmierfehler des
     * Panels und keine Eingabe eines Kunden: Sie wird abgewiesen und nicht
     * stillschweigend weggelassen.
     */
    public static function until(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match(self::UNTIL, $value) !== 1) {
            throw AgentException::badRequest(
                'Die voraussichtliche Endzeit muss die Form JJJJ-MM-TT HH:MM haben.',
                ['maintenance_until' => is_scalar($value) ? (string) $value : gettype($value)],
            );
        }

        return $value;
    }

    /**
     * Die Wache für einen Server-Block, samt ihrer benannten Fehler-location.
     *
     * **Die Ausnahme kommt aus {@see HttpChallenge::PREFIX} und wird nicht
     * danebengeschrieben.** Zwei Listen, die dasselbe meinen, laufen
     * auseinander — und die zweite ist die, die veraltet. `preg_quote` macht
     * daraus einen Ausdruck; nginx spricht PCRE, die Ausgabe passt also.
     */
    public static function nginxGuard(?string $until): string
    {
        $flag = self::FLAG;
        $prefix = preg_quote(HttpChallenge::PREFIX);
        $page = self::page($until);

        return <<<CONF
            # **Wartungsmodus.** Liegt die Datei, antwortet diese Domain mit 503
            # — bis auf die Prüfadresse von ACME, sonst stürbe während jeder
            # Wartung die Zertifikatserneuerung (docs/81 §2.3p, M24).
            #
            # Auf Serverebene und nicht in `location /`: Dort deckte es die
            # verschachtelte PHP-location nicht ab (M25).
            set \$wartung 0;
            if (-f {$flag}) { set \$wartung 1; }
            if (\$uri ~ ^{$prefix}/) { set \$wartung 0; }
            if (\$wartung = 1) { return 503; }

            # `add_header` ist in einem `if` auf Serverebene nicht erlaubt (M27)
            # — Rumpf und Header stehen deshalb hier.
            error_page 503 @wartung;
            location @wartung {
                add_header Retry-After 3600 always;
                default_type text/html;
                return 503 '{$page}';
            }
        CONF;
    }

    /**
     * Die Seite, die ein Besucher während der Wartung liest.
     *
     * **Feste Form und kein Freitext** (`docs/101 §2`): Der informative Teil
     * ist die Zeitangabe, und die ist ein typisierter Wert. Kein „wenden Sie
     * sich an den Betreiber" — das ist die Sperrseite, und die verlangt vom
     * Leser etwas. Bei einer Wartung gibt es für ihn nichts zu tun.
     */
    private static function page(?string $until): string
    {
        $satz = $until === null
            ? 'Diese Website ist wegen Wartungsarbeiten vorübergehend nicht erreichbar.'
            : sprintf(
                'Diese Website ist wegen Wartungsarbeiten vorübergehend nicht erreichbar. '.
                'Voraussichtlich ab %s Uhr wieder erreichbar.',
                $until,
            );

        return '<!doctype html><html lang=de><head><meta charset=utf-8>'.
               '<meta name=viewport content=width=device-width,initial-scale=1>'.
               '<title>Wartungsarbeiten</title></head><body>'.
               '<h1>Wartungsarbeiten</h1>'.
               '<p>'.$satz.'</p>'.
               '</body></html>';
    }
}
