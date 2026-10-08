<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Media;
use App\Models\Page;
use Database\Seeders\SampleContentSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * WF-M21 — ساختِ محتوای نمونه روی تقاضا برای نصبِ تازه.
 *
 * چرا از `Artisan::call('db:seed')` و نه `new SampleContentSeeder`؟ چون
 * seederهای تودرتو (DefaultPagesSeeder/FormSeeder) از `$this->command->info()`
 * استفاده می‌کنند و فقط مسیرِ `db:seed` است که `command` را به آن‌ها منتقل
 * می‌کند. فراخوانیِ مستقیم در یک درخواستِ وب، `command` را تهی می‌گذارد.
 *
 * idempotent است: seeder صفحه/فرم/media را با کلیدهای یکتا به‌روزرسانی می‌کند،
 * پس فراخوانیِ دوباره چیزی تکرار نمی‌کند و شمارندهٔ خروجی صفر می‌ماند.
 */
class SampleContentController extends Controller
{
    public function store(): JsonResponse
    {
        $before = [
            'pages' => Page::withTrashed()->count(),
            'media' => Media::withTrashed()->count(),
            'forms' => Form::query()->count(),
        ];

        $exit = Artisan::call('db:seed', [
            '--class' => SampleContentSeeder::class,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            return response()->json(['message' => 'ساخت محتوای نمونه ناموفق بود.'], 500);
        }

        return response()->json([
            'message' => 'محتوای نمونه ساخته شد.',
            'data' => [
                'pages' => Page::withTrashed()->count() - $before['pages'],
                'media' => Media::withTrashed()->count() - $before['media'],
                'forms' => Form::query()->count() - $before['forms'],
            ],
        ]);
    }
}
