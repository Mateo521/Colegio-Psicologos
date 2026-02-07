<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('conversations', function (Blueprint $table) {
        $table->id();
        $table->string('whatsapp_id')->unique(); // El número del cliente
        $table->boolean('bot_active')->default(true); // true = IA responde, false = Humano responde
        $table->timestamp('last_message_at');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
