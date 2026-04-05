<?php

namespace app\commands;

use yii\console\Controller;
use app\models\Professional;
use Yii;

class UpdateFullNameController extends Controller
{
    public function actionUpdateFullName()
    {
        // קבלת כל הרשומות מהמודל שלך
        $models = Professional::find()->where(['full_name' => null])->all();

        foreach ($models as $model) {
            // אם full_name NULL, תחבר את first_name ו-last_name
            if ($model->first_name && $model->last_name) {
                $model->full_name = $model->first_name . ' ' . $model->last_name;
                // שמירה של השם המלא החדש
                if ($model->save()) {
                    echo "השם המלא עבור {$model->id} עודכן בהצלחה\n";
                } else {
                    echo "שגיאה בעדכון השם המלא עבור {$model->id}\n";
                }
            }
        }
    }
}
