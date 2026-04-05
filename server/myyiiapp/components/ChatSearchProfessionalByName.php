<?php


namespace app\components;

use Yii;
use app\models\Member;
use app\models\Recommendation;
use app\models\MemberFriend;
use app\models\Professional;
use app\models\ProfessionalCompany;
use app\models\ProfessionalInsurance;
use app\models\Company;
use app\models\Insurance;
use app\models\Localities;
use app\models\ProfessionalMainSpecialization;
use app\models\ProfessionalMainCare;
use app\models\ProfessionalLocalities;
use app\models\ProfessionalExpertise;
use app\models\ProfessionalAddress;
use app\components\RecommendedProfessionalGetter;
use yii\helpers\ArrayHelper;
use yii\db\Expression;


class ChatSearchProfessionalByName
{
    protected $member;
    protected $name;

    public function __construct (?Member $member, $name, $specialty,$care, $address, $isKids, $lat, $lng)
    {
        $this->member = $member;
        $this->name = $name;
        $this->specialty = $specialty;
        $this->care = $care;
        if(!$address && $this->member) {
            $address = $this->member->address;
            if(!$lat) {
                  $lat = $this->member->lat;
              }
              if(!$lng ) {
                  $lng = $this->member->lng;
              }
          }
          $this->address = $address;
           
          if((!$lat || !$lng) && $address) {
            $latLng = ChatActionHelper::calculateCoordinates($address, $lat, $lng);
            $lat = $latLng['lat'];
            $lng = $latLng['lng'];
          }
          $this->lat = $lat;
          $this->lng = $lng;
        $this->isKids = $isKids;
       
        $this->limit = 20;
        $this->limit2 = 40;
        $this->offset = 0;
    }

