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
use app\models\ProfessionalMainCare;
use app\models\ProfessionalLocalities;
use app\models\ProfessionalCare;
use app\models\ProfessionalAddress;
use app\models\UserInsurance;
use app\components\RecommendedProfessionalGetter;
use app\components\ChatActionHelper;
use app\models\Care;
use app\models\MainCare;
use yii\helpers\ArrayHelper;
use yii\db\Expression;
use app\models\CategoryFaq;

class ChatSearchTherapists
{
    protected $member;
    protected $company;
    protected $insurance;
    protected $offset;
    protected $limit;
    protected $expertises;
    protected $mainCare;
    protected $address;
    protected $lat;
    protected $lng;
    protected $isKids;
    protected $restrictToArea;
    protected $restrictToHmo;
    protected $restrictToInsurance;
    protected $locationType;
    protected $localityIds;
    protected $districtName; // District name for extending search when few results

    public function __construct(?Member $member, $page, $expertises, $mainCare, $isKids = false, $company = null, $insurance = null, $address = null, $lat = null, $lng = null, $restrictToArea = false, $restrictToHmo = false, $restrictToInsurance = false, $locationType = "city")
    {
        $this->member = $member;
        if (!$company && $this->member) {
            $company = $this->member->hmo;
        }
        $this->company = ChatActionHelper::convertHmoToId($company);
        if (!$insurance && $this->member) {
            $insRows = UserInsurance::find()
                ->alias('ui')
                ->select(['ui.insurance_id', 'i.name'])
                ->innerJoin(['i' => Insurance::tableName()], 'i.id = ui.insurance_id')
                ->where(['ui.member_id' => $this->member->id])
                ->asArray()
                ->all();

            $insurance = array_map('intval', array_column($insRows, 'insurance_id'));
        }
        $this->insurance = $insurance;
        $this->limit = 20;
        $this->offset = $this->limit * ($page - 1);
        $this->expertises = count($expertises) ? [$expertises[0]] : [];
        $this->mainCare = $mainCare;
        
        if (!$address && $this->member) {
            $address = $this->member->address;
            if (!$lat) {
                $lat = $this->member->lat;
            }
            if (!$lng) {
                $lng = $this->member->lng;
            }
        }
        $this->address = $address;

       /*  if ((!$lat || !$lng) && $address) {
            $latLng = ChatActionHelper::calculateCoordinates($address, $lat, $lng);
            $lat = $latLng['lat'];
            $lng = $latLng['lng'];
        } */
        $this->locationType = $locationType;
        $this->restrictToArea = $restrictToArea;
        $this->restrictToHmo = $restrictToHmo;
        $this->restrictToInsurance = $restrictToInsurance;
        $this->isKids = $isKids;
        
        if ($address && $restrictToArea) {
            if($locationType == 'district'){
                $locality = Localities::find()->where(['english_district_name' => $address])->select(['id'])->column();
                $this->localityIds = $locality;
                $this->districtName = $address; // Store district name
                $this->lat = null;
                $this->lng = null;
                $this->address = Localities::findOne(['id' => $locality[0]])->district_name;
                $this->restrictToArea = true;
            } else if($locationType == 'city') {
                $locality = Localities::findOne(['english_city_name' => $address]);
                $this->localityIds = $locality ? [(int)$locality->id] : [];
                $this->lat = ($lat !== null) ? (float)$lat : ($locality && $locality->lat !== null ? (float)$locality->lat : null);
                $this->lng = ($lng !== null) ? (float)$lng : ($locality && $locality->lng !== null ? (float)$locality->lng : null);
                $this->districtName = $locality ? $locality->district_name : null;
                $this->address = $locality ? $locality->city_name : null;
                $this->restrictToArea = true;
            } else if($locationType == 'nearMe') {
                $this->lat = $lat;
                $this->lng = $lng;
                $this->address = $address;
            }
        } else if($address && !$restrictToArea) {
            $locality = Localities::findOne(['city_name' => $address]);
            if($locality) {
                $this->localityIds = [(int)$locality->id];
                $this->address = $address;
                $this->lat = $locality->lat;
                $this->lng = $locality->lng;
            } else {
                $this->localityIds = [];
                if($this->member && $this->member->address == $address) {
                    $this->address = $this->member->address;
                    $this->lat = $this->member->lat;
                    $this->lng = $this->member->lng;
                } else {
                    $this->lat = null;
                    $this->lng = null;
                }
            }
        }

        
       
    }

