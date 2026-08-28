<?php

use App\Enums\SummaryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1 動画に 1 要約。カラムの根拠は docs/db_design.md §2.3。
     * 字幕があった動画にだけ作る（no_transcript の動画には作らない）。
     */
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('video_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // 要約単体の状態。App\Enums\SummaryStatus と対応。
            $table->string('status', 16)->default(SummaryStatus::Pending->value);

            // 要約の言語。基本 ja。
            $table->string('language', 16)->default('ja');

            // 要約本文（Markdown）。完了まで null。
            $table->text('content')->nullable();

            // どのモデル / プロンプト版で作ったか（後で品質比較するため）。
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            // API の使用トークン数（map-reduce は合算）と概算コスト（USD）。
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();

            // 失敗時のメッセージと、完了時刻。
            $table->text('error_message')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
