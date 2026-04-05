<?php
namespace app\components;

use GuzzleHttp\Client;

class AgentService
{
    private Client $client;
    private string $baseUrl;
    private ?string $token;

    public function __construct(string $baseUrl, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => 30.0,
        ]);
    }

    public function analyze(string $text, ?int $memberId, ?int $professionalId): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $resp = $this->client->post('analyze', [
            'headers' => $headers,
            'json' => [
                'text' => $text,
                'member_id' => $memberId,
                'professional_id' => $professionalId,
            ],
        ]);

        $data = json_decode($resp->getBody()->getContents(), true);
        return is_array($data) ? $data : ['ok' => false, 'error' => 'invalid_json'];
    }
}
