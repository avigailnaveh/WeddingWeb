<?php

namespace app\commands;

use Yii;
use yii\console\Controller;

class UpdateLicenseStatusController extends Controller
{
    public function actionIndex()
    {
        $db = Yii::$app->db;

        $rows = $db->createCommand("
            SELECT DISTINCT p.id
            FROM professional p
            INNER JOIN professional_unions pu ON pu.professional_id = p.id
            WHERE pu.unions_id = 1
        ")->queryAll();

        if (empty($rows)) {
            echo "לא נמצאו אנשי מקצוע עם unions_id = 1\n";
            return;
        }

        foreach ($rows as $row) {
            $db->createCommand()->update('professional', ['license_status' => 'לא בתוקף'], ['id' => $row['id']])->execute();
            echo "עודכן איש מקצוע ID: {$row['id']} ל־'לא בתוקף'\n";
        }

        echo "העדכון הסתיים בהצלחה.\n";
    }
}
