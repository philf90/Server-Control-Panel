<?php

declare(strict_types=1);

use App\Support\Operations\OperationRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Begründung eines Fehlschlags passte nicht in ihre Spalte.
 *
 * **Der Anlass ist der Abnahmelauf von P5b, Punkt 8**, und er hat den
 * teuersten Fehlertyp dieses Projekts noch einmal gezeigt. Ein hochgeladener
 * Dump wollte `ALTER ROLE … SUPERUSER`. Der Agent hat ihn abgewiesen und genau
 * das gemeldet, was das Abnahmekriterium verlangt:
 *
 *     Das Zurückspielen ist gescheitert: psql:….restore.sql:74:
 *     ERROR:  permission denied to alter role
 *     DETAIL:  Only roles with the SUPERUSER attribute may change the
 *     SUPERUSER attribute.
 *
 * Zweihundertsechzig Zeichen. Die Spalte war `varchar(255)`, angelegt am
 * 2. August 2026 als `$table->string('message')` — die Voreinstellung, über die
 * nie jemand nachgedacht hat.
 *
 * ## Was daraus wurde, und warum es niemand sah
 *
 * `OperationRecorder::fail()` schrieb die Meldung, MariaDB wies sie ab
 * (`SQLSTATE[22001]: Data too long`), und die `PDOException` flog aus dem
 * `catch (AgentException)`-Zweig heraus, der sie gerade festhalten wollte. Der
 * Auftrag starb, Laravel rief `RunAgentOperation::failed()`, und der Vorgang
 * stand noch offen — also bekam er die Meldung dieses Handlers: *„Der Vorgang
 * wurde von der Warteschlange abgebrochen — vermutlich Zeitüberschreitung."*
 *
 * Der Vorgang lief **eine Sekunde**.
 *
 * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
 *
 * Und die Pointe steht in der Länge: Je wichtiger die Begründung, desto länger
 * ist sie. „Datei nicht gefunden" passte immer. Die abgewiesene Anweisung eines
 * fremden Dumps — die einzige Auskunft, die Kriterium 5 verlangt — passte nie.
 *
 * ## `text` und trotzdem gekürzt
 *
 * Die Spalte fasst jetzt 65535 Byte, und {@see OperationRecorder}
 * kürzt zusätzlich. Das ist keine doppelte Sicherung aus Zaghaftigkeit: Ein
 * Agent, der die Ausgabe eines fehlgeschlagenen Kommandos durchreicht, kann
 * beliebig viel liefern, und die nächste Grenze wäre wieder eine, an der der
 * Fehlerweg scheitert. Wer eine Grenze setzt, hält sie selbst ein.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->text('message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->string('message')->nullable()->change();
        });
    }
};
