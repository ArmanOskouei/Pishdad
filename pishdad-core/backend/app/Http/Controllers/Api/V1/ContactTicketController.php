<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\MailTemplates;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outbox\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * تسک ۵.۳ — ایجاد تیکت از فرم تماس عمومی.
 * ضداسپم: honeypot فلد `website` (پر باشد = موفقیت جعلی بدون ساخت رکورد)
 * + rate-limit سفت `contact` (۵ در دقیقه برای هر IP).
 *
 * E6 — بعد از ساخت تیکت، به سوپرادمین‌ها «در پنل» اطلاع داده می‌شود (اعلان
 * دیتابیسی) و یک ایمیل هم به آدرسِ مدیر در **outbox** صف می‌شود. ایمیل از
 * outbox می‌رود، نه با `Mail::send()` مستقیم:
 *
 *  - SMTP در نصبِ عمومی تضمین‌شده نیست؛ صف یعنی خطای SMTP پاسخِ فرم را ۵۰۰
 *    نمی‌کند و پیام با retry از دست نمی‌رود.
 *  - `dedupe_key` پایدار یعنی ارسالِ دوبارهٔ همان فرم دو ایمیل نمی‌سازد.
 *
 * اگر اعلان، ایمیل یا لاگ شکست بخورد، باز هم ۲۰۱ برمی‌گردد — تیکت در دیتابیس
 * هست و بازگرداندن ۵۰۰ یعنی فرستنده دوباره می‌فرستد و رکورد تکراری می‌سازد.
 */
class ContactTicketController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:200',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'website' => 'sometimes|nullable|string|max:200', // honeypot
        ], [
            'name.required' => 'نام الزامی است.',
            'email.required' => 'ایمیل الزامی است.',
            'email.email' => 'قالب ایمیل معتبر نیست.',
            'subject.required' => 'موضوع الزامی است.',
            'body.required' => 'متن پیام الزامی است.',
        ]);

        // بات honeypot را پر می‌کند: پاسخ موفق جعلی، بدون هیچ رکوردی.
        if (! empty($validated['website'])) {
            return response()->json(['message' => 'پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.'], 201);
        }

        $ticket = Ticket::query()->create([
            'user_id' => null,
            'subject' => $validated['subject'],
            'contact_name' => $validated['name'],
            'contact_email' => $validated['email'],
            'source' => 'contact',
        ]);
        $ticket->messages()->create([
            'user_id' => null,
            'author_type' => 'contact',
            'body' => $validated['body'],
        ]);

        $this->notifyAdmins($ticket, $validated);

        return response()->json([
            'message' => 'پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.',
            'data' => ['ticket_id' => $ticket->id],
        ], 201);
    }

    /**
     * E6 — اعلانِ داخلِ پنل + صفِ ایمیل + لاگ.
     *
     * اگر هیچ سوپرادمینی نباشد (نصبِ نیمه‌کاره) فقط لاگ می‌ماند؛ این یک هشدار
     * نیست چون تیکت همچنان در دیتابیس ثبت شده و از پنل دیده می‌شود. صفِ ایمیل
     * اما مستقل از دیتابیسِ اعلان‌ها کار می‌کند و اگر `MAIL_ADMIN_ADDRESS`
     * تنظیم شده باشد، حتی بدونِ سوپرادمین هم به همان آدرس می‌رود.
     *
     * @param  array{name: string, email: string, subject: string, body: string}  $validated
     */
    private function notifyAdmins(Ticket $ticket, array $validated): void
    {
        $context = [
            'ticket_id' => $ticket->id,
            'source' => $ticket->source,
        ];

        try {
            $admins = User::query()->whereIn('role', ['owner', 'admin'])->get();

            if ($admins->isNotEmpty()) {
                // E75 — به‌جای اعلانِ دستی، از دیسپچرِ کاتالوگ: همان سطرِ
                // دیتابیس (حالا با کلید و لینکِ اقدام) + تلگرامِ فوری برای
                // مدیرانی که کانالش را روشن دارند. ایمیل از مسیرِ پایینِ همین
                // تابع می‌رود، پس در کلیدِ کاتالوگ نیست تا دو بار نرود.
                // E77 — values به ترتیبِ قالب: [نام، ایمیل، تلفن، متن].
                // این فرم فیلدِ تلفن ندارد ⇒ `—`.
                foreach ($admins as $admin) {
                    NotificationDispatcher::send($admin, 'ticket.created', [
                        $validated['name'],
                        $validated['email'],
                        '—',
                        $validated['body'],
                    ]);
                }
            } else {
                Log::warning('فرم تماس تیکت ساخت اما هیچ سوپرادمینی برای اطلاع‌رسانی پیدا نشد.', $context);
            }

            $queued = $this->queueAdminEmail($ticket, $validated, $admins);

            Log::info('پیام جدید فرم تماس ثبت شد.', $context + [
                'admins' => $admins->count(),
                'emails_queued' => $queued,
            ]);
        } catch (Throwable $e) {
            // fail-soft: فرم تماس نباید به‌خاطر اعلان‌رسانی ۵۰۰ بدهد.
            Log::error('اعلان‌رسانی فرم تماس شکست خورد ولی تیکت ثبت شد.', $context + [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * E6 — صف‌کردنِ ایمیلِ «پیام جدید فرم تماس» در outbox.
     *
     * گیرنده: `mail.admin_address` اگر معتبر باشد، وگرنه ایمیلِ سوپرادمین‌ها.
     * چرا اولِ کانفیگ؟ چون در نصب‌هایی که سوپرادمین ایمیلِ واقعی ندارد ولی
     * می‌خواهند اعلان به یک صندوق مشترک برود، یک نقطهٔ کنترل صریح لازم است.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $admins
     * @return int شمارِ ایمیل‌های صف‌شده
     */
    private function queueAdminEmail(Ticket $ticket, array $validated, $admins): int
    {
        $recipients = [];

        $configured = trim((string) config('mail.admin_address', ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $configured;
        }

        if ($recipients === []) {
            foreach ($admins as $admin) {
                $email = trim((string) $admin->email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }
        }

        $recipients = array_values(array_unique($recipients));

        $template = MailTemplates::render('contact', [
            'app_name' => (string) config('app.name'),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'ticket_id' => (int) $ticket->id,
        ]);

        foreach ($recipients as $recipient) {
            Outbox::email(
                $recipient,
                "contact-ticket:{$ticket->id}:{$recipient}",
                $template['subject'],
                $template['body'],
                html: true,
            );
        }

        return count($recipients);
    }
}
