<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Http\JsonResponse;

/**
 * تسک ۶ — schema تنظیمات ویجت‌ها برای SchemaForm (به‌جای JSON خام).
 * هسته از config/widgets.php + ویجت‌های اعلام‌شده در مانیفست پلاگین‌های
 * فعال و قالب فعال کاربر (هوک widgets — قرارداد docs/PLUGIN-MANIFEST-CONTRACT.md).
 * type ناشناس در این لیست نیست؛ فرانت برایش fallback به JSON خام می‌دهد.
 */
class WidgetSchemaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ManifestRegistry::widgetSchemas(),
        ]);
    }
}
