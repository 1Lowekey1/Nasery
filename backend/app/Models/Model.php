<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base for every NaSeRy domain model (tables in database/supabase/).
 *
 * - uuid primary keys (Laravel generates ordered UUIDv7 before insert)
 * - timestamptz columns with microseconds, stored in UTC
 * - mass assignment is opt-in per model via #[Fillable]
 *
 * Models whose table lacks updated_at (append-only history tables) set
 * `const UPDATED_AT = null;`; tables with deleted_at use SoftDeletes.
 */
abstract class Model extends EloquentModel
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
