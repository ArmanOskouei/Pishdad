<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** لیست پرداخت‌ها (فقط‌خواندنی — پرداخت فقط از مسیر pay/وب‌هوک ساخته می‌شود). */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with('invoice:id,client_id,amount,status');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->latest()->paginate(20)]);
    }
}
