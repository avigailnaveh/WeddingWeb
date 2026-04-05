<?php

namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalMainCare;
use yii\db\Query;
use Yii;

class TransferSpecializationToExpertiseController extends Controller {

    public function actionTransferSpecializationToMainCare()
    {
        $mainSpecializationId = 55;
        $mainCareId = 58;

        // שליפת כל המקצוענים המקושרים ל-main_specialization_id
        $professionals = (new Query())
            ->select('professional_id')
            ->from('professional_main_specialization')
            ->where(['main_specialization_id' => $mainSpecializationId])
            ->all();

        if (empty($professionals)) {
            echo "לא נמצאו מקצוענים עם main_specialization_id: $mainSpecializationId \n";
            return;
        }

        foreach ($professionals as $professional) {
            $result = Yii::$app->db->createCommand()
                ->insert('professional_main_care', [
                    'professional_id' => $professional['professional_id'],
                    'main_care_id' => $mainCareId,
                ])->execute();

            if (!$result) {
                echo "שגיאה בשמירה של main_care למקצוען ID: " . $professional['professional_id'] ." \n";
            }
        }

        Yii::$app->db->createCommand()
            ->delete('professional_main_specialization', ['main_specialization_id' => $mainSpecializationId])
            ->execute();

        Yii::$app->db->createCommand()
            ->delete('main_specialization', ['id' => $mainSpecializationId])
            ->execute();

        echo " הקישורים הועברו בהצלחה מ־main_specialization ל־main_care והנתונים שנמחקו \n";
    }

    public function actionTransferExpertiseToCare()
    {
        $mainSpecializationId = 1412;
        $mainCareId = 149;

        // שליפת כל המקצוענים המקושרים ל-main_specialization_id
        $professionals = (new Query())
            ->select('professional_id')
            ->from('professional_expertise')
            ->where(['expertise_id' => $mainSpecializationId])
            ->all();

        if (empty($professionals)) {
           echo "לא נמצאו מקצוענים עם main_specialization_id: $mainSpecializationId  \n";
            return;
        }

        // חזור על כל המקצוענים והעבר אותם לטבלת professional_main_care
        foreach ($professionals as $professional) {
            $result = Yii::$app->db->createCommand()
                ->insert('professional_care', [
                    'professional_id' => $professional['professional_id'],
                    'care_id' => $mainCareId,
                ])->execute();

            if (!$result) {
               echo "שגיאה בשמירה של main_care למקצוען ID: " . $professional['professional_id'] ."\n";
            }
        }

        // מחיקת הקישורים ב-professional_main_specialization
        Yii::$app->db->createCommand()
            ->delete('professional_expertise', ['expertise_id' => $mainSpecializationId])
            ->execute();

        // מחיקת הרשומות בטבלת main_specialization
        Yii::$app->db->createCommand()
            ->delete('expertise', ['id' => $mainSpecializationId])
            ->execute();

        echo " הקישורים הועברו בהצלחה מ־main_specialization ל־main_care והנתונים שנמחקו \n";
    }

    public function actionTransferCareToExpertise()
    {
        $careId = 133;
        $expertiseId = 1413;

        // שליפת כל המקצוענים המקושרים ל-care_id
        $professionals = (new Query())
            ->select('professional_id')
            ->from('professional_care')
            ->where(['care_id' => $careId])
            ->all();

        if (empty($professionals)) {
            echo "לא נמצאו מקצוענים עם main_care_id: $careId  \n";
            return;
        }

        // חזור על כל המקצוענים והעבר אותם לטבלת professional_expertise (דלג על כפולים)
        foreach ($professionals as $professional) {
            $result = Yii::$app->db->createCommand(
                'INSERT IGNORE INTO professional_expertise (professional_id, expertise_id) 
                VALUES (:pid, :eid)',
                [':pid' => $professional['professional_id'], ':eid' => $expertiseId]
            )->execute();

            if ($result === false) {
                echo "שגיאה בשמירה של expertise למקצוען ID: " . $professional['professional_id'] . "\n";
            }
        }

        // מחיקת הקישורים ב-professional_care
        Yii::$app->db->createCommand()
            ->delete('professional_care', ['care_id' => $careId])
            ->execute();

        // מחיקת הרשומות בטבלת care
        Yii::$app->db->createCommand()
            ->delete('care', ['id' => $careId])
            ->execute();

        echo "הקישורים הועברו בהצלחה מ־care ל־expertise; כפילויות קיימות דולגו באמצעות INSERT IGNORE.\n";
    }


    public function actionTransferMainCareToSpecialization()
    {
        $MainCareId = 45;
        $MainSpecializationId = 58;

        // שליפת כל המקצוענים המקושרים ל-MainCareId
        $professionals = (new Query())
            ->select('professional_id')
            ->from('professional_main_care')
            ->where(['main_care_id' => $MainCareId])
            ->all();
        
        if (empty($professionals)) {
            echo "לא נמצאו מקצוענים עם MainCareId: $MainCareId \n";
            return;
        }

        foreach ($professionals as $professional) {
            // בדיקה אם הרשומה כבר קיימת בטבלת professional_expertise
            $exists = (new Query())
                ->from('professional_main_specialization')
                ->where([
                    'professional_id' => $professional['professional_id'],
                    'main_specialization_id' => $MainSpecializationId
                ])
                ->exists();

            if ($exists) {
                echo "הרשומה עבור מקצוען ID: " . $professional['professional_id'] . " כבר קיימת בטבלת professional_main_specialization, לא נוספה שוב. \n";
                continue; // המשך למקצוען הבא
            }

            // אם הרשומה לא קיימת, הכנס אותה
            $result = Yii::$app->db->createCommand()
                ->insert('professional_main_specialization', [
                    'professional_id' => $professional['professional_id'],
                    'main_specialization_id' => $MainSpecializationId,
                ])->execute();

            if (!$result) {
                echo "שגיאה בשמירה של main_care למקצוען ID: " . $professional['professional_id'] . " \n";
            }
        }

        // מחיקת הקישורים ב-professional_care
        Yii::$app->db->createCommand()
            ->delete('professional_main_care', ['main_care_id' => $MainCareId])
            ->execute();

        // מחיקת הרשומות בטבלת care
        Yii::$app->db->createCommand()
            ->delete('main_care', ['id' => $MainCareId])
            ->execute();

        echo "הקישורים הועברו בהצלחה מ־main_specialization ל־main_care והנתונים שנמחקו \n";
    }

}
