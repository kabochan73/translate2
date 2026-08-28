# translate2 設計書（アーキテクチャ）

- 版: 1.1
- 作成日: 2026-08-27（1.1: 2026-08-28 認証を後回しに変更）
- 対象: `~/Desktop/translate2`
- 関連: 要件定義書（Artifact） / [db_design.md](./db_design.md)

> **認証について（2026-08-28 決定）**
> 今回はポートフォリオ用の短期デプロイのため、**ログイン機能は実装しない**（誰でも動画登録できる無防備な公開）。
> ただし将来つける前提で「削除」ではなく「後回し」。Laravel 標準の `users` テーブル等は残す。
> 本書で `~~取り消し線~~` の付いた記述は「ログイン機能を追加する時に戻す項目」。

この文書は「どう作るか（HOW）」をまとめる。「何を作るか（WHAT）」は要件定義書を参照。

---

## 1. 全体像

YouTube の URL を登録すると、バックグラウンドで
「メタデータ取得 → 字幕取得 → 要約生成」を順に実行し、
詳細画面で動画（埋め込み）と日本語要約を表示する Web アプリ。
（字幕テキストは取得・保存するが画面には出さない。§6 参照）

```
[ブラウザ]
   │ URL 登録 (POST /videos)
   ▼
[VideoController::store]
   │ ・URL 検証 / youtube_id 抽出
   │ ・重複チェック
   │ ・Video を status=pending で作成
   │ ・Bus::chain([...]) をキューに投入
   ▼ 即リダイレクト
[GET /videos/{id}]  ←── 3秒ごとに GET /videos/{id}/status をポーリング
   ▲
   │ 状態更新
[キューワーカー] (php artisan queue:work)
   FetchVideoMetadata → FetchTranscript → GenerateSummary
        │                    │                  │
   [YouTube Data API]   [字幕ライブラリ]    [Claude Messages API]
```

ポイント:

- **Web リクエストは外部 API を叩かない**。全部キュー経由（NFR-1）。
- 状態は `videos.status` の 1 本に集約。画面はそれを見るだけ。
- 失敗してもリトライで最終的に成功する設計（NFR-2）。

---

## 2. レイヤー構成

| レイヤー | 置き場所 | 役割 |
|---|---|---|
| Controller | `app/Http/Controllers` | HTTP 入出力のみ。ビジネスロジックを持たない |
| FormRequest | `app/Http/Requests` | 入力バリデーション |
| Job | `app/Jobs` | 取り込みパイプラインの各工程。キューで実行 |
| Service | `app/Services` | 外部 API との通信をカプセル化。純粋な PHP クラス |
| Model | `app/Models` | Eloquent。リレーションとキャストのみ |
| Enum | `app/Enums` | `ProcessingStatus` など |
| Action（任意） | `app/Actions` | 複数モデルにまたがる処理を切り出したい時だけ |

**方針**: Controller は薄く、Service は「外部との境界」、Job は「工程のオーケストレーション」。
Model にロジックを詰めすぎない。

---

## 3. 取り込みパイプライン

### 3.1 ジョブチェーン

```php
// VideoController::dispatchIngestion()（store / retry で共用）
Bus::chain([
    new FetchVideoMetadata($video),
    new FetchTranscript($video),
])->dispatch();
```

> 2026-08-28: 専用 `ingest` キューはやめて **default キュー 1 本**にした。
> 個人利用の規模で分ける利点が薄く、`queue` コンテナの `queue:work` も
> default だけ見ればよく単純。将来重い処理を分けたくなったら `onQueue()` を足す。

> 2026-08-28: **`GenerateSummary` は静的チェーンに入れない**（§3.4 の未決事項を決定）。
> `FetchTranscript` が字幕を取れた時だけ `GenerateSummary::dispatch($video)` を投げる。
> 字幕なしはチェーンが `FetchTranscript` で自然に終わる（無駄なジョブが走らない）。

チェーンは「前のジョブが成功したら次」。途中で例外が投げられると
以降のジョブは実行されず、`$job->failed()` が呼ばれる。

### 3.2 各ジョブの責務

| ジョブ | やること | 開始時の status | 正常終了後 |
|---|---|---|---|
| `FetchVideoMetadata` | YouTubeService でメタデータ取得 → `videos` 更新 + タグ紐付け | `fetching_metadata` | 次へ |
| `FetchTranscript` | TranscriptService で字幕取得 → `transcripts` を updateOrCreate | `fetching_transcript` | 字幕あり→`GenerateSummary` を投げる / 字幕なし→`no_transcript` で終了 |
| `GenerateSummary` | SummaryGenerator で要約 → `summaries` 更新（チェーン外。`FetchTranscript` が投げる） | `summarizing` | `completed` |

