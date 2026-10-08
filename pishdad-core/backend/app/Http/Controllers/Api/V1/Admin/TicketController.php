<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MailTemplates;
use App\Models\Media;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketNotification;
use App\Services\Outbox\Outbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * تسک ۵.۳ — تیکت‌های پنل مشتری (مشترک نصب تک‌سایتی: همه مدیران همه تیکت‌ها).
 * ضمیمه = media_id با چک وجود؛ پاسخ جدید = اعلان داخلی (database) برای طرف مقابل.
 */
class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->filteredQuery($request)->latest()->paginate(
            (int) $request->query('per_page', 20)
        )->toArray();
        foreach ($paginator['data'] as $index => $ticket) {
            $paginator[$index] = $ticket;
        }

        return response()->json(['data' => $paginator]);
    }

    /** WF-M10 — خروجی CSV تیکت‌های فیلترشده (جریان‌دهی، بدون بارگذاری کل در حافظه). */
    public function exportCsv(Request $request): StreamedResponse
    {
        $filename = 'tickets-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['id', 'subject', 'status', 'priority', 'labels', 'source', 'contact_name', 'contact_email', 'messages_count', 'created_at', 'updated_at']);

            $this->filteredQuery($request)->latest()->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $ticket) {
                    fputcsv($out, [
                        $ticket->id,
                        $this->csvCell((string) $ticket->subject),
                        $ticket->status,
                        $ticket->priority,
                        $this->csvCell(implode(' | ', (array) $ticket->labels)),
                        $ticket->source,
                        $this->csvCell((string) ($ticket->contact_name ?? '')),
                        $this->csvCell((string) ($ticket->contact_email ?? '')),
                        $ticket->messages_count,
                        $ticket->created_at?->toIso8601String(),
                        $ticket->updated_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** فیلترهای مشترک فهرست و خروجی CSV (مشترک نصب: همه تیکت‌ها). */
    private function filteredQuery(Request $request): Builder
    {
        $query = Ticket::query()->withCount('messages');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        if ($label = trim((string) $request->query('label', ''))) {
            $query->whereJsonContains('labels', $label);
        }

        // دسته ۱ (برابری UI/UX): جستجوی متنی موضوع + متن پیام‌ها.
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('subject', 'ilike', "%{$search}%")
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'ilike', "%{$search}%")));
        }

        return $query;
    }

    /** محافظت از CSV injection: سلول‌های آغازشده با = + - @ با ' بی‌خطر می‌شوند. */
    private function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    /** برچسب‌ها: trim، حذف خالی‌ها و یکتاسازی (بدون تکرار). */
    private function normalizeLabels(array $labels): array
    {
        $clean = [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label !== '' && ! in_array($label, $clean, true)) {
                $clean[] = $label;
            }
        }

        return $clean;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'priority' => 'sometimes|string|in:'.implode(',', Ticket::PRIORITIES),
            'labels' => 'sometimes|array|max:20',
            'labels.*' => 'nullable|string|max:40',
            'attachment_media_id' => 'sometimes|nullable|integer|min:1',
        ], [
            'subject.required' => 'موضوع تیکت الزامی است.',
            'body.required' => 'متن پیام الزامی است.',
            'priority.in' => 'اولویت معتبر نیست.',
        ]);

        $this->attachmentExists($validated['attachment_media_id'] ?? null);

        $ticket = Ticket::query()->create([
            'user_id' => $request->user()->id,
            'subject' => $validated['subject'],
            'priority' => $validated['priority'] ?? 'normal',
            'labels' => $this->normalizeLabels($validated['labels'] ?? []),
            'source' => 'panel',
        ]);
        $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'author_type' => 'admin',
            'body' => $validated['body'],
            'attachment_media_id' => $validated['attachment_media_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'تیکت ثبت شد.',
            'data' => $ticket->load('messages'),
        ], 201);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json(['data' => $ticket->load(['messages.user:id,name'])]);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:open,pending,closed',
            'priority' => 'sometimes|string|in:'.implode(',', Ticket::PRIORITIES),
            'labels' => 'sometimes|array|max:20',
            'labels.*' => 'nullable|string|max:40',
        ], [
            'status.in' => 'وضعیت معتبر نیست.',
            'priority.in' => 'اولویت معتبر نیست.',
        ]);

        if (array_key_exists('labels', $validated)) {
            $validated['labels'] = $this->normalizeLabels($validated['labels']);
        }

        $ticket->fill($validated);
        if (($validated['status'] ?? null) === Ticket::CLOSED) {
            $ticket->closed_at = now();
        }
        $ticket->save();

        return response()->json([
            'message' => 'تیکت به‌روزرسانی شد.',
            'data' => $ticket->fresh(),
        ]);
    }

    public function destroy(Request $request, Ticket $ticket): JsonResponse
    {
        $ticket->delete();

        return response()->json(['message' => 'تیکت حذف شد.']);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        if ($ticket->status === Ticket::CLOSED) {
            return response()->json(['message' => 'تیکت بسته شده است؛ برای ادامه، تیکت جدید ثبت کنید.'], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
            'attachment_media_id' => 'sometimes|nullable|integer|min:1',
        ], [
            'body.required' => 'متن پیام الزامی است.',
        ]);

        $this->attachmentExists($validated['attachment_media_id'] ?? null);

        $message = $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'author_type' => 'admin',
            'body' => $validated['body'],
            'attachment_media_id' => $validated['attachment_media_id'] ?? null,
        ]);

        if ($ticket->status === Ticket::OPEN) {
            $ticket->forceFill(['status' => Ticket::PENDING])->save();
        }

        // اعلان داخلی برای سازنده تیکت (وقتی پاسخ‌دهنده خودش نیست — مثلاً اپراتور).
        $this->notifyCreator($ticket, $message);

        return response()->json([
            'message' => 'پاسخ ثبت شد.',
            'data' => $message,
        ], 201);
    }

    /** ضمیمه باید رسانه موجود نصب باشد (فقط وجود، بدون چک مالکیت). */
    private function attachmentExists(?int $mediaId): void
    {
        if ($mediaId === null) {
            return;
        }
        $exists = Media::query()
            ->where('id', $mediaId)
            ->exists();

        if (! $exists) {
            abort(response()->json(['message' => 'فایل ضمیمه یافت نشد.'], 404));
        }
    }

    private function notifyCreator(Ticket $ticket, TicketMessage $message): void
    {
        $creator = $ticket->user;
        if (! $creator instanceof User || (int) $creator->id === (int) $message->user_id) {
            return;
        }

        $creator->notify(new TicketNotification($ticket, $message));

        // WF-H11 — ایمیلِ پاسخ هم صف می‌شود (قالبِ قابل ویرایش). fail-soft:
        // شکستِ صف‌کردن نباید ثبت پاسخ را بیندازد.
        $email = trim((string) $creator->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            $template = MailTemplates::render('ticket', [
                'app_name' => (string) config('app.name'),
                'name' => (string) $creator->name,
                'subject' => (string) $ticket->subject,
                'body' => (string) $message->body,
                'ticket_id' => (int) $ticket->id,
            ]);

            Outbox::email(
                $email,
                "ticket-reply:{$message->id}:{$email}",
                $template['subject'],
                $template['body'],
                html: true,
            );
        } catch (Throwable $e) {
            Log::warning('صف‌کردن ایمیل پاسخ تیکت شکست خورد.', [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
