<?php

namespace app\commands;

use yii\console\Controller;
use yii\db\Query;
use Yii;
use app\models\Professional;

class UpdateGenderController extends Controller
{

    public function actionUpdateGender()
    {
        $careId = 135;

        $rows = (new \yii\db\Query())
            ->select(['professional_id'])
            ->from('professional_care')
            ->where(['care_id' => $careId])
            ->all();

        if (empty($rows)) {
            echo "לא נמצאו רופאים המקושרים ל-care_id 135.\n";
            return;
        }
        $count =0;

        foreach ($rows as $row) {
            $professionalId = $row['professional_id'];
            $professional = \app\models\Professional::findOne($professionalId);

            if (!$professional) {
                echo "Professional ID {$professionalId} לא נמצא.\n";
                continue;
            }

            if ($professional->gender === null || $professional->gender === '' ) {
                $count++;
                
                $professional->gender = 2;

                if ($professional->save(false, ['gender'])) {
                    echo "עודכן gender = 2 עבור Professional ID {$professionalId}\n";
                } else {
                    echo "שגיאה בעדכון Professional ID {$professionalId}\n";
                }
            } else {
                echo "Professional ID {$professionalId} כבר עם gender מוגדר, דילוג.\n";
            }
        }
        echo $count . "\n";

        echo "סיום העדכונים.\n";
    }
}