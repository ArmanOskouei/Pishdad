<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pishdad\Plugins\Central\Models\Payment;

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
