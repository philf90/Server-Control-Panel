<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Model;

/**
 * Wovon ein Vorgang handelt — als Aufzählung und nicht als Klassenname.
 *
 * **Der Unterschied zu Laravels polymorpher Beziehung ist der Bezug.** Dort
 * steht `App\Models\Domain` als Zeichenkette in der Datenbank, und niemand
 * prüft, ob es diese Klasse noch gibt. Hier steht `domain`, und
 * {@see self::modelClass()} beantwortet die Frage im Quelltext: Ein Tippfehler
 * fällt beim Übersetzen auf, eine Umbenennung ebenso, und ein Wert, den es in
 * dieser Version nicht mehr gibt, wird zu `null` statt zu einem Absturz.
 *
 * Die Aufzählung wächst mit den Ausbaustufen: Datenbanken sind mit P5
 * dazugekommen, Cronjobs folgen in P6, Zonen in P7. Was hier hinzukommt,
 * braucht ein Modell — und ein Test hält beides zusammen.
 */
enum OperationSubject: string
{
    case Domain = 'domain';

    /*
     * P5 — die Datenbank ist der zweite Gegenstand, und der Kommentar oben hat
     * sie angekündigt: „Die Aufzählung wächst mit den Ausbaustufen."
     */
    case Database = 'database';

    /**
     * Und die Sicherung — der Gegenstand, an dem ein `db.dump.*` hängt.
     *
     * Sie ist nicht die Datenbank: Ein Zurückspielen handelt von *dieser*
     * Sicherung, und welche es war, ist genau die Frage, die man später stellt.
     */
    case Dump = 'dump';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Domain => Domain::class,
            self::Database => Database::class,
            self::Dump => DatabaseDump::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Domain => 'Domain',
            self::Database => 'Datenbank',
            self::Dump => 'Sicherung',
        };
    }

    /**
     * Wie der Gegenstand in einer Zeile heisst.
     *
     * **Bei der Sicherung ist es der Name der Datenbank und nicht ihr eigener.**
     * `storage_name` ist ein Dateiname mit Zeitstempel; wer eine Sicherung
     * wiedererkennt, erkennt sie an der Datenbank, aus der sie stammt.
     * `database_name` wird beim Anlegen abgeschrieben und überlebt deshalb die
     * Datenbank — was hier genau richtig ist: Der Vorgang, der sie gelöscht
     * hat, ist der Grund, aus dem sie fehlt.
     */
    public function nameOf(Model $subject): string
    {
        return match ($this) {
            self::Domain, self::Database => is_string($subject->getAttribute('name'))
                ? $subject->getAttribute('name')
                : '',
            self::Dump => is_string($subject->getAttribute('database_name'))
                ? $subject->getAttribute('database_name')
                : '',
        };
    }

    /**
     * Wo man ihn ansieht — oder `null`, wenn es dafür keine Seite gibt.
     *
     * **Die Sicherung hat keine eigene Seite.** `/databases/{db}/dumps/{dump}`
     * ist ein Herunterladen und kein Ort; gezeigt wird sie auf der Seite ihrer
     * Datenbank, und dorthin führt der Verweis. Fehlt die Datenbank, führt er
     * nirgendwohin — und das ist besser als irgendwohin.
     *
     * **Die Pfade stehen hier und nicht in der Vorlage.** Eine Zeichenkette,
     * die auf eine Route zeigt, ohne dass etwas den Bezug prüft, ist der Fehler,
     * den dieses Projekt sechsmal eingeholt hat; `OperationOriginTest` löst
     * jeden dieser Pfade gegen die angemeldeten Routen auf.
     */
    public function pathTo(Model $subject): ?string
    {
        $id = $subject->getKey();

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return match ($this) {
            self::Domain => '/domains/'.$id,
            self::Database => '/databases/'.$id,
            self::Dump => is_int($subject->getAttribute('database_id'))
                ? '/databases/'.$subject->getAttribute('database_id')
                : null,
        };
    }

    /**
     * Der Gegenstand eines Vorgangs, oder `null`.
     *
     * `null` heisst hier zweierlei, und beides ist in Ordnung: Der Vorgang
     * handelt von nichts Einzelnem (Agent anpingen), oder der Gegenstand ist
     * inzwischen fort (die Domain wurde entfernt — dann ist der Vorgang, der
     * sie entfernt hat, genau der Grund dafür).
     */
    public static function tryResolve(?string $type, ?int $id): ?Model
    {
        if ($type === null || $id === null) {
            return null;
        }

        $subject = self::tryFrom($type);

        if ($subject === null) {
            return null;
        }

        /** @var Model|null $model */
        $model = $subject->modelClass()::query()->find($id);

        return $model;
    }
}
