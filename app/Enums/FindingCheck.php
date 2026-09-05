<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Finding;
use SrvPanel\Agent\Catalog;

/**
 * Welche Prüfung einen Befund erzeugt hat — und welche Gründe sie kennt.
 *
 * ## Warum die Gründe hier stehen und nicht bei der Prüfung
 *
 * Der Grund eines Befundes ist der **stabile** Teil seiner Kennung
 * ({@see Finding}). Der Wortlaut des Werkzeugs ist es nicht: Jede
 * `[emerg]`-Zeile von nginx trägt Datum **und Prozessnummer**, jede Zeile von
 * php-fpm ein Datum (`docs/81 §2.3o` M9). Zwei Läufe an derselben kaputten
 * Datei ergeben zwei Texte.
 *
 * > **Ein Befund braucht eine Kennung, die nicht sein Text ist.**
 *
 * Stünde die Liste der Gründe bei der Prüfung, die sie erzeugt, wäre sie über
 * dreizehn Stellen verteilt und niemand könnte sie am Stück lesen — dieselbe
 * Überlegung wie bei {@see Catalog} für die Units.
 *
 * ## Die Schwere hängt am Grund und nicht an der Zeile
 *
 * {@see self::state()} ist die einzige Stelle, die aus einem Grund ein Urteil
 * macht. Deshalb steht in der Tabelle `findings` **keine** Spalte dafür: Sie
 * wäre die zweite Fassung derselben Regel, und die zweite ist die, die
 * veraltet.
 *
 * > **Wenn zwei Fälle denselben Grund und verschiedene Schwere haben, sind es
 * > zwei Gründe.**
 *
 * ## `unreachable` steht fast überall, und das ist der Punkt
 *
 * Er heisst „diese Prüfung ist nicht durchgelaufen" und ergibt
 * {@see FindingState::Unknown} — nie `Ok`. Ein Diagnoselauf, der bei totem
 * Agenten Entwarnung gibt, ist schlimmer als keiner (`docs/44`).
 *
 * Drei Prüfungen kennen ihn **nicht**, und das ist kein Versehen:
 * {@see self::OrphanRow} fragt allein den eigenen Bestand,
 * {@see self::SystemUser} allein die eigene Maschine, und
 * {@see self::TlsWire} ist die einzige, die über das Netz geht — dort ist
 * „nicht erreichbar" der gemessene Zustand und keine ausgefallene Messung.
 *
 * **Die dritte ist beim Bauen von Schritt 5 dazugekommen.** `system.user` trug
 * `unreachable`, weil fast jede Prüfung ihn trägt; ausgesprochen hätte ihn
 * niemand. Ein Grund ohne Sprecher ist ein toter Eintrag — dieselbe Art, die
 * bei einer Umbenennung entsteht.
 *
 * ## Vierzehn Schlüssel für neun Prüfungen
 *
 * `docs/98 §3` gliedert in neun Abschnitte A bis I; die Schlüssel hier sind
 * feiner, weil ein Befund einen Gegenstand braucht und nicht einen Abschnitt.
 * Aus A werden drei (je Prüfer einer), aus B zwei, aus D zwei und aus E zwei —
 * die Frage an die Datei und die an die Leitung sind zwei Fragen
 * (`docs/98 §3 E`, Frage 3).
 */
enum FindingCheck: string
{
    /** `nginx -t` gegen den laufenden Bestand. */
    case WebConfig = 'web.config';

    /** `php-fpm -t` gegen den laufenden Bestand. */
    case PhpConfig = 'php.config';

    /** `sshd -t` gegen den laufenden Bestand. */
    case SshConfig = 'ssh.config';

    /** Die Vhost-Datei einer Domain, gegen die Zusagen ihrer Vorlage. */
    case WebFile = 'web.file';

    /** Die Pool-Datei einer Domain, gegen die Zusagen ihrer Vorlage. */
    case PhpFile = 'php.file';

