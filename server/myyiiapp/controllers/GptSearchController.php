<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\Cors;
use yii\db\Expression;
use yii\db\Query;
use GuzzleHttp\Client;

class GptSearchController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => ['http://localhost:5173'],
                    'Access-Control-Request-Method' => ['POST', 'OPTIONS'],
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
            Yii::$app->response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            Yii::$app->response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            Yii::$app->end();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        Yii::info('=== GptSearchController actionIndex Started ===', __METHOD__);

        $post = Yii::$app->request->post();
        $sessionId = $post['sessionId'] ?? null;
        $message = trim($post['message'] ?? '');

        Yii::debug([
            'sessionId' => $sessionId,
            'message' => $message,
            'post_data' => $post,
        ], __METHOD__);

        if (!$sessionId || !$message) {
            Yii::warning('Missing sessionId or message', __METHOD__);
            return ['error' => 'Missing sessionId or message'];
        }

        // שליפת רשימת ההתמחויות
        $expertises = (new Query())
            ->select(['name'])
            ->from('expertise')
            ->column();

        Yii::info('Found ' . count($expertises) . ' expertises from DB', __METHOD__);
        Yii::debug(['expertises' => $expertises], __METHOD__);

        // שליחת השאלה ל-GPT
        $systemMessage = "אתה עוזר רפואי. המשתמש יתאר בעיה רפואית והוא מחפש רופא.

עליך לזהות ולהחזיר רק את שם ההתמחות הכי מתאימה מתוך הרשימה הבאות:
" . implode(", ", $expertises) . "

