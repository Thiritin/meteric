<?php

declare(strict_types=1);

namespace Meteric\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class MetericModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    /** Tables already use real defaults; let DB manage created/updated where triggers exist. */
    protected function casts(): array
    {
        return [];
    }
}
