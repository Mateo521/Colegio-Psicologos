<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\KnowledgeNode;
use OpenAI\Laravel\Facades\OpenAI; // Asegúrate de configurar el Facade o usar Client directo
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\ResponseInterface;

class IngestKnowledge extends Command
{
    protected $signature = 'knowledge:ingest {url}';
    protected $description = 'Indexar sitio web a base vectorial';

    public function handle()
    {
        $url = $this->argument('url');
        $this->info("Rastreando $url...");

        Crawler::create()
            ->setCrawlObserver(new class extends CrawlObserver {
                public function crawled(UriInterface $url, ResponseInterface $response, ?UriInterface $foundOnUrl = null): void 
                {
                    // 1. Limpieza básica del HTML
                    $html = (string) $response->getBody();
                    $text = strip_tags($html); 
                    // (Aquí deberías añadir una limpieza mejor para quitar menús/footers)
                    
                    // 2. Chunking (dividir en trozos de 800 caracteres)
                    $chunks = str_split($text, 800);

                    $client = \OpenAI::client(env('OPENAI_API_KEY'));

                    foreach ($chunks as $chunk) {
                        if(strlen($chunk) < 100) continue; // Ignorar trozos muy cortos

                        // 3. Crear Embedding
                        $response = $client->embeddings()->create([
                            'model' => 'text-embedding-3-small',
                            'input' => $chunk,
                        ]);

                        $vector = $response->embeddings[0]->embedding;

                        // 4. Guardar en DB
                        KnowledgeNode::create([
                            'content' => $chunk,
                            'url' => (string) $url,
                            'embedding' => $vector
                        ]);
                        echo "."; // Feedback visual
                    }
                }
                public function crawlFailed(UriInterface $url, $requestException, ?UriInterface $foundOnUrl = null): void {}
            })
            ->startCrawl($url);
            
        $this->info("\n¡Ingesta completada!");
    }
}