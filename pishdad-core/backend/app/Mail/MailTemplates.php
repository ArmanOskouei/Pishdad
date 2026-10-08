<?php

namespace App\Mail;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;
use Throwable;

/**
 * WF-H11 — قالب‌های قابل ویرایشِ ایمیل برای تیکت/بازیابی رمز/فرم تماس.
 *
 * بدنه HTML است و هنگام ذخیره پاک‌سازی می‌شود (حذف script/iframe/on* و
 * javascript:/data:). متغیرها با `{{name}}` جایگزین می‌شوند و هنگام رندر در
 * HTML مقادیر escape می‌شوند تا دادهٔ کاربر به HTML تزریق نشود.
 */
final class MailTemplates
{
    public const GROUP = 'mail_templates';

    public const KEY = 'global';

    public const KEYS = ['ticket', 'password_reset', 'contact'];

    /** @return array<string, array{subject: string, body: string}> */
    public static function defaults(): array
    {
        return [
            'contact' => [
                'subject' => 'پیام جدید از فرم تماس: {{subject}}',
                'body' => '<p>پیام تازه از فرم تماس سایت <strong>{{app_name}}</strong>:</p>'
                    .'<p>شناسهٔ تیکت: {{ticket_id}}</p>'
                    .'<p>نام: {{name}}<br>ایمیل: {{email}}<br>موضوع: {{subject}}</p>'
                    .'<hr>'
                    .'<p>{{body}}</p>',
            ],
            'ticket' => [
                'subject' => 'پاسخ به تیکت: {{subject}}',
                'body' => '<p>{{name}} عزیز،</p>'
                    .'<p>پاسخ تازه‌ای برای تیکت «{{subject}}» ثبت شد:</p>'
                    .'<blockquote>{{body}}</blockquote>'
                    .'<p>برای مشاهدهٔ کامل، وارد پنل مدیریت شوید.</p>',
            ],
            'password_reset' => [
                'subject' => 'بازیابی رمز عبور {{app_name}}',
                'body' => '<p>{{name}} عزیز،</p>'
                    .'<p>برای تعیین رمز عبور تازه روی پیوند زیر بزنید:</p>'
                    .'<p><a href="{{url}}">بازیابی رمز عبور</a></p>'
                    .'<p>اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.</p>',
            ],
        ];
    }

    /**
     * قالب‌های ذخیره‌شده روی پیش‌فرض‌ها سوار می‌شوند. مقدارِ خالی = پیش‌فرض
     * (تا یک قالبِ خالی، ایمیلِ بی‌متن نسازد).
     *
     * @return array<string, array{subject: string, body: string}>
     */
    public static function get(): array
    {
        try {
            $stored = CachedSettings::remember(
                self::GROUP,
                self::KEY,
                300,
                fn () => Setting::get(self::GROUP, self::KEY, []),
            );
        } catch (Throwable) {
            $stored = [];
        }

        $templates = self::defaults();
        $stored = is_array($stored) ? $stored : [];

        foreach (self::KEYS as $key) {
            $subject = trim((string) ($stored[$key]['subject'] ?? ''));
            $body = trim((string) ($stored[$key]['body'] ?? ''));

            if ($subject !== '') {
                $templates[$key]['subject'] = $subject;
            }

            if ($body !== '') {
                $templates[$key]['body'] = $body;
            }
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $templates
     */
    public static function save(array $templates): void
    {
        $stored = [];

        foreach (self::KEYS as $key) {
            if (! isset($templates[$key]) || ! is_array($templates[$key])) {
                continue;
            }

            $stored[$key] = [
                'subject' => trim((string) ($templates[$key]['subject'] ?? '')),
                'body' => self::sanitizeHtml((string) ($templates[$key]['body'] ?? '')),
            ];
        }

        Setting::set(self::GROUP, self::KEY, $stored);
        CachedSettings::forget(self::GROUP, self::KEY);
    }

    /**
     * رندر قالب با متغیرها. خروجی: `subject` و `body` (بدنه HTML یا متنِ
     * خام با `nl2br` اگر هیچ تگی نداشته باشد).
     *
     * @param  array<string, mixed>  $vars
     * @return array{subject: string, body: string}
     */
    public static function render(string $key, array $vars = []): array
    {
        $templates = self::get();
        $template = $templates[$key] ?? self::defaults()['contact'];

        $subject = self::interpolate((string) $template['subject'], $vars, false);

        $body = (string) $template['body'];
        $isHtml = (bool) preg_match('/<[a-z][^>]*>/i', $body);
        $body = self::interpolate($body, $vars, $isHtml);

        if (! $isHtml) {
            $body = nl2br($body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * پاک‌سازیِ تهاجمی: تگ‌های اجراپذیر و ویژگی‌های رویداد و اسکیم‌های خطرناک
     * حذف می‌شوند. عمداً روی allowlist کامل نیست تا نگهداری‌اش ساده بماند،
     * ولی سطحِ خطرِ XSS/BEef را می‌بندد.
     */
    public static function sanitizeHtml(string $html): string
    {
        // عناصر با محتوا (script/style/iframe/object/embed/form).
        $html = (string) preg_replace(
            '#<\s*(script|style|iframe|object|embed|form|template|svg|math)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            '',
            $html,
        );

        // همان عناصر بدون بسته/خودبسته.
        $html = (string) preg_replace(
            '#<\s*/?\s*(script|style|iframe|object|embed|form|template|svg|math|meta|link|base)\b[^>]*>#is',
            '',
            $html,
        );

        // ویژگی‌های رویداد (onclick، onerror، …).
        $html = (string) preg_replace(
            '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $html,
        );

        // href/src با اسکیمِ اجراپذیر.
        $html = (string) preg_replace(
            '/(href|src)\s*=\s*("|\')\s*(javascript|vbscript|data)\s*:[^"\']*\2/i',
            '$1="#"',
            $html,
        );

        return $html;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private static function interpolate(string $text, array $vars, bool $escape): string
    {
        $result = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $m) use ($vars, $escape): string {
                $value = (string) ($vars[$m[1]] ?? '');

                return $escape ? e($value) : $value;
            },
            $text,
        );

        return $result ?? $text;
    }
}
