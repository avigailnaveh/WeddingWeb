<?php

namespace app\components;

use app\models\Company;
use app\models\Insurance;

class ChatActionHelper
{
    /**
     * קבלת קופת חולים (שם / אובייקט) והחזרה של ID
     */
    public static function convertHmoToId($company): int
    {
        if ($company instanceof Company) {
            return (int)$company->id;
        }

        if (is_numeric($company)) {
            return (int)$company;
        }

        if (is_string($company) && $company !== '') {
            $model = Company::find()->where(['name' => $company])->one();
            return $model ? (int)$model->id : 0;
        }

        return 0;
    }

    /**
     * המרת פרמטרים פנימיים (IDs) לשמות ידידותיים
     */
    public static function convertUsedParamsToNames(array $params): array
    {
        $result = [];

        if (!empty($params['company'])) {
            $company = Company::findOne((int)$params['company']);
            $result['hmo'] = $company ? $company->name : null;
        }

        if (!empty($params['insurance'])) {
            $ids = is_array($params['insurance'])
                ? array_map('intval', $params['insurance'])
                : [(int)$params['insurance']];

            $names = Insurance::find()
                ->select('name')
                ->where(['id' => $ids])
                ->column();

            $result['insurance'] = !empty($names)
                ? implode(', ', $names)
                : null;
        }

        return $result;
    }

    /**
     * חישוב קואורדינטות – אם קיימות מחזיר, אחרת fallback
     */
    public static function calculateCoordinates($address, $lat, $lng): array
    {
        if (is_numeric($lat) && is_numeric($lng) && $lat != 0 && $lng != 0) {
            return [
                'lat' => (float)$lat,
                'lng' => (float)$lng,
            ];
        }

        // fallback – אין גיאוקודינג כאן, רק מניעת קריסה
        return [
            'lat' => 0.0,
            'lng' => 0.0,
        ];
    }
}