חוקים:
1. החזר רק את שם ההתמחות, ללא ניסוחים נוספים, שאלות או מילים מיותרות.
2. אל תענה בשאלות או משפטים.
3. דוגמה: המשתמש כותב 'כואבת לי הבטן' – אתה עונה: 'גסטרואנטרולוגיה'";

        $messages = [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $message],
        ];

        Yii::debug(['messages_to_openai' => $messages], __METHOD__);

        $expertise = $this->getExpertiseFromOpenAI($messages, $expertises);

        if ($expertise) {
            Yii::info("Expertise identified: $expertise", __METHOD__);
        } else {
            Yii::warning('No expertise identified from OpenAI response', __METHOD__);
        }

        if (!$expertise) {
            return ['reply' => 'לא זוהתה התמחות מתאימה מהתיאור שלך. נסה לתאר את הבעיה בצורה יותר מפורטת.'];
        }

        $result = $this->getDoctorsByExpertise($expertise);
        
        Yii::info('Final result prepared', __METHOD__);
        Yii::debug(['result' => $result], __METHOD__);

        return $result;
    }

    private function getExpertiseFromOpenAI($messages, $expertises)
    {
        $apiKey = Yii::$app->params['openaiApiKey'];

        if (empty($apiKey)) {
            Yii::error('OpenAI API Key is missing!', __METHOD__);
            return null;
        }
        Yii::debug('OpenAI API Key exists (length: ' . strlen($apiKey) . ')', __METHOD__);

        try {
            Yii::info('Sending request to OpenAI API...', __METHOD__);
            
            $client = new Client();
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-5.2',
                    'messages' => $messages,
                    'temperature' => 0.3,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $reply = trim($data['choices'][0]['message']['content'] ?? '');

            Yii::info("OpenAI Response: $reply", __METHOD__);
            Yii::debug(['full_openai_response' => $data], __METHOD__);

            // התאמה פשוטה לשם ההתמחות מתוך הרשימה
            foreach ($expertises as $exp) {
                if (mb_strtolower($reply) === mb_strtolower($exp)) {
                    Yii::info("Matched expertise: $exp", __METHOD__);
                    return $exp;
                }
            }

            Yii::warning("No exact match found. Reply was: '$reply'", __METHOD__);

        } catch (\Exception $e) {
            Yii::error("OpenAI Error: " . $e->getMessage(), __METHOD__);
            Yii::error("Stack trace: " . $e->getTraceAsString(), __METHOD__);
        }

        return null;
    }

    private function getDoctorsByExpertise($expertise)
    {
        Yii::info("Searching doctors for expertise: $expertise", __METHOD__);

        $expertiseId = (new Query())
            ->select(['id'])
            ->from('expertise')
            ->where(['name' => $expertise])
            ->scalar();

        if ($expertiseId) {
            Yii::debug("Expertise ID found: $expertiseId", __METHOD__);
        } else {
            Yii::warning("Expertise '$expertise' not found in database", __METHOD__);
        }

        if (!$expertiseId) {
            return ['reply' => "התמחות '$expertise' לא קיימת במערכת."];
        }

        // שליפת רופאים עם כל הנתונים הרלוונטיים
        $doctors = (new Query())
            ->select([
                'p.id',
                'p.full_name',
                // Count recommendations
                'COUNT(DISTINCT r.id) as recommendation_count',
                // Count positive recommendations
                'SUM(CASE WHEN ra.sentiment = "pos" THEN 1 ELSE 0 END) as positive_recommendations',
            ])
            ->from('professional_expertise pe')
            ->innerJoin('professional p', 'pe.professional_id = p.id')
            ->leftJoin('recommendation r', 'r.professional_id = p.id AND (r.status IS NULL OR r.status = 1)')
            ->leftJoin('recommendation_analysis ra', 'ra.recommendation_id = r.id')
            ->where(['pe.expertise_id' => $expertiseId])
            ->groupBy(['p.id', 'p.full_name'])
            ->orderBy(['positive_recommendations' => SORT_DESC, 'recommendation_count' => SORT_DESC])
            ->limit(15)
            ->all();

        Yii::info('Found ' . count($doctors) . ' doctors', __METHOD__);
        Yii::debug(['doctors' => $doctors], __METHOD__);

        if (empty($doctors)) {
            return ['reply' => "לא נמצאו רופאים המתמחים ב־$expertise."];
        }

        // עיבוד נוסף של נתוני הרופאים
        $enrichedDoctors = [];
        foreach ($doctors as $doctor) {
            $professionalId = $doctor['id'];
            
            // Get expertises for this doctor
            $doctorExpertises = (new Query())
                ->select(['e.name'])
                ->from('professional_expertise pe')
                ->innerJoin('expertise e', 'pe.expertise_id = e.id')
                ->where(['pe.professional_id' => $professionalId])
                ->column();

            // Get address from professional_address table
            $address = (new Query())
                ->select(new Expression("CONCAT(street, ' ', house_number, ', ', city)"))
                ->from('professional_address')
                ->where(['professional_id' => $professionalId])
                ->scalar();
            
            // Get company/HMO from professional_company table
            $company_id = (new Query())
                ->select(['company_id'])
                ->from('professional_company')
                ->where(['professional_id' => $professionalId])
                ->scalar();

            $company = (new Query())
                ->select(['name'])
                ->from('company')
                ->where(['id' => $company_id])
                ->scalar();

            // Get insurance from professional_insurance table
            $insurance_id = (new Query())
                ->select(['insurance_id'])
                ->from('professional_insurance')
                ->where(['professional_id' => $professionalId])
                ->scalar();
            
            $insurance = (new Query())
                ->select(['name'])
                ->from('insurance')
                ->where(['id' => $insurance_id])
                ->scalar();

            // Get aggregated analysis data
            $analysisData = (new Query())
                ->select([
                    'ra.sentiment',
                    'ra.sentiment_confidence',
                    'ra.hmo',
                    'ra.insurance',
                    'ra.languages_json',
                    'ra.doctor_metrics_json',
                ])
                ->from('recommendation r')
                ->innerJoin('recommendation_analysis ra', 'ra.recommendation_id = r.id')
                ->where([
                    'r.professional_id' => $professionalId,
                ])
                ->andWhere('r.status IS NULL OR r.status = 1')
                ->andWhere('ra.sentiment IS NOT NULL')
                ->orderBy(['ra.sentiment_confidence' => SORT_DESC])
                ->limit(10)
                ->all();

            // Aggregate sentiment
            $sentimentCounts = ['pos' => 0, 'neg' => 0, 'neu' => 0];
            $totalConfidence = 0;
            $hmos = [];
            $insurances = [];
            $languages = [];
            $metricsData = [
                'professionalism' => [],
                'cost' => [],
                'empathy' => [],
                'availability' => [],
                'patience' => [],
                'clear_explanation' => [],
            ];

            foreach ($analysisData as $analysis) {
                // Sentiment
                if (!empty($analysis['sentiment'])) {
                    $sentimentCounts[$analysis['sentiment']]++;
                    $totalConfidence += floatval($analysis['sentiment_confidence'] ?? 0);
                }

                // HMO & Insurance
                if (!empty($analysis['hmo'])) {
                    $hmos[$analysis['hmo']] = ($hmos[$analysis['hmo']] ?? 0) + 1;
                }
                if (!empty($analysis['insurance'])) {
                    $insurances[$analysis['insurance']] = ($insurances[$analysis['insurance']] ?? 0) + 1;
                }

                // Languages
                if (!empty($analysis['languages_json'])) {
                    $langs = json_decode($analysis['languages_json'], true);
                    if (is_array($langs)) {
                        foreach ($langs as $lang) {
                            $languages[$lang] = ($languages[$lang] ?? 0) + 1;
                        }
                    }
                }

                // Metrics
                if (!empty($analysis['doctor_metrics_json'])) {
                    $metrics = json_decode($analysis['doctor_metrics_json'], true);
                    if (is_array($metrics)) {
                        foreach ($metricsData as $key => $values) {
                            if (isset($metrics[$key]) && $metrics[$key] !== null) {
                                $metricsData[$key][] = $metrics[$key];
                            }
                        }
                    }
                }
            }

            // Calculate dominant sentiment
            $dominantSentiment = 'neu';
            $maxCount = 0;
            foreach ($sentimentCounts as $sentiment => $count) {
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $dominantSentiment = $sentiment;
                }
            }

            $avgConfidence = count($analysisData) > 0 ? $totalConfidence / count($analysisData) : null;

            // Get most common HMO, Insurance from analysis (fallback)
            $mostCommonHmo = !empty($hmos) ? array_search(max($hmos), $hmos) : null;
            $mostCommonInsurance = !empty($insurances) ? array_search(max($insurances), $insurances) : null;

            // Get most common languages (top 3)
            arsort($languages);
            $topLanguages = array_slice(array_keys($languages), 0, 3);

            // Calculate average metrics
            $avgMetrics = [];
            foreach ($metricsData as $key => $values) {
                if (!empty($values)) {
                    if ($key === 'cost') {
                        // For cost, get the most common value
                        $costCounts = array_count_values($values);
                        arsort($costCounts);
                        $avgMetrics[$key] = array_key_first($costCounts);
                    } else {
                        // For numeric metrics, calculate average
                        $avgMetrics[$key] = round(array_sum($values) / count($values), 1);
                    }
                }
            }

            // Calculate average rating (average of all numeric metrics)
            $numericMetrics = array_filter($avgMetrics, function($key) {
                return $key !== 'cost';
            }, ARRAY_FILTER_USE_KEY);
            $avgRating = !empty($numericMetrics) ? array_sum($numericMetrics) / count($numericMetrics) : null;

            $enrichedDoctors[] = [
                'id' => intval($doctor['id']),
                'full_name' => $doctor['full_name'],
                'expertise' => $doctorExpertises,
                'sentiment' => $maxCount > 0 ? $dominantSentiment : null,
                'sentiment_confidence' => $avgConfidence,
                'hmo' => $mostCommonHmo,
                'insurance' => $mostCommonInsurance,
                'languages' => $topLanguages,
                'doctor_metrics' => !empty($avgMetrics) ? $avgMetrics : null,
                'recommendation_count' => intval($doctor['recommendation_count']),
                'positive_recommendations' => intval($doctor['positive_recommendations']),
                'average_rating' => $avgRating,
                // New fields from dedicated tables
                'professional_address' => $address,
                'professional_company' => $company,
                'professional_insurance' => $insurance,
            ];
        }

        return [
            'reply' => "נמצאו " . count($enrichedDoctors) . " רופאים מומלצים בתחום $expertise:",
            'doctors' => $enrichedDoctors,
            'expertise' => $expertise,
        ];
    }
}