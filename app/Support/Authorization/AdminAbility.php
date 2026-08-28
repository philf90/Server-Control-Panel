<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\AdminRole;

/**
 * Die Adminfähigkeiten und die Rolle, der jede gehört.
 *
 * ## Warum es diese Datei gibt, bevor es die Rollen gibt
 *
 * `docs/20 §6.1` teilt die Admin-**Ebene** in zwei Rollen: **Betreiber** (dem
 * `root` dieses Servers nahe, darf alles) und **Administrator** (verwaltet
 * Kunden, Abonnements, Domains, Datenbanken — Kritisches weder sehen noch
 * bedienen). Gebaut wird das in A9; **entschieden ist es bereits.**
 *
 * Dazwischen liegt die Stufe P7b, und sie baut Adminfunktionen: Logs, Dienste,
 * Diagnose, Pakete und Updates. `docs/81 §11` sagt dazu einen Satz, der teuer
 * wird, wenn man ihn überliest:
 *
 * > **Wer eine Adminfunktion baut, entscheidet beim Bauen, auf welcher Seite
 * > sie liegt — und nicht später.** Eine Fähigkeit nachträglich zu spalten ist
 * > der Weg, auf dem eine zweite Fassung der Policy entsteht.
 *
 * Diese Datei ist der Ort, an dem diese Entscheidung fällt. Sie **erzwingt**
 * heute nichts: Beide Fähigkeiten lösen auf `isAdmin()` auf, weil es nur eine
 * Rolle gibt. Was A9 ändert, ist die **Auflösung** — nicht eine einzige
 * Aufrufstelle in `routes/web.php`, nicht ein Schlüssel in der `can`-Ablage
 * einer Seite und kein Bild.
 *
 * ## Warum das keine Verzierung ist
 *
 * Weil die Zuordnung gelesen wird: `AdminAbilityTest` hält sie gegen
 * `routes/web.php`. Eine Einstellungsseite, die neu dazukommt, gehört dem
 * Betreiber — es sei denn, sie steht unten mit ihrer Begründung. Das ist
 * dieselbe Bauart wie bei {@see RouteGuard} und aus demselben Grund: **Eine
 * Registratur, die nur in eine Richtung geprüft wird, wächst über Jahre und
 * deckt irgendwann etwas, an das niemand mehr gedacht hat.**
 *
 * ## Was „kritisch" heisst
 *
 * Drei Merkmale, und eines genügt (`docs/20 §6.1`):
 *
 * 1. **Es verleiht root auf Dauer** — Paketquellen, unbeaufsichtigte Updates.
 * 2. **Es nimmt alle Kunden mit** — Dienste stoppen, Firewall, Neustart,
 *    Systemupdates einspielen.
 * 3. **Es zeigt ein Geheimnis** — DNS-Zugangsdaten, SMTP-Kennwort, private
 *    Schlüssel des Panels.
 *
 * **Geteilt wird nach Wirkung, nicht nach Bildschirm.** Wer die
 * DNS-Zugangsdaten nicht *sieht*, aber eine Bestellung auslösen darf, die sie
 * benutzt, für den ist das Geheimnis weiterhin wirksam.
 */
final class AdminAbility
{
    /**
     * **Die Rollen stehen in {@see AdminRole} und nicht mehr hier.**
     *
     * Sie standen an dieser Stelle als zwei Konstanten, weil es den Enum noch
     * nicht gab. Zwei Stellen für denselben Namen sind der Fehler, den dieses
     * Repo am häufigsten macht — und beim Anlegen der Spalte `accounts.role`
     * wäre daraus eine dritte geworden.
     *
     * > **Ein Name, der an zwei Stellen steht, steht bald an dreien.**
     */

    /** Was nur der Betreiber darf. */
    public const OPERATE_SERVER = 'operate-server';

    /** Was auch der Administrator darf. */
    public const MANAGE_SETTINGS = 'manage-settings';

    /**
     * Den Zustand des Servers ansehen und die Paketlisten auffrischen.
     *
     * **Getrennt von {@see self::OPERATE_SERVER}, weil die Achse eine andere
     * ist.** `docs/81 §3` Frage 2: Der Betreiber hat nicht nach „lesen gegen
     * schreiben" geteilt, sondern nach dem Gegenstand — der Administrator
     * verwaltet Kunden und Abonnements und sieht dabei, woran der Server
     * steht, ohne an ihm zu drehen.
     *
     * > **Meine Aufteilung trennte nach dem Verb, seine nach dem Gegenstand.**
     *
     * **`refresh` gehört hierher und ist trotzdem eine Handlung.** Es
     * verändert keinen Paketstand, sondern nur, wie aktuell die Frage danach
     * ist — wer die Zahlen sehen darf, muss sie auch auffrischen dürfen, sonst
     * sieht er einen Stand von gestern und kann nichts dagegen tun.
     */
    public const INSPECT_SERVER = 'inspect-server';

