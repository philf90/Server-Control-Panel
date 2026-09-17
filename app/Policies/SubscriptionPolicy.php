<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Subscription;
use App\Support\Plans\Feature;

/**
 * Wer was mit einem Abonnement darf.
 *
 * **Kein `Gate::before` für Admins.** Der bequeme Weg wäre eine einzige Zeile
 * in einem Provider: „ist der Anmelder ein Admin, darf er alles". Sie hat
 * einen Haken, der erst spät auffällt — sie beantwortet auch Fragen, die es
 * gar nicht gibt. Wird eine Policy-Methode umbenannt oder ein Fähigkeitsname
 * vertippt, liefert `$admin->can('vertipptes-recht')` weiterhin `true`, und
 * der Fehler zeigt sich ausschließlich bei Kunden. Deshalb steht die
 * Adminzeile in jeder Policy einzeln: Ein Tippfehler scheitert dann für alle,
 * sofort und sichtbar.
 *
 * **Zwei Fragen, nicht eine.** Bei einem Zusatzbenutzer entscheidet die
 * Zuordnung, *ob* er an das Abonnement darf, und der Rechtekatalog, *was* er
 * darin darf. Beides muss zutreffen. Eine Prüfung, die nur eines von beidem
 * ansieht, ist entweder zu streng oder zu großzügig — und zu großzügig fällt
 * niemandem auf.
 */
