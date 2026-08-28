<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1 動画に 1 字幕。カラムの根拠は docs/db_design.md §2.2。
     */
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();

            // video_id に unique を張って「1 対 1」を DB で保証する。
            // 動画が消えたら字幕も一緒に消える（ON DELETE CASCADE）。
            $table->foreignId('video_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // 取得できた字幕の言語コード。
            $table->string('language', 16);

            // 全セグメントを半角スペースで連結した本文。要約の入力。
            $table->text('content');

            // 字幕プレイヤーの各行 [{ start, end, text }, ...]。
            // jsonb（json ではなく）で将来の部分参照に備える。
            $table->jsonb('segments')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
