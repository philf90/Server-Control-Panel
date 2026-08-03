<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Einstellung des Betreibers.
 *
 * Der Wert ist immer eine Ablage und nie ein Skalar — `encrypted:array`. Das
 * hält zwei Dinge zusammen, die sonst auseinanderlaufen: Alles, was zu einer
 * Einstellung gehört, steht in einer Zeile, und alles davon ist verschlüsselt,
 * ohne dass jemand je Feld daran denken muss.
 *
 * @property string $key
 * @property array<string, mixed>|null $value
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }
}
