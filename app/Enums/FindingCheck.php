<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Finding;
use App\Support\Diagnose\Checks\DiskSpace;
use App\Support\Diagnose\FindingLog;
use App\Support\Plans\Quota;
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
 * Seitdem sind es mehr geworden, jede mit ihrem Grund an ihrem Fall; die
 * vollständige Liste hält `DiagnoseCatalogTest`, und eine Zahl stünde hier
 * nur, um zu veralten.
 *
 * ## Vierzehn Schlüssel für neun Prüfungen
 *
 * `docs/98 §3` gliedert in neun Abschnitte A bis I; die Schlüssel hier sind
 * feiner, weil ein Befund einen Gegenstand braucht und nicht einen Abschnitt.
 * Aus A werden drei (je Prüfer einer), aus B zwei, aus D zwei und aus E zwei —
 * die Frage an die Datei und die an die Leitung sind zwei Fragen
 * (`docs/98 §3 E`, Frage 3). **Seit B9 sind es aus E drei** (`docs/142`): Wie
 * lange ein Zertifikat gilt, ist eine dritte Frage, und sie geht als einzige
 * den Kunden an.
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

    /** Das Zertifikat, wie es auf dem Datenträger liegt — ob es da ist und die Namen deckt. */
    case TlsFile = 'tls.file';

    /**
     * Wie lange das Zertifikat einer Domain noch gilt (B9, `docs/142`).
     *
     * **Bis zum 7. Oktober 2026 stand das als zwei Gründe unter
     * {@see self::TlsFile}**, und die Gründe dort schlossen einander aus: Beim
     * Ablauf löste `expired` den Befund `expiring` ab, und der Webhook bekam
     * für „läuft demnächst ab" eine Entwarnung in dem Augenblick, in dem es
     * schlimmer wurde. Und ein Zertifikat, das abläuft und einen Namen nicht
     * deckt, hiess nur `name_mismatch` (`docs/142 §2`, Befund 3).
     *
     * **Zwei Fragen sind zwei Schlüssel.** Ob die Datei da ist und die Namen
     * deckt, ist eine Frage an den Bestand des Servers; wie lange sie gilt,
     * eine an die Zeit — und nur die zweite geht den Kunden an. Seit B9 bekommt
     * er sie per Mail, die erste der Betreiber.
     *
     * > **Ein Befund, den ein schwererer ablöst, ist nicht erledigt — und wer
     * > ihn dabei schliesst, meldet eine Entwarnung für einen Zustand, der
     * > schlimmer geworden ist.** Zum zweiten Mal nach dem Platz in B5.
     */
    case TlsExpiry = 'tls.expiry';

    /** Das Zertifikat, wie der Server es ausliefert — mit SNI. */
    case TlsWire = 'tls.wire';

    /** Wird die Quota erzwungen. */
    case QuotaState = 'quota.state';

    /**
     * Liegt ein Abonnement über einem seiner Kontingente (B5, `docs/129 §9`).
     *
     * **Der Nachbar von {@see self::QuotaState} und etwas anderes als er.**
     * Jener fragt, ob der *Server* die Quota überhaupt erzwingt; dieser, ob ein
     * *Kunde* über seiner Grenze liegt. Beides heisst „Kontingent" und misst
     * zwei verschiedene Dinge — deshalb zwei Prüfungen und nicht zwei Gründe
     * derselben.
     *
     * **Warum das ein Befund ist und keine eigene Tabelle.** Die Zusage „ein
     * Zustand über zwei Läufe ist eine Zeile, und was der Lauf nicht mehr
     * nennt, ist fort" steht seit A10 in {@see FindingLog}.
     * Genau sie braucht B5 für „genau eine Mail" — ein zweites Zustandsbuch
     * wäre die zweite Fassung derselben Regel.
     *
     * **Und der Betreiber sieht es dadurch, wie es zugesagt war.**
     * {@see Quota::TrafficGb} trug seit P1 den Hinweis
     * *„Die Überschreitung erscheint in der Übersicht"*. Bis B5 löste das
     * niemand ein — und B5 löste es auf der Seite „Diagnose" ein und nicht in
     * der Übersicht. Seit dem 5. Oktober 2026 nennt der Hinweis die Seite, auf
     * der es steht (`docs/141 §0`).
     *
     * > **Eine Zusage im Hinweistext ist eine Zusage.**
     */
    case QuotaExceeded = 'quota.exceeded';

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

    /**
     * Ob die Flagdatei wirklich das sagt, was das Panel anzeigt
     * (`docs/911 §2`, M8).
     *
     * Getrennt von {@see self::MaintenanceWindow}, weil die Frage eine andere
     * ist und einen anderen Weg nimmt: Jene vergleicht zwei abgelegte Werte
     * miteinander und kommt ohne den Agenten aus, diese fragt die Datei.
     */
    case MaintenanceFlag = 'maintenance.flag';

    /**
     * Eine Sicherung, gemessen an ihren eigenen Bytes (`docs/117 §6` Schritt 6).
     *
     * **Sie wird nicht im Nachtlauf der Bestandsdiagnose geschrieben**, sondern
     * in einem eigenen (`Catalog::BACKUP_CHECKS`). Der Grund steht dort: Diese
     * Prüfung liest Kundenarchive von der Platte, und der Bestandslauf kostet
     * gemessen 391 ms.
     *
     * Der Schlüssel liegt trotzdem in **diesem** Katalog, denn die Liste der
     * Befunde ist eine. Wer sie liest, fragt „was ist auf diesem Server nicht
     * in Ordnung" und nicht „welcher Zeitgeber hat das gemessen".
     */
    case BackupFile = 'backup.file';

    /**
     * Die jüngste Sicherung eines Abonnements ist gescheitert (B9, `docs/142`).
     *
     * **Bis zum 7. Oktober 2026 erfuhr das niemand**, auch der Betreiber
     * nicht. {@see self::BackupFile} urteilt über die Bytes **fertiger**
     * Archive und sieht eine gescheiterte Sicherung mit Absicht nicht an;
     * `BackupStatus::Failed` wurde geschrieben und nirgends gelesen
     * (`docs/142 §2`, Befund 1).
     *
     * **Ein eigener Schlüssel und kein Grund unter `backup.file`**, weil es
     * eine andere Frage ist und ein anderer Lauf sie stellt: Diese liest allein
     * die Datenbank und steht im Nachtlauf der Diagnose, jene liest jedes
     * Archiv von der Platte und hat ihre eigene Unit.
     */
    case BackupLatest = 'backup.latest';

    /**
     * Wie voll ein Dateisystem ist — Platz und Inodes (`docs/136`).
     *
     * **Auch dieser Schlüssel wird in einem eigenen Lauf geschrieben**
     * (`Catalog::DISK_CHECKS`), und zwar alle fünf Minuten: Bei voller Platte
     * stürzt MariaDB beim nächsten Wachsen einer Tabelle ab (`docs/136 §3` M5),
     * und ein Nachtlauf erführe davon bis zu einen Tag zu spät.
     */
    case DiskSpace = 'disk.space';

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
            self::TlsExpiry => 'Laufzeit eines Zertifikats',
            self::TlsWire => 'Ausgeliefertes Zertifikat',
            self::QuotaState => 'Speicherkontingent',
            self::QuotaExceeded => 'Überschrittenes Kontingent',
            self::SystemUser => 'Systembenutzer',
            self::OrphanRow => 'Zeile ohne Gegenstand',
            self::AptKey => 'Signaturschlüssel der Paketquelle',
            self::MaintenanceWindow => 'Wartungsmodus',
            self::MaintenanceFlag => 'Schalter des Wartungsmodus',
            self::BackupFile => 'Sicherung',
            self::BackupLatest => 'Jüngste Sicherung',
            self::DiskSpace => 'Belegung eines Dateisystems',
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
            self::WebFile, self::TlsFile, self::TlsExpiry, self::TlsWire => 'Domain',
            self::PhpFile => 'Datei',
            self::UnitState, self::UnitSchedule => 'Unit',
            self::QuotaState => 'Verzeichnis',
            self::QuotaExceeded => 'Abonnement',
            self::SystemUser => 'Abonnement',
            self::OrphanRow => 'Zeile',
            self::AptKey => 'Schlüssel',
            self::MaintenanceWindow => 'Server',
            self::MaintenanceFlag => 'Datei',
            self::BackupFile => 'Sicherung',
            self::BackupLatest => 'Abonnement',
            self::DiskSpace => 'Einhängepunkt',
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
                'name_mismatch' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Zertifikat deckt den Namen dieser Domain nicht.',
                ],
                ...$unreachable,
            ],

            /*
             * **`expiring` bleibt neben `expired` stehen**, wie die Vorwarnung
             * des Platzes neben „ausgeschöpft" (`docs/141 §0` Befund 7). Eine
             * Mail nennt dann nur den schwereren.
             *
             * **Kein `unreachable`.** Die Laufzeit kommt aus derselben Antwort
             * des Agenten wie die Frage an die Datei. Fehlt sie, steht
             * `tls.file / unreachable` da, und die Befunde hier bleiben
             * ungeprüft stehen — nicht widerlegt. Ein zweiter Satz „nicht
             * durchgelaufen" je Domain sagte dasselbe noch einmal.
             */
            self::TlsExpiry => [
                'expiring' => [
                    'state' => FindingState::Warn,
                    'text' => 'Das Zertifikat läuft demnächst ab.',
                ],
                'expired' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Zertifikat ist abgelaufen.',
                ],
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

            /*
             * **Die Richtung entscheidet die Schwere, und nicht die Grösse der
             * Abweichung.** Beide Befunde sagen „Ablage und Datei gehen
             * auseinander", und ihre Folgen sind sehr verschieden:
             *
             * - Fehlt die Datei, während das Panel „an" sagt, sind die
             *   Kundenwebsites **erreichbar**. Falsch ist nur, was der
             *   Betreiber liest — unangenehm, aber niemand sitzt im Dunkeln.
             * - Liegt die Datei, während das Panel „aus" sagt, antwortet
             *   **jede Kundenwebsite mit 503**, und niemand weiss davon. Das
             *   ist ein Ausfall, den niemand gewollt hat und den keine Anzeige
             *   nennt.
             *
             * > **Zwei Fälle derselben Abweichung sind nicht derselbe Befund,
             * > wenn nur einer den Dienst einstellt.**
             */
            self::MaintenanceFlag => [
                'missing' => [
                    'state' => FindingState::Warn,
                    'text' => 'Das Panel führt den Wartungsmodus als eingeschaltet, aber die Datei liegt nicht.',
                ],
                'unexpected' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Datei liegt, obwohl der Wartungsmodus als ausgeschaltet geführt wird — alle Kundenwebsites antworten mit 503.',
                ],
                ...$unreachable,
            ],

            /*
             * **Fünf Arten von Schaden und ein Missgriff — und alle fünf sind
             * `fail`.** Eine Sicherung hat genau eine Aufgabe, und sie stellt
             * sich erst an dem Tag, an dem jemand sie braucht. Bis dahin sieht
             * eine kaputte wie eine heile aus; das ist der Grund, aus dem es
             * diese Prüfung gibt, und es ist auch der Grund, aus dem keine
             * Abstufung hilft.
             *
             * > **Ein Schaden, der erst auffällt, wenn man den Gegenstand
             * > braucht, hat keine Vorstufe.**
             *
             * `entry_unexpected` ist die Ausnahme und steht auf `warn`: Da ist
             * etwas **mehr** drin, nicht weniger. Es ist ein Zeichen, dass
             * jemand am Archiv war — und das gehört gemeldet —, aber es kostet
             * keine Datei.
             */
            self::QuotaExceeded => [
                /*
                 * **`warn` und nicht `fail`.** Datenbanken und Traffic werden
                 * gemessen und nicht erzwungen ({@see \App\Support\Plans\Quota::hint()});
                 * über der Grenze ist dort nichts kaputt, jemand ist über eine
                 * vereinbarte Grenze.
                 *
                 * **Der Platz ist die Ausnahme, und sie steht im Satz und nicht
                 * im Rang.** Ihn erzwingt die Dateisystem-Quota: Ist er
                 * ausgeschöpft, scheitern die Schreibzugriffe der Website. Das
                 * ist ein Schaden beim Kunden und keiner am Server — die
                 * Diagnose des Betreibers bleibt deshalb bei `warn`, und die
                 * Mail sagt dem Kunden, was er davon merkt.
                 *
                 * Hier stand bis zum 5. Oktober 2026, für den Platz gelte
                 * „dasselbe" wie für den Traffic — gemessen, nicht erzwungen.
                 * Der Satz ging bis in die Kundenmail (`docs/141 §0`).
                 */
                'disk_near_limit' => [
                    'state' => FindingState::Warn,
                    'text' => 'Der Speicherplatz ist fast ausgeschöpft. Ist er voll, lassen sich keine Dateien mehr schreiben.',
                ],
                'disk_over' => [
                    'state' => FindingState::Warn,
                    'text' => 'Der Speicherplatz ist ausgeschöpft. Neue Dateien lassen sich nicht schreiben, bis Platz frei wird.',
                ],
                'databases_over' => [
                    'state' => FindingState::Warn,
                    'text' => 'Die Datenbanken dieses Abonnements liegen zusammen über ihrem Kontingent.',
                ],
                'traffic_over' => [
                    'state' => FindingState::Warn,
                    'text' => 'Der Traffic dieses Monats liegt über dem Kontingent.',
                ],

                /*
                 * **Ein eigener Grund statt `unreachable`, und das ist der
                 * Unterschied zwischen „nichts gemessen" und „eines von drei
                 * nicht gemessen".**
                 *
                 * Diese Prüfung liest den eigenen Bestand; sie kann als Ganzes
                 * nicht ausfallen. Ein Teil von ihr kann es: Welcher Monat
                 * gerade läuft, ist eine Frage an die Zone des Servers, und die
                 * ist im Web-Request seit `docs/108` nicht immer lesbar. Platz
                 * und Datenbanken sind daneben trotzdem beurteilt.
                 *
                 * `unreachable` mit seinem Satz „Diese Prüfung ist nicht
                 * durchgelaufen" wäre an dieser Stelle schlicht falsch.
                 */
                'traffic_unknown' => [
                    'state' => FindingState::Unknown,
                    'text' => 'Der Traffic dieses Monats ist nicht zu beurteilen — die Zeitzone des Servers ist nicht lesbar.',
                ],
            ],

            self::BackupFile => [
                'missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Panel führt diese Sicherung, und ihre Datei liegt nicht mehr da.',
                ],
                'unreadable' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Archiv lässt sich nicht öffnen — es ist abgeschnitten oder beschädigt.',
                ],
                'no_manifest' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Archiv trägt kein lesbares Verzeichnis; was darin fehlt, lässt sich nicht sagen.',
                ],
                'entry_missing' => [
                    'state' => FindingState::Fail,
                    'text' => 'Das Verzeichnis nennt eine Datei, die im Archiv nicht liegt.',
                ],
                'entry_unexpected' => [
                    'state' => FindingState::Warn,
                    'text' => 'Im Archiv liegt eine Datei, die sein Verzeichnis nicht kennt.',
                ],
                'corrupt' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die Bytes einer Datei im Archiv stimmen nicht mit ihrer Prüfsumme überein.',
                ],

                /*
                 * **Die Gegenrichtung, und `warn` und nicht `fail`.** Eine
                 * Datei ohne Zeile ist kein Schaden an einer Sicherung — es
                 * ist Platz, den niemand zuordnet. Sie entsteht, wenn ein
                 * `backup.remove` scheitert, nachdem die Zeile fort ist.
                 *
                 * Ein `fail` wäre die falsche Dringlichkeit: Nichts ist kaputt,
                 * und niemand muss nachts aufstehen. Ein Betreiber, der jede
                 * Nacht ein rotes Urteil für einen liegengebliebenen Rest
                 * bekäme, hörte auf hinzusehen.
                 */
                'orphan' => [
                    'state' => FindingState::Warn,
                    'text' => 'Zu dieser Datei gibt es keine Zeile — sie gehört keiner Sicherung, die das Panel kennt.',
                ],

                /*
                 * **Dieselbe Richtung eine Ebene höher, und aus demselben Grund
                 * `warn`.** Befund 10 des P8-Abnahmelaufs: Nach dem Entfernen
                 * aller Stände blieb das Verzeichnis eines Abonnements leer
                 * liegen, und die Prüfung meldete „Keine Befunde" — sie sucht
                 * Dateien ohne Zeile, und ein leeres Verzeichnis hat keine.
                 *
                 * **Gemeldet und nicht gelöscht**, wie jeder Rest seit A10. Der
                 * Griff dafür gibt es im Agenten (`backup.remove` ohne
                 * `storage`); ihn nachts von selbst gehen zu lassen hiesse, ein
                 * Verzeichnis abzuräumen, ohne dass jemand hingesehen hat.
                 *
                 * Es kostet vier Kilobyte, und darum geht es nicht: Stehen
                 * bleibt der **Name** eines zurückgebauten Abonnements, in
                 * einem Verzeichnis, das niemand mehr anfasst.
                 */
                'empty_directory' => [
                    'state' => FindingState::Warn,
                    'text' => 'Hier liegt ein leeres Verzeichnis für ein Abonnement, das es nicht mehr gibt — und keine Sicherung, zu der es gehörte.',
                ],

                ...$unreachable,
            ],

            /*
             * **`fail` und nicht `warn`.** Eine Sicherung hat genau eine
             * Aufgabe, und in dieser Nacht hat sie sie nicht erfüllt —
             * dieselbe Begründung, mit der `backup.file` durchgehend auf
             * `fail` steht.
             *
             * **Kein `unreachable`.** Die Prüfung liest allein die eigene
             * Datenbank; es gibt keinen Agenten, der schweigen könnte.
             */
            self::BackupLatest => [
                'failed' => [
                    'state' => FindingState::Fail,
                    'text' => 'Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen.',
                ],
            ],

            /*
             * **Warnung und Störung sind zwei Gründe und nicht einer** (`docs/136
             * §4`). Jeder hat seine Schwelle und seinen Rückweg; wird aus einer
             * Warnung eine Störung, bleibt die Warnung stehen. Andersherum
             * hiesse der Aufstieg „Warnung behoben" — genau in dem Augenblick,
             * in dem es schlimmer wird.
             *
             * **Die Zahlen stehen einmal da**, in {@see DiskSpace}; die Sätze
             * lesen sie, statt sie ein zweites Mal hinzuschreiben. Sie nennen
             * beide Grenzen, weil ein Befund bis zum Rückweg stehen bleibt: Bei
             * 82 % ist „ab 85 %" ohne den zweiten Halbsatz eine falsche Auskunft.
             */
            self::DiskSpace => [
                'space_tight' => [
                    'state' => FindingState::Warn,
                    'text' => sprintf(
                        'Das Dateisystem wird eng: gewarnt ab %d %%, entwarnt unter %d %%.',
                        DiskSpace::WARN_PERCENT,
                        DiskSpace::WARN_PERCENT - DiskSpace::RELEASE_POINTS,
                    ),
                ],
                'space_full' => [
                    'state' => FindingState::Fail,
                    'text' => sprintf(
                        'Das Dateisystem ist fast voll (ab %d %%, entwarnt unter %d %%). Läuft es ganz voll, stürzt MariaDB beim nächsten Wachsen einer Tabelle ab.',
                        DiskSpace::FAIL_PERCENT,
                        DiskSpace::FAIL_PERCENT - DiskSpace::RELEASE_POINTS,
                    ),
                ],
                'inodes_tight' => [
                    'state' => FindingState::Warn,
                    'text' => sprintf(
                        'Die Inodes werden knapp: gewarnt ab %d %%, entwarnt unter %d %%.',
                        DiskSpace::WARN_PERCENT,
                        DiskSpace::WARN_PERCENT - DiskSpace::RELEASE_POINTS,
                    ),
                ],
                'inodes_full' => [
                    'state' => FindingState::Fail,
                    'text' => sprintf(
                        'Die Inodes sind fast aufgebraucht (ab %d %%, entwarnt unter %d %%). Sind sie es ganz, scheitert jede neue Datei mit „No space left on device", auch bei freiem Platz.',
                        DiskSpace::FAIL_PERCENT,
                        DiskSpace::FAIL_PERCENT - DiskSpace::RELEASE_POINTS,
                    ),
                ],

                ...$unreachable,
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
