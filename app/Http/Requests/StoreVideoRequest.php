<?php

namespace App\Http\Requests;

use App\Services\YouTubeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreVideoRequest extends FormRequest
{
    /**
     * ログイン機能が無いので誰でも許可（今回のスコープ）。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }

    /**
     * 形式チェックの後に「YouTube の動画 URL として ID を取り出せるか」を見る。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $url = (string) $this->input('url');

            if ($url !== '' && $this->youtube()->extractVideoId($url) === null) {
                $validator->errors()->add('url', 'YouTube の動画 URL を入力してください。');
            }
        });
    }

    /**
     * バリデーション済みの URL から取り出した 11 桁の動画 ID。
     */
    public function youtubeId(): string
    {
        return $this->youtube()->extractVideoId((string) $this->input('url'));
    }

    private function youtube(): YouTubeService
    {
        return app(YouTubeService::class);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['url' => 'URL'];
    }
}
