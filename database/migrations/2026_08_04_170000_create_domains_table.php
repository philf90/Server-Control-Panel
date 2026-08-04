<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains — der erste Zweig unter dem Abonnement (§5.1).
 *
 * **`name` ist serverweit einmalig, nicht je Abonnement.** Zwei Server-Blöcke
 * mit demselben `server_name` sind für nginx kein Fehler: Es nimmt wortlos den
 * ersten und liefert dessen Inhalte aus. Wäre die Eindeutigkeit nur je
 * Abonnement erzwungen, könnte ein Kunde die Domain eines anderen eintragen
 * und je nach Reihenfolge der Konfigurationsdateien dessen Besucher bekommen —
 * ein Mandantenübergriff, der keine einzige Rechteprüfung berührt. Die
 * Datenbank ist hier die letzte Schicht, die das noch abfängt.
 *
 * **Keine weiche Löschung, anders als beim Abonnement.** Das Abonnement wird
 * zurückgezogen statt gelöscht, weil sein Systembenutzer verbraucht bleiben
 * muss: Die UID darf nicht neu vergeben werden, solange auf dem Dateisystem
 * noch etwas liegt, das ihr gehört. Bei einer Domain gibt es diesen Grund
 * nicht — mit ihr geht ihr Verzeichnis, ihr vhost, ihr Pool-Eintrag und ihr
 * Protokoll. Danach ist der Name auf dem Server nirgends mehr belegt, und ihn
 * trotzdem für immer zu sperren hiesse, dass ein versehentlich gelöschter
 * Eintrag nie wieder anlegbar wäre — auch nicht für den Kunden, dem die Domain
 * gehört. Was bleibt, steht im Protokoll: Wer wann welche Domain entfernt hat.
 *
 * **`document_root` ist relativ.** Gespeichert wird `httpdocs` oder
 * `beispiel.de/public`, nie `/var/www/vhosts/…`. Den absoluten Pfad baut der
 * Agent aus dem Namen des Abonnements — dieselbe Regel wie bei
 * `subscription.provision`, und sie ist der Grund, warum es dort keinen
 * Pfadausbruch gibt: Was nie übergeben wird, muss nicht geprüft werden.
 *
 * **Rückmigration.** `down()` wirft die Tabelle weg. Das ist verträglich mit
 * §8.1: Der Rückweg beim Update legt nur den Symlink um und nimmt keine
 * Migration zurück — eine Version ohne Domains liest diese Tabelle einfach
 * nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();

            // Der Anker der Mandantentrennung. `cascadeOnDelete` ist hier
            // Absicherung und nicht der Weg: Ein Abonnement wird über
            // `subscription.remove` zurückgebaut, und der räumt die Domains
            // mit ihren Verzeichnissen ab. Bliebe eine Zeile stehen, weil
            // jemand am Panel vorbei in der Datenbank gelöscht hat, wäre sie
            // eine Domain ohne Abonnement — und damit ohne Mandanten.
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Subdomains und Aliasse hängen an einer anderen Domain. Der
            // Fremdschlüssel zeigt in dieselbe Tabelle; `cascadeOnDelete`
            // sorgt dafür, dass ein Alias nicht die Domain überlebt, für die
            // er ein zweiter Name war.
            $table->foreignId('parent_domain_id')->nullable()
                ->constrained('domains')->cascadeOnDelete();

            $table->string('name')->unique();
            $table->string('type', 16);
            $table->string('status', 32)->default('provisioning');

            // Leer bei Alias und Weiterleitung — beide liefern keine eigenen
            // Dateien aus.
            $table->string('document_root')->nullable();

            // Ausdrücklich gespeichert und nicht bei jeder Anzeige neu
            // ausgerechnet. Eine Voreinstellung, die sich aus „der neuesten
            // installierten Version" ergäbe, würde jede Website ohne eigene
            // Wahl in dem Moment umstellen, in dem der Betreiber eine neue
            // PHP-Version installiert — eine Systemänderung ohne Handelnden,
            // und die Anwendung des Kunden merkt es als Erste.
            $table->string('php_version', 8)->nullable();

            // Übersteuerungen je Domain, gedeckelt durch den Plan (P3, §9).
            // JSON aus demselben Grund wie bei den Kontingenten: Das Fehlen
            // eines Schlüssels heißt „gilt wie vorgegeben" und ist etwas
            // anderes als ein gesetzter Wert.
            $table->json('php_settings')->nullable();

            // Eigene nginx-Direktiven, gegen eine Positivliste geprüft. Die
            // Prüfung sitzt im Agenten; hier steht nur, was jemand eingegeben
            // hat.
            $table->json('nginx_directives')->nullable();

            $table->string('redirect_target')->nullable();
            $table->string('redirect_kind', 16)->nullable();

            $table->timestamps();

            $table->index(['subscription_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
