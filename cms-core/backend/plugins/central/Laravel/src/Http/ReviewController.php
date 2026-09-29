<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
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

    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->findOrFail($type, $id);

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
