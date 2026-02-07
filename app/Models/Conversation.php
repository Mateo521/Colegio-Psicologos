<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    // Esta es la línea que falta y causa el error
    protected $fillable = [
        'whatsapp_id',
        'bot_active',
        'last_message_at'
    ];
}