    /** Der verwaltete Bereich in einer fremden Datei. */
    case BlockIntegrity = 'block.integrity';

    /** Läuft eine Unit, die laufen soll. */
    case UnitState = 'unit.state';

    /** Hat ein Timer einen nächsten Termin. */
    case UnitSchedule = 'unit.schedule';

    /** Das Zertifikat, wie es auf dem Datenträger liegt. */
    case TlsFile = 'tls.file';

    /** Das Zertifikat, wie der Server es ausliefert — mit SNI. */
    case TlsWire = 'tls.wire';

    /** Wird die Quota erzwungen. */
    case QuotaState = 'quota.state';

    /** Gibt es den Systembenutzer eines Abonnements. */
    case SystemUser = 'system.user';

    /** Eine Zeile ohne ihren Gegenstand. */
    case OrphanRow = 'orphan.row';

    /** Der Signaturschlüssel der eigenen Paketquelle. */
    case AptKey = 'apt.key';

    /**
     * Der Wartungsmodus, gemessen an seiner eigenen Ankündigung.
     *
     * **Das ist, was von der gestrichenen Automatik übrigbleibt** (`docs/101
     * §2`) — und es ist ehrlicher als sie: Der Nachtlauf meldet am Morgen, was
     * der Betreiber am Abend vergessen hat. Niemand verlässt sich auf einen
     * Zeitgeber, dessen Ausfall wie ein laufendes Fenster aussähe.
     */
    case MaintenanceWindow = 'maintenance.window';

