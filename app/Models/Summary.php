<?php

namespace App\Models;

use App\Enums\SummaryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Summary extends Model
{
    protected $fillable = [
        'video_id',
        'status',
        'language',
        'content',
        'model',
        'prompt_version',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            // 小数第 6 位まで保持する文字列として扱う（浮動小数の誤差を避ける）。
            'cost_usd' => 'decimal:6',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * この要約が属する動画。
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
