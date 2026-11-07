<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * Wie ein Vorgang heisst, wo ein Mensch ihn liest.
 *
 * **Bis zum 7. November 2026 stand jeder Vorgang eines Kunden mit seiner
 * Maschinenkennung da** — im Streifen „Vorgang 812 web.site.apply — läuft",
 * in den Tabellen der Domain- und der Abonnementseite `php.pool.apply`, nach
 * „Jetzt sichern" `backup.create`. Bezeichnungen gab es nur für die elf
 * Aufgaben des Betreibers in {@see Task}; alles andere fiel auf die Kennung
 * zurück. Gefunden beim Ausschreiben des Durchlaufs mit einer fremden Person
 * (`docs/145 §0`, Befund 5): Eine Person, die das Projekt nicht kennt, liest
 * dort eine Zeichenkette, die nichts sagt, an genau der Stelle, die ihr sagen
 * soll, was gerade geschieht.
 *
 * **Eine Stelle für alle Orte.** Der Streifen, die Vorgangsliste, die
 * Vorgangsseite und die beiden Tabellen fragen hier. Vorher fragte die Liste
 * {@see Task} und der Streifen niemanden — zwei Antworten auf dieselbe Frage.
 *
 * **Was hier steht, ist jeder Vorgang, der entsteht und keine Aufgabe in
 * {@see Task} ist** — die meisten sieht der Kunde, weil sie von ihm ausgelöst
 * werden oder an einem Abonnement des Kunden laufen; die sechs am Ende sieht nur der Betreiber.
 * Die übrigen Operationen des Agenten werden aufgerufen und nicht als Vorgang
 * angelegt. `OperationLabelTest` hält die Liste an den Stellen fest, an denen
 * Vorgänge entstehen, und an den Operationen, die es im Agenten gibt.
 *
 * **Ohne Bezeichnung bleibt die Kennung**, und das ist kein Versehen: Ein
 * alter Vorgang mit einer Kennung, die es nicht mehr gibt, soll in der Liste
 * stehen und nicht die Seite kosten.
 */
final class OperationLabel
{
    /**
     * Die Bezeichnungen der Vorgänge, die keine Aufgabe des Betreibers sind.
     *
     * Verben im Infinitiv wie in {@see Task}: Der Streifen setzt den Zustand
     * dahinter („— läuft", „— fertig"), und „Sicherung anlegen — fertig" liest
     * sich als Satz.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        // Domains
        'web.site.apply' => 'Domain einrichten',
        'web.site.remove' => 'Domain entfernen',
        'php.pool.apply' => 'PHP einrichten',
        'php.pool.remove' => 'PHP entfernen',
        'web.logrotate.apply' => 'Protokollrotation einrichten',
        'acme.certificate.issue' => 'Zertifikat bestellen',

        // Sicherungen des Abonnements
        'backup.create' => 'Sicherung anlegen',
        'backup.remove' => 'Sicherung entfernen',
        'backup.restore' => 'Sicherung zurückspielen',

        // Datenbanken — beide Systeme heissen gleich, weil der Kunde sie gleich bedient
        'db.database.remove' => 'Datenbank entfernen',
        'pg.database.remove' => 'Datenbank entfernen',
        'db.user.lock' => 'Datenbankzugang sperren',
        'pg.role.lock' => 'Datenbankzugang sperren',
        'db.dump.create' => 'Datenbank sichern',
        'pg.dump.create' => 'Datenbank sichern',
        'db.dump.import' => 'Datenbanksicherung übernehmen',
        'pg.dump.import' => 'Datenbanksicherung übernehmen',
        'db.restore' => 'Datenbanksicherung zurückspielen',
        'pg.restore' => 'Datenbanksicherung zurückspielen',
        'db.dump.remove' => 'Datenbanksicherung entfernen',

        // Das Abonnement selbst — ausgelöst vom Betreiber, sichtbar für den Kunden
        'subscription.provision' => 'Abonnement einrichten',
        'subscription.suspend' => 'Abonnement sperren',
        'subscription.resume' => 'Abonnement freigeben',
        'subscription.remove' => 'Abonnement zurückbauen',
        'subscription.quota' => 'Speichergrenze anwenden',

        // Der Server — nur der Betreiber sieht sie, und auch dort lesen sich Worte besser
        'system.packages.refresh' => 'Paketlisten auffrischen',
        'system.packages.upgrade' => 'Updates installieren',
        'system.packages.unattended' => 'Automatische Updates umschalten',
        'system.sources.toggle' => 'Paketquelle umschalten',
        'system.reboot' => 'Server neu starten',
        'panel.tls.ensure' => 'Zertifikat des Panels bestellen',
    ];

    /**
     * Die Bezeichnung eines Vorgangs.
     *
     * Gefragt wird zuerst nach `$task`, dann nach `$type` — je in {@see Task}
     * und in dieser Liste. Ein Vorgang aus einer Aufgabe trägt in `task` deren
     * Namen und in `type` die Operation des Agenten; in allen übrigen sind
     * beide gleich.
     *
     * **Ohne Bezeichnung bleibt die Operation des Agenten** und nicht die
     * Aufgabe: Eine Aufgabe, die es nicht mehr gibt, sagt niemandem etwas, die
     * Operation wenigstens dem Betreiber. So stand es vorher in der
     * Vorgangsliste, und `OperationTaskTest` hält es fest.
     */
    public static function of(?string $task, string $type): string
    {
        foreach ([$task, $type] as $name) {
            if ($name === null) {
                continue;
            }

            $label = Task::tryFrom($name)?->label() ?? self::LABELS[$name] ?? null;

            if ($label !== null) {
                return $label;
            }
        }

        return $type;
    }
}
