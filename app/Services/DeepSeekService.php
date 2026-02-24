<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekService
{
    protected string $apiKey;
    protected string $apiUrl = 'https://api.deepseek.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.deepseek.api_key');
        
        if (empty($this->apiKey)) {
            Log::error('DeepSeek API key is not configured');
        }
    }

    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 500): string
    {
        Log::info('Sending request to DeepSeek API', [
            'messages_count' => count($messages),
            'temperature' => $temperature
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, [
                'model' => 'deepseek-chat',
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'stream' => false,
            ]);

            if ($response->failed()) {
                Log::error('DeepSeek API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception('API error: ' . $response->body());
            }

            $result = $response->json('choices.0.message.content');
            
            Log::info('DeepSeek API response received', [
                'response_length' => strlen($result)
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('DeepSeek API exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}