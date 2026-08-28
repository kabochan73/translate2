<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * このタグが付いている動画。
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class);
    }
}
