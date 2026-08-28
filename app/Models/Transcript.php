<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcript extends Model
{
    protected $fillable = [
        'video_id',
        'language',
        'content',
        'segments',
    ];

    protected function casts(): array
    {
        return [
            // jsonb カラム ⇄ PHP 配列。$transcript->segments が配列で扱える。
            'segments' => 'array',
        ];
    }

    /**
     * この字幕が属する動画。
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