### 3.3 共通の設定（各ジョブに書く）

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchVideoMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    /** 再試行の待ち時間（秒）: 1回目失敗→10s, 2回目→30s, 3回目→60s */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public readonly Video $video) {}

    public function handle(YouTubeService $youtube): void { /* ... */ }

    /** 3回試行しても失敗した時に1度だけ呼ばれる */
    public function failed(\Throwable $e): void
    {
        $this->video->update([
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'metadata',
            'failed_reason' => \Str::limit($e->getMessage(), 500),
        ]);
    }
}
```

### 3.4 字幕なしの分岐（2026-08-28 実装確定）

チェーンは `[FetchVideoMetadata, FetchTranscript]` の 2 本だけ。
`GenerateSummary` は `FetchTranscript` が字幕を取れた時だけ投げる。
字幕なしは `status = no_transcript`（**例外ではなく正常終了**）にして return するだけ
＝チェーンがそこで自然に終わる。

```php
// FetchTranscript::handle()
$data = $transcripts->fetch($this->video->youtube_id, $this->video->source_language);

if ($data === null) {
    $this->video->update(['status' => ProcessingStatus::NoTranscript]);
    return;                                   // チェーンはここで終わり
}

$this->video->transcript()->updateOrCreate([], [...]);
GenerateSummary::dispatch($this->video);      // 字幕がある時だけ後続を投げる
```

一時的な取得失敗（レート制限等）は `TranscriptService` が例外を投げ、ジョブが
リトライ → 3 回失敗で `failed`（`failed_step=transcript`）。

### 3.5 再試行（ユーザー操作）

詳細ページの「再試行」ボタン → `POST /videos/{id}/retry`:

```php
$video->update([
    'status' => ProcessingStatus::Pending,
    'failed_step' => null,
    'failed_reason' => null,
]);
Bus::chain([...])->dispatch();
```

`updateOrCreate` を使うので、既にある `transcript` / `summary` は上書き更新される（NFR-3 冪等性）。

---

## 4. Service 設計

### 4.1 YouTubeService

- `extractVideoId(string $url): ?string` — 正規表現で 11 桁 ID を抽出
- `fetchVideoData(string $videoId): array` — Data API v3 `videos?part=snippet,contentDetails`
- `parseDuration()` は `CarbonInterval::make($iso)?->totalSeconds` に置き換え（translate1 の DateTime 手計算をやめる）
- `Http::retry(3, 200)` + `timeout(15)` を付ける

### 4.2 TranscriptService

- `mrmysql/youtube-transcript` の `TranscriptListFetcher` をラップ
- 優先言語ロジック: `source_language` → ベース言語（`en-US`→`en`）→ 利用可能な任意
- 返り値: `{ language, content, segments: [{start, end, text}] }` または `null`
  - `content` = 要約の入力。`segments` は保存のみ（§6 のとおり画面表示はしない）
- `html_entity_decode` で `&amp;` 等を戻す（translate1 と同じ）

### 4.3 AnthropicService（2026-08-28 実装）

Claude Messages API の薄い HTTP ラッパー。**公式 SDK ではなく `Http::` を使う**
（YouTubeService と揃える／`Http::fake()` でテストする方針）。translate1 から以下を追加:

- `complete(string $system, string $user, int $maxTokens = 4096): array`
  → `{ content, input_tokens, output_tokens }`。`input_tokens` はキャッシュ読み書き分も合算。
- `timeout(120)` と `Http::retry(3, 1000, ..., throw: false)` で 429 / 529 に対応
- prompt caching: system プロンプトを
  `[{ type: 'text', text: '...', cache_control: { type: 'ephemeral' } }]` 形式で送る。
  **基本のキャッシュに `anthropic-beta` ヘッダは不要**（`anthropic-version: 2023-06-01` のみ）。
- モデルは `config('services.anthropic.model')`（`.env` の `ANTHROPIC_MODEL`、既定 `claude-sonnet-5`）

### 4.4 SummaryGenerator（新規）

要約の組み立てロジック。AnthropicService を使う。

```
入力: string $transcriptContent（Transcript::content）
 1. トークン概算分割（1チャンク ≒ 6000 tok。mb_strlen/3 で概算、単語境界で詰める）
    ※ 自動生成字幕は文の区切り記号が無いことが多いので「文境界」ではなく単語境界にした
 2. チャンクが1個なら: そのまま最終プロンプトへ
    チャンクが複数なら:
      a. 各チャンクを「部分要点」プロンプトで抽出 (map, maxTokens 1500)
      b. 部分要点を全部連結して「統合要約」プロンプトへ (reduce)
 3. 出力フォーマット: TL;DR / キーポイント / チャプター別
 4. usage を合算して返す
