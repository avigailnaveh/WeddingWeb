<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\ProfessionalInsurance;

class UpdateInsuranceController extends Controller
{
    public function actionUpdateInsurance()
    {
        // התחלת טרנזקציה
        $transaction = Yii::$app->db->beginTransaction();

        try {
            // שליפת כל הרשומות מתוך טבלת professional_union שבהן הערך של unions_id הוא אחד מהערכים הרצויים
            $unions = Yii::$app->db->createCommand("
                SELECT professional_id, unions_id 
                FROM professional_unions 
                WHERE unions_id IN (17, 18, 19, 20)
            ")->queryAll();

            foreach ($unions as $union) {
                // בדיקת אם רשומה עם professional_id כבר קיימת בטבלת professional_insurance
                $existingInsurance = ProfessionalInsurance::find()
                    ->where(['professional_id' => $union['professional_id']])
                    ->one();

                // אם אין רשומה קיימת, צור רשומה חדשה
                if (!$existingInsurance) {
                    $insurance = new ProfessionalInsurance();
                    $insurance->professional_id = $union['professional_id'];

                    // קביעת insurance_id לפי unions_id
                    switch ($union['unions_id']) {
                        case 17:
                            $insurance->insurance_id = 1;
                            break;
                        case 18:
                            $insurance->insurance_id = 3;
                            break;
                        case 19:
                            $insurance->insurance_id = 6;
                            break;
                        case 20:
                            $insurance->insurance_id = 5;
                            break;
                    }

                    // שמירת הרשומה
                    if (!$insurance->save()) {
                        throw new \Exception("Error saving insurance for professional_id: " . $union['professional_id']);
                    }
                } else {
                    echo "רשומה עבור professional_id " . $union['professional_id'] . " כבר קיימת.\n";
                }
            }

            // התחייבות לטרנזקציה
            $transaction->commit();
            echo "העדכון בוצע בהצלחה.\n";
        } catch (\Exception $e) {
            // במקרה של שגיאה מבוצע rollback
            $transaction->rollBack();
            echo "התרחשה שגיאה: " . $e->getMessage() . "\n";
        }
    }
}