    public function getRecommendedLikeMe()
    {
        if (!$this->member) {
            return [];
        }
        
        $recLikeMe = Recommendation::find() //all recommendations of category professionals of other people who recommended like me
            ->alias('r');
    
        if ($this->mainCare && count($this->mainCare)) {
            $recLikeMe->innerJoin(
                ['pms' => ProfessionalMainCare::tableName()],
                'pms.professional_id = r.professional_id AND pms.main_care_id IN (' . implode(',', $this->mainCare) . ')'
            );
        }
        if (!empty($this->expertises)) {
            $recLikeMe->innerJoin(
                ['pe' => ProfessionalCare::tableName()],
                'pe.professional_id = r.professional_id AND pe.care_id IN (' . implode(',', $this->expertises) . ')'
            );
        }
        
        // Apply restrictive filters if any
        $this->addRestrictiveFiltersToRecommendation($recLikeMe);
        
        return $recLikeMe->andWhere(['in', 'member_id', $this->getMembersLikeMe()])
            ->andWhere(['<>', 'member_id', $this->member->id])
            ->andWhere(['not in', 'r.professional_id', $this->getMyRecommendedProfessionalsIds()])
            ->groupBy(['r.professional_id'])
            ->orderBy('count DESC')
            ->select(['r.professional_id', 'COUNT(DISTINCT r.member_id) as count'])
            ->createCommand()
            ->queryAll();
    }

    public function getMembersLikeMe()
    {
        if (!$this->member) {
            return [];
        }
        
        $recLikeMe = Recommendation::find() //all recommendations of category professionals of other people who recommended like me
            ->andWhere(['in', 'professional_id', $this->getMyRecommendedProfessionalsIds()])
            ->andWhere(['<>', 'member_id', $this->member->id])
            ->select(['member_id']);

        return $recLikeMe;
    }

    public function getRecommendedFriends()
    {
        if (!$this->member) {
            return [];
        }
        
        $allFriends = MemberFriend::find() //all ID's of my friends
            ->select('friend_member_id')
            ->where(['member_id' => $this->member->id]);

        $recFriends = Recommendation::find() //all recommendations of my friends on category professionals
            ->alias('r');
    
        if ($this->mainCare && count($this->mainCare)) {
            $recFriends->innerJoin(
                ['pms' => ProfessionalMainCare::tableName()],
                'pms.professional_id = r.professional_id AND pms.main_care_id IN (' . implode(',', $this->mainCare) . ')'
            );
        }
        if (!empty($this->expertises)) {
            $recFriends->innerJoin(
                ['pe' => ProfessionalCare::tableName()],
                'pe.professional_id = r.professional_id AND pe.care_id IN (' . implode(',', $this->expertises) . ')'
            );
        }
        
        // Apply restrictive filters if any
        $this->addRestrictiveFiltersToRecommendation($recFriends);
        
        return $recFriends->where(['in', 'member_id', $allFriends]) //this is my friend
            ->andWhere(['<>', 'member_id', $this->member->id])
            ->groupBy(['r.professional_id'])
            ->orderBy('count DESC')
            ->select(['r.professional_id', 'COUNT(DISTINCT r.member_id) as count'])
            ->createCommand()
            ->queryAll();
    }

