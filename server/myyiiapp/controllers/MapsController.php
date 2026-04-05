<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class MapsController extends Controller
{
    public function actionSearch(string $q)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $data = Yii::$app->serpApi->googleMapsSearch([
            'q'  => $q,
            'hl' => 'he',   // language
            'gl' => 'il',   // country
        ]);

        return [
            'search_metadata' => $data['search_metadata'] ?? null,
            'local_results'   => $data['local_results'] ?? [],
        ];
    }

    public function actionReviews(string $dataId)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $data = Yii::$app->serpApi->googleMapsReviews([
            'data_id' => $dataId,
            'hl' => 'iw',
            'gl' => 'il',
        ]);

        return $data;
    }
}
