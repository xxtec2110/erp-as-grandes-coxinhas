<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdvWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        if (! config('pdv.webhook_enabled')) {
            abort(404);
        }

return response()->json(['message' => 'Autenticação oficial do webhook ainda não configurada.'], 501);
    }
}
