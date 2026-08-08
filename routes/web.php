<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsSettingsController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\MailSettingsController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\OperationStreamController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PhpSettingsController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionDnsController;
use App\Http\Controllers\TlsSettingsController;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Route;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Version;

/*
 * Anmeldung.
 *
 * Die einzigen Routen, die ohne Konto erreichbar sind — neben der
 * Bereitschaftsprüfung, die das Paket beim Update braucht.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

/*
 * Der zweite Schritt der Anmeldung.
 *
 * Ohne `auth`: Zwischen Passwort und zweitem Faktor ist niemand angemeldet —
 * das wartende Konto steht in der Sitzung. Wäre hier `auth` verlangt, käme
 * niemand je an diese Seite.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/two-factor', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorChallengeController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Alles Weitere setzt ein Konto voraus.
 *
 * Die Gruppe ist kein Komfort, sondern die Voreinstellung: Eine neue Route
 * entsteht innerhalb dieser Klammer und ist damit von sich aus geschützt. Wer
 * eine öffentliche Route braucht, muss sie ausdrücklich außerhalb anlegen —
 * und das fällt beim Lesen auf.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('/', OverviewController::class)->name('overview');

    /*
     * Vorgänge.
     *
     * `viewAny` lässt jedes angemeldete Konto auf die Liste; was darauf steht,
     * entscheidet die Mandantenklammer. `create` ist enger — und die Prüfung,
     * welche Aufgabe jemand auslösen darf, sitzt noch einmal dahinter im
     * Katalog (App\Support\Operations\Task).
     */
    Route::get('/operations', [OperationController::class, 'index'])
        ->middleware('can:viewAny,'.Operation::class)
        ->name('operations.index');

    Route::post('/operations', [OperationController::class, 'store'])
        ->middleware('can:create,'.Operation::class)
        ->name('operations.store');

    Route::get('/operations/{operation}', [OperationController::class, 'show'])
        ->middleware('can:view,operation')
        ->name('operations.show');

    /*
     * Abbrechen darf, wer den Vorgang sieht — er hat ihn in aller Regel selbst
     * ausgelöst. Die Policy prüft zusätzlich, dass er überhaupt noch offen ist.
     */
    Route::post('/operations/{operation}/cancel', [OperationController::class, 'cancel'])
        ->middleware('can:cancel,operation')
        ->name('operations.cancel');

    /*
     * Die Live-Ausgabe eines Vorgangs.
     *
     * Die erste Route mit einer Policy an der Aktion statt einer Eintragung
     * in der Registratur — `can:view,operation`. Die Modellbindung läuft
     * bereits unter der Mandantenklammer (siehe bootstrap/app.php), ein
     * fremder Vorgang ist deshalb schon „nicht gefunden" und erreicht die
     * Policy gar nicht erst. Sie steht trotzdem da: zwei Schichten, und
     * wenn eine ausfällt, hält die andere.
     */
    Route::get('/operations/{operation}/stream', OperationStreamController::class)
        ->middleware('can:view,operation')
        ->name('operations.stream');

    /*
     * Das Protokoll.
     *
     * `viewAny` gibt jedem angemeldeten Konto Zugang — was es dann sieht,
     * entscheidet AuditQuery::visibleTo. Die Policy an der Route ersetzt
     * diese Prüfung nicht, sie steht davor: Ohne Konto gar nichts, mit Konto
     * genau das Eigene.
     */
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('can:viewAny,'.AuditEvent::class)
        ->name('audit');

    Route::get('/audit/export', [AuditController::class, 'export'])
        ->middleware('can:viewAny,'.AuditEvent::class)
        ->name('audit.export');

    /*
     * Kunden — die Betreiberseite.
     */
    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('can:viewAny,'.Customer::class)
        ->name('customers.index');

    Route::get('/customers/create', [CustomerController::class, 'create'])
        ->middleware('can:create,'.Customer::class)
        ->name('customers.create');

    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('can:create,'.Customer::class)
        ->name('customers.store');

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('can:view,customer')
        ->name('customers.show');

    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])
        ->middleware('can:update,customer')
        ->name('customers.edit');

    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('can:update,customer')
        ->name('customers.update');

    /*
     * Sperren und freigeben — mit den Abonnements.
     *
     * `can:suspend` und nicht `can:update`: Das Bearbeiten ändert einen
     * Datensatz, das Sperren nimmt einem Kunden seine Abonnements vom Netz.
     */
    Route::post('/customers/{customer}/suspend', [CustomerController::class, 'suspend'])
        ->middleware('can:suspend,customer')
        ->name('customers.suspend');

    Route::post('/customers/{customer}/resume', [CustomerController::class, 'resume'])
        ->middleware('can:suspend,customer')
        ->name('customers.resume');

    /*
     * Zurückziehen, nicht löschen.
     *
     * `DELETE` als Methode, weil das die Absicht des Aufrufers ist; was
     * daraus wird, ist ein `deleted_at` — die Kundennummer bleibt vergeben.
     * Der Controller weist ab, solange Abonnements laufen.
     */
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
        ->middleware('can:delete,customer')
        ->name('customers.destroy');

    /*
     * Pläne — die Vorlage für die Kontingente eines Abonnements (§5.2).
     *
     * Durchgehend Betreibersache, deshalb an jeder Route eine Policy. Ein
     * Kunde sieht seine Kontingente an seinem Abonnement und nicht hier; was
     * er sieht, ist der Stand, nicht die Vorlage.
     */
    Route::get('/plans', [PlanController::class, 'index'])
        ->middleware('can:viewAny,'.Plan::class)
        ->name('plans.index');

    Route::get('/plans/create', [PlanController::class, 'create'])
        ->middleware('can:create,'.Plan::class)
        ->name('plans.create');

    Route::post('/plans', [PlanController::class, 'store'])
        ->middleware('can:create,'.Plan::class)
        ->name('plans.store');

    Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])
        ->middleware('can:update,plan')
        ->name('plans.edit');

    Route::put('/plans/{plan}', [PlanController::class, 'update'])
        ->middleware('can:update,plan')
        ->name('plans.update');

    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])
        ->middleware('can:delete,plan')
        ->name('plans.destroy');

    /*
     * Abonnements (docs/26).
     *
     * `viewAny` lässt jedes Konto auf die Liste; was darauf steht, entscheidet
     * die Mandantenklammer — ein Kunde sieht seine eigenen. Alles Ändernde
     * bleibt beim Betreiber.
     */
    Route::get('/subscriptions', [SubscriptionController::class, 'index'])
        ->middleware('can:viewAny,'.Subscription::class)
        ->name('subscriptions.index');

    Route::get('/subscriptions/create', [SubscriptionController::class, 'create'])
        ->middleware('can:create,'.Subscription::class)
        ->name('subscriptions.create');

    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->middleware('can:create,'.Subscription::class)
        ->name('subscriptions.store');

    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])
        ->middleware('can:view,subscription')
        ->name('subscriptions.show');

    /*
     * Plan wechseln und Kontingente übersteuern (§5.2).
     *
     * `can:update` und nicht `can:view`: Der Kunde sieht seine Grenzen an
     * seinem Abonnement, ändern darf sie der Betreiber. Was ein Kunde davon
     * sieht, entscheidet die Show-Seite, nicht diese Route.
     */
    Route::get('/subscriptions/{subscription}/edit', [SubscriptionController::class, 'edit'])
        ->middleware('can:update,subscription')
        ->name('subscriptions.edit');

    Route::patch('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])
        ->middleware('can:update,subscription')
        ->name('subscriptions.update');

    Route::post('/subscriptions/{subscription}/suspend', [SubscriptionController::class, 'suspend'])
        ->middleware('can:suspend,subscription')
        ->name('subscriptions.suspend');

    Route::post('/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume'])
        ->middleware('can:suspend,subscription')
        ->name('subscriptions.resume');

    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
        ->middleware('can:delete,subscription')
        ->name('subscriptions.destroy');

    /*
     * Die eigenen DNS-Zugangsdaten eines Abonnements (docs/34 §5, Schritt 6b).
     *
     * `can:manageDns` und nicht `can:update`: Es geht nicht um die Stammdaten
     * des Abonnements — die gehören dem Betreiber —, sondern um die Freigabe
     * `dns_edit` aus dem Plan. Ohne sie gilt das Profil des Betreibers, und
     * dann gibt es hier nichts zu hinterlegen.
     *
     * Das Profil steht nicht in der Adresse: Es wird aus dem Abonnement
     * abgeleitet, sonst könnte ein Kunde das eines anderen nennen.
     */
    Route::put('/subscriptions/{subscription}/dns', [SubscriptionDnsController::class, 'store'])
        ->middleware('can:manageDns,subscription')
        ->name('subscriptions.dns.store');

    Route::delete('/subscriptions/{subscription}/dns', [SubscriptionDnsController::class, 'destroy'])
        ->middleware('can:manageDns,subscription')
        ->name('subscriptions.dns.forget');

    /*
     * Domains (P3, §9).
     *
     * **Angelegt wird am Abonnement, alles Weitere an der Domain.** Der
     * Unterschied steht in der Adresse und in der Policy: `can:create` fragt
     * am Abonnement, in dem die Domain entstehen soll — ohne es liesse sich
     * nur fragen, ob dieses Konto *irgendwo* Domains anlegen darf.
     *
     * `viewAny` lässt jedes Konto auf die Liste; was darauf steht, entscheidet
     * die Mandantenklammer. Ein Kunde sieht dort seine Domains, der Betreiber
     * alle.
     */
    Route::get('/domains', [DomainController::class, 'index'])
        ->middleware('can:viewAny,'.Domain::class)
        ->name('domains.index');

    Route::get('/subscriptions/{subscription}/domains/create', [DomainController::class, 'create'])
        ->middleware('can:create,'.Domain::class.',subscription')
        ->name('domains.create');

    Route::post('/subscriptions/{subscription}/domains', [DomainController::class, 'store'])
        ->middleware('can:create,'.Domain::class.',subscription')
        ->name('domains.store');

    Route::get('/domains/{domain}', [DomainController::class, 'show'])
        ->middleware('can:view,domain')
        ->name('domains.show');

    Route::patch('/domains/{domain}', [DomainController::class, 'update'])
        ->middleware('can:update,domain')
        ->name('domains.update');

    /*
     * Entfernen nimmt das Verzeichnis mit — so hat der Betreiber es
     * festgelegt. Die Rückfrage in der Oberfläche nennt den Pfad, bevor
     * jemand bestätigt.
     */
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])
        ->middleware('can:delete,domain')
        ->name('domains.destroy');

    /*
     * Ein Zertifikat für diese Domain bestellen.
     *
     * **Von Hand, obwohl es von selbst passiert.** Der Lebenslauf bestellt,
     * sobald der Server-Block steht; scheitert die Prüfung — falscher
     * DNS-Eintrag, Port 80 zu —, wartet die Domain sonst auf den nächsten
     * Anlass, den es nicht gibt. Wer den Eintrag gerade berichtigt hat, will
     * es jetzt versuchen und nicht morgen.
     *
     * Dieselbe Fähigkeit wie beim Ändern der Domain: Wer sie einstellen darf,
     * darf auch ihr Zertifikat bestellen.
     */
    Route::post('/domains/{domain}/certificate', [DomainController::class, 'certificate'])
        ->middleware('can:update,domain')
        ->name('domains.certificate');

    /*
     * Ein eigenes Zertifikat hinterlegen.
     *
     * **Eine eigene Fähigkeit und nicht `update`.** Wer hier hochlädt, legt
     * einen privaten Schlüssel auf den Server, und was danach ausgeliefert
     * wird, sieht jeder Besucher. Ob ein Kunde das darf, entscheidet der Plan
     * über `certificate_upload` — die Freigabe gibt es dort seit P2.
     */
    Route::post('/domains/{domain}/certificate/upload', [DomainController::class, 'uploadCertificate'])
        ->middleware('can:uploadCertificate,domain')
        ->name('domains.certificate.upload');

    /*
     * Auswählen, welches Zertifikat diese Domain ausliefert.
     *
     * **Dieselbe Fähigkeit wie beim Ändern der Domain.** Gewählt wird unter
     * dem, was dem eigenen Abonnement gehört und alle Namen deckt — es kommt
     * also nichts hinzu, was jemand nicht ohnehin hätte. Wer die Domain
     * einstellen darf, entscheidet auch, welches ihrer Zertifikate gilt.
     */
    Route::put('/domains/{domain}/certificate', [DomainController::class, 'chooseCertificate'])
        ->middleware('can:update,domain')
        ->name('domains.certificate.choose');

    /*
     * Die Protokolle einer Domain.
     *
     * Eigene Fähigkeit statt `view`: Ein Fehlerprotokoll enthält Pfade,
     * Dateinamen und Bruchstücke aus dem Quelltext. Wer Dateien nicht lesen
     * darf, soll sie nicht über diesen Umweg sehen.
     */
    Route::get('/domains/{domain}/logs', [DomainController::class, 'logs'])
        ->middleware('can:viewLogs,domain')
        ->name('domains.logs');

    /*
     * Datenbanken (P5, docs/36).
     *
     * **Angelegt wird am Abonnement, alles Weitere an der Datenbank** —
     * dieselbe Aufteilung wie bei den Domains in P3, und sie steht in der
     * Adresse: `can:create` fragt am Abonnement, in dem die Datenbank entstehen
     * soll. Ohne es liesse sich nur fragen, ob dieses Konto *irgendwo*
     * Datenbanken anlegen darf.
     *
     * `viewAny` lässt jedes Konto auf die Liste; was darauf steht, entscheidet
     * die Mandantenklammer.
     */
    Route::get('/databases', [DatabaseController::class, 'index'])
        ->middleware('can:viewAny,'.Database::class)
        ->name('databases.index');

    Route::get('/subscriptions/{subscription}/databases/create', [DatabaseController::class, 'create'])
        ->middleware('can:create,'.Database::class.',subscription')
        ->name('databases.create');

    Route::post('/subscriptions/{subscription}/databases', [DatabaseController::class, 'store'])
        ->middleware('can:create,'.Database::class.',subscription')
        ->name('databases.store');

    Route::get('/databases/{database}', [DatabaseController::class, 'show'])
        ->middleware('can:view,database')
        ->name('databases.show');

    /*
     * Entfernen nimmt die Daten mit, und die Rückfrage sagt das.
     *
     * Es gibt keine Sicherung davor — dieselbe Lage wie beim Rückbau eines
     * Abonnements (`docs/26 §6` und `§13`): Sie kommt mit P8, und solange es
     * sie nicht gibt, ist das Entfernen endgültig.
     */
    Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])
        ->middleware('can:delete,database')
        ->name('databases.destroy');

    /*
     * Die Zugänge einer Datenbank.
     *
     * `can:update` und nicht `can:delete`: Einen Zugang anzulegen oder zu
     * entfernen ändert die Datenbank, es wirft sie nicht weg. Wer die eine
     * Fähigkeit hat, hat auch die andere — die Trennung steht trotzdem da,
     * damit sie sich später auseinandernehmen lässt, ohne dass jemand eine
     * Route umschreiben muss.
     */
    Route::post('/databases/{database}/users', [DatabaseController::class, 'storeUser'])
        ->middleware('can:update,database')
        ->name('databases.users.store');

    /*
     * Ein neues Passwort — der einzige Weg zurück zu einem verlorenen.
     *
     * Es liegt nirgends (`docs/36 §4`), also gibt es kein Nachschlagen. Die
     * Route heisst deshalb `password` und nicht `password.show`.
     */
    Route::post('/databases/{database}/users/{user}/password', [DatabaseController::class, 'resetPassword'])
        ->middleware('can:update,database')
        ->name('databases.users.password');

    /*
     * Einen vorhandenen Zugang mit dieser Datenbank verbinden oder die
     * Verbindung lösen — dieselbe Adresse, die Richtung steht im Rumpf.
     *
     * **Zwei Knöpfe, ein Weg.** `db.user.grant` kennt `grant` und `revoke` als
     * einen Aufruf mit einem Schalter; zwei Adressen dafür wären zwei Fassungen
     * derselben Regel, und die eine driftet. Die Operation und
     * `Databases::grant()` gab es seit P5 — nur hat sie bis zum 8. August 2026
     * niemand aufgerufen (docs/36 §22.3o).
     */
    Route::put('/databases/{database}/users/{user}', [DatabaseController::class, 'access'])
        ->middleware('can:update,database')
        ->name('databases.users.access');

    Route::delete('/databases/{database}/users/{user}', [DatabaseController::class, 'destroyUser'])
        ->middleware('can:update,database')
        ->name('databases.users.destroy');

    /*
     * Sichern, herunterladen, zurückspielen (docs/36 §10).
     *
     * **Alles an `can:update`, auch das Zurückspielen.** Es liegt nahe, dafür
     * `can:delete` zu verlangen — es überschreibt schliesslich Daten. Nur wäre
     * das eine zweite Fassung derselben Frage: Wer die Datenbank ändern darf,
     * darf ihre Tabellen ohnehin leeren. Die Schranke, die zählt, sitzt
     * woanders — der Dump läuft unter einem befristeten Benutzer mit Rechten
     * auf genau diese eine Datenbank, und ein `GRANT … ON *.*` darin scheitert.
     *
     * **Das Herunterladen ist die Ausnahme und trägt `can:view`.** Es ändert
     * nichts; wer die Datenbank sehen darf, darf ihre Sicherung holen. Sie
     * enthält allerdings alles, was in der Datenbank steht — deshalb steht die
     * Datei unter `/var/lib/srvpanel/dumps` und nicht im Abo-Verzeichnis, wo
     * ein Webserver sie ausliefern könnte.
     */
    Route::post('/databases/{database}/dumps', [DatabaseController::class, 'export'])
        ->middleware('can:update,database')
        ->name('databases.dumps.store');

    /*
     * Eine mitgebrachte Sicherung — die einzige Stelle in P5, an der eine
     * Datei von aussen hereinkommt (docs/36 §22.3u). Sie landet im
     * Schreibbereich des Panels, und der Agent holt sie von dort ab; über den
     * Socket geht ein halbes Gigabyte nicht.
     */
    Route::post('/databases/{database}/dumps/import', [DatabaseController::class, 'import'])
        ->middleware('can:update,database')
        ->name('databases.dumps.import');

    Route::get('/databases/{database}/dumps/{dump}', [DatabaseController::class, 'download'])
        ->middleware('can:view,database')
        ->name('databases.dumps.download');

    Route::post('/databases/{database}/dumps/{dump}/restore', [DatabaseController::class, 'restore'])
        ->middleware('can:update,database')
        ->name('databases.dumps.restore');

    Route::delete('/databases/{database}/dumps/{dump}', [DatabaseController::class, 'destroyDump'])
        ->middleware('can:update,database')
        ->name('databases.dumps.destroy');

    /*
     * Die PHP-Versionen des Servers (§4.3).
     *
     * Dieselbe Fähigkeit wie Mailversand und Zertifikat: Es gibt kein Modell,
     * dem diese Einstellungen gehören. Installieren und Entfernen laufen von
     * hier aus über den Aufgabenkatalog — die Prüfung steht an dessen Route.
     */
    Route::get('/settings/php', [PhpSettingsController::class, 'show'])
        ->middleware('can:manage-settings')
        ->name('settings.php');

    /*
     * „Anmelden als" (§6.3).
     *
     * Der Beginn braucht die Fähigkeit `impersonate` am Kunden. Der Rückweg
     * nicht: Wer schon in fremder Sicht ist, ist in diesem Moment ein
     * Kundenkonto und hätte sie nicht mehr — die Prüfung stünde ihm dann
     * ausgerechnet beim Zurückkommen im Weg.
     */
    Route::post('/customers/{customer}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('can:impersonate,customer')
        ->name('impersonation.start');

    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonation.stop');

    /*
     * Das eigene Konto.
     *
     * Es gehört jedem angemeldeten Konto und keinem bestimmten Objekt —
     * deshalb keine Policy, sondern die Anmeldung als Schranke. Wer hier
     * ändert, ändert sich selbst; `$request->user()` ist der einzige Weg an
     * das Ziel, eine ID aus der Anfrage gibt es nicht.
     */
    Route::get('/settings/profile', [ProfileController::class, 'show'])
        ->name('profile');
    Route::patch('/settings/profile', [ProfileController::class, 'update'])
        ->name('profile.update');
    Route::put('/settings/password', [ProfileController::class, 'password'])
        ->name('profile.password');

    /*
     * Die Darstellung — hell, dunkel oder das, was das Betriebssystem sagt.
     *
     * Eigene Route und nicht Teil von `profile.update`: Jene verlangt das
     * aktuelle Passwort, und das ist für einen Umschalter die falsche Hürde.
     * Eine Rückfrage nach dem Passwort für eine Farbe erzieht dazu, es
     * beiläufig einzutippen — genau das, was die Hürde dort verhindern soll.
     */
    Route::put('/settings/theme', [ProfileController::class, 'theme'])
        ->name('profile.theme');

    /*
     * Mailversand über ein Relay (docs/25).
     *
     * `can:manage-settings` ist eine Fähigkeit und keine Policy: Es gibt kein
     * Modell, dem diese Einstellungen gehören. Die mechanische Routenprüfung
     * nimmt beide Formen an — Hauptsache, an der Route steht eine Prüfung.
     */
    Route::get('/settings/mail', [MailSettingsController::class, 'show'])
        ->middleware('can:manage-settings')
        ->name('settings.mail');

    Route::put('/settings/mail', [MailSettingsController::class, 'update'])
        ->middleware('can:manage-settings')
        ->name('settings.mail.update');

    Route::post('/settings/mail/test', [MailSettingsController::class, 'test'])
        ->middleware('can:manage-settings')
        ->name('settings.mail.test');

    /*
     * Das Zertifikat der Oberfläche (docs/27).
     *
     * Ansehen und neu ausstellen — dieselbe Fähigkeit wie beim Mailversand:
     * Es gibt kein Modell, dem diese Einstellung gehört.
     */
    Route::get('/settings/tls', [TlsSettingsController::class, 'show'])
        ->middleware('can:manage-settings')
        ->name('settings.tls');

    Route::post('/settings/tls', [TlsSettingsController::class, 'store'])
        ->middleware('can:manage-settings')
        ->name('settings.tls.reissue');

    /*
     * Kontaktadresse und Zertifizierungsstelle für ACME.
     *
     * Ohne die Adresse bestellt das Panel nichts — dieses Formular ist der
     * Schalter, mit dem TLS für die Kunden überhaupt anfängt. Bis P4 gab es
     * ihn nur auf der Kommandozeile.
     */
    Route::put('/settings/tls/acme', [TlsSettingsController::class, 'acme'])
        ->middleware('can:manage-settings')
        ->name('settings.tls.acme');

    /*
     * Die DNS-Zugangsdaten des Betreibers (docs/34 §5, Schritt 6b).
     *
     * Dieselbe Fähigkeit wie beim Mailversand und beim Panel-Zertifikat: Es
     * gibt kein Modell, dem diese Einstellung gehört. Das Profil `betrieb`
     * steht nicht in der Adresse — es wird abgeleitet, nicht entgegengenommen.
     *
     * **Ohne Warteschlange, mit Absicht.** Ein eingereihter Vorgang legt seine
     * Argumente in `operations.payload` ab; ein DNS-Token läge damit dauerhaft
     * im Klartext in der Datenbank.
     */
    Route::get('/settings/dns', [DnsSettingsController::class, 'show'])
        ->middleware('can:manage-settings')
        ->name('settings.dns');

    Route::put('/settings/dns', [DnsSettingsController::class, 'store'])
        ->middleware('can:manage-settings')
        ->name('settings.dns.store');

    Route::delete('/settings/dns', [DnsSettingsController::class, 'destroy'])
        ->middleware('can:manage-settings')
        ->name('settings.dns.forget');

    /*
     * Den zweiten Faktor einrichten oder abschalten.
     */
    Route::get('/settings/two-factor', [TwoFactorSetupController::class, 'show'])
        ->name('two-factor.setup');
    Route::post('/settings/two-factor', [TwoFactorSetupController::class, 'store'])
        ->name('two-factor.setup.store');
    Route::delete('/settings/two-factor', [TwoFactorSetupController::class, 'destroy'])
        ->name('two-factor.setup.destroy');
});

/*
 * Bereitschaftsprüfung.
 *
 * Sie ist die Bedingung, unter der ein Update übernommen wird: Antwortet sie
 * nach dem Umschalten nicht mit „bereit", geht das Paket auf die vorige
 * Fassung zurück. Deshalb prüft sie den Agenten mit — eine Anwendung, die
 * läuft, aber nicht ins System kommt, ist kein brauchbarer Zustand.
 *
 * Ohne Anmeldung erreichbar, und das mit Absicht: Sie läuft, während das
 * Paket umschaltet, und es gibt in diesem Moment niemanden, der angemeldet
 * wäre. Sie gibt nur Fassungsnummern und einen Bereitschaftszustand heraus.
 */
Route::get('/health', function (Client $agent) {
    $agentUp = $agent->reachable();

    return response()->json([
        'ready' => $agentUp,
        'app' => config('app.version'),
        'protocol' => Version::PROTOCOL,
        'agent' => $agentUp ? 'reachable' : 'nicht erreichbar',
    ], $agentUp ? 200 : 503);
})->name('health');
