<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MailSettings;
use App\Mail\MailTemplates;
use App\Mail\OutboundEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * WF-H11 — SMTP اختصاصی هر نصب + قالب‌های ایمیل.
 *
 * رمز SMTP رمزنگاری‌شده ذخیره می‌شود و هرگز به کلاینت برنمی‌گردد؛ فقط
 * `password_set` (بولین) می‌آید. ذخیرهٔ رمزِ خالی = «بدون تغییر».
 */
class MailSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => 'nullable|string|max:255|no_markup',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255|no_markup',
            // رمز می‌تواند شامل `<` باشد ⇒ عمداً `no_markup` ندارد؛ فقط طول.
            'password' => 'nullable|string|max:1024',
            'clear_password' => 'sometimes|boolean',
            'from_address' => 'nullable|email|max:200',
            'from_name' => 'nullable|string|max:150|no_markup',
            'encryption' => 'required|string|in:tls,ssl,none',
        ], [
            'host.max' => 'میزبان SMTP بیش از حد طولانی است.',
            'port.integer' => 'پورت باید عدد باشد.',
            'port.min' => 'پورت باید بین ۱ تا ۶۵۵۳۵ باشد.',
            'port.max' => 'پورت باید بین ۱ تا ۶۵۵۳۵ باشد.',
            'username.max' => 'نام کاربری بیش از حد طولانی است.',
            'password.max' => 'رمز عبور بیش از حد طولانی است.',
            'from_address.email' => 'ایمیل فرستنده معتبر نیست.',
            'from_name.max' => 'نام فرستنده بیش از حد طولانی است.',
            'encryption.required' => 'نوع رمزنگاری الزامی است.',
            'encryption.in' => 'نوع رمزنگاری معتبر نیست (tls، ssl یا none).',
        ]);

        MailSettings::save($validated);

        return response()->json([
            'message' => 'تنظیمات ایمیل ذخیره شد.',
            'data' => $this->payload(),
        ]);
    }

    public function updateTemplates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'templates' => 'required|array',
            'templates.ticket.subject' => 'nullable|string|max:255|no_markup',
            'templates.ticket.body' => 'nullable|string|max:20000',
            'templates.password_reset.subject' => 'nullable|string|max:255|no_markup',
            'templates.password_reset.body' => 'nullable|string|max:20000',
            'templates.contact.subject' => 'nullable|string|max:255|no_markup',
            'templates.contact.body' => 'nullable|string|max:20000',
        ], [
            'templates.required' => 'قالب‌ها الزامی است.',
            'templates.ticket.subject.max' => 'موضوع قالب تیکت بیش از حد طولانی است.',
            'templates.ticket.body.max' => 'بدنهٔ قالب تیکت بیش از حد طولانی است.',
            'templates.password_reset.subject.max' => 'موضوع قالب بازیابی رمز بیش از حد طولانی است.',
            'templates.password_reset.body.max' => 'بدنهٔ قالب بازیابی رمز بیش از حد طولانی است.',
            'templates.contact.subject.max' => 'موضوع قالب فرم تماس بیش از حد طولانی است.',
            'templates.contact.body.max' => 'بدنهٔ قالب فرم تماس بیش از حد طولانی است.',
        ]);

        MailTemplates::save($validated['templates']);

        return response()->json([
            'message' => 'قالب‌های ایمیل ذخیره شد.',
            'data' => ['templates' => MailTemplates::get()],
        ]);
    }

    /**
     * ارسال ایمیل آزمایشی با همان تنظیماتِ ذخیره‌شده. نتیجه **صادقانه** است:
     * هر استثنای transport ⇒ ۴۲۲ با `sent=false`، نه یک پیام موفقِ دروغ.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email|max:200',
        ], [
            'to.required' => 'ایمیل گیرندهٔ آزمایشی الزامی است.',
            'to.email' => 'ایمیل گیرندهٔ آزمایشی معتبر نیست.',
        ]);

        if (trim((string) MailSettings::get()['host']) === '') {
            return response()->json([
                'message' => 'ابتدا میزبان SMTP را ذخیره کنید.',
                'data' => ['sent' => false],
            ], 422);
        }

        MailSettings::applyToConfig();

        try {
            Mail::to($validated['to'])->send(new OutboundEmail(
                'ایمیل آزمایشی '.config('app.name'),
                'این یک ایمیل آزمایشی از '.config('app.name').' است. '
                    .'اگر آن را دریافت کردید، تنظیمات SMTP درست کار می‌کند.',
            ));
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'ارسال ایمیل آزمایشی ناموفق بود: '.$e->getMessage(),
                'data' => ['sent' => false],
            ], 422);
        }

        return response()->json([
            'message' => 'ایمیل آزمایشی با موفقیت ارسال شد.',
            'data' => ['sent' => true],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $settings = MailSettings::get();

        return [
            'host' => (string) $settings['host'],
            'port' => (int) $settings['port'],
            'username' => (string) $settings['username'],
            'from_address' => (string) $settings['from_address'],
            'from_name' => (string) $settings['from_name'],
            'encryption' => (string) $settings['encryption'],
            'password_set' => MailSettings::hasPassword(),
            'templates' => MailTemplates::get(),
        ];
    }
}
