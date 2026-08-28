# translate2 データベース設計書

- 版: 1.1
- 作成日: 2026-08-27（1.1: 2026-08-28 認証を後回しに変更）
- DBMS: PostgreSQL 16
- 関連: 要件定義書（Artifact） / [design.md](./design.md)

> **認証について（2026-08-28 決定）**
> 今回はログイン機能を実装しない（ポートフォリオ用の短期デプロイ）。将来つける前提の「後回し」。
> `users` / `password_reset_tokens` は Laravel 標準マイグレーションのまま**残す**（消さない）。
> ただし AdminSeeder は作らず、`ADMIN_*` 環境変数も今回は未使用。

---

## 1. ER 概要

```
users        （認証。Breeze 標準）

videos 1 ──── 1 transcripts
       1 ──── 1 summaries
       * ──── * tags        （中間テーブル tag_video）
```

- `videos` が中心。1 動画につき字幕 0..1・要約 0..1・タグ 0..*。
- `transcripts` / `summaries` は `video_id` に **UNIQUE** を張り「1対1」を DB で保証する。
- 削除は `videos` を消したら子（transcripts / summaries / tag_video）も消える（`ON DELETE CASCADE`）。

---

## 2. テーブル定義

### 2.1 `videos`

| カラム | 型 | 制約 / 既定 | 説明 |
|---|---|---|---|
| `id` | bigint | PK, auto increment | |
| `youtube_id` | varchar(255) | NOT NULL, **UNIQUE** | 11 桁の YouTube 動画 ID。重複登録判定キー |
| `url` | varchar(2048) | NOT NULL | 登録された元 URL |
| `title` | varchar(255) | NULL | メタデータ取得前は NULL |
| `channel_name` | varchar(255) | NULL | |
| `thumbnail_url` | varchar(2048) | NULL | |
| `duration_seconds` | integer | NULL, unsigned 相当（CHECK >= 0） | 再生時間（秒） |
| `published_at` | timestamptz | NULL | 動画の公開日時 |
| `source_language` | varchar(16) | NULL | 音声言語コード（例 `en`, `ja`, `en-US`） |
| `status` | varchar(32) | NOT NULL, DEFAULT `'pending'` | 取り込み状態（§4） |
| `failed_step` | varchar(32) | NULL | 失敗した工程 `metadata` / `transcript` / `summary` |
| `failed_reason` | text | NULL | 失敗理由（ユーザー表示向けに要約済み、最大 500 字目安） |
| `created_at` | timestamptz | NOT NULL | |
| `updated_at` | timestamptz | NOT NULL | |

**インデックス**

| 名前 | 対象 | 用途 |
|---|---|---|
| `videos_youtube_id_unique` | `youtube_id` | 重複チェック（自動） |
| `videos_status_index` | `status` | 一覧の状態フィルタ、ワーカー起動時の未処理拾い |
| `videos_created_at_index` | `created_at` | `latest()` 並び替え + ページネーション |

> `title` / `channel_name` の `LIKE '%...%'` 検索は前方一致でないため通常インデックスは効かない。
> 個人利用の件数（数百〜数千）ならフルスキャンで問題なし。スコープ外の全文検索は張らない。

---

### 2.2 `transcripts`

| カラム | 型 | 制約 / 既定 | 説明 |
|---|---|---|---|
| `id` | bigint | PK | |
| `video_id` | bigint | NOT NULL, **UNIQUE**, FK → `videos.id` ON DELETE CASCADE | 1 動画 1 字幕 |
| `language` | varchar(16) | NOT NULL | 取得できた字幕の言語コード |
| `content` | text | NOT NULL | 全セグメントを半角スペースで連結した本文。要約の入力 |
| `segments` | jsonb | NULL | 下記 JSON 構造。字幕プレイヤーの各行 |
| `created_at` / `updated_at` | timestamptz | NOT NULL | |

**`segments` の構造**

```json
[
  { "start": 0.0,  "end": 4.2,  "text": "字幕1行目" },
  { "start": 4.2,  "end": 9.1,  "text": "字幕2行目" }
]
```

- `start` / `end` は秒（float）。`end = start + duration`。
- 配列順 = 再生順。
- 型は `jsonb`（`json` ではなく）。将来の部分参照に備える。現状インデックスは不要。

**インデックス**: `transcripts_video_id_unique`（自動）のみ。

---

### 2.3 `summaries`

