<?php
namespace app\components;

use GuzzleHttp\Client;

class OpenAiService
{
    private $apiKey;
    private $client;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 10.0,
        ]);
    }

    public function chat($messages, $model = 'gpt-5.2', $maxTokens = 300)
    {
        try {
            $response = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            return $body['choices'][0]['message']['content'] ?? null;

        } catch (\Exception $e) {
            // אפשר גם לוג או טיפול בשגיאות מפורט יותר
            return 'Error: ' . $e->getMessage();
        }
    }
}
