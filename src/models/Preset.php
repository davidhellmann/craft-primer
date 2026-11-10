<?php

namespace davidhellmann\primer\models;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use davidhellmann\primer\elements\Preset as PresetElement;

/**
 * A preset's row in Primer's table: its section, entry type and field choice. The element is `elements\Preset`.
 *
 * @property int $id
 * @property int $sectionId
 * @property int $typeId
 * @property string[]|null $fieldUids
 */
class Preset extends BaseModel
{
    use HasUid;

    protected $table = PresetElement::TABLE;

    public $incrementing = false;

    protected $casts = [
        'fieldUids' => 'array',
    ];
}
