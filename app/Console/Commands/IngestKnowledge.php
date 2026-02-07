<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException; 
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use App\Models\KnowledgeNode;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI; 

class IngestKnowledge extends Command
{
    protected $signature = 'knowledge:ingest {url}';
    protected $description = 'Scrapea una web y vectoriza su contenido para el bot';

    public function handle()
    {
        $url = $this->argument('url');
        $this->info("Iniciando el crawler en: $url");
        
        if ($this->confirm('¿Queres borrar la memoria actual..?master', true)) {
            KnowledgeNode::truncate();
            $this->info(" Memoria borrada.");
        }

        Crawler::create()
            ->setCrawlObserver(new class($this) extends CrawlObserver {
                private $command;

                public function __construct($command) {
                    $this->command = $command;
                }

                public function crawled(
                    UriInterface $url, 
                    ResponseInterface $response, 
                    ?UriInterface $foundOnUrl = null, 
                    ?string $linkText = null
                ): void
                {
                    if ($response->getStatusCode() !== 200) return;

                    $contentType = $response->getHeaderLine('Content-Type');
                    if (!str_contains($contentType, 'text/html')) return;

                    $html = (string) $response->getBody();
                    
                    try {
                        $dom = new DomCrawler($html);
                        if ($dom->filter('body')->count() === 0) return;

                        $content = $dom->filter('body')->text();
                        $content = preg_replace('/\s+/', ' ', $content);
                        $content = trim($content);

                        if (strlen($content) < 100) return; 

                        $this->command->info(" Procesando: " . $url);

                        $chunks = str_split($content, 800); 

                        foreach ($chunks as $chunk) {
                            try {
                                $client = \OpenAI::client(env('OPENAI_API_KEY'));
                                $response = $client->embeddings()->create([
                                    'model' => 'text-embedding-3-small',
                                    'input' => $chunk,
                                ]);
                                
                                $vector = $response->embeddings[0]->embedding;

                                KnowledgeNode::create([
                                    'content' => $chunk,
                                    'url' => (string) $url,
                                    'embedding' => json_encode($vector)
                                ]);

                              
                              
                                $this->command->getOutput()->write('.'); 

                            } catch (\Exception $e) {
                              
                                $this->command->error("X");
                            }
                        }
                        $this->command->newLine();

                    } catch (\Exception $e) {
                        $this->command->error("Error procesando HTML: " . $e->getMessage());
                    }
                }

                public function crawlFailed(
                    UriInterface $url, 
                    RequestException $requestException, 
                    ?UriInterface $foundOnUrl = null, 
                    ?string $linkText = null
                ): void
                {
                    $this->command->error("Falló al visitar: " . $url . " - Error: " . $requestException->getMessage());
                }
            })
            ->setCrawlProfile(new CrawlInternalUrls($url)) 
            ->setTotalCrawlLimit(10)
            ->startCrawling($url);

        $this->info("\nIngesta completada.");
    }
}