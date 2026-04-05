<?php
namespace app\commands;

use Yii;
use yii\db\Query;
use yii\console\Controller;
use app\models\ProfessionalAddress;

//php yii get-lat-lng/update-lat-lng

class GetLatLngController extends Controller
{
    
    /** @var string */
    private string $apiKey;

    public function init()
    {
        parent::init();
        $this->apiKey = (string)(Yii::$app->params['google_api_key'] ?? '');
        if ($this->apiKey === '') {
            fwrite(STDERR, "Missing google_api_key in params.\n");
        }
    }

    public function actionUpdateLatLng()
    {

        $rows = ProfessionalAddress::find()
            ->where(['or',
                ['lat' => null],
                ['lng' => null],
                ['lat' => 0],
                ['lng' => 0]
            ])
            ->andwhere(['=', 'type', 'ThunderbitShiatsu'])
             ->andWhere(['not', ['street' => null]])
            //  ->andWhere(['not', ['city' => null]])
            // ->andWhere(['not', ['house_number' => null]])
             ->andWhere(['not', ['street' => '']])
            //  ->andWhere(['not', ['city' => '']])
            // ->andWhere(['not', ['house_number' => '']])
            // ->andWhere(['in', 'phone', $cleanPhones])
            // ->andwhere(['=', 'professional_id', 169926])
            //->orderBy(['id' => SORT_ASC])
            //->limit(1)
            ->all();

        echo "rows:\n";
        // print_r($rows);


        foreach ($rows as $row) {
            $id = $row['id'];
            $addressParts = array_filter([$row['street'], $row['house_number'], $row['city']]);
            if (empty($addressParts)) {
                echo "Skipping ID $id - incomplete address.\n";
                continue;
            }

            $address = implode(', ', $addressParts);
            $coordinates = $this->getCoordinatesFromGoogle($address);

            if ($coordinates) {
                Yii::$app->db->createCommand()
                    ->update('professional_address', [
                        'lat' => $coordinates['lat'],
                        'lng' => $coordinates['lng'],
                        'type_location_google' => $coordinates['type_location'],
                        'number_house_google' => $coordinates['number_house'],
                        'street_google' => $coordinates['street'],
                        'city_google' => $coordinates['city'],
                        'neighborhood_google' => $coordinates['neighborhood'],
                    ], ['id' => $id])
                    ->execute();

                echo "Updated ID $id: {$coordinates['lat']}, {$coordinates['lng']}\n";
                /* if (!empty($coordinates['neighborhood'])) {
                    echo ", neighborhood: {$coordinates['neighborhood']}";
                } */
            } else {
                echo "Failed to geocode ID $id: $address";
            }

            sleep(1); // Avoid hitting rate limits (50 per second or 2500/day for free tier)
        }
    }

    private function getCoordinatesFromGoogle($address)
    {
        
        $addressEncoded = urlencode($address);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$addressEncoded}&language=he&key={$this->apiKey}";

        $response = file_get_contents($url);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if ($data['status'] === 'OK') {
            $result = $data['results'][0];
            $location = $result['geometry']['location'];
            // print_r($result);

            $addressParts = $this->extractAddressParts($result);
            //print_r($addressParts);

            return [
                'lat' => $addressParts['lat'],
                'lng' => $addressParts['lng'],
                'neighborhood' => $addressParts['neighborhood'],
                'type_location' => $addressParts['type_location'],
                'number_house' => $addressParts['house_number'],
                'street' => $addressParts['street'],
                'city' => $addressParts['city'],
            ];
        }

        return null;
    }

    function extractAddressParts(array $result): array {
        $components = $result['address_components'] ?? [];
    
        $pick = function (array $typePriority) use ($components): string {
            foreach ($typePriority as $wanted) {
                foreach ($components as $c) {
                    if (!empty($c['types']) && in_array($wanted, $c['types'], true)) {
                        return (string)($c['long_name'] ?? '');
                    }
                }
            }
            return '';
        };
    
        return [
            'house_number'    => $pick(['street_number']),
            'street'          => $pick(['route']),
            'city'            => $pick(['locality','postal_town','administrative_area_level_2','administrative_area_level_1']),
            'neighborhood'    => $pick(['neighborhood','sublocality_level_1','sublocality','administrative_area_level_3']),
            'type_location'   => $result['geometry']['location_type'] ?? '',
            'lat'             => $result['geometry']['location']['lat'] ?? null,
            'lng'             => $result['geometry']['location']['lng'] ?? null,
        ];
    }
   
    

}