| カラム | 型 | 制約 / 既定 | 説明 |
|---|---|---|---|
| `id` | bigint | PK | |
| `video_id` | bigint | NOT NULL, **UNIQUE**, FK → `videos.id` ON DELETE CASCADE | 1 動画 1 要約 |
| `status` | varchar(16) | NOT NULL, DEFAULT `'pending'` | `pending` / `processing` / `completed` / `failed` |
| `language` | varchar(16) | NOT NULL, DEFAULT `'ja'` | 要約の言語 |
| `content` | text | NULL | 要約本文（Markdown）。完了まで NULL |
| `model` | varchar(64) | NULL | 使用した Claude モデル ID |
| `prompt_version` | varchar(16) | NULL | プロンプトの版（`v1` など） |
| `input_tokens` | integer | NULL | API 入力トークン数（map-reduce は合算） |
| `output_tokens` | integer | NULL | API 出力トークン数（合算） |
| `cost_usd` | numeric(10,6) | NULL | 概算コスト（USD） |
| `error_message` | text | NULL | 失敗時のメッセージ |
| `completed_at` | timestamptz | NULL | 要約完了時刻 |
| `created_at` / `updated_at` | timestamptz | NOT NULL | |

**インデックス**: `summaries_video_id_unique`（自動）のみ。

> `summaries.status` は `videos.status` とは別物。
> `videos.status` が取り込み全体の状態、`summaries.status` が要約単体の状態。
> 画面表示は基本 `videos.status` を見て、要約セクションの細かい出し分けだけ `summaries.status` を参照する。

---

### 2.4 `tags`

| カラム | 型 | 制約 | 説明 |
|---|---|---|---|
| `id` | bigint | PK | |
| `name` | varchar(255) | NOT NULL, **UNIQUE** | タグ名。`firstOrCreate(['name' => ...])` で作る |
| `created_at` / `updated_at` | timestamptz | NOT NULL | |

**インデックス**: `tags_name_unique`（自動）。

---

### 2.5 `tag_video`（中間テーブル）

| カラム | 型 | 制約 |
|---|---|---|
| `tag_id` | bigint | NOT NULL, FK → `tags.id` ON DELETE CASCADE |
| `video_id` | bigint | NOT NULL, FK → `videos.id` ON DELETE CASCADE |

- **複合主キー** `PRIMARY KEY (tag_id, video_id)` — 同じ組み合わせの重複を防ぐ。
- `timestamps` は持たない（translate1 と同じ）。
- Laravel 命名規約どおり単数形アルファベット順 `tag_video`。

---

### 2.6 `users`（Breeze 標準・参考）

| カラム | 型 | 制約 |
|---|---|---|
| `id` | bigint | PK |
| `name` | varchar(255) | NOT NULL |
| `email` | varchar(255) | NOT NULL, UNIQUE |
| `email_verified_at` | timestamptz | NULL |
| `password` | varchar(255) | NOT NULL |
| `remember_token` | varchar(100) | NULL |
| `created_at` / `updated_at` | timestamptz | NOT NULL |

- **今回はログイン機能なし**。`users` テーブルは Laravel 標準マイグレーションのまま作られるが、レコードは 0 件で運用（どの画面も認証を要求しない）。
- 将来ログイン機能を追加する時に、シーダーが `ADMIN_EMAIL` / `ADMIN_NAME` / `ADMIN_PASSWORD` から 1 件だけ `updateOrCreate` する想定（今回は未実装）。
- `password_reset_tokens` / `sessions` テーブルは Laravel 標準セット。ただしセッションは Redis ドライバなので `sessions` テーブルは未使用。

---

## 3. マイグレーション順序

FK があるので順番が重要。

```
0001_..._create_users_table                （Laravel 標準。今回ログイン機能は無いが消さずに残す）
0001_..._create_cache_table                 （Laravel 標準）
0001_..._create_jobs_table                  （キュー / failed_jobs）
2026_..._create_videos_table
2026_..._create_transcripts_table           （videos に依存）
2026_..._create_summaries_table             （videos に依存）
2026_..._create_tags_table
2026_..._create_tag_video_table             （tags, videos に依存）
```

`jobs` / `failed_jobs` テーブルは `php artisan make:queue-table` 系、
または `queue.php` の設定に応じて標準マイグレーションで作る（キューは Redis だが
`failed_jobs` は DB に残すのが既定）。

---

## 4. `videos.status` 状態機械

値は `App\Enums\ProcessingStatus`（string backed enum）。

