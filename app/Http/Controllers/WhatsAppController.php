<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Conversation;
use App\Models\KnowledgeNode;
use OpenAI;

class WhatsAppController extends Controller
{
    // Verificar el Webhook (Paso obligatorio de Meta)
    public function verify(Request $request)
    {
        if ($request->hub_mode === 'subscribe' && 
            $request->hub_verify_token === env('WHATSAPP_VERIFY_TOKEN')) {
            return $request->hub_challenge;
        }
        return response('Error de token', 403);
    }

    // Recibir mensajes
    public function handle(Request $request)
    {


        $data = $request->all();
        
        // Validar que sea un mensaje de texto
        $entry = $data['entry'][0]['changes'][0]['value'] ?? null;
        if (!isset($entry['messages'][0])) return response('OK');

        $message = $entry['messages'][0];
        $phoneId = $entry['metadata']['phone_number_id'];
        $from = $message['from']; // El número del cliente
        $text = $message['text']['body'] ?? '';

        // 1. Obtener o crear conversación
        $chat = Conversation::firstOrCreate(
            ['whatsapp_id' => $from],
            ['bot_active' => true]
        );

        // 2. Lógica de "Human Handoff"
        // Si el cliente pide hablar con humano explícitamente
        if (str_contains(strtolower($text), 'asesor') || str_contains(strtolower($text), 'humano')) {
            $chat->update(['bot_active' => false]);
            $this->sendWhatsApp($from, "Entendido. Un asesor humano te contactará pronto. (IA Desactivada)");
            // Aquí enviarías un email o notificación a tu cliente real
            return response('Handoff triggered');
        }

        // Si el bot está apagado, NO responder
        if (!$chat->bot_active) {
            return response('Bot is sleeping');
        }

        // 3. RAG: Buscar información relevante
        $vectorSearch = $this->getEmbedding($text);
        
        // Búsqueda de similitud de cosenos en Postgres
        $contextNodes = KnowledgeNode::query()
            ->selectRaw("content, 1 - (embedding <=> '$vectorSearch') as similarity")
            ->orderByRaw("embedding <=> '$vectorSearch'")
            ->limit(3)
            ->get();

        $contextText = $contextNodes->pluck('content')->implode("\n---\n");

        // 4. Generar Respuesta con GPT-4o
        $response = $this->askOpenAI($text, $contextText);

        // 5. Enviar respuesta a WhatsApp
        $this->sendWhatsApp($from, $response);

        return response('EVENT_RECEIVED');
    }

    private function getEmbedding($text)
    {
        $client = \OpenAI::client(env('OPENAI_API_KEY'));
        $response = $client->embeddings()->create([
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ]);
        return json_encode($response->embeddings[0]->embedding);
    }

    private function askOpenAI($question, $context)
    {
        $client = \OpenAI::client(env('OPENAI_API_KEY'));
        $result = $client->chat()->create([
            'model' => 'gpt-4o-mini', // Modelo rápido y barato
            'messages' => [
                ['role' => 'system', 'content' => "Eres un asistente útil. Responde usando SOLO esta información:\n" . $context],
                ['role' => 'user', 'content' => $question],
            ],
        ]);
        return $result->choices[0]->message->content;
    }

    private function sendWhatsApp($to, $message)
    {
        Http::withToken(env('WHATSAPP_TOKEN'))
            ->post("https://graph.facebook.com/v18.0/".env('WHATSAPP_PHONE_ID')."/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message]
            ]);
    }
}
