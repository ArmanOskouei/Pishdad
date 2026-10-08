<?php

namespace App\Services\Market;

use Illuminate\Http\Request;

/** Domain error with an HTTP status + optional details payload for the K8 route block. */
class MarketException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /**
     * WF-H18 — مسیرهای کنترلریِ بازار این استثنا را در `$k8` نمی‌پیچند، پس
     * پاسخِ JSON اینجا ساخته می‌شود تا همان قراردادِ `{message, details}` برقرار
     * بماند. در بلاکِ closureِ `v1/market` همچنان `$k8` اول می‌گیرد و این متد
     * صدا زده نمی‌شود.
     */
    public function render(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = ['message' => $this->getMessage()];

        if ($this->details !== null) {
            $payload['details'] = $this->details;
        }

        return response()->json($payload, $this->status);
    }
}
