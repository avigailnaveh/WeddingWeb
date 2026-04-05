<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\web\BadRequestHttpException;
use app\components\AddressParser;

class GeoController extends Controller
{
    public function actionParseAddress()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $input = trim((string)Yii::$app->request->post('address', ''));
        if ($input === '') {
            throw new BadRequestHttpException('address is required');
        }

        $search = Yii::$app->serpApi->googleMapsSearch([
            'type' => 'search',
            'q'  => $input,
            'hl' => 'iw',
            'gl' => 'il',
        ]);

        $first = $search['local_results'][0] ?? null;
        if (!$first) {
            return [
                'ok' => false,
                'reason' => 'No local_results found',
                'input' => $input,
                'raw' => $search,
            ];
        }

        $placeId = $first['place_id'] ?? null;
        $gps = $first['gps_coordinates'] ?? null;
        $addrStr = $first['address'] ?? null;

        $place = null;
        if ($placeId) {
            $place = Yii::$app->serpApi->googleMapsPlaceById($placeId, ['hl' => 'he', 'gl' => 'il']);
            $placeResults = $place['place_results'] ?? null;

            if ($placeResults) {
                $addrStr = $placeResults['address'] ?? $addrStr;
                $gps = $placeResults['gps_coordinates'] ?? $gps;
            }
        }

        $parsed = AddressParser::parseIsraeliAddress($addrStr);

        return [
            'ok' => true,
            'input' => $input,
            'street' => $parsed['street'],
            'house_number' => $parsed['house_number'],
            'city' => $parsed['city'],
            'lat' => $gps['latitude'] ?? null,
            'lng' => $gps['longitude'] ?? null,
            'raw_address' => $parsed['raw'],
            'place_id' => $placeId,
        ];
    }
}
