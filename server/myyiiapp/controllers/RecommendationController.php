<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\Cors;

use app\models\Recommendation;
use app\models\RecommendationAnalysis;

class RecommendationController extends Controller
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
     * POST /index.php?r=recommendation/create
     * Body JSON:
     * {
     *   "professional_id": 123,
     *   "rec_description": "....",
     *   "member_id": 1 (אופציונלי, אצלך תמיד 1)
     * }
     */
    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $body = Yii::$app->request->bodyParams;

        $professionalId = (int)($body['professional_id'] ?? 0);
        $text = trim((string)($body['rec_description'] ?? ''));
        $memberId = (int)($body['member_id'] ?? 1);

        if ($professionalId <= 0 || $text === '') {
            return ['ok' => false, 'error' => 'Missing professional_id or rec_description'];
        }

        // 1) שמירת המלצה
        $rec = new Recommendation();
        $rec->member_id = $memberId;
        $rec->professional_id = $professionalId;
        $rec->rec_description = $text;
        $rec->status = 0; // pending by default

        if (!$rec->save()) {
            return ['ok' => false, 'stage' => 'save_recommendation_failed', 'errors' => $rec->errors];
        }

        // 2) קריאה ל-Agent
        try {
            Yii::debug("insert to agent", 'app');
            $agentResp = Yii::$app->agentService->analyze($text, $memberId, $professionalId);
            Yii::debug([$agentResp], 'app');
        } catch (\Throwable $e) {
            // ההמלצה כבר נשמרה - מחזירים שגיאה רק על הניתוח
            return [
                'ok' => false,
                'stage' => 'agent_call_failed',
                'recommendation_id' => $rec->id,
                'error' => $e->getMessage(),
            ];
        }

        $analysis = $agentResp['analysis'] ?? null;
        if (!is_array($analysis)) {
            return [
                'ok' => false,
                'stage' => 'bad_agent_response',
                'recommendation_id' => $rec->id,
                'agent_response' => $agentResp,
            ];
        }

        // Check if Agent returned an error (ok=false)
        if (isset($agentResp['ok']) && $agentResp['ok'] === false) {
            Yii::debug("Agent returned error: " . ($agentResp['error'] ?? 'unknown error'), 'app');
            // Still save analysis even if there was an error, but with fallback values
        }

        // 3) שמירת analysis ל-DB
        $ra = new RecommendationAnalysis();
        $ra->recommendation_id = (int)$rec->id;

        $ra->sentiment = $analysis['sentiment'] ?? null;
        $ra->sentiment_confidence = isset($analysis['sentiment_confidence']) ? (float)$analysis['sentiment_confidence'] : null;

        $ra->doctor_name = $analysis['doctor_name'] ?? null;
        $ra->doctor_title = $analysis['doctor_title'] ?? null;

        $ra->hmo = $analysis['hmo'] ?? null;
        $ra->insurance = $analysis['insurance'] ?? null;

        // Handle JSON fields - support both string and array inputs from Agent
        // If Agent returns JSON string, decode it first to ensure clean storage
        $languages = $analysis['languages'] ?? [];
        if (is_string($languages)) {
            $languages = json_decode($languages, true) ?: [];
        }
        $ra->languages_json = json_encode($languages);
        
        $specialties = $analysis['specialties'] ?? [];
        if (is_string($specialties)) {
            $specialties = json_decode($specialties, true) ?: [];
        }
        $ra->specialties_json = json_encode($specialties);
        
        $topics = $analysis['topics'] ?? [];
        if (is_string($topics)) {
            $topics = json_decode($topics, true) ?: [];
        }
        $ra->topics_json = json_encode($topics);

        $entities = $analysis['extracted_entities'] ?? (object)[];
        if (is_string($entities)) {
            $entities = json_decode($entities, true) ?: (object)[];
        }
        $ra->extracted_entities_json = json_encode($entities);
        
        $metrics = $analysis['doctor_metrics'] ?? (object)[];
        if (is_string($metrics)) {
            $metrics = json_decode($metrics, true) ?: (object)[];
        }
        $ra->doctor_metrics_json = json_encode($metrics);

        $ra->works_with_children = isset($analysis['works_with_children']) ? (int)$analysis['works_with_children'] : 0;
        $ra->children_signal = $analysis['children_signal'] ?? null;
        $ra->children_evidence = $analysis['children_evidence'] ?? null;

        $ra->model_version = $agentResp['model_version'] ?? null;
        $ra->created_at = date('Y-m-d H:i:s');

        if (!$ra->save()) {
            return [
                'ok' => false,
                'stage' => 'save_analysis_failed',
                'recommendation_id' => $rec->id,
                'errors' => $ra->errors,
            ];
        }

        // Update recommendation status based on sentiment
        if ($ra->sentiment === 'pos') {
            $rec->status = 1; // approved
            $rec->save(false);
        }

        return [
            'ok' => true,
            'recommendation_id' => $rec->id,
            'analysis_saved' => true,
            'analysis' => $analysis,
        ];
    }

    /**
     * Alias for actionCreate
     * POST /index.php?r=recommendation/ingest
     */
    public function actionIngest()
    {
        return $this->actionCreate();
    }

    /**
     * GET /index.php?r=recommendation/index
     * Returns list of pending recommendations with counts
     */
    public function actionIndex()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $limit = (int)(Yii::$app->request->get('limit', 100));
        $offset = (int)(Yii::$app->request->get('offset', 0));
        $sentimentFilter = Yii::$app->request->get('sentiment');
        $statusFilter = Yii::$app->request->get('status');

        // Get pending (status=0) recommendations with full details
        $query = Recommendation::find()
            ->select([
                'recommendation.id',
                'recommendation.member_id',
                'recommendation.professional_id',
                'recommendation.category_id',
                'recommendation.rec_description',
                'recommendation.status',
                'ra.sentiment',
                'ra.sentiment_confidence',
                'ra.created_at AS analysis_created_at'
            ])
            ->leftJoin('recommendation_analysis ra', 'ra.recommendation_id = recommendation.id')
            ->where(['recommendation.status' => 0])
            ->orderBy(['recommendation.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        if ($sentimentFilter) {
            $query->andWhere(['ra.sentiment' => $sentimentFilter]);
        }

        $pending = $query->asArray()->all();

        // Get counts
        $pendingCount = Recommendation::find()->where(['status' => 0])->count();
        $approvedCount = Recommendation::find()->where(['status' => 1])->count();
        $totalCount = Recommendation::find()->count();

        return [
            'ok' => true,
            'pending' => $pending,
            'pending_count' => (int)$pendingCount,
            'approved_count' => (int)$approvedCount,
            'total_count' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Alias for actionIndex
     * GET /index.php?r=recommendation/list
     */
    public function actionList()
    {
        return $this->actionIndex();
    }

    /**
     * GET /index.php?r=recommendation/view&id=123
     * Returns a specific recommendation with analysis
     */
    public function actionView()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = (int)Yii::$app->request->get('id', 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Missing or invalid id'];
        }

        $rec = Recommendation::find()
            ->where(['id' => $id])
            ->with('recommendationAnalysis')
            ->asArray()
            ->one();

        if (!$rec) {
            return ['ok' => false, 'error' => 'Recommendation not found'];
        }

        // Format analysis data
        if (isset($rec['recommendationAnalysis'])) {
            $analysis = $rec['recommendationAnalysis'];
            $rec['analysis'] = [
                'sentiment' => $analysis['sentiment'],
                'sentiment_confidence' => $analysis['sentiment_confidence'],
                'doctor_name' => $analysis['doctor_name'],
                'doctor_title' => $analysis['doctor_title'],
                'hmo' => $analysis['hmo'],
                'insurance' => $analysis['insurance'],
                'languages' => json_decode($analysis['languages_json'] ?? '[]', true),
                'specialties' => json_decode($analysis['specialties_json'] ?? '[]', true),
                'topics' => json_decode($analysis['topics_json'] ?? '[]', true),
                'doctor_metrics' => json_decode($analysis['doctor_metrics_json'] ?? '{}', true),
                'works_with_children' => $analysis['works_with_children'],
                'children_signal' => $analysis['children_signal'],
                'children_evidence' => $analysis['children_evidence'],
                'model_version' => $analysis['model_version'],
                'created_at' => $analysis['created_at'],
            ];
            unset($rec['recommendationAnalysis']);
        }

        return [
            'ok' => true,
            'data' => $rec,
        ];
    }

    /**
     * PATCH /index.php?r=recommendation/update&id=123
     * Updates recommendation status
     * Body JSON: { "status": 1 }
     */
    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = (int)Yii::$app->request->get('id', 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Missing or invalid id'];
        }

        $rec = Recommendation::findOne($id);
        if (!$rec) {
            return ['ok' => false, 'error' => 'Recommendation not found'];
        }

        $body = Yii::$app->request->bodyParams;
        
        if (isset($body['status'])) {
            $rec->status = (int)$body['status'];
        }

        if (!$rec->save()) {
            return ['ok' => false, 'errors' => $rec->errors];
        }

        return [
            'ok' => true,
            'recommendation_id' => $rec->id,
        ];
    }

    /**
     * DELETE /index.php?r=recommendation/delete&id=123
     * Deletes a recommendation and its analysis
     */
    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = (int)Yii::$app->request->get('id', 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Missing or invalid id'];
        }

        $rec = Recommendation::findOne($id);
        if (!$rec) {
            return ['ok' => false, 'error' => 'Recommendation not found'];
        }

        // Delete analysis first (if exists)
        if ($rec->recommendationAnalysis) {
            $rec->recommendationAnalysis->delete();
        }

        // Delete recommendation
        if (!$rec->delete()) {
            return ['ok' => false, 'error' => 'Failed to delete recommendation'];
        }

        return [
            'ok' => true,
            'deleted_id' => $id,
        ];
    }

    /**
     * GET /index.php?r=recommendation/my-recommendations&member_id=1
     * Returns all recommendations for a specific member
     */
    public function actionMyRecommendations()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $memberId = (int)Yii::$app->request->get('member_id', 1);

        $recs = Recommendation::find()
            ->where(['member_id' => $memberId])
            ->asArray()
            ->all();

        $result = [];
        foreach ($recs as $rec) {
            $result[$rec['id']] = [
                'id' => $rec['id'],
                'text' => $rec['rec_description'],
            ];
        }

        return [
            'ok' => true,
            'recommendations' => $result,
        ];
    }
}