出力: { content: string(markdown), input_tokens, output_tokens, prompt_version }
```

プロンプトは `SummaryGenerator::PROMPT_VERSION = 'v1'` 定数。変えたら版を上げる。
`GenerateSummary` ジョブが結果を `summaries` に書き、`cost_usd` を単価×トークンで概算する。

---

## 5. 進捗のライブ表示（FR-7）

### 5.1 ステータス API

```
GET /videos/{video}/status
→ 200 {
    "status": "summarizing",
    "step": 3,            // 1..4（表示用）
    "is_terminal": false,
    "summary_ready": false,
    "failed_step": null,
    "failed_reason": null
  }
```

- ~~認証必須。~~（今回は認証なし）`VideoController::status()` で返す。`throttle:60,1`。
- キャッシュ不可（毎回最新）。
- 実装済み（2026-08-28）。`step` は `ProcessingStatus::step()`、`is_terminal` は `isTerminal()`、
  `summary_ready` は `summaries.status === completed` の存在チェック。

### 5.2 フロント（Alpine）

```html
<div x-data="ingestProgress({{ $video->id }}, '{{ $video->status->value }}')"
     x-init="start()">
  <!-- ステッパー表示 -->
</div>
```

```js
function ingestProgress(id, initial) {
  return {
    status: initial,
    timer: null,
    start() {
      if (this.isTerminal()) return;
      this.timer = setInterval(() => this.poll(), 3000);
    },
    async poll() {
      const res = await fetch(`/videos/${id}/status`, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      this.status = data.status;
      if (data.is_terminal) {
        clearInterval(this.timer);
        window.location.reload();   // 要約を出すため一度だけリロード
      }
    },
    isTerminal() {
      return ['completed', 'no_transcript', 'failed'].includes(this.status);
    },
  };
}
```

> WebSocket（Reverb）は使わない。個人利用の規模ではポーリングで十分。

---

## 6. 詳細ページの動画表示（旧 FR-10 インタラクティブ字幕プレイヤー）

> **2026-08-28 決定：インタラクティブ字幕プレイヤーは実装しない。**
> 字幕は元言語（英語等）のままで、日本語話者には同期表示の実用性が薄いと判断。
> 詳細ページは「動画 + 日本語要約 + メタ情報」に絞る。
> `transcripts.segments` は**保存は続けるが画面には出さない**（将来 v2 機能として
> プレイヤーを足す余地は残す。ログイン機能と同じ「後回し」）。
> 旧 §6 の IFrame Player API / click-to-seek / ハイライト / 自動スクロール /
> ページ内フィルタ、および `transcript-player.js`・`_transcript-player.blade.php` は作らない。

### 6.1 詳細ページの構成（現行）

```
戻る
タイトル / チャンネル名 / ステータス
[失敗時] 失敗理由
埋め込み動画（プレーンな iframe embed。JS API は使わない）
タグ
メタ情報（元URL / 再生時間 / 公開日）
要約（Markdown, フェーズ4）
```

- 動画は `https://www.youtube.com/embed/{youtube_id}` をそのまま `<iframe>` で貼るだけ。
  IFrame Player API（`iframe_api`）は読み込まない。

---

## 7. 一覧・検索（FR-8, FR-9）

```php
Video::query()
    ->with('tags')                          // N+1 回避
    ->withCount(...)                         // 必要なら
    ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
        $w->whereLike('title', "%{$q}%")
          ->orWhereLike('channel_name', "%{$q}%")
          ->orWhereHas('tags', fn ($t) => $t->whereLike('name', "%{$q}%"));
    }))
    ->latest()
    ->simplePaginate(18)                     // 3 列 × 6 行、前へ/次へのみ（総件数 COUNT を省く）
    ->withQueryString();                     // 検索語をページリンクへ
```

- 全文検索（tsvector）は**使わない**。translate1 と同じ `LIKE` で十分（スコープ外）。
- `whereLike` は Laravel 11+ の大文字小文字非依存ヘルパ。
- ページ送りは `simplePaginate`（`tailwind.blade.php` ではなく `simple-tailwind.blade.php` を使う）。

---

## 8. エラーハンドリング方針

| 事象 | 扱い |
|---|---|
| URL パース失敗 | Controller で即バリデーションエラー返却（キューに乗せない） |
| YouTube API 失敗 | ジョブをリトライ → 3回ダメなら `failed`（`failed_step=metadata`） |
| 字幕が存在しない | 正常系。`no_transcript` |
| 字幕ライブラリの例外 | リトライ → ダメなら `failed`（`failed_step=transcript`） |
| Claude 429 / 529 | Http::retry で吸収 → それでもダメならジョブリトライ → `failed`（`failed_step=summary`） |
| Claude その他 4xx/5xx | ジョブリトライ → `failed` |

- `failed_reason` はユーザーに見せる前提で、生スタックトレースではなく要約したメッセージを入れる。
- ログには詳細を残す（`Log::error(..., ['video_id' => ..., 'exception' => $e])`）。

---

## 9. ディレクトリ構成（translate1 からの差分）

```
app/
  Enums/
    ProcessingStatus.php          # translate1 は app/ 直下 → Enums/ へ移動
  Http/Controllers/
    VideoController.php           # index / store / show / status / retry
  Jobs/
    FetchVideoMetadata.php        # 新規（store の同期処理を分解）
    FetchTranscript.php           # 新規
    GenerateSummary.php           # translate1 の GenerateVideoSummary を改名・分割
  Services/
    YouTubeService.php
    TranscriptService.php
    AnthropicService.php
    SummaryGenerator.php          # 新規（map-reduce ロジック）
resources/views/videos/
  index.blade.php
  show.blade.php
  partials/
    _card.blade.php
    _progress.blade.php
    # _transcript-player.blade.php は作らない（§6）
resources/js/
  # transcript-player.js は作らない（§6）
  app.js                          # Alpine 起動 + Alpine.data('ingestProgress')（§5.2）
lang/ja/
  video.php                       # 画面文言（未着手。今は Blade 直書き）
docs/
  design.md
  db_design.md
```

---

## 10. テスト方針（NFR-8）

| 対象 | テスト種別 | ポイント |
|---|---|---|
| `VideoController::store` | Feature | `Http::fake` + `Bus::fake`。チェーンが投入されること |
| `FetchVideoMetadata` | Feature | `Http::fake`。`videos` 更新とタグ紐付け |
| `FetchTranscript` | Feature | `TranscriptService` をモック。字幕あり/なし両方 |
| `GenerateSummary` | Feature | `Http::fake`（Anthropic）。completed / failed |
| `SummaryGenerator` の分割 | Unit | 長文で複数チャンクに割れること、usage 合算 |
| ステータス API | Feature | 各 status で正しい JSON |
| 検索 | Feature | title / channel / tag ヒット、0件メッセージ |
| ~~認証~~ | ~~Feature~~ | ~~未ログインで `/videos` → `/login`~~（ログイン機能追加時に戻す） |

- 外部 API は**絶対に本物を呼ばない**。
- `Bus::fake()` / `Queue::fake()` でジョブ投入を検証、`Bus::assertChained([...])` を活用。

---

## 11. 開発フェーズと本書の対応

| フェーズ | 本書の関連セクション |
|---|---|
| 1 土台作り | §9 ディレクトリ構成 |
| 2 データ設計 | db_design.md 全体 |
| 3 動画の取り込み | §2, §3, §4.1 |
| 4 字幕と要約 | §3.4, §4.2, §4.3, §4.4, §8 |
| 5 画面 | §5, §6, §7（§6 は動画埋め込み＋要約のみ。字幕プレイヤーは無し） |
| 6 検索 | §7 |
| 7 デプロイ | 要件定義書 §12 |
| （将来）ログイン機能 | Breeze 導入・`auth` ミドルウェア・AdminSeeder。今回のスコープ外 |
| （将来）字幕プレイヤー | 旧 FR-10。IFrame Player API + click-to-seek 等。今回のスコープ外（§6） |

---

## 12. 未決事項（実装時に確定する）

- [x] `Bus::chain` で「字幕なし時に後続を止める」具体的な書き方（§3.4）→ **2 本チェーン + `FetchTranscript` が条件付きで `GenerateSummary` を投げる方式に決定（2026-08-28）**
- [ ] Claude prompt caching に `anthropic-beta` ヘッダが要るか（公式ドキュメント確認）
- [ ] map-reduce のチャンクサイズと分割アルゴリズムの詳細
- [x] `cost_usd` の単価テーブルをどこに持つか → **`config/services.php` の `anthropic.input_cost_per_mtok` / `output_cost_per_mtok`（`.env` で上書き可）。既定は claude-sonnet-5 のレート $2 / $10（2026-08-28）**
- [ ] IFrame Player の CSP 設定（本番 nginx）
