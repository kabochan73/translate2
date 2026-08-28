import Alpine from 'alpinejs';

/**
 * 詳細ページの取り込み進捗（FR-7）。
 * 3 秒ごとに /videos/{id}/status を叩き、終了状態になったら 1 度だけリロードする。
 */
Alpine.data('ingestProgress', (videoId, initialStatus, initialStep) => ({
    status: initialStatus,
    step: initialStep,
    timer: null,

    init() {
        if (!this.isTerminal()) {
            this.timer = setInterval(() => this.poll(), 3000);
        }
    },

    async poll() {
        try {
            const res = await fetch(`/videos/${videoId}/status`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;

            const data = await res.json();
            this.status = data.status;
            this.step = data.step;

            if (data.is_terminal) {
                clearInterval(this.timer);
                window.location.reload();
            }
        } catch {
            // 一時的なネットワークエラーは無視して次のポーリングを待つ
        }
    },

    isTerminal() {
        return ['completed', 'no_transcript', 'failed'].includes(this.status);
    },
}));

window.Alpine = Alpine;

Alpine.start();
