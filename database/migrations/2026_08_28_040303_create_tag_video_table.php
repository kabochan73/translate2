<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * videos と tags の多対多をつなぐ中間テーブル（docs/db_design.md §2.5）。
     * Laravel 規約どおり単数形アルファベット順で "tag_video"。
     */
    public function up(): void
    {
        Schema::create('tag_video', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();

            // 複合主キー。同じ (tag, video) の組み合わせを二重に作らせない。
            $table->primary(['tag_id', 'video_id']);

            // timestamps は持たない（translate1 と同じ）。
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_video');
    }
};
