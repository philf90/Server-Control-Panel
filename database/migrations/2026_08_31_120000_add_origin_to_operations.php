<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Von welcher Seite aus ein Vorgang ausgelöst wurde.
 *
 * **Der Befund, gegen den es diese Spalte gibt.** Einundzwanzig Weiterleitungen
 * aus sieben Controllern enden auf `operations.show`, und der Brotkrümel dort
 * trug genau eine Verknüpfung: `Vorgänge`, also die Liste aller Vorgänge. Wer
 * eine Domain anlegte, fand von dort nicht zur Domain; wer Pakete einspielte,
 * nicht zurück zu den Updates. Der Weg zurück war der Zurück-Knopf des
 * Browsers.
 *
 * > **Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe
 * > nimmt, ist keiner, den die Anwendung anbietet.**
 *
 * Gemeldet hat es der Betreiber am 31. August 2026, und zwar beim **Erklären**
 * — die Frage war, wie man denselben Knopf ein zweites Mal drückt.
 *
 * **Ein Pfad und keine volle Adresse.** Das Panel ist unter mehreren Namen
 * erreichbar; eine gespeicherte Adresse mit Rechnernamen wäre unter dem
 * zweiten falsch. Der Pfad stimmt unter jedem.
 *
 * **Nullable, und das ist eine Aussage.** Die Zertifikatsautomatik und der
 * Cron-Einsammler setzen Vorgänge ohne Sitzung ab; sie kommen von keiner Seite.
 * Ein Wert, den man dort erfände, sähe aus wie eine Auskunft.
 *
 * **Was diese Spalte nicht behebt**, steht in `docs/92`: dass man überhaupt
 * weggetragen wird. Das ist eine eigene Stufe und in `docs/20 §9` (P9)
 * vorgemerkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            // 255 reicht für jeden Pfad dieses Panels und ist die Länge, mit
            // der `varchar` hier überall arbeitet. Ein Pfad, der länger wäre,
            // gehört nicht in einen Brotkrümel — er wird beim Schreiben
            // verworfen und nicht abgeschnitten, damit kein halber Pfad
            // entsteht, der irgendwohin führt.
            $table->string('origin', 255)->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
