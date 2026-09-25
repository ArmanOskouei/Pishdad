<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** رجیستری بلوک‌های فعال + JSON Schema برای SchemaForm (تسک ۱.۱). */
class BlockSchemaController extends Controller
{
    public function index(): JsonResponse
    {
        $blocks = collect(config('blocks', []))
            ->filter(fn ($b) => ($b['active'] ?? false) === true)
            ->map(fn ($b, $type) => [
                'type' => $type,
                'title' => $b['title'] ?? $type,
                'description' => $b['description'] ?? null,
                'schema' => $b['schema'] ?? ['type' => 'object'],
            ])
            ->values();

        return response()->json(['data' => $blocks]);
    }
}
