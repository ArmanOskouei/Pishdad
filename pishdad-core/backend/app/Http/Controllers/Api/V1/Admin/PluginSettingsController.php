<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginSettingsStore;
use App\Services\Plugins\PluginSettingsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * K6.7 — تنظیمات افزونه‌ها: خواندن اسکیما + مقادیر، و ذخیرهٔ مقادیر.
 *
 * ## چرا این نقطه «بیرون از فهرست نقاط اتصال» است
 *
 * افزونه اینجا چیزی **اعلام** نمی‌کند؛ فقط اسکیما را در مانیفست اعلام کرده و
 * حالا کاربر مقدار می‌دهد. پس برخلاف `admin.menu`، سرویسی است که هسته
 * می‌دهد و افزونه مصرفش می‌کند.
 *
 * ## مرز امنیتی
 *
 * مقدار ذخیره‌شده بعداً مستقیم به `SchemaForm` می‌رود و رندر می‌شود. پس:
 *  ۱) ذخیره فقط برای افزونه‌ای که **همین حالا فعال** است و اسکیما اعلام کرده.
 *  ۲) هر کلیدِ اعلام‌نشده رد می‌شود (`PluginSettingsValidator`) — وگرنه یک
 *     درخواست دستی هر کلیدی تزریق می‌کرد و آن کلید رندر می‌شد.
 *  ۳) نوع دقیق سنجیده می‌شود، نه coerces.
 */
class PluginSettingsController extends Controller
{
    /**
     * `GET /v1/admin/plugins/settings` — اسکیما + مقادیر همهٔ افزونه‌ها.
     *
     * یک درخواست، نه یکی به‌ازای هر افزونه: صفحهٔ تنظیمات همه را با هم
     * می‌خواهد و صد درخواست برای ده افزونه یعنی ده بار همان کار.
     */
    public function index(Request $request): JsonResponse
    {
        $schemas = ManifestRegistry::pluginSettingsSchemas();

        // فقط افزونه‌هایی که اسکیما اعلام کرده‌اند. مقادیرِ ذخیره‌شدهٔ افزونهٔ
        // حذف‌شده نباید در پاسخ بیایند — نه چون رمزی است، بلکه چون UI هیچ
        // فرمی برایشان ندارد و دادهٔ بی‌صاحب فقط جای اشغال می‌کند.
        $slugs = array_values(array_unique(array_map(
            fn (array $s) => (string) ($s['slug'] ?? ''),
            array_filter($schemas, fn ($s) => is_array($s) && ($s['slug'] ?? '') !== ''),
        )));

        $values = [];
        foreach ($slugs as $slug) {
            $values[$slug] = PluginSettingsStore::read($slug);
        }

        return response()->json([
            'data' => [
                'schemas' => array_values(array_filter(
                    $schemas,
                    fn ($s) => is_array($s) && in_array((string) ($s['slug'] ?? ''), $slugs, true),
                )),
                'values' => $values,
            ],
        ]);
    }

    /**
     * `PUT /v1/admin/plugins/settings/{slug}` — ذخیرهٔ مقادیر یک افزونه.
     *
     * ۴۰۴ وقتی افزونه اسکیما اعلام نکرده، نه ۴۲۲. تفاوت مهم است: ۴۲۲ می‌گوید
     * «ورودی‌ات بد است» و کاربر دنبال اصلاح ورودی می‌رود، در حالی که مشکل
     * این است که اصلاً صفحهٔ تنظیمی وجود ندارد.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $schema = $this->schemaFor($slug);

        if ($schema === null) {
            return response()->json(['message' => 'این افزونه فرم تنظیمات اعلام نکرده است.'], 404);
        }

        $result = PluginSettingsValidator::validate($schema, $request->all());

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages(array_map(
                fn (string $m) => [$m],
                $result['errors'],
            ));
        }

        PluginSettingsStore::write($slug, $result['values']);

        return response()->json([
            'message' => 'تنظیمات افزونه ذخیره شد.',
            'data' => ['values' => $result['values']],
        ]);
    }

    /**
     * اسکیمای اعلام‌شدهٔ یک افزونهٔ فعال، یا `null`.
     *
     * `pluginSettingsSchemas()` فقط مانیفست افزونه‌های **فعال** را می‌خواند، پس
     * غیرفعال‌بودن خودش اینجا گرفته می‌شود و نیازی به بررسی جدا نیست.
     */
    private function schemaFor(string $slug): ?array
    {
        foreach (ManifestRegistry::pluginSettingsSchemas() as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? null) === $slug) {
                $schema = $entry['schema'] ?? null;

                return is_array($schema) ? $schema : null;
            }
        }

        return null;
    }
}
