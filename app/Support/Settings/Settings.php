<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
use App\Support\Authorization\AdminNetwork;
use App\Support\Time\Clock;
use App\Support\Web\PhpSelection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Der Zugang zu den Einstellungen des Betreibers.
 *
 * **Warum das nicht direkt über das Modell läuft.** Die Mailkonfiguration wird
 * beim Hochfahren der Anwendung gelesen — also auch dann, wenn es die Tabelle
 * noch gar nicht gibt: bei `migrate` auf einem frischen System, im Installer,
 * in jedem Artisan-Kommando vor der ersten Migration. Ein Modellzugriff wäre
 * dort ein Absturz mit einer Meldung über eine fehlende Tabelle, und zwar
 * ausgerechnet in dem Lauf, der sie anlegen soll.
 *
 * Deshalb fängt `mail()` beides ab: die fehlende Tabelle und den Fehler beim
 * Entschlüsseln. Der zweite Fall ist der unangenehmere — wechselt der
 * `APP_KEY`, sind die abgelegten Zugangsdaten nicht mehr lesbar. Die Antwort
 * darauf sind leere Einstellungen und kein Fehler: Ohne Mailversand läuft das
 * Panel weiter, mit einer Ausnahme beim Hochfahren nicht mehr.
 */
final class Settings
{
    private const MAIL = 'mail';

    /**
     * Die auf dem Server installierten PHP-Versionen.
     *
     * Kein Geheimnis und trotzdem in derselben Ablage: Sie ist die eine
     * Stelle für Zustand, den der Betreiber setzt und der keine eigene
     * Tabelle rechtfertigt. Verschlüsselt ist sie, weil es die Ablage ist —
     * nicht, weil die Liste es bräuchte.
     */
    private const PHP_VERSIONS = 'php_versions';

    /**
     * Ob dieses Panel PostgreSQL überhaupt anbietet.
     *
     * **Das ist der Betreiberschalter aus `docs/38 §7` und nicht der Zustand
     * des Servers.** Die beiden auseinanderzuhalten ist der ganze Punkt: Ob
     * ein Cluster läuft, beantwortet `pg.server.info` und niemand sonst — eine
     * im Panel gemerkte Fassung wäre die, die veraltet — dieselbe Begründung
     * wie bei `bind-address` im `DatabaseSettingsController`.
     * Was hier steht, ist die Absicht: *Kunden dürfen PostgreSQL-Datenbanken
     * anlegen.*
     *
     * Der Unterschied ist nicht theoretisch. Ein Server kann ein PostgreSQL
     * tragen, das dem Betreiber gehört — für sein eigenes Zeug, mit seinen
     * eigenen Rollen. Ohne diesen Schalter fiele die Kundenfläche in dem
     * Augenblick auf, in dem `pg_lsclusters` etwas findet, und die erste
     * Kundendatenbank landete in einem Cluster, den niemand dafür vorgesehen
     * hat.
     */
    private const POSTGRES = 'postgresql';

    /** Was der Messlauf über die Dateisystem-Quota gesehen hat. */
    private const DISK_QUOTA = 'usage.disk_quota';

    /**
     * Die Adressen, auf die eine Kundendomain zeigen soll — übersteuert.
     *
     * **Leer heisst „nimm die abgeleiteten"** (`docs/72 §2.1a`), und das ist
     * der Normalfall. Eingetragen wird nur, wo die Ableitung nicht geht:
     * hinter NAT, einer Floating-IP oder einem Lastverteiler ist die Adresse,
     * unter der ein Server von aussen erreichbar ist, von innen nicht zu
     * erfahren.
     *
     * **Das ist derselbe Fall wie `bind-address` und der PostgreSQL-Schalter
     * darüber**, und dieselbe Warnung gilt: Was hier steht, ist eine im Panel
     * gemerkte Fassung eines Serverzustands, und die kann veralten. Bekommt
     * der Server eine neue Adresse, meldet der Abgleich sonst jede Domain als
     * falsch, die in Wahrheit richtig steht — deshalb zeigt die Seite beides,
     * das Eingetragene und das Abgeleitete.
     */
    private const DNS_ADDRESSES = 'dns.addresses';

    /** Die Netze, aus denen sich ein Adminkonto anmelden darf (A9 Schritt 7). */
    private const ADMIN_NETWORKS = 'admin.networks';

