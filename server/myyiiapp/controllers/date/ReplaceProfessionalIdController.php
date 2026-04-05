<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\db\Exception;

class ReplaceProfessionalIdController extends Controller
{
    /**
     * שימוש:
     * php yii replace-professional-id/run <idtochange> <idthatchanges>
     *
     * idtochange     — היעד (ה-ID שיישאר לאחר ההחלפה)
     * idthatchanges  — המקור (ה-ID שיוחלף בכל הטבלאות)
     */
    public function actionRun(): int
    {
        $idToChange = 101472;
        $idThatChanges = 118698;

        if ($idToChange <= 0 || $idThatChanges <= 0) {
            echo "שגיאה: שני ה־ID חייבים להיות מספרים חיוביים.\n";
            return 1;
        }
        if ($idToChange === $idThatChanges) {
            echo "אין מה להחליף: שני ה־ID זהים.\n";
            return 0;
        }

        $db = Yii::$app->db;

        // מיפוי הטבלאות והעמודות לעדכון
        $targets = [
            ['professional',                    'id'], // PK
            ['professional_address',            'professional_id'],
            ['professional_company',            'professional_id'],
            ['professional_care',               'professional_id'],
            ['professional_categories',         'professional_id'],
            ['professional_expertise',          'professional_id'],
            ['professional_insurance',          'professional_id'],
            ['professional_language',           'professional_id'],
            ['professional_localities',         'professional_id'],
            ['professional_main_care',          'professional_id'],
            ['professional_main_specialization','professional_id'],
            ['professional_unions',             'professional_id'],
        ];

        $tx = $db->beginTransaction();
        try {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

            $existsTo    = (int)$db->createCommand('SELECT COUNT(*) FROM professional WHERE id = :id', [':id'=>$idToChange])->queryScalar();
            $existsFrom  = (int)$db->createCommand('SELECT COUNT(*) FROM professional WHERE id = :id', [':id'=>$idThatChanges])->queryScalar();

            if (!$existsFrom) {
                echo "אזהרה: ה־ID המקור ($idThatChanges) לא קיים ב־professional.\n";
            }

            foreach ($targets as [$table, $col]) {
                if ($table === 'professional') {
                    continue;
                }
                $sql = "UPDATE IGNORE `$table` SET `$col` = :to WHERE `$col` = :from";
                $count = $db->createCommand($sql, [':to'=>$idToChange, ':from'=>$idThatChanges])->execute();
                if ($count > 0) {
                    echo "עודכנו $count שורות ב־$table.$col\n";
                }
            }

            if ($existsTo && $existsFrom) {
                $deleted = $db->createCommand('DELETE FROM professional WHERE id = :id', [':id'=>$idThatChanges])->execute();
                echo "נמחקו $deleted שורות כפולות ב־professional עבור id=$idThatChanges\n";
            } elseif (!$existsTo && $existsFrom) {
                $updatedPk = $db->createCommand('UPDATE IGNORE professional SET id = :to WHERE id = :from', [
                    ':to'   => $idToChange,
                    ':from' => $idThatChanges
                ])->execute();
                if ($updatedPk > 0) {
                    echo "עודכן ה־PK ב־professional: $idThatChanges → $idToChange\n";
                } else {
                    echo "אזהרה: עדכון PK נכשל — יבוצע מסלול גיבוי.\n";
                    $db->createCommand('INSERT IGNORE INTO professional (id, full_name) SELECT :to, full_name FROM professional WHERE id = :from', [
                        ':to'=>$idToChange, ':from'=>$idThatChanges
                    ])->execute();
                    $db->createCommand('DELETE FROM professional WHERE id = :from', [':from'=>$idThatChanges])->execute();
                }
            }

            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
            $tx->commit();

            echo "הושלם בהצלחה.\n";
            return 0;
        } catch (Exception $e) {
            try { $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute(); } catch (\Throwable $t) {}
            $tx->rollBack();
            echo "שגיאה: {$e->getMessage()}\n";
            return 1;
        }
    }
}