    /**
     * Jede Adminfähigkeit mit ihrer Rolle und dem Grund dafür.
     *
     * Die Gates entstehen aus dieser Liste — eine Fähigkeit, die hier nicht
     * steht, gibt es nicht.
     *
     * @return array<string, array{role: AdminRole, reason: string}>
     */
    public static function abilities(): array
    {
        return [
            self::MANAGE_SETTINGS => [
                'role' => AdminRole::Administrator,
                'reason' => 'Einstellungen, die keines der drei Merkmale tragen: kein Geheimnis, kein '
                    .'Weg zu root, keine Wirkung auf alle Kunden. Der Administrator verwaltet Kunden '
                    .'und Abonnements — dazu gehört, das Panel dafür einrichten zu können.',
            ],
            self::INSPECT_SERVER => [
                'role' => AdminRole::Administrator,
                'reason' => 'Ansehen, woran der Server steht, und die Paketlisten auffrischen — '
                    .'ohne etwas an ihm zu ändern. Keines der drei Merkmale aus docs/20 §6.1: Die '
                    .'Versionen installierter Pakete sind kein Geheimnis, das Auffrischen ist kein '
                    .'Weg zu root, und für die Kunden ändert sich dabei nichts. Dass die Versionen '
                    .'verraten, welche bekannten Lücken dieser Server hat, ist gesehen und in '
                    .'docs/81 §3 Frage 2 ausdrücklich zugelassen worden.',
            ],
            self::OPERATE_SERVER => [
                'role' => AdminRole::Operator,
                'reason' => 'Alles, was eines der drei Merkmale aus docs/20 §6.1 trägt. Die '
                    .'Zugangsdaten für DNS und Mailversand sind Geheimnisse; der private Schlüssel '
                    .'des Panels ist eines; PHP-Versionen installieren ruft apt-get und ist damit ein '
                    .'Weg, auf dem Pakete aus einer fremden Quelle auf die Maschine kommen; der '
                    .'Fernzugriff der Datenbank nimmt alle Kunden mit.',
            ],
        ];
    }

    /**
     * Routen, die **nicht** dem Betreiber gehören — mit Begründung.
     *
     * **Die Voreinstellung ist der Betreiber, und das ist Absicht.** Eine
     * Route, die {@see self::MANAGE_SETTINGS} trägt, fällt im Wächter durch,
     * bis sie hier steht. Der Fehler fällt damit zur sicheren Seite: Eine
     * Seite, die versehentlich zu streng ist, meldet sich beim Administrator;
     * eine, die versehentlich zu offen ist, meldet sich nie.
     *
     * **Die Regel galt bis zum 24. August 2026 nur für `/settings/`**, und
     * beim Bau der Seite „Logs" fiel auf, dass das zu wenig ist: `/logs` ist
     * eine Adminseite und liegt nicht dort. Ein Pfad, der die Regel trägt, ist
     * eine Eigenschaft des Ortes und nicht der Sache — gefragt wird deshalb
     * nach der **Fähigkeit** und nicht nach dem Verzeichnis.
     *
     * > **Eine Regel, die an einem Pfad hängt, gilt für die nächste Seite
     * > nicht mehr — und niemand merkt es, weil sie grün bleibt.**
     *
     * Schlüssel ist der Pfad ohne führenden Schrägstrich, so wie er in
     * `routes/web.php` steht.
     *
     * @return array<string, string> Pfad => Begründung
     */
    public static function administratorRoutes(): array
    {
        return [
            'settings/general' => 'Die Anzeigezeitzone des Panels (docs/40). Sie ändert, wie ein '
                .'Zeitstempel dargestellt wird, und sonst nichts — kein Geheimnis, kein Weg zu root, '
                .'und für einen Kunden ändert sich dadurch nichts an seinem Betrieb.',
            'updates' => 'Zahlen, Paketliste und Quellen ansehen (docs/81 §3 Frage 2). Der '
                .'Payload ist für diesen Betrachter gefiltert: keine Schlüsselspalte und kein '
                .'Anteil für den Neustart. Die vier ändernden Routen dieser Seite bleiben beim '
                .'Betreiber.',
            'updates/refresh' => 'Die Paketlisten auffrischen. Es ändert keinen Paketstand, sondern '
                .'nur, wie aktuell die Frage danach ist — wer die Zahlen sehen darf und sie nicht '
                .'auffrischen kann, sieht einen Stand von gestern und kann nichts dagegen tun.',
        ];
    }
}
