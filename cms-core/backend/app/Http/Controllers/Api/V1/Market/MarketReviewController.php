<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\MarketReview;
use App\Models\Plugin;
use App\Services\Market\MarketException;
use App\Services\Market\MarketReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-H18 — امتیاز/نظرات آیتم بازار.
 *
 * - عمومی (خریدارِ وارد‌شده): فهرست نظرهای تأییدشده + میانگین، و ثبت نظر.
 * - بازبینی مرکزی (`role:operator`): صف نظرهای در انتظار + تأیید/رد.
 *
 * همهٔ اعداد از دادهٔ واقعی می‌آیند (لایسنس/سفارش/نظر). خطاهای دامنهٔ بازار با
 * `MarketException::render()` به JSON با همان status برمی‌گردند.
 */
class MarketReviewController extends Controller
{
    public function __construct(private MarketReviewService $reviews) {}

    /** GET v1/market/catalog/{plugin}/reviews */
    public function index(Plugin $plugin): JsonResponse
    {
        $this->ensureMarketplaceItem($plugin);

        return response()->json([
            'data' => $this->reviews->approvedFor($plugin),
            'meta' => $this->reviews->stats($plugin),
        ]);
    }

    /** POST v1/market/catalog/{plugin}/reviews */
    public function store(Request $request, Plugin $plugin): JsonResponse
    {
        $this->ensureMarketplaceItem($plugin);

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ], [
            'rating.required' => 'امتیاز الزامی است.',
            'rating.integer' => 'امتیاز باید یک عدد بین ۱ تا ۵ باشد.',
            'rating.min' => 'امتیاز نمی‌تواند کمتر از ۱ باشد.',
            'rating.max' => 'امتیاز نمی‌تواند بیشتر از ۵ باشد.',
            'comment.max' => 'متن نظر بیش از حد طولانی است.',
        ]);

        $review = $this->reviews->submit(
            $plugin,
            $request->user(),
            (int) $validated['rating'],
            $validated['comment'] ?? null,
        );

        return response()->json([
            'data' => $review,
            'message' => 'نظر شما ثبت شد و پس از تأیید در فروشگاه نمایش داده می‌شود.',
        ], 201);
    }

    /** GET v1/market/reviews (reviewer moderation queue). */
    public function queue(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reviews->pending(20, $request->query('status')),
        ]);
    }

    /** POST v1/market/reviews/{review}/approve */
    public function approve(Request $request, MarketReview $review): JsonResponse
    {
        return response()->json([
            'data' => $this->reviews->approve($review, $this->note($request)),
        ]);
    }

    /** POST v1/market/reviews/{review}/reject */
    public function reject(Request $request, MarketReview $review): JsonResponse
    {
        return response()->json([
            'data' => $this->reviews->reject($review, $this->note($request)),
        ]);
    }

    private function note(Request $request): ?string
    {
        $note = $request->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    private function ensureMarketplaceItem(Plugin $plugin): void
    {
        if ($plugin->source !== Plugin::SOURCE_MARKET
            || $plugin->review_status !== Plugin::REVIEW_APPROVED
            || (bool) $plugin->yanked) {
            throw new MarketException('این افزونه در فروشگاه در دسترس نیست.', 404);
        }
    }
}
