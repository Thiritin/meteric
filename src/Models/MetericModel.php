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

    /** Table name without the schema prefix; getTable() prepends config('meteric.schema.prefix'). */
    protected string $baseTable = '';

    /** Build the table name from the configured prefix unless a subclass pins $table explicitly. */
    public function getTable(): string
    {
        return $this->table ?? config('meteric.schema.prefix', 'meteric_').$this->baseTable;
    }

    /** Tables already use real defaults; let DB manage created/updated where triggers exist. */
    protected function casts(): array
    {
        return [];
    }
}
