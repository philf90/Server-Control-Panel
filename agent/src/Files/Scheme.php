<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Die Verzeichnisse aus §4.5 gehören dem Panel und nicht dem Kunden.
 *
 * ## Was ohne diese Klasse passierte
 *
 * `httpdocs`, `logs`, `tmp`, `conf`, `.ssh` und `mail` entstehen beim Anlegen
 * des Abonnements. Der Kunde besitzt sie (ausser `conf`), also durfte er sie
 * über den Dateimanager entfernen — und der Kernel weist ihn dabei erst **ganz
 * am Ende** ab:
 *
 * 1. `Filesystem::removeTree()` läuft und räumt das Verzeichnis leer. Der Inhalt
 *    gehört dem Kunden, jedes `unlink` gelingt.
 * 2. Das abschliessende `rmdir` scheitert, weil die Vhost-Wurzel `root:root`
 *    gehört und niemand darin einen Eintrag entfernen darf.
 * 3. Der Kunde liest „Das Verzeichnis liess sich nicht vollständig entfernen."
 *
 * **Seine Webseite ist weg, und die Meldung sagt, es sei nichts passiert.**
 *
 * > **Ein Vorgang, der scheitert, nachdem er die Hälfte getan hat, meldet einen
 * > Fehlschlag und hinterlässt eine Wirkung.**
 *
 * Bei `logs` schreibt nginx danach in einen gelöschten Inode, bei `.ssh` sperrt
 * sich der Kunde aus seinem eigenen Zugang aus. Mit der Mehrfachauswahl wird
 * aus diesem Randfall ein Klick.
 *
 * ## Und `chmod` gehört dazu, was weniger offensichtlich ist
 *
 * `httpdocs` trägt seit `docs/51` Schritt 6c das **setgid-Bit** — daran hängt,
 * dass alles darin der Gruppe `www-data` gehört und der Webserver es lesen
 * kann. Ein `chmod 0750` des Kunden nähme es lautlos weg, und die nächste
 * hochgeladene Datei trüge wieder die falsche Gruppe. Das ist Befund 3 aus
 * `docs/53`, nur mit dem Kunden als Verursacher.
 *
 * ## Warum hier und nicht im Panel
 *
 * Die Liste kommt aus {@see SubscriptionProvision::reservedDirectories()} und
 * wächst damit mit dem Schema. Eine zweite Aufzählung im Panel wäre die
 * Fassung, die beim nächsten Zuwachs veraltet — und sie stünde ausserdem auf
 * der falschen Seite der Grenze.
 *
 * ## Was **nicht** geschützt ist
 *
 * Der **Inhalt**. `httpdocs` leerzuräumen ist genau das, was jemand vor einem
 * neuen Deploy tut, und `.ssh/authorized_keys` gehört dem Kunden. Geschützt ist
 * das Gerüst, nicht das, was darin steht.
 *
 * Und die DocumentRoots weiterer Domains: Sie heissen wie ihre Domain, stehen
 * in keiner festen Liste, und der Agent müsste dafür die vhost-Dateien lesen.
 * Das Panel **warnt** dort, weil es die Namen kennt — eine Warnung ist eine
 * Auskunft und keine zweite Durchsetzung.
 */
final class Scheme
{
    /**
     * Die Verzeichnisse des Schemas, jeweils mit führendem Schrägstrich.
     *
     * @return list<string>
     */
    public static function fixed(): array
    {
        return array_map(
            static fn (string $name): string => '/'.$name,
            [SubscriptionProvision::DOCUMENT_ROOT, ...SubscriptionProvision::reservedDirectories()],
        );
    }

    /**
     * Trifft dieser Pfad ein Verzeichnis des Schemas selbst?
     *
     * **Nur auf der ersten Ebene.** `/httpdocs` ja, `/httpdocs/bilder` nein —
     * und `/tmp/httpdocs` erst recht nicht, denn das hat der Kunde angelegt.
     */
    public static function isFixed(string $path): bool
    {
        return in_array(rtrim($path, '/'), self::fixed(), true);
    }

    /**
     * Abweisen, **bevor** etwas geschieht.
     *
     * Das ist der ganze Punkt: Der Kernel weist denselben Vorgang auch ab, aber
     * erst nach dem Leerräumen. Diese Prüfung steht deshalb vor dem Eintritt in
     * die Sandbox und nicht darin.
     *
     * @param  string  $verb  Was versucht wurde — es steht im Satz, den der Kunde liest.
     */
    public static function protect(string $path, string $verb): void
    {
        if (! self::isFixed($path)) {
            return;
        }

        throw AgentException::denied(sprintf(
            'Das Verzeichnis %s gehört zum Aufbau des Abonnements und wird nicht %s. Sein Inhalt lässt sich ändern.',
            rtrim($path, '/'),
            $verb,
        ));
    }
}