| 値 | 意味 | 遷移元 | 遷移先 |
|---|---|---|---|
| `pending` | 登録直後 / 再試行直後。まだ何も処理していない | （新規）, 再試行 | `fetching_metadata` |
| `fetching_metadata` | YouTube メタデータ取得中 | `pending` | `fetching_transcript`, `failed` |
| `fetching_transcript` | 字幕取得中 | `fetching_metadata` | `summarizing`, `no_transcript`, `failed` |
| `summarizing` | Claude で要約中 | `fetching_transcript` | `completed`, `failed` |
| `completed` | 全工程完了（要約あり） | `summarizing` | 再試行で `pending` |
| `no_transcript` | 字幕が無く要約はスキップ（正常終了） | `fetching_transcript` | 再試行で `pending` |
| `failed` | いずれかの工程で 3 回試行しても失敗 | 各処理中状態 | 再試行で `pending` |

```
pending
  → fetching_metadata
      → fetching_transcript
          → summarizing → completed
          → no_transcript
      （どの矢印でも失敗しうる）→ failed

completed / no_transcript / failed → (再試行) → pending
```

**表示用ステップ番号**（進捗ステッパー / ステータス API の `step`）

| status | step |
|---|---|
| `pending` | 1 |
| `fetching_metadata` | 2 |
| `fetching_transcript` | 3 |
| `summarizing` | 4 |
| `completed` / `no_transcript` / `failed` | 4（終了） |

---

## 5. Eloquent リレーション

```php
// Video
public function transcript(): HasOne     { return $this->hasOne(Transcript::class); }
public function summary(): HasOne         { return $this->hasOne(Summary::class); }
public function tags(): BelongsToMany     { return $this->belongsToMany(Tag::class); }

// Transcript / Summary
public function video(): BelongsTo        { return $this->belongsTo(Video::class); }

// Tag
public function videos(): BelongsToMany   { return $this->belongsToMany(Video::class); }
```

**キャスト**

```php
// Video
'duration_seconds' => 'integer',
'published_at'     => 'datetime',
'status'           => ProcessingStatus::class,

// Transcript
'segments'         => 'array',       // jsonb ⇄ PHP array

// Summary
'status'           => SummaryStatus::class,   // 専用 enum に決定（2026-08-28）。App\Enums\SummaryStatus
'input_tokens'     => 'integer',
'output_tokens'    => 'integer',
'cost_usd'         => 'decimal:6',
'completed_at'     => 'datetime',
```

---

## 6. よく使うクエリと N+1 対策

| 画面 | クエリ | eager load |
|---|---|---|
| 一覧 | `Video::with('tags')->latest()->paginate(12)` | `tags` |
| 詳細 | `$video->load('tags', 'transcript', 'summary')` | 3 つ全部 |
| ステータス API | `Video::select('id','status','failed_step','failed_reason')->find($id)` | なし（軽量） |

- 一覧でタグを表示するので `tags` は必須 eager load（無いと動画 12 件 × タグクエリ = N+1）。
- 詳細の `transcript` / `summary` は 1対1 なので `load()` で 2 クエリ追加のみ。

---

## 7. データ整合性ルール

1. `videos.youtube_id` は常に 11 文字。Controller で抽出したものだけを保存する。
2. `transcripts` は「字幕が実際に取得できた時だけ」作る。空の字幕行は作らない。
3. `summaries` は取り込みで字幕があった動画にだけ作る（`no_transcript` の動画には作らない）。
4. 再試行時は `Transcript` / `Summary` を `updateOrCreate` で更新（重複行を作らない）。
5. `videos` を削除すると子テーブルは CASCADE で自動削除。アプリ側で個別 delete しない。

---

## 8. 想定データ量（キャパシティ）

| テーブル | 想定行数 | 1 行サイズ目安 |
|---|---|---|
| `videos` | 〜数千 | 小 |
| `transcripts` | `videos` と同程度 | `content` が数 KB〜数十 KB、`segments` も同程度 |
| `summaries` | `videos` と同程度 | `content` 数 KB |
| `tags` | 〜数千 | 小 |
| `tag_video` | `videos` × 平均タグ数 | 極小 |

個人利用の範囲。パーティショニングやアーカイブは考慮不要。

---

## 9. 未決事項

- [x] `summaries.status` を専用 enum にするか string のままにするか → **専用 enum `App\Enums\SummaryStatus`（pending/processing/completed/failed）に決定（2026-08-28）**。`ProcessingStatus` と扱いを揃えるため
- [ ] `cost_usd` の桁（`numeric(10,6)` で十分か）
- [ ] `failed_jobs` を DB に残すか Redis / Horizon 方式にするか（フェーズ4）
- [ ] `segments` に含める情報を増やすか（話者、信頼度など）— 現状は start/end/text のみ
