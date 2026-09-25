<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۵.۳ — تیکت‌های پنل مشتری (مشترک نصب تک‌سایتی: همه مدیران همه تیکت‌ها).
 * ضمیمه = media_id با چک وجود؛ پاسخ جدید = اعلان داخلی (database) برای طرف مقابل.
 */
class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // مشترک: همه تیکت‌های نصب برای هر مدیری که دسترسی دارد.
        $query = Ticket::query()->withCount('messages');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // دسته ۱ (برابری UI/UX): جستجوی متنی موضوع + متن پیام‌ها.
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('subject', 'ilike', "%{$search}%")
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'ilike', "%{$search}%")));
        }

        $paginator = $query->latest()->paginate(
            (int) $request->query('per_page', 20)
        )->toArray();
        foreach ($paginator['data'] as $index => $ticket) {
            $paginator[$index] = $ticket;
        }

        return response()->json(['data' => $paginator]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'priority' => 'sometimes|string|in:low,normal,high',
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
            'priority' => 'sometimes|string|in:low,normal,high',
        ], [
            'status.in' => 'وضعیت معتبر نیست.',
            'priority.in' => 'اولویت معتبر نیست.',
        ]);

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

    /** پاسخ اپراتور مرکزی روی تیکت مشتری (فقط operator — مسیر central). */
    public function operatorReply(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ], [
            'body.required' => 'متن پیام الزامی است.',
        ]);

        $message = $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'author_type' => 'operator',
            'body' => $validated['body'],
        ]);

        $this->notifyCreator($ticket, $message);

        return response()->json([
            'message' => 'پاسخ اپراتور ثبت شد.',
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
        if ($creator instanceof User && (int) $creator->id !== (int) $message->user_id) {
            $creator->notify(new TicketNotification($ticket, $message));
        }
    }
}