    /** Der Grund, der überall „die Prüfung lief nicht" heisst. */
    public const UNREACHABLE = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::WebConfig => 'Konfiguration des Webservers',
            self::PhpConfig => 'Konfiguration von PHP-FPM',
            self::SshConfig => 'Konfiguration des SSH-Dienstes',
            self::WebFile => 'Vhost-Datei einer Domain',
            self::PhpFile => 'Pool-Datei einer Domain',
            self::BlockIntegrity => 'Verwalteter Bereich',
            self::UnitState => 'Dienst',
            self::UnitSchedule => 'Nächster Termin eines Timers',
            self::TlsFile => 'Zertifikat auf dem Datenträger',
            self::TlsWire => 'Ausgeliefertes Zertifikat',
            self::QuotaState => 'Speicherkontingent',
            self::SystemUser => 'Systembenutzer',
            self::OrphanRow => 'Zeile ohne Gegenstand',
            self::AptKey => 'Signaturschlüssel der Paketquelle',
            self::MaintenanceWindow => 'Wartungsmodus',
        };
    }

    /**
     * Was der Gegenstand eines Befundes dieser Prüfung ist.
     *
     * **Für die Überschrift der Spalte und nicht für die Logik.** Ein Befund
     * ohne Ort erfüllt das Abnahmekriterium nicht (`docs/98 §7` Punkt 2), und
     * diese Angabe sagt dem Leser, was für ein Ort das ist.
     */
    public function subjectLabel(): string
    {
        return match ($this) {
            self::WebConfig, self::PhpConfig, self::SshConfig, self::BlockIntegrity => 'Datei',
            self::WebFile, self::TlsFile, self::TlsWire => 'Domain',
            self::PhpFile => 'Datei',
            self::UnitState, self::UnitSchedule => 'Unit',
            self::QuotaState => 'Verzeichnis',
            self::SystemUser => 'Abonnement',
            self::OrphanRow => 'Zeile',
            self::AptKey => 'Schlüssel',
            self::MaintenanceWindow => 'Server',
        };
    }

    /**
     * Die Gründe, die diese Prüfung kennt — Schlüssel und Satz.
     *
     * **Der Satz ist unsere Formulierung und nicht die des Werkzeugs.** Genau
     * darauf beruht Frage 5 aus `docs/98 §9`: Der Administrator sieht
     * `subject` und diesen Satz, der ungekürzte Wortlaut des Werkzeugs bleibt
     * dem Betreiber. Ein Satz, der den Ort noch einmal nennt, wäre doppelt —
     * er steht daneben in `subject`.
     *
     * @return array<string, array{state: FindingState, text: string}>
     */
    public function reasons(): array
    {
        $unreachable = [
            self::UNREACHABLE => [
                'state' => FindingState::Unknown,
                'text' => 'Diese Prüfung ist nicht durchgelaufen.',
            ],
        ];

        return match ($this) {
            self::WebConfig, self::PhpConfig, self::SshConfig => [
                'invalid' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Prüfer des Dienstes lehnt die Konfiguration ab.',
                ],
                ...$unreachable,
            ],

            /*
             * `directive_lost` ist der Grund, für den es diese Prüfung gibt.
             * `nginx -t` gibt für eine Datei, in der ein Semikolon fehlt, in
             * zwei von vier gemessenen Formen `rc=0` und keine Ausgabe zurück
             * — die nächste Anweisung wird zum Argument der vorigen und ist
             * damit wirkungslos (`docs/81 §2.3o` M3, M21).
             */
            self::PhpFile => [
                'missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Datei, die zu dieser Domain gehört, liegt nicht mehr da.',
                ],
                'empty' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Datei ist leer.',
                ],
                'directive_lost' => [
                    'state' => FindingState::Fail,
                    'text' => 'Eine Anweisung, die die Vorlage zusagt, steht nicht mehr als Anweisung in der Datei.',
                ],
                ...$unreachable,
            ],

            /*
             * **`guard_missing` gibt es nur hier**, und der geteilte Zweig
             * darüber ist deshalb aufgetrennt: Die Wache des Wartungsmodus
             * steht in nginx und nicht in einem PHP-Pool. `DiagnoseSeamTest`
             * hat das gemeldet, als beide Prüfungen den Grund noch teilten —
             * ein Grund, den niemand ausspricht, ist ein toter Eintrag.
             */
            self::WebFile => [
                'missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Datei, die zu dieser Domain gehört, liegt nicht mehr da.',
                ],
                'empty' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Datei ist leer.',
                ],
                'directive_lost' => [
                    'state' => FindingState::Fail,
                    'text' => 'Eine Anweisung, die die Vorlage zusagt, steht nicht mehr als Anweisung in der Datei.',
                ],

                /*
                 * **Der Grund daneben, und er sieht, was die Zusage nicht
                 * sieht.** Gemessen am 5. September 2026: Fehlt allein die
                 * Zeile mit der ACME-Ausnahme, meldet `directive_lost` nichts
                 * — `if` steht ja weiterhin dreimal in der Datei. Während einer
                 * Wartung stürbe damit jede Zertifikatserneuerung, und
                 * `nginx -t` gäbe dabei `rc=0`.
                 */
                'guard_missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Wache des Wartungsmodus fehlt in mindestens einem Server-Block dieser Datei.',
                ],
                ...$unreachable,
            ],

            /*
             * `begin_without_end` ist der Zustand, den `ManagedBlock` selbst
             * für fatal hält und den sein Leseweg heute nicht sieht — der Wurf
             * steht in `without()`, also im Schreibweg (`docs/81 §2.3o` M15).
             *
             * `foreign_line` ist der gefährlichste: Eine fremde Zeile
             * innerhalb der Marken kommt heute als unsere zurück (M16).
             */
            self::BlockIntegrity => [
                'begin_without_end' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der verwaltete Bereich hat einen Anfang und kein Ende. Wo er aufhört, ist nicht zu erkennen.',
                ],
                'end_without_begin' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der verwaltete Bereich hat ein Ende und keinen Anfang. Was davor steht, verwaltet niemand mehr, und der nächste Schreibvorgang legt einen zweiten Bereich an.',
                ],
                'block_missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der verwaltete Bereich fehlt, obwohl es Regeln gibt, die darin stehen müssten.',
                ],
                'duplicate_block' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der verwaltete Bereich steht zweimal in der Datei. Der zweite wird stillschweigend übergangen.',
                ],
                'foreign_line' => [
                    'state' => FindingState::Fail,
                    'text' => 'Im verwalteten Bereich steht eine Zeile, die nicht aus dem Bestand stammt.',
                ],
                'line_missing' => [
                    'state' => FindingState::Warn,
                    'text' => 'Eine Regel aus dem Bestand fehlt im verwalteten Bereich.',
                ],
                ...$unreachable,
            ],

            self::UnitState => [
                'inactive' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Dienst läuft nicht.',
                ],
                'failed' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Dienst ist gescheitert.',
                ],
                'not_installed' => [
                    'state' => FindingState::Warn,
                    'text' => 'Die Unit ist dem System nicht bekannt.',
                ],
                ...$unreachable,
            ],

            /*
             * Der Satz vom 19. August, gemessen statt behauptet (`docs/89` M3):
             * Ein Timer ohne nächsten Termin meldet `ActiveState=active`.
             */
            self::UnitSchedule => [
                'no_next' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Timer hat keinen nächsten Termin. Er ist abgeschaltet und sieht aus wie eingeschaltet.',
                ],
                ...$unreachable,
            ],

            self::TlsFile => [
                'missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Für diese Domain liegt kein Zertifikat.',
                ],
                'expired' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Zertifikat ist abgelaufen.',
                ],
                'expiring' => [
                    'state' => FindingState::Warn,
                    'text' => 'Das Zertifikat läuft demnächst ab.',
                ],
                'name_mismatch' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Zertifikat deckt den Namen dieser Domain nicht.',
                ],
                ...$unreachable,
            ],

            /*
             * `not_served` ist der Fall, den nur die Leitung fängt: Die Datei
             * liegt gültig da, und der Server liefert sie nicht aus — weil der
             * Block nicht neu geladen wurde oder die Anfrage auf den
             * Vorgabeblock fällt. Gefragt wird mit SNI; ohne kommt ein gültig
             * aussehendes Zertifikat mit dem falschen Namen zurück
             * (`docs/78`, nachgestellt in `docs/81 §2.3o` M18).
             *
             * Kein `unreachable`: Dass der Server nicht antwortet, ist hier der
             * gemessene Zustand und keine ausgefallene Messung.
             */
            self::TlsWire => [
                'not_served' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Webserver liefert für diesen Namen ein anderes Zertifikat aus als das, das für ihn abgelegt ist.',
                ],
                'no_answer' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Webserver hat auf diesem Namen keine gesicherte Verbindung angenommen.',
                ],
            ],

            /*
             * `not_enforced` ist der Zustand, den das Panel heute als
             * Entwarnung liest: `repquota` gibt `rc=0` und eine volle Tabelle,
             * sobald die Quotadatei da ist — auch wenn `quotaon -p` daneben
             * `is off` sagt (`docs/81 §2.3o` M11).
             */
            self::QuotaState => [
                'off' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Dateisystem führt keine Benutzerquota. Jede Grenze, die das Panel zeigt, begrenzt nichts.',
                ],
                'not_enforced' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Quotadatei liegt da, erzwungen wird die Quota aber nicht.',
                ],
                ...$unreachable,
            ],

            /*
             * **Kein `unreachable`, und das ist beim Bauen entschieden worden.**
             * Diese Prüfung fragt `/etc/passwd` und `stat` — beides beantwortet
             * die Maschine ohne Umweg, und eine Antwort, die es nicht gibt, ist
             * hier der gemessene Zustand („den Benutzer gibt es nicht") und
             * keine ausgefallene Messung. Ein Grund, den niemand aussprechen
             * kann, ist ein toter Eintrag.
             *
             * `root_missing` meint das Dokumentenverzeichnis und nicht die
             * Wurzel: Die gehört `root:root` und steht auf `0755`, weil ihr
             * Zugriffsbit der Schalter von `subscription.suspend` ist
             * (`SubscriptionState`). Dem Kunden gehört `httpdocs`.
             */
            self::SystemUser => [
                'missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Den Systembenutzer dieses Abonnements gibt es nicht.',
                ],
                'root_missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Dokumentenverzeichnis dieses Abonnements liegt nicht mehr da.',
                ],
                'wrong_owner' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Dokumentenverzeichnis dieses Abonnements gehört einem anderen Benutzer.',
                ],
            ],

            /*
             * Gemeldet und nicht gelöscht (`docs/36 §5`) — und deshalb `Warn`
             * und nicht `Fail`: Eine verwaiste Zeile richtet nichts an, sie
             * bleibt nur liegen.
             */
            self::OrphanRow => [
                'certificate' => [
                    'state' => FindingState::Warn,
                    'text' => 'Dieses Zertifikat deckt keine lebende Domain mehr.',
                ],
                'system_user' => [
                    'state' => FindingState::Warn,
                    'text' => 'Dieser Systembenutzer ist reserviert, und es gibt kein Abonnement dazu.',
                ],
                'cron_file' => [
                    'state' => FindingState::Warn,
                    'text' => 'Zu dieser Cron-Datei gibt es keinen Job im Bestand.',
                ],
            ],

            self::AptKey => [
                'missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Signaturschlüssel der eigenen Paketquelle fehlt. Ein Update kommt damit nicht an.',
                ],
                'expired' => [
                    'state' => FindingState::Fail,
                    'text' => 'Der Signaturschlüssel ist abgelaufen. Ein Update kommt damit nicht an.',
                ],
                'expiring' => [
                    'state' => FindingState::Warn,
                    'text' => 'Der Signaturschlüssel läuft demnächst ab.',
                ],
                ...$unreachable,
            ],

            /*
             * **Auffällig und nicht Kaputt.** Eine überschrittene Ankündigung
             * ist kein Schaden am Server: Die Websites antworten genau so, wie
             * der Betreiber es geschaltet hat. Falsch ist der Satz, den ihre
             * Besucher lesen — und der wird mit jeder Stunde falscher.
             */
            self::MaintenanceWindow => [
                'overdue' => [
                    'state' => FindingState::Warn,
                    'text' => 'Der Wartungsmodus ist an, und die angekündigte Endzeit ist vorbei.',
                ],

                /*
                 * **Und kein `unreachable`.** Diese Prüfung fragt kein Werkzeug
                 * und keine Leitung, sondern zwei Werte aus den Einstellungen —
                 * die antworten oder der ganze Lauf antwortet nicht. Ein Grund,
                 * den niemand ausspricht, ist ein toter Eintrag;
                 * `DiagnoseSeamTest` hat ihn gemeldet, als er hier stand.
                 */
            ],
        };
    }

    /**
     * Das Urteil zu einem Grund — die einzige Stelle, die es fällt.
     *
     * Ein Grund, den diese Prüfung nicht kennt, ist ein Programmierfehler und
     * keine Eingabe: Er kommt nie von aussen, sondern immer aus dem Code, der
     * den Befund anlegt. `DiagnoseCatalogTest` hält beide Richtungen.
     */
    public function state(string $reason): FindingState
    {
        $known = $this->reasons();

        if (! isset($known[$reason])) {
            throw new \InvalidArgumentException(sprintf(
                'Die Prüfung %s kennt den Grund "%s" nicht.',
                $this->value,
                $reason,
            ));
        }

        return $known[$reason]['state'];
    }

    /** Der Satz zu einem Grund, in unserer Formulierung. */
    public function sentence(string $reason): string
    {
        $known = $this->reasons();

        if (! isset($known[$reason])) {
            throw new \InvalidArgumentException(sprintf(
                'Die Prüfung %s kennt den Grund "%s" nicht.',
                $this->value,
                $reason,
            ));
        }

        return $known[$reason]['text'];
    }
}
