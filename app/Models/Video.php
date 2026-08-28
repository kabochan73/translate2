<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    /**
     * 一括代入（create / update に配列で渡す）を許可するカラム。
     *
     * id と timestamps は Laravel が管理するので入れない。
     */
    protected $fillable = [
        'youtube_id',
        'url',
        'title',
        'channel_name',
        'thumbnail_url',
        'duration_seconds',
        'published_at',
        'source_language',
        'status',
        'failed_step',
        'failed_reason',
    ];

    /**
     * DB の値 ⇄ PHP の型 の変換ルール。
     *
     * - status は文字列カラムだが、読み書きは ProcessingStatus enum で行える。
     * - published_at は Carbon 日時オブジェクトになる。
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'published_at' => 'datetime',
            'status' => ProcessingStatus::class,
        ];
    }
}