    public function getAll()
    {

        $professionals = [];
        if (!empty($this->name)) {
            $nameParts = preg_split('/[\s\-]+/u', trim($this->name));

            $query = Professional::find();
            foreach ($nameParts as $i => $part) {
                $query->andWhere(new \yii\db\Expression("
                    FIND_IN_SET(:part{$i}, REPLACE(REPLACE(full_name, '-', ' '), ' ', ',')) > 0
                "))
                ->addParams([":part{$i}" => $part]);
            }
            if(!empty($this->specialty) || !empty($this->care)){
                if ($this->isKids === true) {
                    $query->andWhere(['professional.is_pediatric' => 1]);
                }else{
                    $query->andWhere(['professional.is_pediatric' => 0]);
                }
            }
            // if (!empty($this->specialty) && is_array($this->specialty)) {
                
            //     $query->innerJoin(
            //         ['pms' => ProfessionalMainSpecialization::tableName()],
            //         'pms.professional_id = professional.id AND pms.main_specialization_id IN (' . implode(',', $this->specialty) . ')'
            //     );
            // }elseif(!empty($this->care) && is_array($this->care)){
            //     $query->innerJoin(
            //         ['pms' => ProfessionalMainCare::tableName()],
            //         'pms.professional_id = professional.id AND pms.main_care_id IN (' . implode(',', $this->care) . ')'
            //     );
            // }
            
            if($this->lat != 0 && $this->lng != 0 ){
                $distanceExprPaMin = "
                6371 * ACOS(
                    GREATEST(-1, LEAST(1,
                    COS(RADIANS(:lat)) * COS(RADIANS(pa_min.lat)) *
                    COS(RADIANS(pa_min.lng) - RADIANS(:lng)) +
                    SIN(RADIANS(:lat)) * SIN(RADIANS(pa_min.lat))
                    ))
                )
                ";

                $closestAddrSubquery = (new \yii\db\Query())
                    ->select('pa2.id')
                    ->from(['pa2' => 'professional_address'])
                    ->where('pa2.professional_id = professional.id')
                    ->andWhere(['<>', 'pa2.lat', 0])
                    ->andWhere(['<>', 'pa2.lng', 0])
                    ->orderBy(new \yii\db\Expression(str_replace('pa_min', 'pa2', $distanceExprPaMin)))
                    ->limit(1);

                $query
                    ->leftJoin(
                        ['pa_min' => 'professional_address'],
                        'pa_min.professional_id = professional.id
                        AND pa_min.lat <> 0
                        AND pa_min.lng <> 0
                        AND pa_min.id = (' . $closestAddrSubquery->createCommand()->rawSql . ')',
                        [':lat' => $this->lat, ':lng' => $this->lng]
                    )
                    ->addSelect([
                        'professional.*',
                        'closest_lat'     => 'pa_min.lat',
                        'closest_lng'     => 'pa_min.lng',
                        'distance_km'     => new \yii\db\Expression( $distanceExprPaMin, [':lat' => $this->lat, ':lng' => $this->lng]),
                    ])
                    ->distinct()

                    ->orderBy(new \yii\db\Expression("
                        (pa_min.id IS NULL),
                        $distanceExprPaMin
                    ", [':lat' => $this->lat, ':lng' => $this->lng]));
            }

            $professionals = $query->all();

        }

    
        $professionalsIds = ArrayHelper::getColumn($professionals, 'id');

        if (!empty($this->specialty) && is_array($this->specialty)) {
            $professionalsIds = $this->sortIdsBySpecialty($professionalsIds);
            $professionals = $this->sortProfessionalsByIds($professionals,$professionalsIds);
            $professionalsIds = array_slice($professionalsIds, 0, $this->limit);
            $professionals = array_slice($professionals, 0, $this->limit);

        }elseif (!empty($this->care) && is_array($this->care)) {
            $professionalsIds = $this->sortIdsByCare($professionalsIds);
            $professionals = $this->sortProfessionalsByIds($professionals,$professionalsIds);
            $professionalsIds = array_slice($professionalsIds, 0, $this->limit);
            $professionals = array_slice($professionals, 0, $this->limit);

        }else{
            $professionalsIds = array_slice($professionalsIds, 0, $this->limit2);
            $professionals = array_slice($professionals, 0, $this->limit2);
        }
    
        // Initialize recommendation arrays (empty if no member)
        $friends = [];
        $colleagues = [];
        $likeMe = [];
        $memberLikeMe = [];
        $all = [];
        
        if(!empty($professionalsIds)){
            $friends = $this->member ? ArrayHelper::map($this->getRecommendedFriends($professionalsIds), 'professional_id', 'count') : [];
            $colleagues = $this->member ? ArrayHelper::map( $this->getRecommendedColleague($professionalsIds), 'professional_id', 'count') : [];
            $likeMe = $this->member ? ArrayHelper::map($this->getRecommendedLikeMe($professionalsIds), 'professional_id', 'count') : [];
            $memberLikeMe = $this->member ? ArrayHelper::map($this->getMembersLikeMe($professionalsIds), 'member_id', 'count') : [];
            $all = ArrayHelper::map($this->getAllRecommended($professionalsIds), 'professional_id', 'count');
            
            // $RecommendedProfessionalsIds = array_unique(array_merge(
            //     array_keys($friends), 
            //     array_keys($colleagues),
            //     array_keys($likeMe),
            //     array_keys( $all )
            //     )
            // );
            
            // $q = Professional::find()->where(['IN', 'professional.id', array_slice($professionalsIds, $this->offset, $this->limit)]);
            // $professionals = $q
            //             ->orderBy([new \yii\db\Expression('FIELD (professional.id, ' . implode(',',  array_slice($professionalsIds, $this->offset, $this->limit)) . ')')])
            //             ->all();


        }


        $data = [];
        foreach($professionals as $professional) {
            $adressArr = [];
            $professionalAddress = $professional->getAddresses()->all();
            $recommendedFarFromMe = [];
            if($this->lat !== 0 && $this->lng !== 0 ){
                $recommendedFarFromMe = $this->getRecommendedFarFromMe($professional->id,$this->lat,$this->lng);
                // $recommendedFarFromMe = array_values(array_filter($recommendedFarFromMe, fn($r) => !in_array($r->professional_id, $RecommendedProfessionalsIds)));
            }
            $recCount = [
                'friends' => 0,
                'nearMe' => 0,
                'colleagues' => 0,
                'likeMe' => 0,
                'all' => 0,
            ];
            if (isset($friends[(string)$professional->id])) {
                $recCount['friends'] = $friends[(string)$professional->id];
            } elseif ($recommendedFarFromMe && $recommendedFarFromMe != [] && $this->lat !== 0 && $this->lng !== 0 ) {
                $recCount['nearMe'] = (string)count($recommendedFarFromMe);
            } elseif (isset($colleagues[(string)$professional->id])) {
                $recCount['colleagues'] = $colleagues[(string)$professional->id];
            } elseif (isset($likeMe[(string)$professional->id])) {
                $recCount['likeMe'] = $likeMe[(string)$professional->id];
            } 
            if (isset($all[(string)$professional->id])) {
                $recCount['all'] = $all[(string)$professional->id];
            }
            /* if($address) {
                $adressArr = [
                    'city' => $address->city,
                    //'house_number' => $address->house_number,
                    'street' => $address->street,
             
                ];
            } */
           

            $data[] = array_merge(
                $professional->toArray(),
                ['address' => $professionalAddress],
                [
                        'company' => array_merge($professional->companies,$professional->insurances),
                        'img_url' => $professional->img_url ? ('https://www.doctorita.co.il/rest/' . $professional->img_url) : null,
                        'category' => $professional->mainSpecialization,
                        'main_care' => $professional->mainCare,
                        'expertises' => $professional->expertises,
                        'care' => $professional->care,
                        'isSaved' => $this->member ? $professional->isSaved($this->member) : false,
                        'myRecommendation' => $this->member ? $professional->getMemberRecommendation($this->member) : false,
                ],
                ['recCount' => $recCount]
            ); 
        
        }
        return $data;

    }

    private function sortIdsBySpecialty($professionalsIds)
    {
        if (empty($this->specialty) || !is_array($this->specialty)) {
            return $professionalsIds;
        }

        // קבלת רשימת המקצוענים עם ההתמחות המבוקשת
        $professionalsWithSpecialty = ProfessionalMainSpecialization::find()
            ->select('professional_id')
            ->where(['in', 'main_specialization_id', $this->specialty])
            ->andWhere(['in', 'professional_id', $professionalsIds])
            ->column();

        // חלוקת המערך לשני חלקים
        $withSpecialty = array_intersect($professionalsIds, $professionalsWithSpecialty);
        $withoutSpecialty = array_diff($professionalsIds, $professionalsWithSpecialty);
        
        // החזרת המערך הממוין - תחילה עם התמחות, אחר כך בלי
        return array_merge($withSpecialty, $withoutSpecialty);
    }

    private function sortIdsByCare($professionalsIds)
    {
        if (empty($this->care) || !is_array($this->care)) {
            return $professionalsIds;
        }

        // קבלת רשימת המקצוענים עם ההתמחות המבוקשת
        $professionalsWithCare = ProfessionalMainCare::find()
            ->select('professional_id')
            ->where(['in', 'main_care_id', $this->care])
            ->andWhere(['in', 'professional_id', $professionalsIds])
            ->column();

        // חלוקת המערך לשני חלקים
        $withCare = array_intersect($professionalsIds, $professionalsWithCare);
        $withoutCare = array_diff($professionalsIds, $professionalsWithCare);
        
        // החזרת המערך הממוין - תחילה עם התמחות, אחר כך בלי
        return array_merge($withCare, $withoutCare);
    }

    private function sortProfessionalsByIds($professionals, $sortedIds)
    {
        // יצירת מפה של ID למיקום בסדר הרצוי
        $orderMap = array_flip($sortedIds);
        
        // מיון המקצוענים לפי הסדר החדש
        usort($professionals, function($a, $b) use ($orderMap) {
            $posA = isset($orderMap[$a->id]) ? $orderMap[$a->id] : PHP_INT_MAX;
            $posB = isset($orderMap[$b->id]) ? $orderMap[$b->id] : PHP_INT_MAX;
            
            return $posA <=> $posB;
        });
        
        return $professionals;
    }

    public function getRecommendedLikeMe(array $professionalIds = [])
    {
        if (!$this->member) {
            return [];
        }
        
        $recLikeMe = Recommendation::find()//all recommendations of category professionals of other people who recommended like me
            ->alias('r');

        if (!empty($professionalIds)) {
            $recLikeMe->andWhere(['in', 'r.professional_id', $professionalIds]);
        }
        $recLikeMe->andWhere(['in','member_id', $this->getMembersLikeMe($professionalIds)])
                  ->andWhere(['<>','member_id', $this->member->id])
                  ->andWhere(['not in','r.professional_id', $this->getMyRecommendedProfessionalsIds()]);
        return $recLikeMe
            //->andWhere(['category_id' => $this->category->id]) 
            ->groupBy(['r.professional_id'])
            ->orderBy('count DESC')
            ->select(['r.professional_id', 'COUNT(DISTINCT r.member_id) as count'])->createCommand()->queryAll();
    }


    public function getMembersLikeMe(array $professionalIds = [])
    {
        if (!$this->member) {
            return [];
        }
        
        $recLikeMe = Recommendation::find()//all recommendations of category professionals of other people who recommended like me
            ->andWhere(['in','professional_id', $this->getMyRecommendedProfessionalsIds()])
            ->andWhere(['<>','member_id', $this->member->id])
            ->select(['member_id']);
        
        if (!empty($professionalIds)) {
            $recLikeMe->andWhere(['in', 'r.professional_id', $professionalIds]);
        }

        return $recLikeMe;
    }
    

    public function getRecommendedFriends(array $professionalIds = [])
    {
        if (!$this->member) {
            return [];
        }
        
        $allFriends = MemberFriend::find()//all ID's of my friends
            ->select('friend_member_id')
            ->where(['member_id' =>  $this->member->id]);

        $recFriends = Recommendation::find()//all recommendations of my friends on category professionals
            ->alias('r');
        
        if (!empty($professionalIds)) {
            $recFriends->andWhere(['in', 'r.professional_id', $professionalIds]);
        }

        $recFriends->andWhere(['in', 'member_id', $allFriends]);//this is my friend

        return $recFriends
            ->groupBy(['r.professional_id'])
            ->orderBy('count DESC')
            ->select(['r.professional_id', 'COUNT(DISTINCT r.member_id) as count'])->createCommand()->queryAll();

    }

    public function getRecommendedColleague(array $professionalIds = [])
    {
        if (!$this->member) {
            return [];
        }
        
        $MyRecommendedProfessionals = Member::find()//all of my recommended Professionals's member_id's
            ->select([Member::tableName().'.id'])
            ->where(['in','professional_id', $this->getMyRecommendedProfessionalsIds()]);

        $recProfessionals = Recommendation::find()//all recommendations of my recommended Professionals
            ->alias('r');
        
        if (!empty($professionalIds)) {
            $recProfessionals->andWhere(['in', 'r.professional_id', $professionalIds]);
        }
        $recProfessionals->andWhere(['in','member_id',$MyRecommendedProfessionals]);
        
        return $recProfessionals
            ->groupBy(['r.professional_id'])
            ->orderBy('count DESC')
            ->select(['r.professional_id', 'COUNT(DISTINCT r.member_id) as count'])->createCommand()->queryAll();
    }

    //all ID's of Professionals recommended by me
    private function getMyRecommendedProfessionalsIds()
    {
        if (!$this->member) {
            return [];
        }
        
        return Recommendation::find()
            ->select('professional_id')
            ->where(['member_id' => $this->member->id]);
    }

    public function getAllRecommended(array $professionalIds = [])
    {
        $query = Recommendation::find()//all recommendations of category
            ->alias('r');

        if (!empty($professionalIds)) {
            $query->andWhere(['in', 'r.professional_id', $professionalIds]);
        }
   
        if ($this->member) {
            $query->andWhere(['<>', 'member_id', $this->member->id]);
        }
        
        return $query
            ->groupBy(['r.professional_id'])
            ->select(['r.professional_id', 'COUNT(DISTINCT r.id) AS count'])
            ->orderBy('count DESC')
            ->createCommand()
            ->queryAll();
    }

    public function getRecommendedFarFromMe($professional, $myLat, $myLng)
    {
        if (!$this->member) {
            return [];
        }
        
        Yii::info("myLat: ".print_r($myLat,1));
        Yii::info("myLng: ".print_r($myLng,1));
        $distanceExpr = new Expression(
            "6371 * 2 * ASIN(SQRT(
                POWER(SIN(RADIANS(cr.lat - :myLat) / 2), 2) +
                COS(RADIANS(:myLat)) * COS(RADIANS(cr.lat)) *
                POWER(SIN(RADIANS(cr.lng - :myLng) / 2), 2)
            ))",
            [':myLat' => (float)$myLat, ':myLng' => (float)$myLng]
        );

        $query = Recommendation::find()
            ->alias('r')
            ->innerJoin(['cr' => Member::tableName()], 'cr.id = r.member_id')  
            ->where(['r.professional_id' => $professional])
            ->andWhere(['<>', 'cr.lat', 0])
            ->andWhere(['<>', 'cr.lng', 0])
            ->andWhere(['<>', 'cr.id', $this->member->id])
            ->addSelect(['r.*', 'cr.lat', 'cr.lng', 'distance' => $distanceExpr])
            ->andWhere(['<', $distanceExpr, 0.3]) // 0.3 ק״מ
            ->all();

        Yii::info("sql: ".print_r($query,1));
        
        return $query;
    }

    function sortNearMe($professionals, $userLat, $userLng)
    {
        if (empty($userLat) || empty($userLng)) {
            return $professionals;
        }

        usort($professionals, function($a, $b) use ($userLat, $userLng) {
            $closestA = $this->getClosestAddress($a->addresses, $userLat, $userLng);
            $closestB = $this->getClosestAddress($b->addresses, $userLat, $userLng);

            $distanceA = $this->calculateHaversineDistance($userLat, $userLng, $closestA['lat'], $closestA['lng']);
            $distanceB = $this->calculateHaversineDistance($userLat, $userLng, $closestB['lat'], $closestB['lng']);

            return $distanceA <=> $distanceB;
        });

        return $professionals;
    }

    function getClosestAddress($addresses, $userLat, $userLng)
    {
        $closest = null;
        $minDistance = PHP_INT_MAX;

        foreach ($addresses as $address) {
            if (empty($address->lat) || empty($address->lng)) {
                continue; 
            }

            $distance = $this->calculateHaversineDistance($userLat, $userLng, $address->lat, $address->lng);

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closest = $address;
            }
        }

        return $closest;
    }

    private function calculateHaversineDistance($lat1, $lng1, $lat2, $lng2) { 
        
        $earthRadius = 6371; $lat1Rad = deg2rad($lat1); 
        $lat2Rad = deg2rad($lat2); 
        $lng1Rad = deg2rad($lng1); 
        $lng2Rad = deg2rad($lng2); 
        
        return $earthRadius * acos( cos($lat1Rad) * cos($lat2Rad) * cos($lng2Rad - $lng1Rad) + sin($lat1Rad) * sin($lat2Rad) ); 
    } 
    
    /**
     * Get the actual parameter values used in the search (after applying member fallbacks)
     * This method returns the values that were actually used, not just the ones passed in
     * @return array ['address' => string|null, 'lat' => float|null, 'lng' => float|null]
     */
    public function getUsedParams()
    {
        return [
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }

}