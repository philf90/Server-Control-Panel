<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DbUserNetworkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Netz, aus dem ein PostgreSQL-Zugang hereindarf.
 *
 * **Die eine Stelle, an der das Datenmodell von P5 bricht** (`docs/38 §14.3`).
 * In MariaDB sind `'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'`
 * **zwei Benutzer** mit zwei Passwörtern und zwei Rechtelisten; deshalb steht
 * dort `host` als Spalte in {@see DbUser} und der eindeutige Index über beiden.
 *
 * In PostgreSQL ist es **eine Rolle, ein Passwort, mehrere erlaubte Netze**.
 * Zwei Zeilen mit demselben Namen wären hier nicht zwei Zugänge, sondern zwei
 * Regeln für einen — und `pg.role.create` liefe zweimal und setzte ein zweites
 * Passwort auf dieselbe Rolle. Das ist kein Baufehler des einen oder des
 * anderen Systems, sondern ihre Bauart: Wo MariaDB den Wirt in die Kennung
 * schreibt, schreibt PostgreSQL ihn in `pg_hba.conf`.
 *
 * **Es gehört auf die Seite, weil ein Kunde, der P5 kennt, das Gegenteil
 * annimmt.** Wer hier ein zweites Netz einträgt, bekommt keinen zweiten
 * Zugang; wer sein Passwort neu setzt, setzt es für alle Netze zugleich.
 *
 * **Der Wert steht als Text da, wie PostgreSQL ihn schreibt.** Zerlegt in
 * Adresse und Präfixlänge und wieder zusammengesetzt wäre er eine zweite
 * Fassung derselben Regel — und die zweite ist die, die veraltet. Geprüft wird
 * er von `SrvPanel\Agent\Pg\Hba::cidr()`, also von derselben Stelle, die die
 * Zeile später schreibt.
 *
 * @property int $id
 * @property int $db_user_id
 * @property string $cidr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DbUser $user
 */
class DbUserNetwork extends Model
{
    /** @use HasFactory<DbUserNetworkFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['db_user_id', 'cidr'];

    /**
     * Der Zugang, zu dem dieses Netz gehört.
     *
     * **Ohne eigene Mandantenklammer**, und das ist Absicht: Diese Zeile hängt
     * an einem {@see DbUser}, der sie hat — wer an den nicht herankommt, kommt
     * an sie nicht heran. Eine zweite Klammer hier wäre eine zweite Fassung
     * derselben Regel, und die Modelle dieser Stufe klammern durchweg über ihr
     * Abonnement.
     *
     * @return BelongsTo<DbUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(DbUser::class, 'db_user_id');
    }
}
