<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SmsWebhookController extends Controller
{
    public function deliveryReport(Request $request): JsonResponse
    {
        Log::info('Africa\'s Talking SMS delivery report', [
            'all' => $request->all(),
            'content' => $request->getContent(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function inbox(Request $request): JsonResponse
    {
        Log::info('Africa\'s Talking SMS inbox', [
            'all' => $request->all(),
            'content' => $request->getContent(),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
