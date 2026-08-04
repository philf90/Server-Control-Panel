<?php

declare(strict_types=1);

namespace App\Enums;

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
 * Die Aufzählung wächst mit den Ausbaustufen: Datenbanken in P5, Cronjobs in
 * P6, Zonen in P7. Was hier hinzukommt, braucht ein Modell — und ein Test
 * hält beides zusammen.
 */
enum OperationSubject: string
{
    case Domain = 'domain';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Domain => Domain::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Domain => 'Domain',
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
