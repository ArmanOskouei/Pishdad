<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Catalog;
use App\Services\Notifications\TelegramSender;
use App\Services\Outbox\Outbox;
use Illuminate\Console\Command;

/**
 * WF-M15 — خلاصهٔ روزانهٔ اعلان‌ها.
 *
 * ## ⭐ چرا یک command و نه ارسالِ تکی
 *
 * «اعلانِ خوانده‌نشده» یعنی چیزی که کاربر هنوز ندیده. اگر برای هرکدام یک
 * ایمیل/پیام جدا برود، کسی که یک هفته سرش شلوغ بوده دفعهٔ بعد ۳۰ پیام می‌گیرد
 * و کانال را خاموش می‌کند. اینجا همه در **یک** پیام جمع می‌شوند.
 *
 * ## ⭐ چرا از outbox رد می‌شود (و نه Mail/Http مستقیم)
 *
 * همان دلیلِ بقیهٔ کانال‌ها: retry، dedupe، و «گیرکردگیِ قابلِ‌مشاهده». dedupe
 * اینجا نقشِ مضاعف دارد — `digest:{user}:{date}` یعنی اجرای دوبارهٔ command در
 * همان روز (یا یک retryِ scheduler) پیامِ دوم نمی‌سازد.
 *
 * ## ⭐ چرا خوانده‌نشده‌ها را read نمی‌کنیم
 *
 * خلاصه یعنی «یادآوری». اگر read شوند، کاربری که ایمیل را باز نکرده دیگر
 * هشدار نمی‌گیرد. پس وضعیت دست‌نخورده می‌ماند و dedupe روزانه جلوی اسپم را
 * می‌گیرد.
 */
class NotificationDigest extends Command
{
    /** سقفِ مواردِ هر خلاصه — بیشتر از این، پیام به یک دیوارِ متن تبدیل می‌شود. */
    private const MAX_ITEMS = 50;

    protected $signature = 'notifications:digest
        {--dry-run : فقط گزارش بده؛ چیزی در صف ثبت نکن}';

    protected $description = 'خلاصهٔ روزانهٔ اعلان‌های خوانده‌نشده را برای کاربرانِ opt-in می‌فرستد';

    public function handle(): int
    {
        $date = now()->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $users = 0;
        $emails = 0;
        $telegrams = 0;
        $skipped = 0;

        User::query()
            ->where('notification_daily_digest', true)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($date, $dryRun, &$users, &$emails, &$telegrams, &$skipped): void {
                foreach ($chunk as $user) {
                    $unread = $user->unreadNotifications()->latest()->limit(self::MAX_ITEMS)->get();

                    if ($unread->isEmpty()) {
                        $skipped++;

                        continue;
                    }

                    $email = is_string($user->email) ? trim($user->email) : '';
                    $chat = TelegramSender::configured() && is_string($user->telegram_chat_id)
                        ? trim((string) $user->telegram_chat_id)
                        : '';

                    if ($email === '' && $chat === '') {
                        $skipped++;

                        continue;
                    }

                    $users++;

                    if ($dryRun) {
                        continue;
                    }

                    $count = $unread->count();
                    $subject = 'خلاصهٔ روزانهٔ اعلان‌ها ('.$count.' مورد)';
                    $body = $this->compose($unread);

                    $dedupe = 'digest:'.$user->id.':'.$date;

                    if ($email !== '') {
                        Outbox::email($email, $dedupe.':email', $subject, $body);
                        $emails++;
                    }

                    if ($chat !== '') {
                        Outbox::telegram($chat, $dedupe.':telegram', mb_substr($subject."\n".$body, 0, 1500));
                        $telegrams++;
                    }
                }
            });

        if ($dryRun) {
            $this->info(sprintf('حالت آزمایشی — کاربران: %d · بدونِ اعلان: %d', $users, $skipped));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'کاربران: %d · ایمیل: %d · تلگرام: %d · بدونِ اعلان: %d',
            $users,
            $emails,
            $telegrams,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $notifications
     */
    private function compose($notifications): string
    {
        $lines = "اعلان‌های خوانده‌نشدهٔ شما در پیش‌داد:\n\n";

        foreach ($notifications as $n) {
            $lines .= '• '.$this->titleFor($n)."\n";
        }

        return $lines;
    }

    /**
     * عنوانِ یک اعلان از **کاتالوگِ امروز** می‌آید (همان منبعِ حقیقتِ صندوق)،
     * نه از `data`. ردیف‌های افزونه (K5.8) از `data` خوانده می‌شوند.
     */
    private function titleFor(object $n): string
    {
        $key = is_string($n->catalog_key ?? null) ? $n->catalog_key : null;

        if ($key !== null && Catalog::has($key)) {
            $entry = Catalog::entry($key);

            return Catalog::render($entry['title'], $this->valuesOf($n));
        }

        $data = $this->decode($n->data ?? null);
        $title = $data['title'] ?? null;

        return is_string($title) ? Catalog::plain($title) : 'اعلان';
    }

    /** @return list<scalar|null> */
    private function valuesOf(object $n): array
    {
        $values = $this->decode($n->data ?? null)['values'] ?? [];

        return is_array($values) ? array_values($values) : [];
    }

    /** @return array<string, mixed> */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
