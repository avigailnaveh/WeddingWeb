<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\Cors;
use app\controllers\member\ChatAction;
use app\models\Member;

class MemberController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => ['http://localhost:5173'],
                    'Access-Control-Request-Method' => ['POST', 'OPTIONS', 'GET'],
                    'Access-Control-Allow-Credentials' => true,
                    'Access-Control-Max-Age' => 86400,
                    'Access-Control-Allow-Headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        // טיפול מיוחד ב-OPTIONS request (preflight)
        if (Yii::$app->request->isOptions) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            Yii::$app->response->statusCode = 200;
            
            // הוספת headers ידנית
            Yii::$app->response->headers->set('Access-Control-Allow-Origin', 'http://localhost:5173');
            Yii::$app->response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS, GET');
            Yii::$app->response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            Yii::$app->response->headers->set('Access-Control-Allow-Credentials', 'true');
            Yii::$app->response->headers->set('Access-Control-Max-Age', '86400');
            
            // סיום מיידי
            Yii::$app->end();
        }
        
        return parent::beforeAction($action);
    }

    /**
     * Chat action - קורא ל-chatAction הקיים
     * 
     * חשוב: chatAction צריך Member object (מטבלת member), לא User object!
     */
    public function actionChat()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        // הוספת CORS headers
        Yii::$app->response->headers->set('Access-Control-Allow-Origin', 'http://localhost:5173');
        Yii::$app->response->headers->set('Access-Control-Allow-Credentials', 'true');
        
        Yii::info([
            'isGuest' => Yii::$app->user->isGuest,
            'identityClass' => Yii::$app->user->identity ? get_class(Yii::$app->user->identity) : null,
            'identityId' => Yii::$app->user->id,
            'cookies' => array_keys(Yii::$app->request->cookies->toArray()),
            'authHeader' => Yii::$app->request->headers->get('Authorization'),
        ], 'auth-debug');

        try {
            // chatAction צריך Member מה-database, לא User!
            // לצורך testing, נשתמש ב-Member הראשון במערכת
            $member = Member::find()->one();
            
            if (!$member) {
                Yii::$app->response->statusCode = 404;
                return [
                    'error' => 'no_member',
                    'response' => 'לא נמצא משתמש במערכת. אנא צור Member בטבלת member במסד הנתונים.'
                ];
            }
            
            Yii::info("Using member ID: {$member->id} for chat");
            
            // יצירת instance של ChatAction
            $chatAction = new ChatAction('chat', $this);
            $chatAction->member = $member;
            
            // הרצת ה-action
            $result = $chatAction->run();
            return $result;
            
        } catch (\Exception $e) {
            Yii::error("Error in chat action: " . $e->getMessage());
            Yii::error("Stack trace: " . $e->getTraceAsString());
            
            Yii::$app->response->statusCode = 500;
            return [
                'error' => 'server_error',
                'response' => 'אירעה שגיאה בשרת: ' . $e->getMessage(),
                'details' => YII_DEBUG ? [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ] : null
            ];
        }
    }

    public function actionMe()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

         $member = Member::find()
            ->select(['id','lat','lng'])
            ->asArray()
            ->one();

        return ['ok' => true, 'member' => $member];
    }


}