final class SubscriptionPolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->isAdmin() || $account->accessibleSubscriptionIds() !== [];
    }

    public function view(Account $account, Subscription $subscription): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        return $account->mayAccessSubscription($subscription);
    }

    /** Anlegen und Löschen bleiben beim Betreiber. */
    public function create(Account $account): bool
    {
        return $account->isAdmin();
    }

    public function delete(Account $account, Subscription $subscription): bool
    {
        return $account->isAdmin();
    }

    /**
     * Stammdaten ändern — Name, Plan, Zustand.
     *
     * Ein Kunde darf sein Abonnement nicht umschreiben; das ist der Vertrag.
     * Was er darin tun darf, regeln die Policies der Objekte darunter.
     */
    public function update(Account $account, Subscription $subscription): bool
    {
        return $account->isAdmin();
    }

    /** Sperren und Entsperren ist Betreibersache. */
    public function suspend(Account $account, Subscription $subscription): bool
    {
        return $account->isAdmin();
    }

    /**
     * Die eigenen DNS-Zugangsdaten hinterlegen (`docs/34 §5`).
     *
     * **Warum das eine eigene Fähigkeit ist und nicht `useFeature(Dns)`.** Es
     * ist dieselbe Freigabe — `Feature::DnsEdit` mit `Permission::Dns` —, aber
     * die Frage lautet hier nicht „darf dieses Konto DNS-Einträge bearbeiten",
     * sondern „gibt es für dieses Abonnement überhaupt ein eigenes Profil".
     * Ohne die Freigabe im Plan gilt das Profil des Betreibers, und dann gibt
     * es hier nichts zu hinterlegen — kein Formular, kein Knopf, keine Route.
     *
     * **Der Admin ist hier ausdrücklich nicht pauschal dabei.** Er darf sonst
     * alles, aber ein Abonnement ohne `DnsEdit` hat kein eigenes Profil; ein
     * Formular, das für ihn erscheint und ein Profil anlegt, das nie gefragt
     * wird, wäre eine Ablage ohne Leser. Was der Betreiber hinterlegen will,
     * gehört unter `betrieb` — dafür gibt es die Seite in den Einstellungen.
     */
    public function manageDns(Account $account, Subscription $subscription): bool
    {
        if (! $subscription->feature(Feature::DnsEdit->value)) {
            return false;
        }

        return $this->useFeature($account, $subscription, Permission::Dns);
    }

    /**
     * Den Dateimanager öffnen — lesend.
     *
     * **Der Sichtbereich ist die ganze Abo-Wurzel** (`docs/51 §3`,
     * Entscheidung 5), also derselbe Ausschnitt, den der Kunde per SFTP ohnehin
     * sieht. Ein Panel, das weniger zeigte als der Zugang daneben, wäre eine
     * zweite Fassung derselben Regel — und die zweite ist die, die veraltet.
     *
     * **Was er darin nicht ändern darf, verhindern die Dateirechte und nicht
     * diese Methode.** `conf/` gehört `root:root 0755`; die Sandbox läuft als
     * der Kunde, also weist das Dateisystem den Schreibversuch ab. Eine Liste
     * verbotener Verzeichnisse im Panel wäre eine zweite Durchsetzung derselben
     * Grenze, und sie ginge beim nächsten Schema-Zuwachs auseinander.
     */
    /**
     * Den SFTP-Zugang verwalten — Schlüssel ansehen, eintragen, entfernen.
     *
     * **Am Recht `FtpAccounts` und nicht an einem neuen.** Der Katalog führt es
     * seit P0 unter diesem Wert; P6 baut ausdrücklich kein FTP (`docs/51 §13`),
     * und SFTP ist das, was an seine Stelle tritt. Der gespeicherte Wert bleibt,
     * die Beschriftung heisst „SFTP-Zugang" — eine Migration über
     * `account_subscription` wäre der Preis dafür, dass eine Zeichenkette
     * hübscher aussieht.
     *
     * **Und es ist nicht `FilesWrite`.** Wer Dateien im Panel ändern darf, darf
     * damit noch lange keinen dauerhaften Zugang von aussen einrichten: Ein
     * Schlüssel überlebt den Entzug des Panel-Zugangs, wenn niemand daran denkt.
     */
    public function manageSftp(Account $account, Subscription $subscription): bool
    {
        return $this->useFeature($account, $subscription, Permission::FtpAccounts);
    }

    /**
     * Die Zeitsteuerung verwalten — Jobs ansehen, anlegen, ändern, entfernen.
     *
     * **Am Recht `Cron`**, das der Katalog seit P0 führt. Es gibt hier nichts
     * abzuleiten: Ein Cronjob ist eine eigene Fähigkeit und keine Spielart des
     * Dateizugriffs.
     *
     * **Und es ist ausdrücklich nicht `FilesWrite`**, aus demselben Grund, aus
     * dem es bei {@see self::manageSftp()} nicht `FilesWrite` ist — nur schärfer:
     * Wer eine Datei schreiben darf, hat damit noch nicht die Erlaubnis, sie
     * jede Minute **ausführen** zu lassen. Ein Cronjob überlebt den Entzug des
     * Panel-Zugangs, wenn niemand daran denkt.
     *
     * > **Etwas ablegen zu dürfen ist nicht dasselbe, wie es ausführen zu
     * > lassen.**
     */
    public function manageCron(Account $account, Subscription $subscription): bool
    {
        return $this->useFeature($account, $subscription, Permission::Cron);
    }

    /**
     * Sicherungen verwalten — ansehen, anlegen, herunterladen, entfernen.
     *
     * **Am Recht `Backups`, und das ist der Befund, der diese Methode
     * ausgelöst hat.** `Permission::Backups` und `Feature::Backups` gibt es
     * seit P0, beide sind aufeinander abgebildet — und **bis P8 hat niemand
     * sie gefragt.** Ein Plan konnte „Sicherungen" freigeben oder verweigern,
     * und es bedeutete nichts.
     *
     * > **Ein Recht, das keine Policy fragt, ist von aussen nicht von einem zu
     * > unterscheiden, das es nicht gibt.** Derselbe Fall wie `context` im
     * > Protokoll (`docs/66`) und `Settings::saveDnsAddresses()` (`docs/74`),
     * > nur an einer Berechtigung.
     *
     * **Und ausdrücklich nicht `FilesRead`.** Eine Sicherung enthält den
     * ganzen Baum des Abonnements samt seinen Datenbanken — und nach
     * `docs/117 §4` auch den privaten Schlüssel eines hochgeladenen
     * Zertifikats. Wer eine einzelne Datei lesen darf, hat damit nicht die
     * Erlaubnis, alles auf einmal herunterzuladen.
     *
     * > **Etwas ansehen zu dürfen ist nicht dasselbe, wie es mitnehmen zu
     * > dürfen.**
     */
    public function manageBackups(Account $account, Subscription $subscription): bool
    {
        return $this->useFeature($account, $subscription, Permission::Backups);
    }

    /**
     * Die Sicherungen **ohne** Abonnement — ansehen und entfernen.
     *
     * **Sie gehören keinem Kunden mehr**, und deshalb kann keine Frage an ein
     * Abonnement sie beantworten: `backups.subscription_id` steht auf
     * `nullOnDelete`, damit die Sicherung ihren Rückbau überlebt
     * ({@see Backup}). Was bleibt, ist der abgeschriebene Name —
     * und die Frage, wer ihn sehen darf.
     *
     * **Der Befund, für den es diese Methode gibt** (P8, 17. September 2026):
     * `BackupController::pick()` fragte hier `create, Subscription` — eine
     * Fähigkeit, die etwas anderes bedeutet und heute dasselbe ergibt. Würde
     * sie je gelockert (etwa: ein Kunde darf ein Abonnement anlegen), bekäme
     * derselbe Kunde damit die Sicherungen **fremder** Abonnements zu sehen.
     *
     * > **Zwei Fragen, die heute dieselbe Antwort haben, bleiben nicht
     * > dieselbe Frage — und welche der beiden sich ändert, entscheidet
     * > niemand, der die andere gemeint hat.**
     *
     * Am **Typ** und nicht an der Rolle: Ein Administrator verwaltet die
     * Abonnements dieses Servers, und eine Sicherung ohne Abonnement ist genau
     * das. Wer die Datei **herausgeben** darf, ist eine engere Frage und steht
     * in {@see self::downloadBackup()}.
     */
    public function manageOrphanedBackups(Account $account): bool
    {
        return $account->isAdmin();
    }

    /**
     * Und die fertige Datei herausgeben — enger als sie verwalten.
     *
     * **Seit P8 trägt eine Sicherung ein Geheimnis**: den privaten Schlüssel
     * eines *hochgeladenen* Zertifikats. Er steht nirgends sonst
     * (`certificates` führt `storage_name` und kein Schlüsselmaterial), und
     * ohne ihn ist ein hochgeladenes Zertifikat nach einer Wiederherstellung
     * verloren — deshalb gehört er hinein (`docs/117 §4`).
     *
     * **Damit reicht {@see self::manageBackups()} für diesen einen Griff
     * nicht.** Es löst über {@see self::useFeature()} auf, und das gibt bei
     * `isAdmin()` sofort durch — und `isAdmin()` fragt den **Typ** und nicht
     * die Rolle. Jeder Administrator hätte mit jeder Sicherung den privaten
     * Schlüssel jedes Kunden bekommen, der eines hochgeladen hat.
     *
     * > **Ein Ablageort, der ein Geheimnis vor dem Dateisystem schützt, sagt
     * > nichts darüber, wer den Knopf drücken darf, der es herausgibt.**
     *
     * `docs/117 §4` hat den Schlüssel gegen das Dateisystem abgewogen
     * (`root:srvpanel 0640`, ausserhalb des Kunden-Chroots) und gegen den
     * Kunden, der seine eigene Sicherung lädt. Die Rolle aus A9 kam darin nicht
     * vor.
     *
     * **Dieselbe Grenze wie bei `/logs`:** Ein Stacktrace trägt die
     * Zugangsdaten der Datenbank, deshalb gehört die Seite dem Betreiber
     * allein. Ein privater TLS-Schlüssel ist dieselbe Art Inhalt.
     *
     * **Der Kunde bleibt drin**, und das ist keine Ausnahme: Es ist sein
     * Schlüssel, den er selbst hochgeladen hat.
     *
     * Dies ist die **erste** Policy dieses Panels, die nach der Rolle fragt.
     * Die anderen Betreiberstellen sind Routen ohne Modell und tragen deshalb
     * `can:operate-server`; hier geht das nicht, weil der Kunde durchkommen
     * muss und der Gate ihn abwiese.
     */
    public function downloadBackup(Account $account, Subscription $subscription): bool
    {
        if ($account->isAdmin()) {
            return $account->isOperator();
        }

        return $this->useFeature($account, $subscription, Permission::Backups);
    }

    public function browseFiles(Account $account, Subscription $subscription): bool
    {
        return $this->useFeature($account, $subscription, Permission::FilesRead);
    }

    /**
     * Und schreibend.
     *
     * **Getrennt von {@see self::browseFiles()}, weil `docs/23` es trennt.**
     * `Permission::FilesRead` und `FilesWrite` stehen seit P1 nebeneinander im
     * Rechtemodell und hatten bis P6 keinen Benutzer; ein Zusatzbenutzer, der
     * nachsehen darf und nichts kaputt machen kann, ist genau der Fall, für den
     * die Trennung gedacht war.
     *
     * Wer schreiben darf, darf auch lesen — die Oberfläche zeigt keinen Baum,
     * in den man nur hineinschreiben kann.
     */
    public function editFiles(Account $account, Subscription $subscription): bool
    {
        return $this->browseFiles($account, $subscription)
            && $this->useFeature($account, $subscription, Permission::FilesWrite);
    }

    /**
     * Eine Fachfunktion innerhalb des Abonnements.
     *
     * Der Einstieg für alles, was ab P2 dazukommt: Datenbanken, DNS, Cron,
     * Sicherungen. Drei Bedingungen, und alle drei müssen gelten — Zugang zum
     * Abonnement, Freigabe im Plan, Recht des Zusatzbenutzers.
     */
    public function useFeature(Account $account, Subscription $subscription, Permission $permission): bool
    {
        if ($account->isAdmin()) {
            return true;
        }

        if (! $account->mayAccessSubscription($subscription)) {
            return false;
        }

        // Ein gesperrtes Abonnement bleibt sichtbar, aber unbenutzbar. Sonst
        // wäre „gesperrt" nur ein Etikett in der Oberfläche.
        if (! $subscription->usable()) {
            return false;
        }

        if (! $this->planAllows($subscription, $permission)) {
            return false;
        }

        return $account->hasPermission($subscription, $permission);
    }

    /**
     * Gibt der Plan diese Funktion überhaupt frei?
     *
     * Die Zuordnung ist bewusst unvollständig: Was keiner Freigabe zugeordnet
     * ist, ist keine planabhängige Funktion und damit immer erlaubt — Dateien
     * lesen etwa gehört zu jedem Abonnement.
     *
     * Die Zuordnung selbst stand bis August 2026 hier als `match` mit vier
     * Zeichenketten darin. Sie steht jetzt in {@see Feature}, wo auch die
     * Beschriftung und der Vorgabewert stehen — vier Literale an einer Stelle,
     * die nichts von den Plänen weiss, waren vier Gelegenheiten für einen
     * Tippfehler, der eine Funktion stillschweigend für alle freigibt.
     */
    private function planAllows(Subscription $subscription, Permission $permission): bool
    {
        $feature = Feature::forPermission($permission);

        return $feature === null || $subscription->feature($feature->value);
    }
}
