<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\Cors;
use app\models\Professional;
use app\models\Member;
use app\models\Recommendation;
use app\components\AddressParser;

class ProfessionalController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => ['http://localhost:5173'],
                    'Access-Control-Request-Method' => ['POST', 'GET', 'PATCH', 'DELETE', 'OPTIONS'],
                    'Access-Control-Allow-Credentials' => true,
                    'Access-Control-Max-Age' => 86400,
                    'Access-Control-Allow-Headers' => ['Content-Type', 'Authorization'],
                ],
            ],
        ]);
    }

    public function beforeAction($action)
    {
        if (Yii::$app->request->isOptions) {
            Yii::$app->response->headers->set('Access-Control-Allow-Origin', 'http://localhost:5173');
            Yii::$app->response->headers->set('Access-Control-Allow-Methods', 'POST, GET, PATCH, DELETE, OPTIONS');
            Yii::$app->response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            Yii::$app->end();
        }
        return parent::beforeAction($action);
    }

    /**
     * GET /index.php?r=professional/get-current-professional
     * מחזיר את המידע של הרופא המחובר (member_id = 1)
     */
    public function actionGetCurrentProfessional()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = 1; // תמיד member 1
        
        $member = Member::find()
            ->select(['professional_id'])
            ->where(['id' => $memberId])
            ->asArray()
            ->one();

        if (!$member || !$member['professional_id']) {
            return [
                'ok' => false,
                'is_professional' => false,
                'error' => 'User is not a professional'
            ];
        }

        $professionalId = $member['professional_id'];
        $professionalData = $this->getProfessionalData($professionalId);

        if (!$professionalData) {
            return [
                'ok' => false,
                'error' => 'Professional not found'
            ];
        }

        return [
            'ok' => true,
            'is_professional' => true,
            'data' => $professionalData
        ];
    }

    /**
     * GET /index.php?r=professional/get-options
     * מחזיר את כל האפשרויות הזמינות להוספה
     * כולל סינון תת-התמחויות לפי ההתמחויות הראשיות של הרופא
     */
    public function actionGetOptions()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = 1;
        $member = Member::find()->select(['professional_id'])->where(['id' => $memberId])->asArray()->one();
        $professionalId = $member['professional_id'] ?? null;

        $mainSpecializations = Yii::$app->db->createCommand("
            SELECT id, name FROM main_specialization ORDER BY name
        ")->queryAll();

        $mainCare = Yii::$app->db->createCommand("
            SELECT id, name FROM main_care ORDER BY name
        ")->queryAll();

        // אם הרופא קיים, נסנן את התת-התמחויות לפי ההתמחויות הראשיות שלו
        $expertises = [];
        $care = [];

        if ($professionalId) {
            // קבלת ההתמחויות הראשיות של הרופא (main_specialization)
            $professionalMainSpecs = Yii::$app->db->createCommand("
                SELECT main_specialization_id 
                FROM professional_main_specialization 
                WHERE professional_id = :pid
            ", [':pid' => $professionalId])->queryColumn();

            // אם יש לרופא התמחויות ראשיות, נסנן את ה-expertises לפיהן
            if (!empty($professionalMainSpecs)) {
                $expertises = Yii::$app->db->createCommand("
                    SELECT DISTINCT e.id, e.name 
                    FROM expertise e
                    INNER JOIN main_specialization_expertise mse ON e.id = mse.expertise_id
                    WHERE mse.main_specialization_id IN (" . implode(',', array_map('intval', $professionalMainSpecs)) . ")
                    ORDER BY e.name
                ")->queryAll();
            }

            // קבלת ההתמחויות הראשיות של הרופא (main_care)
            $professionalMainCare = Yii::$app->db->createCommand("
                SELECT main_care_id 
                FROM professional_main_care 
                WHERE professional_id = :pid
            ", [':pid' => $professionalId])->queryColumn();

            // אם יש לרופא main_care, נסנן את ה-care לפיהן
            if (!empty($professionalMainCare)) {
                $care = Yii::$app->db->createCommand("
                    SELECT DISTINCT c.id, c.name 
                    FROM care c
                    INNER JOIN main_care_sub_care mcsc ON c.id = mcsc.sub_care_id
                    WHERE mcsc.main_care_id IN (" . implode(',', array_map('intval', $professionalMainCare)) . ")
                    ORDER BY c.name
                ")->queryAll();
            }
        } else {
            // אם אין רופא, נחזיר רשימות ריקות או את כולן
            $expertises = Yii::$app->db->createCommand("
                SELECT id, name FROM expertise ORDER BY name
            ")->queryAll();

            $care = Yii::$app->db->createCommand("
                SELECT id, name FROM care ORDER BY name
            ")->queryAll();
        }

        $languages = Yii::$app->db->createCommand("
            SELECT id, name FROM speaking_language ORDER BY name
        ")->queryAll();

        $companies = Yii::$app->db->createCommand("
            SELECT id, name FROM company ORDER BY name
        ")->queryAll();

        $insurances = Yii::$app->db->createCommand("
            SELECT id, name FROM insurance ORDER BY name
        ")->queryAll();

        return [
            'ok' => true,
            'options' => [
                'main_specializations' => $mainSpecializations,
                'main_care' => $mainCare,
                'expertises' => $expertises,
                'care' => $care,
                'languages' => $languages,
                'companies' => $companies,
                'insurances' => $insurances,
            ]
        ];
    }

    /**
     * POST /index.php?r=professional/add-item
     * הוספת פריט (התמחות, שפה וכו')
     */
    public function actionAddItem()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = 1;
        $member = Member::find()->select(['professional_id'])->where(['id' => $memberId])->asArray()->one();

        if (!$member || !$member['professional_id']) {
            return ['ok' => false, 'error' => 'User is not a professional'];
        }

        $professionalId = $member['professional_id'];
        $body = Yii::$app->request->bodyParams;
        
        $type = $body['type'] ?? '';
        $itemId = (int)($body['item_id'] ?? 0);

        if (!$type || !$itemId) {
            return ['ok' => false, 'error' => 'Missing type or item_id'];
        }

        try {
            $tableMap = [
                'main_specialization' => ['table' => 'professional_main_specialization', 'field' => 'main_specialization_id'],
                'main_care' => ['table' => 'professional_main_care', 'field' => 'main_care_id'],
                'expertise' => ['table' => 'professional_expertise', 'field' => 'expertise_id'],
                'care' => ['table' => 'professional_care', 'field' => 'care_id'],
                'language' => ['table' => 'professional_language', 'field' => 'language_id'],
                'company' => ['table' => 'professional_company', 'field' => 'company_id'],
                'insurance' => ['table' => 'professional_insurance', 'field' => 'insurance_id'],
            ];

            if (!isset($tableMap[$type])) {
                return ['ok' => false, 'error' => 'Invalid type'];
            }

            $config = $tableMap[$type];
            Yii::$app->db->createCommand()->insert($config['table'], [
                'professional_id' => $professionalId,
                $config['field'] => $itemId
            ])->execute();

            return ['ok' => true, 'message' => 'Item added successfully'];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /index.php?r=professional/remove-item
     * הסרת פריט
     */
    public function actionRemoveItem()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = 1;
        $member = Member::find()->select(['professional_id'])->where(['id' => $memberId])->asArray()->one();

        if (!$member || !$member['professional_id']) {
            return ['ok' => false, 'error' => 'User is not a professional'];
        }

        $professionalId = $member['professional_id'];
        $body = Yii::$app->request->bodyParams;
        
        $type = $body['type'] ?? '';
        $itemId = (int)($body['item_id'] ?? 0);

        if (!$type || !$itemId) {
            return ['ok' => false, 'error' => 'Missing type or item_id'];
        }

        try {
            $tableMap = [
                'main_specialization' => ['table' => 'professional_main_specialization', 'field' => 'main_specialization_id'],
                'main_care' => ['table' => 'professional_main_care', 'field' => 'main_care_id'],
                'expertise' => ['table' => 'professional_expertise', 'field' => 'expertise_id'],
                'care' => ['table' => 'professional_care', 'field' => 'care_id'],
                'language' => ['table' => 'professional_language', 'field' => 'language_id'],
                'company' => ['table' => 'professional_company', 'field' => 'company_id'],
                'insurance' => ['table' => 'professional_insurance', 'field' => 'insurance_id'],
            ];

            if (!isset($tableMap[$type])) {
                return ['ok' => false, 'error' => 'Invalid type'];
            }

            $config = $tableMap[$type];
            Yii::$app->db->createCommand()->delete($config['table'], [
                'professional_id' => $professionalId,
                $config['field'] => $itemId
            ])->execute();

            return ['ok' => true, 'message' => 'Item removed successfully'];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /index.php?r=professional/get-profile&id=123
     * מחזיר את פרופיל הרופא לפי ID
     */
    public function actionGetProfile()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $professionalId = (int)Yii::$app->request->get('id', 0);
        
        if ($professionalId <= 0) {
            return ['ok' => false, 'error' => 'Missing professional id'];
        }

        $data = $this->getProfessionalData($professionalId);

        if (!$data) {
            return ['ok' => false, 'error' => 'Professional not found'];
        }

        return [
            'ok' => true,
            'data' => $data
        ];
    }

    /**
     * פונקציה פרטית לשליפת כל נתוני הרופא
     */
    private function getProfessionalData($professionalId)
    {
        $professional = Professional::find()
            ->where(['id' => $professionalId])
            ->asArray()
            ->one();

        if (!$professional) {
            return null;
        }

        // כתובות
        $addresses = Yii::$app->db->createCommand("
            SELECT id, city_google, street_google, number_house_google
            FROM professional_address
            WHERE professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        $addressList = [];
        foreach ($addresses as $addr) {
            $parts = array_filter([
                $addr['street_google'],
                $addr['number_house_google'],
                $addr['city_google']
            ]);
            if (!empty($parts)) {
                $addressList[] = [
                    'id' => $addr['id'],
                    'address' => implode(' ', $parts),
                ];
            }
        }

        // התמחויות ראשיות + IDs
        $mainSpecializations = Yii::$app->db->createCommand("
            SELECT ms.id, ms.name
            FROM professional_main_specialization pms
            JOIN main_specialization ms ON ms.id = pms.main_specialization_id
            WHERE pms.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        $mainCare = Yii::$app->db->createCommand("
            SELECT mc.id, mc.name
            FROM professional_main_care pmc
            JOIN main_care mc ON mc.id = pmc.main_care_id
            WHERE pmc.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        // התמחויות משניות + IDs
        $expertises = Yii::$app->db->createCommand("
            SELECT e.id, e.name
            FROM professional_expertise pe
            JOIN expertise e ON e.id = pe.expertise_id
            WHERE pe.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        $care = Yii::$app->db->createCommand("
            SELECT c.id, c.name
            FROM professional_care pc
            JOIN care c ON c.id = pc.care_id
            WHERE pc.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        // שפות + IDs
        $languages = Yii::$app->db->createCommand("
            SELECT sl.id, sl.name
            FROM professional_language pl
            JOIN speaking_language sl ON sl.id = pl.language_id
            WHERE pl.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        // קופות חולים + IDs
        $companies = Yii::$app->db->createCommand("
            SELECT c.id, c.name
            FROM professional_company pc
            JOIN company c ON c.id = pc.company_id
            WHERE pc.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        // ביטוחים + IDs
        $insurances = Yii::$app->db->createCommand("
            SELECT i.id, i.name
            FROM professional_insurance pi
            JOIN insurance i ON i.id = pi.insurance_id
            WHERE pi.professional_id = :id
        ")->bindValue(':id', $professionalId)->queryAll();

        return [
            'id' => $professional['id'],
            'full_name' => $professional['full_name'] ?? '',
            'title' => $professional['title'] ?? '',
            'gender' => $professional['gender'] ?? '',
            'email' => $professional['email'] ?? '',
            'phone' => $professional['phone'] ?? '',
            'about' => $professional['about'] ?? '',
            'profile_image' => $professional['img_url'] ?? null,
            'primary_specialties' => array_merge($mainSpecializations, $mainCare),
            'secondary_specialties' => array_merge($expertises, $care),
            'languages' => $languages,
            'health_funds' => $companies,
            'insurances' => $insurances,
            'clinic_addresses' => $addressList,
        ];
    }

    /**
     * GET /index.php?r=professional/get-statistics&id=123
     * מחזיר סטטיסטיקות אמיתיות מ-recommendation_analysis
     */
    public function actionGetStatistics()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $professionalId = (int)Yii::$app->request->get('id', 0);
        
        if ($professionalId <= 0) {
            return ['ok' => false, 'error' => 'Missing professional id'];
        }

        // ספירת המלצות מאושרות
        $totalRecommendations = Recommendation::find()
            ->where(['professional_id' => $professionalId, 'status' => 1])
            ->count();

        // המלצות לפי חודש (8 חודשים אחרונים)
        $reviewsOverTime = [];
        $months = ['ינו', 'פבר', 'מרץ', 'אפר', 'מאי', 'יוני', 'יולי', 'אוג', 'ספט', 'אוק', 'נוב', 'דצמ'];
        
        for ($i = 7; $i >= 0; $i--) {
            $date = date('Y-m-01', strtotime("-$i months"));
            $nextDate = date('Y-m-01', strtotime("-" . ($i - 1) . " months"));
            
            $count = Recommendation::find()
                ->where(['professional_id' => $professionalId, 'status' => 1])
                ->andWhere(['>=', 'created_at', $date])
                ->andWhere(['<', 'created_at', $nextDate])
                ->count();

            $monthIndex = (int)date('n', strtotime($date)) - 1;
            $reviewsOverTime[] = [
                'month' => $months[$monthIndex],
                'reviews' => (int)$count
            ];
        }

        // ניתוח קטגוריות מ-recommendation_analysis
        $analysisData = Yii::$app->db->createCommand("
            SELECT 
                ra.doctor_metrics_json,
                ra.topics_json,
                ra.sentiment
            FROM recommendation r
            JOIN recommendation_analysis ra ON ra.recommendation_id = r.id
            WHERE r.professional_id = :id AND r.status = 1
        ")->bindValue(':id', $professionalId)->queryAll();

        // חישוב מטריקות
        $categories = [
            'professionalism' => 0,
            'empathy' => 0,
            'availability' => 0,
            'cost' => 0,
            'clear_explanation' => 0,
            'patience' => 0,
        ];
        
        $categoryCount = [
            'professionalism' => 0,
            'empathy' => 0,
            'availability' => 0,
            'cost' => 0,
            'clear_explanation' => 0,
            'patience' => 0,
        ];

        foreach ($analysisData as $analysis) {
            if (!empty($analysis['doctor_metrics_json'])) {
                $metrics = json_decode($analysis['doctor_metrics_json'], true);
                if (is_array($metrics)) {
                    foreach ($metrics as $key => $value) {
                        if (isset($categories[$key]) && is_numeric($value)) {
                            $categories[$key] += floatval($value);
                            $categoryCount[$key]++;
                        }
                    }
                }else{
                    $temp = json_decode($metrics, true);
                    if (is_string($temp)) {
                        $temp = json_decode($temp, true);
                    }

                    $doctorMetrics = [
                        'professionalism' => $temp['professionalism'] ?? null,
                        'empathy' => $temp['empathy'] ?? null,
                        'patience' => $temp['patience'] ?? null,
                        'availability' => $temp['availability'] ?? null,
                        'clear_explanation' => $temp['clear_explanation'] ?? null,
                        'cost' => $temp['cost'] ?? null,
                    ];

                    if (is_array($doctorMetrics)) {
                        foreach ($doctorMetrics as $key => $value) {
                            if (isset($categories[$key]) && is_numeric($value)) {
                                $categories[$key] += floatval($value);
                                $categoryCount[$key]++;
                            }
                        }
                    } 
                }
            }
        }

        $categoryAverages = [];
        foreach ($categories as $key => $total) {
            $count = $categoryCount[$key];
            $categoryAverages[$key] = $count;
        }

        $profileViews = (int)Yii::$app->db->createCommand("
            SELECT COUNT(*)
            FROM phone_click_logs pcl
            WHERE pcl.professional_id = :id
            AND pcl.type = 'details_card_click'
        ")->bindValue(':id', $professionalId)->queryScalar();

        // תחילת החודש הנוכחי והקודם
        $startThisMonth = date('Y-m-01 00:00:00');
        $startLastMonth = date('Y-m-01 00:00:00', strtotime('-1 month'));
        $startNextMonth = date('Y-m-01 00:00:00', strtotime('+1 month'));

        // צפיות החודש
        $viewsThisMonth = (int)Yii::$app->db->createCommand("
            SELECT COUNT(*)
            FROM phone_click_logs pcl
            WHERE pcl.professional_id = :id
            AND pcl.type = 'details_card_click'
            AND pcl.date >= :startThis
            AND pcl.date < :startNext
        ")->bindValue(':id', $professionalId)
        ->bindValue(':startThis', $startThisMonth)
        ->bindValue(':startNext', $startNextMonth)
        ->queryScalar();


        $viewsChange = $viewsThisMonth > 0 ? $viewsThisMonth . "+" : '0';

        $reviewsThisMonth = (int)Yii::$app->db->createCommand("
            SELECT COUNT(*)
            FROM recommendation r
            WHERE r.professional_id = :id
            AND r.created_at >= :startThis
            AND r.created_at < :startNext
        ")->bindValue(':id', $professionalId)
        ->bindValue(':startThis', $startThisMonth)
        ->bindValue(':startNext', $startNextMonth)
        ->queryScalar();

        $reviewsChange = $reviewsThisMonth > 0 ? $reviewsThisMonth . "+" : '0';

        return [
            'ok' => true,
            'data' => [
                'total_recommendations' => (int)$totalRecommendations,
                'profile_views' => $profileViews,
                'reviews_over_time' => $reviewsOverTime,
                'monthly_change' => $reviewsChange,
                'views_change' => $viewsChange,
                'category_metrics' => $categoryAverages,
            ]
        ];
    }

    /**
     * GET /index.php?r=professional/get-reviews&id=123
     * מחזיר את ההמלצות של הרופא
     */
    public function actionGetReviews()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $professionalId = (int)Yii::$app->request->get('id', 0);
        
        if ($professionalId <= 0) {
            return ['ok' => false, 'error' => 'Missing professional id'];
        }

        $status = Yii::$app->request->get('status');

        $query = Yii::$app->db->createCommand("
            SELECT 
                r.id,
                r.rec_description,
                r.status,
                r.created_at,
                ra.sentiment,
                ra.sentiment_confidence,
                m.first_name,
                m.last_name
            FROM recommendation r
            LEFT JOIN recommendation_analysis ra ON ra.recommendation_id = r.id
            LEFT JOIN member m ON m.id = r.member_id
            WHERE r.professional_id = :id
            AND r.status IN (0, 1)
            ORDER BY r.created_at DESC
        ")->bindValue(':id', $professionalId);

        $recommendations = $query->queryAll();

        $reviews = [];
        foreach ($recommendations as $rec) {
            $isActive = ($rec['status'] == 1);
            $isFlagged = ($rec['status'] == 0 && $rec['sentiment'] == 'neg');

            if ($status === 'active' && !$isActive) continue;
            if ($status === 'flagged' && !$isFlagged) continue;

            $rating = 3;
            if ($rec['sentiment'] === 'pos') $rating = 5;
            elseif ($rec['sentiment'] === 'neg') $rating = 2;

            $first = trim((string)($rec['first_name'] ?? ''));
            $last  = trim((string)($rec['last_name'] ?? ''));


            $reviews[] = [
                'id' => (string)$rec['id'],
                'firstName' => $first,
                'lastName' => $last,
                'rating' => $rating,
                'date' => $this->formatDate($rec['created_at']),
                'content' => $rec['rec_description'],
                'status' => $isActive ? 'active' : 'flagged',
            ];
        }

        return [
            'ok' => true,
            'reviews' => $reviews,
            'total' => count($reviews)
        ];
    }


    /**
     * PATCH /index.php?r=professional/update-profile
     * עדכון פרופיל הרופא
     */
    public function actionUpdateProfile()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = 1;
        
        $member = Member::find()
            ->select(['professional_id'])
            ->where(['id' => $memberId])
            ->asArray()
            ->one();

        if (!$member || !$member['professional_id']) {
            return ['ok' => false, 'error' => 'User is not a professional'];
        }

        $professionalId = $member['professional_id'];
        $body = Yii::$app->request->bodyParams;

        $professional = Professional::findOne($professionalId);
        if (!$professional) {
            return ['ok' => false, 'error' => 'Professional not found'];
        }

        if (isset($body['full_name'])) $professional->full_name = $body['full_name'];
        if (isset($body['phone'])) $professional->phone = $body['phone'];
        if (isset($body['email'])) $professional->email = $body['email'];
        if (isset($body['about'])) $professional->about = $body['about'];

        if (!empty($body['delete_addresses']) && is_array($body['delete_addresses'])) {
            Yii::$app->db->createCommand()->delete(
                'professional_address',
                [
                    'and',
                    ['professional_id' => $professionalId],
                    ['id' => $body['delete_addresses']]
                ]
            )->execute();
        }

        if (isset($body['add_addresses']) && is_array($body['add_addresses'])) {

            foreach ($body['add_addresses'] as $address) {
                $address = trim((string)$address);
                if ($address === '') {
                    continue;
                }

                try {
                    $search = Yii::$app->serpApi->googleMapsSearch([
                        'type' => 'search',
                        'q'  => $address,
                        'hl' => 'iw',
                        'gl' => 'il',
                    ]);

                    Yii::debug([$search], 'address_debug');

                    $first = $search['local_results'][0] ?? ($search['place_results'] ?? null);

                    if (!$first) {
                        Yii::$app->db->createCommand()->insert('professional_address', [
                            'professional_id' => $professionalId,
                            'street' => $address,
                        ])->execute();
                        continue;
                    }

                    $placeId = $first['place_id'] ?? null;
                    $gps = $first['gps_coordinates'] ?? null;
                    $addressStr = $first['address'] ?? $address;

                    if ($placeId) {
                        $place = Yii::$app->serpApi->googleMapsPlaceById($placeId, [
                            'hl' => 'iw',
                            'gl' => 'il',
                        ]);

                        if (!empty($place['place_results'])) {
                            $addressStr = $place['place_results']['address'] ?? $addressStr;
                            $gps = $place['place_results']['gps_coordinates'] ?? $gps;
                        }
                    }

                    $parsed = AddressParser::parseIsraeliAddress($addressStr);

                    Yii::$app->db->createCommand()->insert('professional_address', [
                        'professional_id' => $professionalId,

                        'street_google'        => $parsed['street'],
                        'number_house_google'  => $parsed['house_number'],
                        'city_google'          => $parsed['city'],
                        'lat'           => $gps['latitude'] ?? null,
                        'lng'           => $gps['longitude'] ?? null,
                    ])->execute();

                } catch (\Throwable $e) {
                    Yii::error([
                        'address' => $address,
                        'error' => $e->getMessage(),
                    ], 'address_import');

                    Yii::$app->db->createCommand()->insert('professional_address', [
                        'professional_id' => $professionalId,
                        'street' => $address,
                    ])->execute();
                }
            }

        }

        if (!$professional->save()) {
            return ['ok' => false, 'errors' => $professional->errors];
        }

        return [
            'ok' => true,
            'message' => 'Profile updated successfully'
        ];
    }

    /**
     * POST /index.php?r=professional/upload-image
     * העלאת תמונת פרופיל
     */
    public function actionUploadImage()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = 1;
        
        $member = Member::find()
            ->select(['professional_id'])
            ->where(['id' => $memberId])
            ->asArray()
            ->one();

        if (!$member || !$member['professional_id']) {
            return ['ok' => false, 'error' => 'User is not a professional'];
        }

        $professionalId = $member['professional_id'];

        // קבלת הקובץ מה-request
        $image = $_FILES['image'] ?? null;
        
        if (!$image || $image['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'No image uploaded or upload error'];
        }

        // בדיקת סוג הקובץ
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($image['type'], $allowedTypes)) {
            return ['ok' => false, 'error' => 'Invalid file type. Only JPG and PNG allowed'];
        }

        // בדיקת גודל הקובץ (מקסימום 5MB)
        if ($image['size'] > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File too large. Maximum 5MB'];
        }

        // יצירת שם קובץ ייחודי
        $extension = pathinfo($image['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $professionalId . '_' . time() . '.' . $extension;
        
        // תיקיית העלאה
        $uploadDir = Yii::getAlias('@webroot/uploads/profiles/');
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $uploadPath = $uploadDir . $filename;

        // העברת הקובץ
        if (move_uploaded_file($image['tmp_name'], $uploadPath)) {
            // עדכון הפרופיל עם הנתיב לתמונה
            $professional = Professional::findOne($professionalId);
            if ($professional) {
                $professional->img_url = 'http://localhost/myyiiapp/web/uploads/profiles/' . $filename;
                $professional->save();

                return [
                    'ok' => true,
                    'message' => 'Image uploaded successfully',
                    'image_url' => '/uploads/profiles/' . $filename
                ];
            }
        }

        return ['ok' => false, 'error' => 'Failed to upload image'];
    }

    /**
     * PATCH /index.php?r=professional/flag-review&id=123
     * סימון המלצה כלא הולמת
     */
    public function actionFlagReview()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $reviewId = (int)Yii::$app->request->get('id', 0);
        
        if ($reviewId <= 0) {
            return ['ok' => false, 'error' => 'Missing review id'];
        }

        $recommendation = Recommendation::findOne($reviewId);
        if (!$recommendation) {
            return ['ok' => false, 'error' => 'Review not found'];
        }

        $recommendation->status = 0;
        
        if (!$recommendation->save()) {
            return ['ok' => false, 'errors' => $recommendation->errors];
        }

        return [
            'ok' => true,
            'message' => 'Review flagged successfully'
        ];
    }

    /**
     * פונקציה עוזרת לעיצוב תאריך
     */
    private function formatDate($datetime)
    {
        if (!$datetime) return '';
        
        $timestamp = strtotime($datetime);
        $now = time();
        $diff = $now - $timestamp;

        if ($diff < 0) {
            return "עכשיו";
        }

        if ($diff < 60) {
            return "עכשיו";
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes == 1 ? "לפני דקה" : "לפני $minutes דקות";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours == 1 ? "לפני שעה" : "לפני $hours שעות";
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days == 1 ? "אתמול" : "לפני $days ימים";
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks == 1 ? "לפני שבוע" : "לפני $weeks שבועות";
        } else {
            return date('d/m/Y', $timestamp);
        }
    }

}