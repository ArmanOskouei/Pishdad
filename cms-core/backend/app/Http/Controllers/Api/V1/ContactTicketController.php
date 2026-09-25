<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۵.۳ — ایجاد تیکت از فرم تماس عمومی.
 * ضداسپم: honeypot فیلد `website` (پر باشد = موفقیت جعلی بدون ساخت رکورد)
 * + rate-limit سفت `contact` (۵ در دقیقه برای هر IP).
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

        return response()->json([
            'message' => 'پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.',
            'data' => ['ticket_id' => $ticket->id],
        ], 201);
    }
}