    public function getRecommendedColleague()
    {
        if (!$this->member) {
            return [];
        }
        
        $MyRecommendedProfessionals = Member::find() //all of my recommended Professionals's member_id's
            ->select([Member::tableName() . '.id'])
            ->where(['in', 'professional_id', $this->getMyRecommendedProfessionalsIds()]);

        $recProfessionals = Recommendation::find() //all recommendations of my recommended Professionals
            ->alias('r');
      
        if ($this->mainCare && count($this->mainCare)) {
            $recProfessionals->innerJoin(
                ['pms' => ProfessionalMainCare::tableName()],
                'pms.professional_id = r.professional_id AND pms.main_care_id IN (' . implode(',', $this->mainCare) . ')'
            );
        }
        if (!empty($this->expertises)) {
            $recProfessionals->innerJoin(
                ['pe' => ProfessionalCare::tableName()],
                'pe.professional_id = r.professional_id AND pe.care_id IN (' . implode(',', $this->expertises) . ')'
            );
        }
        
        // Apply restrictive filters if any
        $this->addRestrictiveFiltersToRecommendation($recProfessionals);

        return $recProfessionals->where(['in', 'member_id', $MyRecommendedProfessionals])
            ->andWhere(['<>', 'member_id', $this->member->id])
            ->groupBy(['r.professional_id'])
            ->orderBy('count DESC')
            ->select(['r.professional_id', 'COUNT(DISTINCT r.member_id) as count'])
            ->createCommand()
            ->queryAll();
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

    public function getAllRecommended()
    {
        $query = Recommendation::find() //all recommendations of category
            ->alias('r');

        if ($this->mainCare && count($this->mainCare)) {
            $query->innerJoin(
                ['pms' => ProfessionalMainCare::tableName()],
                'pms.professional_id = r.professional_id AND pms.main_care_id IN (' . implode(',', $this->mainCare) . ')'
            );
        }
        if (!empty($this->expertises)) {
            $query->innerJoin(
                ['pe' => ProfessionalCare::tableName()],
                'pe.professional_id = r.professional_id AND pe.care_id IN (' . implode(',', $this->expertises) . ')'
            );
        }
        
        if ($this->member) {
            $query->andWhere(['<>', 'member_id', $this->member->id]);
        }

        // Apply restrictive filters if any
        $this->addRestrictiveFiltersToRecommendation($query);
        
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
            ->andWhere(['<', $distanceExpr, 0.3]) // 0.3 km
            ->all();

        return $query;
    }

    public function getAll()
    {
        // Step 1: Get recommendation counts by type
        $recommendationCounts = $this->getRecommendationCounts();
        
        // Step 2: Get sorted professional IDs based on recommendations
        $sortedProfessionalIds = $this->getSortedProfessionalIds($recommendationCounts);

        // Step 3: Fetch recommended professionals (prioritized)
        $recommendedProfessionals = $this->fetchRecommendedProfessionals($sortedProfessionalIds);

        $alreadyFetchedIds = ArrayHelper::getColumn($recommendedProfessionals, 'id');

        // Step 4: Fetch additional professionals based on filters
        $additionalProfessionals = $this->fetchAdditionalProfessionals($alreadyFetchedIds);

        // Step 5: Merge all professionals
        $allProfessionals = array_merge($recommendedProfessionals, $additionalProfessionals);
        
        // Step 6: Format and return data
        return $this->formatProfessionalsData($allProfessionals, $recommendationCounts, $alreadyFetchedIds);
    }

    /**
     * Get recommendation counts grouped by type (friends, colleagues, likeMe, all)
     * @return array ['friends' => array, 'colleagues' => array, 'likeMe' => array, 'all' => array]
     */
    private function getRecommendationCounts()
    {
        return [
            'friends' => $this->member ? ArrayHelper::map($this->getRecommendedFriends(), 'professional_id', 'count') : [],
            'colleagues' => $this->member ? ArrayHelper::map($this->getRecommendedColleague(), 'professional_id', 'count') : [],
            'likeMe' => $this->member ? ArrayHelper::map($this->getRecommendedLikeMe(), 'professional_id', 'count') : [],
            'all' => ArrayHelper::map($this->getAllRecommended(), 'professional_id', 'count'),
        ];
    }

    /**
     * Get sorted professional IDs based on recommendation priority
     * @param array $recommendationCounts
     * @return array Sorted professional IDs
     */
    private function getSortedProfessionalIds(array $recommendationCounts)
    {
        $friends = $recommendationCounts['friends'];
        $colleagues = $recommendationCounts['colleagues'];
        $likeMe = $recommendationCounts['likeMe'];
        $all = $recommendationCounts['all'];

        // Collect all professional IDs
        $professionalIds = array_unique(
            array_merge(
                array_keys($friends),
                array_keys($colleagues),
                array_keys($likeMe),
                array_keys($all)
            )
        );

        // Filter out invalid IDs
        $professionalIds = array_values(array_filter($professionalIds, function ($id) {
            return !empty($id) && $id !== '' && $id !== null && $id > 0;
        }));

        // Sort by recommendation priority
        $maxRec = count($all) ? max($all) : 0;
        usort($professionalIds, function ($item1, $item2) use ($friends, $colleagues, $likeMe, $all, $maxRec) {
            $item1Level = $this->calculateProfessionalLevel($item1, $friends, $colleagues, $likeMe, $all, $maxRec);
            $item2Level = $this->calculateProfessionalLevel($item2, $friends, $colleagues, $likeMe, $all, $maxRec);
            return $item2Level <=> $item1Level;
        });

        return $professionalIds;
    }

    /**
     * Calculate priority level for a professional
     * @param int $professionalId
     * @param array $friends
     * @param array $colleagues
     * @param array $likeMe
     * @param array $all
     * @param int $maxRec
     * @return int
     */
    private function calculateProfessionalLevel($professionalId, array $friends, array $colleagues, array $likeMe, array $all, int $maxRec)
    {
        $friendCount = $friends[$professionalId] ?? 0;
        $likeMeCount = $likeMe[$professionalId] ?? 0;
        $colleaguesCount = $colleagues[$professionalId] ?? 0;
        $allCount = $all[$professionalId] ?? 0;

        $level = $friendCount + $likeMeCount + $colleaguesCount;
        $level += $level > 0 ? $maxRec : $allCount;

        return $level;
    }

    /**
     * Fetch recommended professionals based on sorted IDs
     * @param array $sortedProfessionalIds
     * @return array
     */
    private function fetchRecommendedProfessionals(array $sortedProfessionalIds)
    {
        $paginatedIds = array_slice($sortedProfessionalIds, $this->offset, $this->limit);

        if (empty($paginatedIds)) {
            return [];
        }

        return Professional::find()
            ->where(['IN', 'professional.id', $paginatedIds])
            ->orderBy([new \yii\db\Expression('FIELD (professional.id, ' . implode(',', $paginatedIds) . ')')])
            ->all();
    }

    /**
     * Fetch additional professionals based on filters (excluding already fetched)
     * @param array $excludeIds
     * @return array
     */
    private function fetchAdditionalProfessionals(array $excludeIds)
    {
        $professionals = [];
        $hasInsurance = !empty($this->insurance) && $this->insurance != 0 && $this->insurance != [];
        $hasCompany = $this->company != 0;
        $professionalsHmoIns = [];

        // Check if any restrictive filter is active
        $hasRestrictiveFilter = $this->restrictToArea || $this->restrictToHmo || $this->restrictToInsurance;

        // If restrictive filters are active, only search within those filters
        if ($hasRestrictiveFilter) {
            // Query with restrictive filters only
            $query = $this->buildBaseQuery();
            $this->addCommonFilters($query);
            
            // Apply restrictive filters
            if ($this->restrictToHmo && $hasCompany) {
                $this->addCompanyFilter($query);
            }
            if ($this->restrictToInsurance && $hasInsurance) {
                $this->addInsuranceFilter($query);
            }
            if ($this->restrictToArea) {
                $this->addAreaFilter($query);
            }
            
            $this->addDistanceCalculation($query);
            $this->excludeProfessionals($query, $excludeIds);
            $query->limit($this->limit)->offset($this->offset);

            $professionals = $query->all();
            Yii::info("Query (restrictive filters) - Count: " . count($professionals) . " | Limit: " . $this->limit . " | SQL: " . $query->createCommand()->rawSql);
            
            // If restrictToArea is active and we have less than 10 results, extend search to district
            // Only extend if we're searching by city (not already by district)
            if ($this->restrictToArea && $this->locationType !== 'district' && count($professionals) < 10 && !empty($this->districtName) && !empty($this->localityIds)) {
                Yii::info("Extending search to district: " . $this->districtName . " (had " . count($professionals) . " results)");
                
                // Build new query with district filter
                $queryDistrict = $this->buildBaseQuery();
                $this->addCommonFilters($queryDistrict);
                
                // Apply restrictive filters
                if ($this->restrictToHmo && $hasCompany) {
                    $this->addCompanyFilter($queryDistrict);
                }
                if ($this->restrictToInsurance && $hasInsurance) {
                    $this->addInsuranceFilter($queryDistrict);
                }
                
                // Use district filter instead of specific localityIds
                $this->addAreaFilter($queryDistrict, true);
                
                $this->addDistanceCalculation($queryDistrict);
                $this->excludeProfessionals($queryDistrict, $excludeIds);
                $queryDistrict->limit($this->limit)->offset($this->offset);
                
                $professionalsDistrict = $queryDistrict->all();
                Yii::info("Query (district extended) - Count: " . count($professionalsDistrict) . " | SQL: " . $queryDistrict->createCommand()->rawSql);
                
                // Merge results, removing duplicates (keep original locality results first)
                $existingIds = ArrayHelper::getColumn($professionals, 'id');
                $professionalsDistrict = array_filter($professionalsDistrict, function($p) use ($existingIds) {
                    return !in_array($p->id, $existingIds);
                });
                
                // Sort district results by distance if coordinates available
                if ($this->lat != 0 && $this->lng != 0 && !empty($professionalsDistrict)) {
                    $professionalsDistrict = $this->sortNearMe($professionalsDistrict, $this->lat, $this->lng);
                }
                
                // Merge: original results first, then district results
                $professionals = array_merge($professionals, $professionalsDistrict);
            }
            
            // Sort by distance if coordinates available
            if ($this->lat != 0 && $this->lng != 0 && !empty($professionals)) {
                $professionals = $this->sortNearMe($professionals, $this->lat, $this->lng);
            }
        } else {
            // Original behavior: search in multiple queries when no restrictive filters
            // Query 1: Base query with company filter (only if company is provided)
            if ($hasCompany) {
                $query1 = $this->buildBaseQuery();
                $this->addCompanyFilter($query1);
                $this->addCommonFilters($query1);
                $this->addDistanceCalculation($query1);
                $this->excludeProfessionals($query1, $excludeIds);
                $query1->limit($this->limit)->offset($this->offset);

                $query1Results = $query1->all();
                Yii::info("Query1 (company filter) - Count: " . count($query1Results) . " | Limit: " . $this->limit . " | SQL: " . $query1->createCommand()->rawSql);
                
                // If we also have insurance, collect in temporary array to merge with insurance results later
                // Otherwise, add directly to final results
                if ($hasInsurance) {
                    $professionalsHmoIns = $query1Results;
                } else {
                    $professionals = array_merge($professionals, $query1Results);
                }
            }

            // Query 2: Insurance-specific query (if insurance is provided)
            if ($hasInsurance) {
                $query2 = $this->buildBaseQuery();
                $this->addInsuranceFilter($query2);
                $this->addCommonFilters($query2);
                $this->addDistanceCalculation($query2);
                $this->excludeProfessionals($query2, array_merge($excludeIds, ArrayHelper::getColumn($professionalsHmoIns ?? [], 'id')));
                $query2->limit($this->limit)->offset($this->offset);

                $professionalsIns = $query2->all();
                Yii::info("Query2 (insurance filter) - Count: " . count($professionalsIns) . " | Limit: " . $this->limit . " | SQL: " . $query2->createCommand()->rawSql);
                
                // Merge insurance results with company results (if any) for later sorting
                $professionalsHmoIns = array_merge($professionalsHmoIns ?? [], $professionalsIns);
            }

            // Query 3: Distance-based query (if coordinates available)
            /* if ($this->lat != 0 && $this->lng != 0) { */
                $query3 = $this->buildBaseQuery();
                $this->addCommonFilters($query3);
                $this->addDistanceCalculation($query3);
                
                $alreadyFetchedIds = array_merge(
                    $excludeIds,
                    ArrayHelper::getColumn($professionals, 'id'),
                    ArrayHelper::getColumn($professionalsHmoIns ?? [], 'id')
                );
                $this->excludeProfessionals($query3, $alreadyFetchedIds);
                $query3->limit($this->limit)->offset($this->offset);

                $professionalsNearMe = $query3->all();
                
                if ($hasInsurance) {
                    $professionalsHmoIns = array_merge($professionalsHmoIns ?? [], $professionalsNearMe);
                    $professionalsHmoIns = $this->sortNearMe($professionalsHmoIns, $this->lat, $this->lng);
                    $professionals = array_merge($professionals, $professionalsHmoIns);
                } else {
                    $professionalsNearMe = $this->sortNearMe($professionalsNearMe, $this->lat, $this->lng);
                    $professionals = array_merge($professionals, $professionalsNearMe);
                }
            /* } elseif ($hasInsurance) {
                $professionals = array_merge($professionals, $professionalsHmoIns ?? []);
            } */
        }

        Yii::info("Total professionals returned: " . count($professionals) . " | Limit per query: " . $this->limit);
        return $professionals;
    }

    /**
     * Build base query with common setup
     * @return \yii\db\ActiveQuery
     */
    private function buildBaseQuery()
    {
        return Professional::find()
            ->with(['addresses' => function ($query) {
                $query->andWhere(['NOT', ['lat' => 0]])
                    ->andWhere(['NOT', ['lng' => 0]]);
            }])
            ->distinct();
    }

    /**
     * Add company filter to query
     * @param \yii\db\ActiveQuery $query
     */
    private function addCompanyFilter($query)
    {
        if ($this->company != 0) {
            $query->innerJoin(
                ['pc' => ProfessionalCompany::tableName()],
                'pc.professional_id = professional.id AND pc.company_id = :cid',
                [':cid' => $this->company]
            );
        }
    }

    /**
     * Add insurance filter to query
     * @param \yii\db\ActiveQuery $query
     */
    private function addInsuranceFilter($query)
    {
        $query->innerJoin(
            ['pi' => ProfessionalInsurance::tableName()],
            'pi.professional_id = professional.id'
        )->andWhere(['pi.insurance_id' => $this->getInsuranceIds()]);
    }

    /**
     * Add area filter to query
     * When restrictToArea is true, address is city_name from Localities
     * @param \yii\db\ActiveQuery $query
     * @param bool $useDistrict If true, filter by district instead of specific localityIds
     */
    private function addAreaFilter($query, $useDistrict = false)
    {
        if ($useDistrict && !empty($this->districtName)) {
            // Search professionals by district name
            $query->innerJoin(
                ['pl' => \app\models\ProfessionalLocalities::tableName()],
                'pl.professional_id = professional.id'
            )
            ->innerJoin(
                ['l' => Localities::tableName()],
                'l.id = pl.localities_id AND l.english_district_name = :district_name'
            )
            ->addParams([':district_name' => $this->districtName]);
        } elseif (!empty($this->localityIds)) {
            // Search professionals by locality ID via ProfessionalLocalities
            $query->innerJoin(
                ['pl' => \app\models\ProfessionalLocalities::tableName()],
                'pl.professional_id = professional.id'
            )
            ->innerJoin(
                ['l' => Localities::tableName()],
                'l.id = pl.localities_id AND l.id IN (' . implode(',', array_map('intval', $this->localityIds)) . ')'
            );
        }
    }

    /**
     * Add restrictive filters to a Recommendation query
     * @param \yii\db\ActiveQuery $query
     */
    private function addRestrictiveFiltersToRecommendation($query)
    {
        if ($this->restrictToArea && !empty($this->localityIds)) {
            // Search professionals by locality ID via ProfessionalLocalities
            $query->innerJoin(
                ['pl' => \app\models\ProfessionalLocalities::tableName()],
                'pl.professional_id = r.professional_id'
            )
            ->innerJoin(
                ['l' => Localities::tableName()],
                'l.id = pl.localities_id AND l.id IN (' . implode(',', $this->localityIds) . ')'
            );
        }
        if ($this->restrictToHmo && $this->company != 0) {
            $query->innerJoin(
                ['pc' => ProfessionalCompany::tableName()],
                'pc.professional_id = r.professional_id AND pc.company_id = :company_id',
                [':company_id' => $this->company]
            );
        }
        if ($this->restrictToInsurance && !empty($this->insurance) && $this->insurance != 0 && $this->insurance != []) {
            $query->innerJoin(
                ['pi' => ProfessionalInsurance::tableName()],
                'pi.professional_id = r.professional_id AND pi.insurance_id IN (' . implode(',', $this->insurance) . ')'
            );
        }
    }

    /**
     * Add common filters (mainCare, expertises)
     * @param \yii\db\ActiveQuery $query
     */
    private function addCommonFilters($query)
    {
        if (count($this->mainCare)) {
            $query->innerJoin(
                ['pms' => ProfessionalMainCare::tableName()],
                'pms.professional_id = professional.id AND pms.main_care_id IN (' . implode(',', $this->mainCare) . ')'
            );
        }

        if (count($this->expertises)) {
            $query->innerJoin(
                ['pe' => ProfessionalCare::tableName()],
                'pe.professional_id = professional.id AND pe.care_id IN (' . implode(',', $this->expertises) . ')'
            );
        }
    }

    /**
     * Add distance calculation to query if coordinates are available
     * @param \yii\db\ActiveQuery $query
     */
    private function addDistanceCalculation($query)
    {
        if ($this->lat != 0 && $this->lng != 0) {
            $distanceExpr = $this->getDistanceExpression();
            $closestAddrSubquery = $this->getClosestAddressSubquery($distanceExpr);

            $query->leftJoin(
                ['pa_min' => 'professional_address'],
                'pa_min.professional_id = professional.id
                    AND pa_min.lat <> 0
                    AND pa_min.lng <> 0
                    AND pa_min.id = (' . $closestAddrSubquery->createCommand()->rawSql . ')',
                [':lat' => $this->lat, ':lng' => $this->lng]
            )
            ->addSelect([
                'professional.*',
                'closest_lat' => 'pa_min.lat',
                'closest_lng' => 'pa_min.lng',
                'distance_km' => new \yii\db\Expression($distanceExpr, [':lat' => $this->lat, ':lng' => $this->lng]),
            ])
            ->distinct()
            ->orderBy(new \yii\db\Expression("
                (pa_min.id IS NULL),
                $distanceExpr
            ", [':lat' => $this->lat, ':lng' => $this->lng]));
        }
    }

    /**
     * Get distance calculation SQL expression
     * @return string
     */
    private function getDistanceExpression()
    {
        return "
            6371 * ACOS(
                GREATEST(-1, LEAST(1,
                COS(RADIANS(:lat)) * COS(RADIANS(pa_min.lat)) *
                COS(RADIANS(pa_min.lng) - RADIANS(:lng)) +
                SIN(RADIANS(:lat)) * SIN(RADIANS(pa_min.lat))
                ))
            )
        ";
    }

    /**
     * Get subquery for closest address
     * @param string $distanceExpr
     * @return \yii\db\Query
     */
    private function getClosestAddressSubquery($distanceExpr)
    {
        return (new \yii\db\Query())
            ->select('pa2.id')
            ->from(['pa2' => 'professional_address'])
            ->where('pa2.professional_id = professional.id')
            ->andWhere(['<>', 'pa2.lat', 0])
            ->andWhere(['<>', 'pa2.lng', 0])
            ->orderBy(new \yii\db\Expression(str_replace('pa_min', 'pa2', $distanceExpr)))
            ->limit(1);
    }

    /**
     * Exclude professionals from query
     * @param \yii\db\ActiveQuery $query
     * @param array $excludeIds
     */
    private function excludeProfessionals($query, array $excludeIds)
    {
        if (!empty($excludeIds)) {
            $query->andWhere(['NOT IN', 'professional.id', $excludeIds]);
        }
    }

    /**
     * Format professionals data for response
     * @param array $professionals
     * @param array $recommendationCounts
     * @param array $recommendedIds
     * @return array
     */
    private function formatProfessionalsData(array $professionals, array $recommendationCounts, array $recommendedIds)
    {
        $data = [];
        $friends = $recommendationCounts['friends'];
        $colleagues = $recommendationCounts['colleagues'];
        $likeMe = $recommendationCounts['likeMe'];
        $all = $recommendationCounts['all'];
        $userNaf = $this->getNafByCity($this->address);

        foreach ($professionals as $professional) {
            $naf = $professional->getNaf()->all();
            $address = $professional->getAddresses()->all();
            $recommendedFarFromMe = [];

            if ($this->lat !== 0 && $this->lng !== 0) {
                $recommendedFarFromMe = $this->getRecommendedFarFromMe($professional->id, $this->lat, $this->lng);
                $recommendedFarFromMe = array_values(array_filter($recommendedFarFromMe, fn($r) => !in_array($r->professional_id, $recommendedIds)));
            }

            $recCount = $this->calculateRecCount($professional->id, $friends, $colleagues, $likeMe, $all, $recommendedFarFromMe);

            $data[] = array_merge(
                $professional->toArray(),
                ['naf' => $naf],
                ['userNaf' => $userNaf],
                ['address' => $address],
                [
                    'company' => array_merge($professional->companies, $professional->insurances),
                    'img_url' => $professional->img_url ? ('https://www.doctorita.co.il/rest/' . $professional->img_url) : null,
                    'category' => $professional->mainSpecialization,
                    'main_care' => $professional->mainCare,
                    'expertises' => $professional->expertises,
                    'care' => $professional->care,
                    'isSaved' => $this->member ? $professional->isSaved($this->member) : false,
                    'myRecommendation' => $this->member ? $professional->getMemberRecommendation($this->member) : null,
                ],
                ['recCount' => $recCount]
            );
        }

        return $data;
    }

    /**
     * Calculate recommendation count for a professional
     * @param int $professionalId
     * @param array $friends
     * @param array $colleagues
     * @param array $likeMe
     * @param array $all
     * @param array $recommendedFarFromMe
     * @return array
     */
    private function calculateRecCount($professionalId, array $friends, array $colleagues, array $likeMe, array $all, array $recommendedFarFromMe)
    {
        $recCount = [
            'friends' => 0,
            'nearMe' => 0,
            'colleagues' => 0,
            'likeMe' => 0,
            'all' => 0,
        ];

        if (isset($friends[(string)$professionalId])) {
            $recCount['friends'] = $friends[(string)$professionalId];
        } elseif ($recommendedFarFromMe && $recommendedFarFromMe != [] && $this->lat !== 0 && $this->lng !== 0) {
            $recCount['nearMe'] = (string)count($recommendedFarFromMe);
        } elseif (isset($colleagues[(string)$professionalId])) {
            $recCount['colleagues'] = $colleagues[(string)$professionalId];
        } elseif (isset($likeMe[(string)$professionalId])) {
            $recCount['likeMe'] = $likeMe[(string)$professionalId];
        }

        if (isset($all[(string)$professionalId])) {
            $recCount['all'] = $all[(string)$professionalId];
        }

        return $recCount;
    }

    private function getNafByCity($cityName)
    {
        return Localities::find()
            ->select(['localities.city_name', 'localities.city_symbol', 'localities.naf_name', 'localities.naf_symbol'])
            ->where(['city_name' => $cityName])
            ->one();
    }

    function sortNearMe($professionals, $userLat, $userLng)
    {
        if (empty($userLat) || empty($userLng)) {
            return $professionals;
        }

        usort($professionals, function ($a, $b) use ($userLat, $userLng) {
            $closestA = $this->getClosestAddress($a->addresses, $userLat, $userLng);
            $closestB = $this->getClosestAddress($b->addresses, $userLat, $userLng);

            // Handle null addresses (no valid coordinates)
            if (!$closestA && !$closestB) return 0;
            if (!$closestA) return 1; // A goes to end
            if (!$closestB) return -1; // B goes to end

            $distanceA = $this->calculateHaversineDistance($userLat, $userLng, $closestA->lat, $closestA->lng);
            $distanceB = $this->calculateHaversineDistance($userLat, $userLng, $closestB->lat, $closestB->lng);

            return $distanceA <=> $distanceB;
        });

        return $professionals;
    }

    private function getInsuranceIds()
    {
        if (is_array($this->insurance)) {
            return array_values(array_filter(array_map('intval', $this->insurance)));
        }
        return ($this->insurance > 0) ? [(int)$this->insurance] : [];
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
    
    private function calculateHaversineDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371;
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $lng1Rad = deg2rad($lng1);
        $lng2Rad = deg2rad($lng2);

        return $earthRadius * acos(cos($lat1Rad) * cos($lat2Rad) * cos($lng2Rad - $lng1Rad) + sin($lat1Rad) * sin($lat2Rad));
    }

    private function getCategoryArray()
    {
        $type = null;
        $categoryArray = null;
        
        if (is_array($this->expertises) && count($this->expertises) > 0) {
            $categoryModel = Care::findOne(['id' => $this->expertises[0]]);
            if ($categoryModel) {
                $categoryArray = $categoryModel->toArray();
                $type = 'cares';
            }
        }
        if (is_array($this->mainCare) && count($this->mainCare) > 0) {
            $categoryModel = MainCare::findOne(['id' => $this->mainCare[0]]);
            if ($categoryModel) {
                $categoryArray = $categoryModel->toArray();
                $type = 'main_cares';
            }
        }

        if ($categoryArray !== null && $type !== null) {
            $categoryArray['type'] = $type;
            $categoryArray['isKids'] = $this->isKids;
        }

        return $categoryArray;
    }

    private function getFaq()
    {
        $categoryId = (is_array($this->mainCare) && count($this->mainCare) > 0) ? $this->mainCare[0] : ((is_array($this->expertises) && count($this->expertises) > 0) ? $this->expertises[0] : null);
        $categoryType = (is_array($this->mainCare) && count($this->mainCare) > 0) ? CategoryFaq::TYPE_MAIN_CARE : ((is_array($this->expertises) && count($this->expertises) > 0) ? CategoryFaq::TYPE_EXPERTISE : null);
        return CategoryFaq::find()->where(['category_id' => $categoryId, 'category_type' => $categoryType])->all();
    }

    /**
     * Get the actual parameter values used in the search (after applying member fallbacks)
     * This method returns the values that were actually used, not just the ones passed in
     * @return array ['company' => int|null, 'insurance' => array, 'address' => string|null, 'lat' => float|null, 'lng' => float|null]
     */
    public function getUsedParams()
    {
        return [
            'hmo' => ChatActionHelper::convertUsedParamsToNames(['company' => $this->company])['hmo'] ?? null,
            'insurance' => ChatActionHelper::convertUsedParamsToNames(['insurance' => $this->insurance])['insurance'] ?? null,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'categoryArray' => $this->getCategoryArray(),
            'locationType' => $this->locationType,
            'restrictToArea' => $this->restrictToArea,
            'restrictToHmo' => $this->restrictToHmo,
            'restrictToInsurance' => $this->restrictToInsurance,
            'faq' => $this->getFaq(),
        ];
    }
}
