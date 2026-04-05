<?php

namespace app\components;

use yii\base\Component;
use yii\web\ServerErrorHttpException;

class SerpApiClient extends Component
{
    public string $apiKey;
    public string $baseUrl = 'https://serpapi.com/search';

    public function init(): void
    {
        parent::init();
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('SerpApi apiKey is missing');
        }
    }

    private function curlGet(array $query): array
    {
        $url = $this->baseUrl . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno) {
            throw new ServerErrorHttpException('SerpApi curl error: ' . ($err ?: ('errno=' . $errno)));
        }

        if ($http < 200 || $http >= 300) {
            throw new ServerErrorHttpException("SerpApi request failed: HTTP {$http} Body: " . mb_substr($raw, 0, 500));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ServerErrorHttpException('SerpApi invalid JSON: ' . mb_substr($raw, 0, 500));
        }

        return $data;
    }

    public function googleMapsSearch(array $params): array
    {
        $query = array_merge($params, [
            'engine' => 'google_maps',
            'api_key' => $this->apiKey,
        ]);

        return $this->curlGet($query);
    }

    public function googleMapsReviews(array $params): array
    {
        $query = array_merge($params, [
            'engine' => 'google_maps_reviews',
            'api_key' => $this->apiKey,
        ]);

        return $this->curlGet($query);
    }

    public function googleMapsPlaceById(string $placeId, array $params = []): array
    {
        $query = array_merge($params, [
            'engine' => 'google_maps',
            'place_id' => $placeId,
            'api_key' => $this->apiKey,
        ]);

        return $this->curlGet($query);
    }
}
