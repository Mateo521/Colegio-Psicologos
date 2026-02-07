<?php

use App\Http\Controllers\WhatsAppController;

Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'handle']);