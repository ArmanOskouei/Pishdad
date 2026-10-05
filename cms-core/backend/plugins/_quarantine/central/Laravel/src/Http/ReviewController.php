<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Models\SystemPlugin;
use App\Models\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مورد ۶ — صف بازبینی مرکزی پلاگین/قالب.
 *
 * - GET /api/v1/p/central/review/queue: همه pendingها (پلاگین + قالب) با مالک.
 * - POST /api/v1/p/central/review/{type}/{id}/approve: تأیید.
 * - POST /api/v1/p/central/review/{type}/{id}/reject: رد با note اجباری.
 */
class ReviewController extends Controller
{
    public function queue(Request $request): JsonResponse
    {
        $plugins = Plugin::query()
            ->where('review_status', 'pending')
            // J7 — افزونه‌های سیستمی در صف بازبینی معنا ندارند: نه قابل تأییدند و
            // نه قابل رد. اگر در صف بمانند، اپراتور هر بار یک بن‌بست می‌بیند که
            // هر بار ۴۲۲ می‌خورد.
            ->whereNotIn('slug', SystemPlugin::allowedSlugs())
            ->with('user:id,name,email')
            ->latest('submitted_at')
            ->get()
            ->map(fn (Plugin $p) => [
                'type' => 'plugin',
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'version' => $p->version,
                'signature_valid' => $p->signature_valid,
                'submitted_at' => $p->submitted_at,
                'manifest' => $p->manifest,
                'owner' => $p->user ? $p->user->only(['id', 'name', 'email']) : null,
            ]);

        $themes = Theme::query()
            ->where('review_status', 'pending')
            ->with('owner:id,name,email')
            ->latest('submitted_at')
            ->get()
            ->map(fn (Theme $t) => [
                'type' => 'theme',
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'version' => $t->version,
                'signature_valid' => $t->signature_valid,
                'submitted_at' => $t->submitted_at,
                'manifest' => $t->manifest,
                'owner' => $t->owner ? $t->owner->only(['id', 'name', 'email']) : null,
            ]);

        $items = $plugins->concat($themes)->values();

        return response()->json(['data' => $items, 'total' => $items->count()]);
    }

    /**
     * ⛔ **افزونه و قالبِ سیستمی از بازبینی خارج‌اند.**
     *
     * J7 — این guard قبلاً نبود و یعنی یک اپراتور می‌توانست افزونهٔ **هسته** را
     * تأیید کند. `Plugin::isSystem()` می‌گوید افزونه‌های سیستمی محافظت می‌شوند
     * (حذف/نصبِ دوباره با `plugins.edit` ممنوع است) — ولی مسیرِ بازبینی این قاعده
     * را دور می‌زد، یعنی یک حکمِ امنیتیِ نیم‌کاره.
     *
     * چرا این‌جا و نه در `ReviewService` هسته؟ چون این endpoint متعلق به افزونه
     * است و بهتر است خودش قاعده‌اش را داشته باشد؛ دوباره‌گذاری در هر سرویس یعنی
     * یک راهِ دور زدنِ تازه برای همین باگ.
     *
     * پیام **۴۲۲** است نه ۴۰۳: افزونه وجود دارد و قابلِ بازبینی نیست — یعنی درخواست
     * معتبر است ولی موضوعش غلط است. ۴۰۳ اینجا یعنی احراز هویت، که پیامِ گمراه‌کننده
     * می‌دهد.
     */
    private function guardIsReviewable(Plugin|Theme $model): void
    {
        if ($model instanceof Plugin && $model->isSystem()) {
            abort(response()->json([
                'message' => 'افزونه‌های سیستمیِ هسته قابلِ بازبینی نیستند. این‌ها بخشی از خودِ نصب‌اند و وضعیتشان با به‌روزرسانیِ هسته تعیین می‌شود، نه با تأییدِ دستی.',
            ], 422));
        }
    }

    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->findOrFail($type, $id);

        $this->guardIsReviewable($model);

        $model->forceFill([
            'review_status' => 'approved',
            'review_note' => null,
            'reviewed_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'مورد تأیید شد و فعال‌سازی برای مشتری آزاد شد.',
            'data' => $model->fresh(),
        ]);
    }

    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'required|string|min:5|max:2000',
        ], [
            'note.required' => 'دلیل رد الزامی است.',
            'note.min' => 'دلیل رد باید حداقل ۵ نویسه باشد.',
            'note.max' => 'دلیل رد بیش از حد طولانی است.',
        ]);

        $model = $this->findOrFail($type, $id);

        $this->guardIsReviewable($model);

        $model->forceFill([
            'review_status' => 'rejected',
            'review_note' => $validated['note'],
            'reviewed_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'مورد رد شد و دلیل برای مشتری ثبت شد.',
            'data' => $model->fresh(),
        ]);
    }

    private function findOrFail(string $type, int $id): Plugin|Theme
    {
        return match ($type) {
            'plugin' => Plugin::query()->findOrFail($id),
            'theme' => Theme::query()->findOrFail($id),
            default => abort(response()->json(['message' => 'نوع مورد نامعتبر است (plugin یا theme).'], 422)),
        };
    }
}
