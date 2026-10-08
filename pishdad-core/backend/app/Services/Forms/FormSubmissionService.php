<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outbox\Outbox;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WF-H10 — ذخیرهٔ پاسخ فرم + توزیع به مقصد (ایمیل یا تیکت).
 *
 * fail-soft: شکست اعلان/ایمیل نباید ثبت پاسخ را ۵۰۰ کند؛ پاسخ در دیتابیس
 * هست و از پنل دیده می‌شود (همان سیاست فرم تماس موجود).
 */
class FormSubmissionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(Form $form, array $data, ?string $ip, ?string $userAgent): FormSubmission
    {
        $payload = $form->sanitizePayload($data);

        $submission = $form->submissions()->create([
            'payload' => $payload,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        try {
            if ($form->destination === Form::DEST_EMAIL) {
                $this->sendEmail($form, $submission, $payload);
            } else {
                $this->createTicket($form, $submission, $payload);
            }
        } catch (Throwable $e) {
            Log::error('ارسال پاسخ فرم به مقصد شکست خورد ولی پاسخ ذخیره شد.', [
                'form_id' => $form->id,
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendEmail(Form $form, FormSubmission $submission, array $payload): void
    {
        $recipients = array_values(array_unique(array_filter(
            is_array($form->recipients) ? $form->recipients : [],
            fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL),
        )));

        foreach ($recipients as $recipient) {
            Outbox::email(
                $recipient,
                "form-submission:{$submission->id}:{$recipient}",
                'پاسخ فرم: '.$form->name,
                $this->body($form, $payload),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createTicket(Form $form, FormSubmission $submission, array $payload): void
    {
        $email = $this->firstString($payload, ['email', 'e-mail', 'mail']);
        $name = $this->firstString($payload, ['name', 'full_name', 'fullname']);

        $ticket = Ticket::query()->create([
            'user_id' => null,
            'subject' => $form->name,
            'contact_name' => $name,
            'contact_email' => $email,
            'source' => 'form',
        ]);
        $ticket->messages()->create([
            'user_id' => null,
            'author_type' => 'contact',
            'body' => $this->body($form, $payload),
        ]);

        $admins = User::query()->whereIn('role', ['owner', 'admin'])->get();

        if ($admins->isNotEmpty() && $email !== null) {
            // E75 — مثل مسیرِ فرمِ تماس: سطرِ دیتابیس با کلیدِ کاتالوگ +
            // تلگرامِ فوری برای مدیرانی که کانالش را روشن دارند.
            // E77 — values به ترتیبِ قالب: [نام، ایمیل، تلفن، متن].
            foreach ($admins as $admin) {
                NotificationDispatcher::send($admin, 'ticket.created', [
                    $name ?? 'بازدیدکننده',
                    $email,
                    $this->firstString($payload, ['phone', 'mobile', 'tel', 'telephone', 'phone_number']) ?? '—',
                    $this->firstString($payload, ['message', 'body', 'text', 'description']) ?? '—',
                ]);
            }
        }

        $configured = trim((string) config('mail.admin_address', ''));
        $recipient = $configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)
            ? $configured
            : $admins->map(fn (User $u) => (string) $u->email)->first(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        if (is_string($recipient) && $recipient !== '') {
            Outbox::email(
                $recipient,
                "form-ticket:{$ticket->id}:{$recipient}",
                'پاسخ فرم: '.$form->name,
                $this->body($form, $payload)."\n\nشناسهٔ تیکت: {$ticket->id}",
            );
        }
    }

    /**
     * متن خوانا از payload بر اساس برچسب هر فیلد.
     *
     * @param  array<string, mixed>  $payload
     */
    private function body(Form $form, array $payload): string
    {
        $lines = ['پاسخ تازه از فرم سایت: '.$form->name, ''];

        foreach ($form->fieldList() as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key) || ! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_bool($value)) {
                $value = $value ? 'بله' : 'خیر';
            } elseif (is_array($value)) {
                $value = implode('، ', array_map('strval', $value));
            }
            $lines[] = ($field['label'] ?? $key).': '.$value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return trim($payload[$key]);
            }
        }

        return null;
    }
}
