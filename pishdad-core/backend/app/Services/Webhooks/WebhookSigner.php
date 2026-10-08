<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Str;

/**
 * WF-L2 — امضای وب‌هوکِ خروجی (HMAC-SHA256).
 *
 * ## ⭐⭐ قراردادِ دقیق — این را در گیرنده کپی کنید
 *
 * درخواست یک `POST` با بدنهٔ JSON است و **سه** سرآیند دارد:
 *
 * | سرآیند                  | مقدار |
 * |-------------------------|-------|
 * | `X-PISHDAD-Event`           | نامِ رویداد، مثلاً `page.published` |
 * | `X-PISHDAD-Timestamp`       | زمانِ ارسال، **ثانیهٔ Unix** به‌صورت رشتهٔ عددی |
 * | `X-PISHDAD-Signature`       | امضا، **۶۴ نویسهٔ hex کوچک** (بی‌فاصله) |
 *
 * و رشته‌ای که امضا می‌شود **دقیقاً** این است — با **یک نقطه** (`0x2E`)
 * بین timestamp و **بدنهٔ خام** (همان بایت‌هایی که در بدنه می‌آیند، پیش از
 * هر parse کردنی):
 *
 * ```
 * signed_string = X-PISHDAD-Timestamp . "." . raw_request_body
 * signature     = hash_hmac("sha256", signed_string, secret)   // hex, lowercase
 * ```
 *
 * نمونهٔ بررسی (PHP):
 *
 * ```php
 * $ts  = $_SERVER['HTTP_X_PISHDAD_TIMESTAMP'];
 * $raw = file_get_contents('php://input');
 * $ok  = hash_equals(
 *     hash_hmac('sha256', $ts.'.'.$raw, $secret),
 *     $_SERVER['HTTP_X_PISHDAD_SIGNATURE'] ?? ''
 * );
 * ```
 *
 * ## ⭐ چرا «بدنهٔ خام» و نه نسخهٔ parse‌شده
 *
 * اگر گیرنده اول JSON را parse کند و دوباره encode کند، ترتیبِ کلیدها و فاصله‌ها
 * می‌تواند عوض شود ⇒ امضا هرگز مطابقت نمی‌کند، بدون آنکه هیچ‌چیز خراب به نظر
 * برسد. امضا روی **همان بایت‌ها** می‌خوابد تا این کلاسِ خطا ممکن نباشد.
 * به همین دلیل `WebhookSender` بدنه را یک بار می‌سازد و **همان رشته** را هم
 * امضا می‌کند و هم می‌فرستد.
 *
 * ## ⭐ چرا timestamp داخلِ رشتهٔ امضاشده هست
 *
 * `RevalidateSigner` برای ورودی (inbound) بازیِ replay با پنجرهٔ زمانی و nonce
 * دارد. اینجا جهت برعکس است: ما فرستنده‌ایم و فقط باید ثابت کنیم درخواست از
 * همین نصب آمده. با وجودِ timestamp در رشتهٔ امضا، یک ضبطِ قدیمی دیگر با رازِ
 * فعلی مطابقت نمی‌کند (چون timestamp جزئی از امضا است) — بدون آن، یک درخواستِ
 * ضبط‌شده تا ابد معتبر می‌ماند.
 *
 * ## چرا `hash_hmac` خام و نه `hash_equals` اینجا
 *
 * `hash_equals` وظیفهٔ **مقایسه** است و در سمتِ گیرنده انجام می‌شود. این کلاس
 * تولیدکننده است، پس فقط می‌سازد؛ ساختِ دوباره در تست‌ها (`OutboundWebhookTest`)
 * دقیقاً همین مسیر را می‌رود و یعنی تست **گیرنده** را هم آزمایش می‌کند.
 */
final class WebhookSigner
{
    /** نامِ رویداد (`page.published`، `ticket.created`، …). */
    public const HEADER_EVENT = 'X-PISHDAD-Event';

    /** ثانیهٔ Unix به‌صورت رشتهٔ عددی. */
    public const HEADER_TIMESTAMP = 'X-PISHDAD-Timestamp';

    /** HMAC-SHA256 به‌صورت hex کوچک. */
    public const HEADER_SIGNATURE = 'X-PISHDAD-Signature';

    /** شناسهٔ یکتای هر تحویل — برای dedupe سمتِ گیرنده. */
    public const HEADER_DELIVERY = 'X-PISHDAD-Delivery';

    public function __construct(
        private readonly string $secret,
    ) {}

    /**
     * رشته‌ای که امضا می‌شود: `{timestamp}.{rawBody}`.
     */
    public function signedString(string $timestamp, string $rawBody): string
    {
        return $timestamp.'.'.$rawBody;
    }

    public function signature(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $this->signedString($timestamp, $rawBody), $this->secret);
    }

    /**
     * بدنهٔ نهاییِ JSON.
     *
     * ⭐ کلیدها همین سه‌تا و همین ترتیب‌اند و **هیچ چیز دیگری** به JSON نمی‌رود
     * (نه راز، نه نشانی). گیرنده باید بتواند به هر دو سویید همه چیز را از
     * `data` بخواند.
     *
     * @param  array<string, mixed>  $data
     * @return array{event: string, occurred_at: string, data: array<string, mixed>}
     */
    public function payload(string $event, array $data, ?string $occurredAt = null): array
    {
        return [
            'event' => $event,
            'occurred_at' => $occurredAt ?? now()->toIso8601String(),
            'data' => $data,
        ];
    }

    /**
     * JSON خام + سرآیندهای آماده، از **یک** رشته.
     *
     * تنها راهِ درست ساختنِ درخواست: اگر `payload` را به `Http::asJson()` بدهیم،
     * Guzzle خودش دوباره encode می‌کند و آن بایت‌ها ممکن است فرق کنند ⇒ امضا
     * روی چیزی می‌نشیند که مخاطب نمی‌بیند.
     *
     * @param  array<string, mixed>  $data
     * @return array{body: string, headers: array<string, string>}
     */
    public function seal(string $event, array $data, ?string $timestamp = null, ?string $deliveryId = null): array
    {
        $body = json_encode($this->payload($event, $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($body) || $body === '') {
            throw new \RuntimeException('بدنهٔ وب‌هوک قابلِ رمزگذاری نیست.');
        }

        $timestamp ??= (string) now()->getTimestamp();

        return [
            'body' => $body,
            'headers' => [
                self::HEADER_EVENT => $event,
                self::HEADER_TIMESTAMP => $timestamp,
                self::HEADER_SIGNATURE => $this->signature($timestamp, $body),
                self::HEADER_DELIVERY => $deliveryId ?? (string) Str::uuid(),
            ],
        ];
    }
}
