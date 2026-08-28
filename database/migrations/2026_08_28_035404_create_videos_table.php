<?php

use App\Enums\ProcessingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 取り込みの中心テーブル。1 行 = 1 YouTube 動画。
     * カラムの根拠は docs/db_design.md §2.1。
     */
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();

            // 11 桁の YouTube 動画 ID。重複登録の判定キーなので unique。
            $table->string('youtube_id')->unique();

            // 登録された元 URL（クエリ付きで長くなりうるので 2048）。
            $table->string('url', 2048);

            // ここから下はメタデータ取得が終わるまで null。
            $table->string('title')->nullable();
            $table->string('channel_name')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            // 再生時間（秒）。負数はありえないので unsigned。
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestampTz('published_at')->nullable();
            // 音声言語コード（例 en / ja / en-US）。字幕の優先言語に使う。
            $table->string('source_language', 16)->nullable();

            // 取り込み状態。App\Enums\ProcessingStatus と対応。
            $table->string('status', 32)->default(ProcessingStatus::Pending->value);

            // 失敗した工程と、その理由（ユーザー表示向けに要約済みの文字列）。
            $table->string('failed_step', 32)->nullable();
            $table->text('failed_reason')->nullable();

            // published_at と揃えてタイムゾーン付き（timestamptz）にする。
            $table->timestampsTz();

            // 一覧の状態フィルタ / ワーカーの未処理拾い。
            $table->index('status');
            // latest() 並び替え + ページネーション。
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
