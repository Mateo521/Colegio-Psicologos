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
    // Habilitar pgvector (solo funciona si instalaste la extensión en Postgres)
    DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

    Schema::create('knowledge_nodes', function (Blueprint $table) {
        $table->id();
        $table->text('content'); // El fragmento de texto
        $table->string('url')->nullable();
        // Columna especial para el vector de 1536 dimensiones
        $table->vector('embedding', 1536); 
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_nodes');
    }
};
