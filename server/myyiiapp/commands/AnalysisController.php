<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\Json;

/**
 * הרצת ניתוח AI על המלצות
 *
 * שימוש:
 * php yii analysis/run 12 14
 * php yii analysis/single 12
 * php yii analysis/status
 */
class AnalysisController extends Controller
{
    /**
     * הרצת ניתוח על טווח של המלצות
     *
     * @param int $start
     * @param int $end
     * @return int
     */
    public function actionRun($start, $end)
    {
        $this->stdout("\n🤖 מתחיל ניתוח המלצות $start עד $end...\n\n", Console::FG_CYAN);

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? null;

        if (!$apiKey) {
            $this->stdout("❌ שגיאה: OPENAI_API_KEY לא מוגדר\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $recommendations = Yii::$app->db->createCommand("
            SELECT
                r.id,
                r.rec_description,
                r.professional_id,
                p.full_name AS doctor_name
            FROM recommendation r
            LEFT JOIN professional p ON r.professional_id = p.id
            WHERE r.id BETWEEN :start AND :end
              AND r.status = 1
            ORDER BY r.id
        ")
            ->bindValue(':start', (int)$start)
            ->bindValue(':end', (int)$end)
            ->queryAll();

        if (empty($recommendations)) {
            $this->stdout("⚠️ לא נמצאו המלצות בטווח $start-$end\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("📝 נמצאו " . count($recommendations) . " המלצות\n", Console::FG_GREEN);
        $this->stdout(str_repeat("-", 50) . "\n\n", Console::FG_GREY);

        $success = 0;
        $errors = 0;

        foreach ($recommendations as $rec) {
            $id = $rec['id'];
            $preview = mb_substr((string)$rec['rec_description'], 0, 40);
            $this->stdout("🔍 מנתח המלצה #$id", Console::FG_CYAN);
            $this->stdout(" ({$preview}...)\n", Console::FG_GREY);

            try {
                $result = $this->analyzeRecommendation($rec, $apiKey);

                if ($result['ok']) {
                    $data = $result['data'];

                    $this->stdout("   ✅ הושלם בהצלחה\n", Console::FG_GREEN);
                    $this->stdout("   סנטימנט: " . ($data['sentiment'] ?? 'N/A') . "\n", Console::FG_GREY);

                    if (!empty($data['doctor_metrics']) && is_array($data['doctor_metrics'])) {
                        $metrics = $data['doctor_metrics'];

                        if (isset($metrics['professionalism']) && $metrics['professionalism'] !== null) {
                            $prof = round($metrics['professionalism'] * 100);
                            $this->stdout("   מקצועיות: {$prof}%\n", Console::FG_GREY);
                        }

                        if (isset($metrics['empathy']) && $metrics['empathy'] !== null) {
                            $emp = round($metrics['empathy'] * 100);
                            $this->stdout("   אמפתיה: {$emp}%\n", Console::FG_GREY);
                        }
                    }

                    $success++;
                } else {
                    $this->stdout("   ❌ שגיאה: " . $result['error'] . "\n", Console::FG_RED);
                    $errors++;
                }
            } catch (\Throwable $e) {
                $this->stdout("   ❌ חריגה: " . $e->getMessage() . "\n", Console::FG_RED);
                $errors++;
            }

            $this->stdout("\n");

            if ((int)$id < (int)$end) {
                usleep(500000);
            }
        }

        $this->stdout(str_repeat("=", 50) . "\n", Console::FG_GREY);
        $this->stdout("🎉 סיימנו!\n", Console::FG_CYAN);
        $this->stdout("✅ הצלחות: $success\n", Console::FG_GREEN);

        if ($errors > 0) {
            $this->stdout("❌ שגיאות: $errors\n", Console::FG_RED);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * הרצת ניתוח על המלצה בודדת
     *
     * @param int $id
     * @return int
     */
    public function actionSingle($id)
    {
        $this->stdout("\n🔍 מנתח המלצה #$id...\n\n", Console::FG_CYAN);

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? null;

        if (!$apiKey) {
            $this->stdout("❌ שגיאה: OPENAI_API_KEY לא מוגדר\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $recommendation = Yii::$app->db->createCommand("
            SELECT
                r.id,
                r.rec_description,
                r.professional_id,
                p.full_name AS doctor_name
            FROM recommendation r
            LEFT JOIN professional p ON r.professional_id = p.id
            WHERE r.id = :id
              AND r.status = 1
        ")
            ->bindValue(':id', (int)$id)
            ->queryOne();

        if (!$recommendation) {
            $this->stdout("❌ המלצה לא נמצאה או לא פעילה\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout(
            "טקסט: " . mb_substr((string)$recommendation['rec_description'], 0, 100) . "...\n\n",
            Console::FG_GREY
        );

        try {
            $result = $this->analyzeRecommendation($recommendation, $apiKey);

            if ($result['ok']) {
                $this->stdout("✅ ניתוח הושלם בהצלחה!\n\n", Console::FG_GREEN);
                $this->stdout(
                    Json::encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n",
                    Console::FG_GREY
                );
                return ExitCode::OK;
            }

            $this->stdout("❌ שגיאה: " . $result['error'] . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stdout("❌ חריגה: " . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * הצגת סטטוס הניתוחים
     *
     * @return int
     */
    public function actionStatus()
    {
        $this->stdout("\n📊 סטטוס ניתוחים\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 50) . "\n\n", Console::FG_GREY);

        $total = Yii::$app->db->createCommand("
            SELECT COUNT(*) FROM recommendation WHERE status = 1
        ")->queryScalar();

        $analyzed = Yii::$app->db->createCommand("
            SELECT COUNT(*) FROM recommendation_analysis
        ")->queryScalar();

        $notAnalyzed = (int)$total - (int)$analyzed;

        $this->stdout("סה\"כ המלצות: ", Console::FG_GREY);
        $this->stdout("$total\n", Console::FG_CYAN);

        $this->stdout("עם ניתוח: ", Console::FG_GREY);
        $this->stdout("$analyzed\n", Console::FG_GREEN);

        $this->stdout("ללא ניתוח: ", Console::FG_GREY);
        $this->stdout("$notAnalyzed\n", Console::FG_YELLOW);

        if ($notAnalyzed > 0) {
            $this->stdout("\n📝 דוגמאות להמלצות ללא ניתוח:\n", Console::FG_YELLOW);

            $samples = Yii::$app->db->createCommand("
                SELECT r.id, LEFT(r.rec_description, 50) AS preview
                FROM recommendation r
                LEFT JOIN recommendation_analysis ra ON r.id = ra.recommendation_id
                WHERE r.status = 1
                  AND ra.id IS NULL
                LIMIT 5
            ")->queryAll();

            if (!empty($samples)) {
                foreach ($samples as $sample) {
                    $this->stdout("  • ID {$sample['id']}: {$sample['preview']}...\n", Console::FG_GREY);
                }

                $firstId = (int)$samples[0]['id'];
                $this->stdout(
                    "\n💡 טיפ: הרץ 'php yii analysis/run {$firstId} " . ($firstId + 4) . "' לניתוח\n",
                    Console::FG_CYAN
                );
            }
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * ניתוח המלצה בודדת
     *
     * @param array $recommendation
     * @param string $apiKey
     * @return array
     */
    private function analyzeRecommendation(array $recommendation, string $apiKey): array
    {
        try {
            $responseText = $this->callOpenAI($apiKey, (string)$recommendation['rec_description']);

            if (!$responseText) {
                return ['ok' => false, 'error' => 'לא התקבלה תשובה מה-API'];
            }

            $data = $this->parseResponse($responseText);

            if (!$data || !is_array($data)) {
                return ['ok' => false, 'error' => 'לא הצלחנו לפרסר את התשובה'];
            }

            $this->saveAnalysis((int)$recommendation['id'], $data);

            return ['ok' => true, 'data' => $data];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * קריאה ל-OpenAI Responses API עם JSON Schema
     *
     * @param string $apiKey
     * @param string $text
     * @return string|null
     * @throws \Exception
     */
    private function callOpenAI(string $apiKey, string $text): ?string
    {
        $url = 'https://api.openai.com/v1/responses';

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'sentiment' => [
                    'type' => 'string',
                    'enum' => ['positive', 'neutral', 'negative'],
                ],
                'sentiment_confidence' => [
                    'type' => ['number', 'null'],
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'doctor_metrics' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'professionalism' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'empathy' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'availability' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'cost' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'clear_explanation' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'patience' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                    ],
                    'required' => [
                        'professionalism',
                        'empathy',
                        'availability',
                        'cost',
                        'clear_explanation',
                        'patience'
                    ],
                ],
                'topics' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'specialties' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'languages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'sentiment',
                'sentiment_confidence',
                'doctor_metrics',
                'topics',
                'specialties',
                'languages'
            ],
        ];

        $payload = [
            'model' => 'gpt-5.2',
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'אתה מנתח המלצות על רופאים. החזר אך ורק JSON תקין לפי ה-schema.'
                        ]
                    ]
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' =>
                                <<<PROMPT
                                חשוב: תחזיר JSON תקין בלבד. בלי ``` ובלי טקסט מסביב.
                                נתח את ההמלצה הרפואית הבאה והחזר JSON בלבד (ללא טקסט נוסף):

                                המלצה:
                                "$text"

                                החזר JSON במבנה הבא:
                                {
                                "sentiment": "positive|neutral|negative",
                                "sentiment_confidence": 0.0-1.0,
                                "doctor_metrics": {
                                    "professionalism": 0.0/1.0,
                                    "empathy": 0.0/1.0,
                                    "availability": 0.0/1.0,
                                    "cost": 0.0/1.0,
                                    "clear_explanation": 0.0/1.0,
                                    "patience": 0.0/1.0
                                },
                                "topics": ["נושא1", "נושא2"],
                                "specialties": ["התמחות1"],
                                "languages": ["עברית"]
                                }

                                הערות:
                                - doctor_metrics: ציון 0 או 1 לכל מדד רק אם צויין בפירוש בהמלצה(אם לא מוזכר = null)
                                - topics: נושאים מרכזיים
                                - specialties: התמחויות שמוזכרות
                                - languages: שפות

                                החזר רק JSON, ללא הסברים.
                                PROMPT
                        ]
                    ]
                ]
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'recommendation_analysis',
                    'schema' => $schema,
                    'strict' => true,
                ]
            ]
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL Error: ' . $err);
        }

        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $result['error']['message'] ?? $response;
            throw new \Exception("OpenAI API Error: HTTP {$httpCode}; {$msg}");
        }

        // נסה קודם output_text אם קיים
        if (!empty($result['output_text'])) {
            return $result['output_text'];
        }

        // fallback: חפש את הטקסט מתוך output/content
        if (!empty($result['output']) && is_array($result['output'])) {
            foreach ($result['output'] as $item) {
                if (!empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $content) {
                        if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                            return $content['text'];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * פירסור התשובה
     *
     * @param string $response
     * @return array|null
     */
    private function parseResponse(string $response): ?array
    {
        $response = trim($response);
        $response = preg_replace('/```(?:json)?\s*/u', '', $response);
        $response = preg_replace('/```\s*$/u', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            if (preg_match('/\{.*\}/su', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return null;
        }

        if (isset($data['doctor_metrics']) && is_string($data['doctor_metrics'])) {
            $decodedMetrics = json_decode($data['doctor_metrics'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedMetrics)) {
                $data['doctor_metrics'] = $decodedMetrics;
            }
        }

        return $data;
    }

    /**
     * שמירת הניתוח למסד
     *
     * @param int $recommendationId
     * @param array $data
     * @return void
     */
    private function saveAnalysis(int $recommendationId, array $data): void
    {
        $exists = Yii::$app->db->createCommand("
            SELECT id
            FROM recommendation_analysis
            WHERE recommendation_id = :id
        ")
            ->bindValue(':id', $recommendationId)
            ->queryScalar();

        $doctorMetrics = isset($data['doctor_metrics'])
            ? Json::encode($data['doctor_metrics'], JSON_UNESCAPED_UNICODE)
            : null;

        $topics = isset($data['topics'])
            ? Json::encode($data['topics'], JSON_UNESCAPED_UNICODE)
            : null;

        $specialties = isset($data['specialties'])
            ? Json::encode($data['specialties'], JSON_UNESCAPED_UNICODE)
            : null;

        $languages = isset($data['languages'])
            ? Json::encode($data['languages'], JSON_UNESCAPED_UNICODE)
            : null;

        $params = [
            ':id' => $recommendationId,
            ':sentiment' => $data['sentiment'] ?? 'neutral',
            ':confidence' => $data['sentiment_confidence'] ?? null,
            ':metrics' => $doctorMetrics,
            ':topics' => $topics,
            ':specialties' => $specialties,
            ':languages' => $languages,
            ':model' => 'gpt-5.2',
        ];

        if ($exists) {
            Yii::$app->db->createCommand("
                UPDATE recommendation_analysis
                SET
                    sentiment = :sentiment,
                    sentiment_confidence = :confidence,
                    doctor_metrics_json = :metrics,
                    topics_json = :topics,
                    specialties_json = :specialties,
                    languages_json = :languages,
                    model_version = :model,
                    created_at = NOW()
                WHERE recommendation_id = :id
            ")
                ->bindValues($params)
                ->execute();
        } else {
            Yii::$app->db->createCommand("
                INSERT INTO recommendation_analysis (
                    recommendation_id,
                    sentiment,
                    sentiment_confidence,
                    doctor_metrics_json,
                    topics_json,
                    specialties_json,
                    languages_json,
                    model_version,
                    created_at
                ) VALUES (
                    :id,
                    :sentiment,
                    :confidence,
                    :metrics,
                    :topics,
                    :specialties,
                    :languages,
                    :model,
                    NOW()
                )
            ")
                ->bindValues($params)
                ->execute();
        }
    }
}