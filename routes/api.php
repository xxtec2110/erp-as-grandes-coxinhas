<?php

use App\Http\Controllers\PdvWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.receive');
Route::post('/webhooks/pdv/{provider}', [PdvWebhookController::class, 'receive'])->middleware('throttle:30,1')->name('webhooks.pdv.receive');