    /**
     * Wann die Bestandsdiagnose zuletzt gelaufen ist (A10 Schritt 7).
     *
     * **Warum das nicht aus den Befunden kommt.** Ein `ok` erzeugt keine Zeile
     * (`docs/98 §4`), und auf einem heilen Server ist die Tabelle leer — dann
     * gäbe es kein `measured_at`, aus dem sich „zuletzt gemessen" ablesen
     * liesse. Genau das verlangt aber Punkt 1 des Abnahmekriteriums, und zwar
     * für **den** Fall: Eine Seite, die nichts meldet, muss sagen, ob sie
     * geschwiegen oder nicht gemessen hat.
     *
     * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
     * > beiden.**
     */
    private const DIAGNOSE = 'diagnose';

    /**
     * Der Wartungsmodus: ob er an ist, und bis wann er voraussichtlich läuft.
     *
     * **Die Wahrheit für nginx ist die Flagdatei und nicht diese Zeile.**
     * Geschaltet wird die Datei; hier steht, was das Panel darüber weiss — für
     * die Anzeige und für die Bestandsdiagnose, die ein überschrittenes Ende
     * meldet. Dass die beiden auseinanderlaufen können, ist kein Versehen,
     * sondern der Grund, aus dem A10 danach fragt.
     */
    private const MAINTENANCE = 'maintenance';

    private ?MailSettings $mail = null;

    /** @var list<string>|null */
    private ?array $phpVersions = null;

    public function mail(): MailSettings
    {
        if ($this->mail !== null) {
            return $this->mail;
        }

        return $this->mail = MailSettings::fromArray($this->read(self::MAIL));
    }

    public function saveMail(MailSettings $settings): void
    {
        Setting::query()->updateOrCreate(['key' => self::MAIL], ['value' => $settings->toArray()]);

        $this->mail = $settings;
    }

    /**
     * Welche PHP-Versionen auf dem Server liegen.
     *
     * **Leer heisst „nicht bekannt" und wird wie „keine" behandelt.** Vor dem
     * ersten Lauf von `php.versions` weiss das Panel es nicht; eine Domain
     * mit einer Version anzulegen, die es vielleicht nicht gibt, endet in
     * einem Server-Block, den der Agent zurückweist. Siehe
     * {@see PhpSelection::installed()}.
     *
     * @return list<string>
     */
    public function phpVersions(): array
    {
        if ($this->phpVersions !== null) {
            return $this->phpVersions;
        }

        $stored = $this->read(self::PHP_VERSIONS)['installed'] ?? null;

        if (! is_array($stored)) {
            return $this->phpVersions = [];
        }

        return $this->phpVersions = array_values(array_filter($stored, is_string(...)));
    }

