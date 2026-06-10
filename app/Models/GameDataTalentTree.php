<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDataTalentTree extends Model
{
    protected $table = 'game_data_talent_trees';

    /**
     * Composite primary key (tree_id, spec_id). Eloquent does not
     * support multi-column PKs natively — it uses a single $primaryKey
     * to filter UPDATE/DELETE queries. Leaving $primaryKey unset would
     * make `save()` issue UPDATEs with no WHERE clause, mass-overwriting
     * every row in the table on each upsert. We pick `tree_id` as the
     * nominal key and override the save/delete query builder below to
     * AND in `spec_id` so the composite key is honored end-to-end.
     */
    protected $primaryKey = 'tree_id';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'tree_id',
        'spec_id',
        'name',
        'tree',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tree_id' => 'integer',
            'spec_id' => 'integer',
            'tree' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Restrict UPDATE/DELETE to the composite (tree_id, spec_id) row.
     * Without this override, Eloquent filters only by tree_id and would
     * collide across the three specs that share each class's tree id
     * (e.g. Rogue 852 → Assassination/Outlaw/Subtlety).
     */
    protected function setKeysForSaveQuery($query)
    {
        return parent::setKeysForSaveQuery($query)
            ->where('spec_id', '=', $this->getAttribute('spec_id'));
    }

    protected function setKeysForSelectQuery($query)
    {
        return parent::setKeysForSelectQuery($query)
            ->where('spec_id', '=', $this->getAttribute('spec_id'));
    }
}
