<?php

use App\Http\Controllers\WhatsAppController;


use App\Models\KnowledgeNode;
use Illuminate\Support\Facades\Route;



Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'handle']);