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

    public function handle(Request $request)
{
    // PASO 0
    Log::info('--- INICIO DE PROCESO ---');

    try {
        $data = $request->all();
        $entry = $data['entry'][0]['changes'][0]['value'] ?? null;
        
        if (!isset($entry['messages'][0])) return response('OK');

        $message = $entry['messages'][0];
        $from = $message['from']; 
        $text = $message['text']['body'] ?? '';

        Log::info("1. Mensaje extraído: $text");

        // GESTIÓN DB
        $chat = Conversation::firstOrCreate(
            ['whatsapp_id' => $from],
            [
                'bot_active' => true,
                'last_message_at' => now() 
            ]
        );
        
        Log::info("2. Usuario en Base de Datos OK. ID: " . $chat->id);

        if (!$chat->bot_active) {
            Log::info("3. Bot pausado. Fin.");
            return response('Bot Paused');
        }

        // VERIFICAR API KEY
        $apiKey = env('OPENAI_API_KEY');
        if (empty($apiKey)) {
            Log::error("CRÍTICO: No se encontró la OPENAI_API_KEY en el .env");
            return response('Error Config');
        }
        Log::info("3. API Key detectada (comienza con: " . substr($apiKey, 0, 5) . "...)");

        // OPENAI EMBEDDINGS
        Log::info("4. Enviando a OpenAI Embeddings...");
        $vectorSearch = $this->getEmbedding($text);
        Log::info("5. Embedding recibido OK.");

        // BÚSQUEDA DB
        Log::info("6. Buscando en Postgres...");
        $contextNodes = KnowledgeNode::query()
                ->selectRaw("content, url, 1 - (embedding <=> '$vectorSearch') as similarity")
                ->orderByRaw("embedding <=> '$vectorSearch'")
                ->limit(3) // Tomamos los 3 fragmentos más relevantes
                ->get();

            // CAMBIO CLAVE: Formateamos texto + URL
            $contextText = $contextNodes->map(function ($node) {
                return "Fuente: {$node->url}\nInformación: {$node->content}";
            })->implode("\n\n---\n\n");

        // OPENAI CHAT
        Log::info("8. Enviando a GPT-4o-mini...");
        $response = $this->askOpenAI($text, $contextText);
        Log::info("9. Respuesta generada: " . substr($response, 0, 50) . "...");

        // WHATSAPP
        Log::info("10. Enviando respuesta a WhatsApp...");
        $this->sendWhatsApp($from, $response);
        Log::info("--- FIN EXITOSO ---");

        return response('EVENT_RECEIVED');

    } catch (\Throwable $e) {
        Log::error("❌ ERROR EN EL PROCESO: " . $e->getMessage());
        Log::error("Línea: " . $e->getLine());
        Log::error("Archivo: " . $e->getFile());
        return response('ERROR', 200);
    }
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
        
        $systemPrompt = "
            Actúa como el Asistente Virtual oficial del Colegio de Psicólogos de San Luis.
            
            DIRECTRICES DE COMPORTAMIENTO:
            1. Tono: Formal, institucional y empático (trato de 'Usted').
            2. Objetivo: Responder consultas basándose en la información oficial.
            
            REGLAS ESTRICTAS DE RESPUESTA:
            - CASO 1 (SALUDOS): Si el usuario saluda (ej: 'Hola', 'Buenos días') o pregunta quién eres, preséntate brevemente y ofrece ayuda, SIN necesitar buscar en el contexto.
            - CASO 2 (CONSULTAS TÉCNICAS): Para preguntas sobre trámites, costos o reglamentos, basa tu respuesta EXCLUSIVAMENTE en el 'CONTEXTO' de abajo.
            
            - Si encuentras la respuesta en el contexto, CITA LA FUENTE al final: 'Fuente: [URL]'.
            - Si NO encuentras la respuesta, di: 'Disculpe, no dispongo de esa información oficial. Por favor contacte a la administración.'
            
            CONTEXTO DISPONIBLE:
            $context
        ";

        $result = $client->chat()->create([
            'model' => 'gpt-4o-mini', 
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ],
            'temperature' => 0.2, // Temperatura baja = Más serio y fiel a los datos
        ]);
        
        return $result->choices[0]->message->content;
    }

    private function sendWhatsApp($to, $messageBody)
    {
        // --- PARCHE PARA ARGENTINA (Corrección del error 131030) ---
        // El webhook trae el 9 (ej: 549266...), pero tu lista de permitidos
        // parece esperar el formato sin 9 (ej: 54266...)
        if (str_starts_with($to, '549')) {
            $to = '54' . substr($to, 3);
        }
        // -----------------------------------------------------------

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('WHATSAPP_TOKEN'),
            'Content-Type'  => 'application/json',
        ])->post("https://graph.facebook.com/v21.0/".env('WHATSAPP_PHONE_ID')."/messages", [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => 'text',
            'text' => ['body' => $messageBody]
        ]);

        if ($response->successful()) {
            Log::info("✅ Enviado a WhatsApp correctamente a $to. ID: " . $response->json('messages.0.id'));
        } else {
            Log::error("❌ Meta rechazó el mensaje a $to. Razón: " . $response->body());
        }
    }
}
