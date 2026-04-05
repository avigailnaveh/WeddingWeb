<?php

namespace app\commands;

use Yii;
use yii\console\Controller;

class UpdateGenderDulotController extends Controller
{
    public function actionCare314(): int
    {
        $rows = Yii::$app->db->createCommand("
            UPDATE professional p
            INNER JOIN professional_care pc ON pc.professional_id = p.id
            SET p.gender = 2
            WHERE pc.care_id = 314
        ")->execute();

        $this->stdout("Updated {$rows} professionals.\n");
        return 0;
    }
}