    /** @param list<string> $versions */
    public function savePhpVersions(array $versions): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::PHP_VERSIONS],
            // `toDateTimeString` und nicht ISO: Die Angabe steht in der
            // Oberfläche, und dort sieht sie aus wie jeder andere Zeitpunkt im
            // Panel. Ein `2026-08-04T11:05:18+00:00` daneben wäre dieselbe
            // Auskunft in einer zweiten Schreibweise.
            ['value' => ['installed' => array_values($versions), 'checked_at' => now()->toDateTimeString()]],
        );

        $this->phpVersions = array_values($versions);
    }

    /** Wann zuletzt nachgesehen wurde — `null`, wenn noch nie. */
    public function phpVersionsCheckedAt(): ?string
    {
        $at = $this->read(self::PHP_VERSIONS)['checked_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /**
     * Bietet dieses Panel PostgreSQL an?
     *
     * **Der Grundzustand ist „nein", und zwar auch nach einem Update.** Ein
     * Bestandsserver, auf dem P5b ankommt, bekommt keine zweite
     * Datenbankfläche, weil jemand ein Paket aktualisiert hat — dieselbe
     * Richtung wie die Mandantenklammer: im Zweifel nichts.
     *
     * **`false` heisst hier auch „nicht nachgesehen".** Die beiden Lesestellen
     * im Panel entscheiden über eine Kundenfläche, und die Richtung im Zweifel
     * ist dieselbe wie bei der Mandantenklammer: nichts. Wer den Unterschied
     * braucht, fragt {@see self::postgresOffered()} — auf der Kommandozeile ist
     * er der ganze Punkt.
     */
    public function postgres(): bool
    {
        return $this->postgresOffered() === true;
    }

    /**
     * Dasselbe, dreiwertig — `null` heisst „konnte nicht nachgesehen werden".
     *
     * **Der Anlass ist gemessen, am 11. August 2026 auf `cloudsrv24`.**
     * `srvpanel db --remote=on --bind=::` hatte MariaDB gerade IPv6-only
     * gebunden und damit das Panel von seiner Datenbank abgeschnitten. Der
     * PostgreSQL-Teil desselben Laufs kam unmittelbar danach hierher, der
     * Leseversuch scheiterte, {@see self::probe()} machte daraus eine leere
     * Ablage — und das Kommando meldete *„PostgreSQL: übersprungen — das Panel
     * bietet es nicht an"*. Auf der Betreiberseite stand zur selben Zeit „Wird
     * angeboten: ja", und das war die richtige Auskunft.
     *
     * > Ein Wert, der „nein" und „ich weiss es nicht" nicht auseinanderhält,
     * > behauptet das eine, wenn das andere gilt.
     *
     * Der Schaden war nicht die falsche Zeile, sondern die Zeit: Sie klang
     * plausibel, nannte sogar den Befehl zur Abhilfe, und hat die Suche nach
     * dem eigentlichen Fehler in die falsche Richtung geschickt.
     */
    public function postgresOffered(): ?bool
    {
        $value = $this->probe(self::POSTGRES);

        if ($value === null) {
            return null;
        }

        return ($value['offered'] ?? false) === true;
    }

    /**
     * Den Schalter umlegen — aus `srvpanel db --postgresql=on|off`.
     *
     * Wann, steht mit dabei. Nicht aus Ordnungsliebe: Wer auf einer stillen
     * Kundenfläche steht und wissen will, seit wann sie still ist, hat sonst
     * keine Antwort ausser dem Prüfpfad.
     */
    public function savePostgres(bool $offered): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::POSTGRES],
            ['value' => ['offered' => $offered, 'changed_at' => now()->toDateTimeString()]],
        );
    }

    /**
     * Die eingetragenen Adressen — leer, wenn abgeleitet werden soll.
     *
     * @return list<string>
     */
    public function dnsAddresses(): array
    {
        $value = $this->read(self::DNS_ADDRESSES);
        $addresses = [];

        foreach ($value['addresses'] ?? [] as $address) {
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param  list<string>  $addresses  Leer räumt die Übersteuerung ab
     */
    public function saveDnsAddresses(array $addresses): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::DNS_ADDRESSES],
            ['value' => ['addresses' => array_values($addresses)]],
        );
    }

    /**
     * Die Netze, aus denen sich ein Adminkonto anmelden darf.
     *
     * **Eine leere Liste heisst „von überall" und nicht „von nirgends".** Das
     * ist die Voreinstellung eines frisch eingerichteten Servers, und die
     * andere Lesart hätte den Betreiber beim ersten Update ausgesperrt.
     *
     * > **Eine leere Liste, die alles verbietet, sperrt beim Einschalten aus —
     * > eine, die alles erlaubt, ändert nichts.**
     *
     * Die Entscheidung darüber trifft {@see AdminNetwork};
     * hier steht nur, was gespeichert ist.
     *
     * @return list<string>
     */
    public function adminNetworks(): array
    {
        $value = $this->read(self::ADMIN_NETWORKS);
        $networks = [];

        foreach ($value['networks'] ?? [] as $network) {
            if (is_string($network) && $network !== '') {
                $networks[] = $network;
            }
        }

        return $networks;
    }

    /**
     * @param  list<string>  $networks  Leer hebt die Beschränkung auf
     */
    public function saveAdminNetworks(array $networks): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::ADMIN_NETWORKS],
            ['value' => ['networks' => array_values($networks)]],
        );
    }

    /**
     * Ob das Dateisystem unter `/var/www/vhosts` eine Benutzerquota führt.
     *
     * **Gemessen wird das nicht hier, sondern jede Viertelstunde ohnehin.**
     * `subscription.usage` liest die Quota-Datei mit `repquota`; scheitert das,
     * kommt `available: false` samt Grund zurück. Diese Antwort stand bis zum
     * 10. August 2026 nur im Journal des Messlaufs — die Übersicht wusste
     * nichts davon, und ein Betreiber erfuhr vom fehlenden Quota-System erst,
     * wenn er ein Abonnement anlegte.
     *
     * **Die Mount-Option beweist nichts, und das ist gemessen.** Auf
     * `cloudsrv24` stand `rw,relatime,quota,usrquota` in `/proc/mounts` und
     * `quotaon -p /` sagte trotzdem `is off`: Die Quotadatei war nie angelegt
     * worden. Wer nur die Optionen liest, meldet Bereitschaft, wo keine ist —
     * deshalb steht hier das Ergebnis eines Leseversuchs und keine Ableitung.
     *
     * @return array{available: bool|null, reason: string|null, checked_at: string|null}
     */
    public function diskQuota(): array
    {
        $value = $this->read(self::DISK_QUOTA);

        return [
            // Drei Werte. `null` heisst „noch nie gemessen" — der Timer läuft
            // im Viertelstundentakt, und vor seinem ersten Lauf soll die
            // Übersicht schweigen statt Entwarnung zu geben.
            'available' => is_bool($value['available'] ?? null) ? $value['available'] : null,
            'reason' => is_string($value['reason'] ?? null) ? $value['reason'] : null,
            'checked_at' => is_string($value['checked_at'] ?? null) ? $value['checked_at'] : null,
        ];
    }

    /** Was der Messlauf gesehen hat — mit Zeitstempel, wie bei den PHP-Fassungen. */
    public function saveDiskQuota(bool $available, ?string $reason): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::DISK_QUOTA],
            ['value' => [
                'available' => $available,
                'reason' => $available ? null : $reason,
                'checked_at' => now()->toDateTimeString(),
            ]],
        );
    }

    /**
     * Was das Panel über den Wartungsmodus weiss.
     *
     * `until` ist ein Zeitpunkt in UTC oder `null` — die Anzeige macht daraus
     * {@see Clock} eine Ortszeit, und nur dort. Ein
     * bereits formatierter Wert hier wäre die zweite Fassung, und die veraltet
     * mit der nächsten Zeitzonenänderung.
     *
     * @return array{enabled: bool, until: null|string}
     */
    public function maintenance(): array
    {
        $row = $this->read(self::MAINTENANCE);
        $until = $row['until'] ?? null;

        return [
            'enabled' => ($row['enabled'] ?? false) === true,
            'until' => is_string($until) && $until !== '' ? $until : null,
        ];
    }

    /** Den Wartungsmodus festhalten — nachdem der Agent geschaltet hat. */
    public function saveMaintenance(bool $enabled, ?string $until): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::MAINTENANCE],
            ['value' => ['enabled' => $enabled, 'until' => $until]],
        );
    }

    /**
     * Wann der Nachtlauf zuletzt gefahren ist — `null`, wenn noch nie.
     *
     * `null` heisst „noch nie gemessen" und nicht „nichts gefunden". Vor dem
     * ersten Lauf schweigt die Seite, statt Entwarnung zu geben — dieselbe
     * Regel wie bei {@see self::diskQuota()}.
     */
    public function diagnoseRunAt(): ?string
    {
        $at = $this->read(self::DIAGNOSE)['ran_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /** Den Zeitpunkt eines Laufs festhalten — mit dem Wert, den der Lauf trägt. */
    public function saveDiagnoseRun(string $ranAt): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::DIAGNOSE],
            // Derselbe Wert, den die Befunde tragen, und nicht `now()`: Sonst
            // stünde neben einer Zeile von 03:00:07 ein „zuletzt gemessen
            // 03:00:09", und die beiden wären dieselbe Messung.
            ['value' => ['ran_at' => $ranAt]],
        );
    }

    /**
     * Der Leseversuch — `null`, wenn er gescheitert ist.
     *
     * **Bis zum 11. August 2026 war der `catch` die ganze Antwort**, und er hat
     * drei verschiedene Lagen auf denselben Rückgabewert abgebildet: die
     * Tabelle gibt es noch nicht, der `APP_KEY` hat gewechselt, der
     * Datenbankserver ist nicht erreichbar. Die ersten beiden sind wirklich
     * „nichts abgelegt" und stehen weiter drin. Die dritte ist es nicht — sie
     * heisst „ich konnte nicht nachsehen", und sie kommt jetzt als `null`
     * heraus.
     *
     * > Ein Fehlerweg, der jeden Fehler in dieselbe Antwort legt, hat aufgehört,
     * > einer zu sein.
     *
     * {@see self::read()} legt das `null` für die Aufrufer wieder auf `[]` um,
     * die den Unterschied nicht brauchen — Mailversand, PHP-Fassungen, Quota.
     * Sie werden beim Hochfahren gelesen, und dort ist „leer" die einzige
     * Antwort, die weiterlaufen lässt.
     *
     * @return array<string, mixed>|null
     */
    private function probe(string $key): ?array
    {
        try {
            // Vor der ersten Migration gibt es die Tabelle nicht. Das ist kein
            // Fehler, sondern der Grund, aus dem es diese Klasse gibt.
            if (! Schema::hasTable('settings')) {
                return [];
            }

            $value = Setting::query()->find($key)?->value;

            return is_array($value) ? $value : [];
        } catch (DecryptException) {
            // Der gewechselte APP_KEY aus dem Klassenkopf: Die Zeile ist da und
            // ihr Inhalt fort. „Leer" ist dafür die richtige Auskunft — es gibt
            // nichts mehr zu holen, und ein zweiter Versuch ändert daran nichts.
            return [];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $key): array
    {
        return $this->probe($key) ?? [];
    }
}
