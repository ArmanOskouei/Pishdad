<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Notifications\TicketNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * K7.13 — نمونهٔ مرجع: پاسخ اپراتور مرکزی، **درون افزونه**.
 *
 * ## چرا این فایل در `tests/Fixtures` است و نه در `app/`
 *
 * چون هسته `App\` است و افزونه `Pishdad\Plugins\`. اگر این کلاس داخل هسته بود،
 * همان چیزی می‌شد که `PluginAutoloader` باید جلویش را بگیرد — و عملاً
 * ثابت می‌کرد guard بی‌اثر است.
 *
 * ## چرا اصلاً اینجا هست
 *
 * تصمیم محصول: `operatorReply` به افزونهٔ مرکزی برود (سازگار با K7.9 که گفت
 * مرکز یک افزونهٔ معمولی است). این فایل **پیش‌شرط‌های** آن تصمیم را ثابت
 * می‌کند: افزونهٔ مرکزی می‌تواند مدل‌های هستهٔ تیکت را بخواند، بنویسد، و از
 * کانال اعلان هسته استفاده کند.
 *
 * وابستگی‌هایی که این کار با افزونه آورد — که در ledger صریح ثبت شده:
 *   - `App\Models\Ticket` و `TicketMessage` (خواندن و نوشتن)
 *   - `App\Notifications\TicketNotification` (اعلان به سازنده)
 *
 * `PluginDbContract::CORE_TABLES` جلوی **ساختن** این جدول‌ها را می‌گیرد ولی نه
 * خواندنشان — و `PluginAutoloader` هم فقط namespace افزونه را ثبت می‌کند، نه
 * دسترسی به `App\Models` را مسدود می‌کند. پس این وابستگی از قبل ممکن بود و
 * صرفاً استفاده‌اش اعلام نشده بود.
 */
class OperatorReplyController extends Controller
{
    /**
     * پاسخ اپراتور روی تیکت مشتری.
     *
     * بدنه عمداً **همان** `TicketController::operatorReply` هست، بدون تغییر
     * رفتار. تفاوت با `reply()` فقط `author_type = 'operator'` و نداشتن پیوست
     * است.
     *
     * ## چرا نام پارامتر دقیقاً `int $id` است
     *
     * `PluginDispatcher::bindParams()` مقدار مسیر را با **نام پارامتر** پیدا
     * می‌کند، نه با ترتیب: `$params[$parameter->getName()]`. مسیر بالا
     * `{id}` دارد، پس نام پارامتر باید `id` باشد.
     *
     * نسخهٔ اول `int $ticketId` بود و ۵۰۰ بی‌پیام می‌داد: پیام کامل
     * «Argument #2 ($ticketId) must be of type int, null given» است ولی
     * `PluginRouter` آن را به پاسخ عمومی تبدیل می‌کند. یعنی نویسندهٔ افزونه
     * هیچ راهنمایی نمی‌گرفت — بدتر از آنکه اصلاً نگفته باشد.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        // احراز هویت باید **اینجا** باشد، نه فرض.
        //
        // catch-all هسته `auth:sanctum` ندارد (می‌توانست داشته باشد ولی
        // middleware قابل اعلان افزونه است). پس `$request->user()` می‌تواند
        // `null` باشد و `$request->user()->id` یک `ErrorException` می‌دهد که
        // `PluginRouter` به ۵۰۰ عمومی تبدیلش می‌کند — یعنی به‌جای «وارد شوید»
        // یک خطای بی‌معنا.
        //
        // `manifest.api.routes[].middleware` هم می‌تواند `auth:sanctum` اعلام کند
        // (در مجموعهٔ بستهٔ middleware هست). هر دو لایه خوب است.
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'وارد نشده‌اید.'], 401);
        }

        $ticket = Ticket::query()->find($id);

        if ($ticket === null) {
            return response()->json(['message' => 'تیکت یافت نشد.'], 404);
        }

        // همان قاعدهٔ `reply()`: تیکت بسته پاسخ نمی‌گیرد.
        if ($ticket->status === Ticket::CLOSED) {
            return response()->json([
                'message' => 'تیکت بسته شده است؛ برای ادامه، تیکت جدید ثبت کنید.',
            ], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ], [
            'body.required' => 'متن پیام الزامی است.',
        ]);

        $message = $ticket->messages()->create([
            'user_id' => $user->id,
            'author_type' => 'operator',
            'body' => $validated['body'],
        ]);

        // اعلان داخلی برای سازنده — همان کاری که هسته می‌کرد.
        $creator = $ticket->user;
        if ($creator !== null && (int) $creator->id !== (int) $message->user_id) {
            $creator->notify(new TicketNotification($ticket, $message));
        }

        return response()->json([
            'message' => 'پاسخ اپراتور ثبت شد.',
            'data' => $message,
        ], 201);
    }
}
