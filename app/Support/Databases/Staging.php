<?php

declare(strict_types=1);

namespace App\Support\Databases;

use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Guard;

/**
 * Die Übergabe für hochgeladene Sicherungen — anlegen und wieder leeren.
 *
 * **Diese Klasse gibt es, weil die zweite Hälfte fehlte.** Das Panel legte die
 * hochgeladene Datei hier ab und reichte ihren Pfad an den Agenten; bei Erfolg
 * verschiebt der sie nach `/var/lib/srvpanel/dumps`. **Scheiterte er, blieb sie
 * liegen** — für immer. Das `@unlink()` im Steuerungscode sitzt um das
 * *Einreihen* des Vorgangs, und der Agent weist erst später im Arbeiter ab.
 *
 * Am 9. August 2026 auf `cloudsrv24` gemessen: 109 MB einer abgewiesenen
 * Zip-Bombe lagen unangetastet in der Übergabe, und nichts im ganzen System
 * hätte sie je wieder angefasst — `srvpanel db --prune` sieht nur Zeilen ohne
 * Abonnement an, die Paketskripte fassen das Verzeichnis nicht an, und über das
 * Panel ist die Datei gar nicht erreichbar: Die Bestandszeile zeigt auf
 * `dumps/`, wo nie etwas ankam. Bis zu 512 MB je Versuch, ausgelöst von einem
 * Kunden.
 *
 * **Das ist die Lehre aus `docs/35`, an einer neuen Stelle:** Wer etwas
 * anlegt, das auf der Platte bleibt, baut den Weg zurück mit. Er heisst hier
 * {@see self::forget()} und wird aus {@see DbLifecycle::afterFailure()}
 * gerufen.
 */
final class Staging
{
    /**
     * Das Verzeichnis — angelegt, wenn es noch nicht da ist.
     *
     * **`storage_path()` und nicht die Konstante des Agenten.** Beide zeigen in
     * der Auslieferung auf dasselbe Verzeichnis, weil `storage` ein Verweis
     * nach `/var/lib/srvpanel/storage` ist; im Test zeigt `storage_path()`
     * woandershin, und das ist richtig so — dort läuft kein Agent. Dass die
     * beiden in der Auslieferung zusammenfallen, prüft `UploadLimitTest`, und
     * {@see Dump::STAGING_ROOT} ist die Gegenseite davon.
     */
    public static function ensure(): string
    {
        $path = self::root();

        if (! is_dir($path)) {
            // 0700: Der Inhalt ist die Datenbank eines Kunden, und ausser dem
            // Panel und dem Agenten muss dort niemand hineinsehen.
            @mkdir($path, 0o700, true);
        }

        return $path;
    }

    public static function root(): string
    {
        return storage_path('app/private/imports');
    }

    /**
     * Eine Datei aus der Übergabe entfernen — und nur von dort.
     *
     * **Der Pfad kommt aus dem Auftrag eines Vorgangs**, also aus einer Zeile
     * in der Datenbank. Er wird deshalb aufgelöst und gegen die Wurzel
     * gehalten, bevor irgendetwas gelöscht wird: Ein Symlink, der aus der
     * Übergabe herauszeigt, ist genau der Ausbruch, den {@see Guard::pathInside()}
     * auf der anderen Seite verhindert. Dieselbe Prüfung, dieselbe Begründung —
     * hier für das Löschen statt für das Lesen.
     *
     * @return bool ob tatsächlich etwas entfernt wurde
     */
    public static function forget(mixed $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        $real = realpath($path);
        $root = realpath(self::root());

        if ($real === false || $root === false) {
            return false;
        }

        if (! str_starts_with($real, rtrim($root, '/').'/')) {
            return false;
        }

        return is_file($real) && @unlink($real);
    }
}
