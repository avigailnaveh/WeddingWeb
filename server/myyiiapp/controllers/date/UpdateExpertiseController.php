<?php

namespace app\commands;

use Yii;
use yii\db\Query;
use yii\console\Controller;
use app\models\ProfessionalExpertise;
use app\models\Professional;


class UpdateExpertiseController extends Controller
{
    public function actionIndex()
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $pediatricProfessionalIds = ProfessionalExpertise::find()
                ->select('professional_id')
                ->where(['expertise_id' => 1402])
                ->column();

            $professionalsWithDuplicates = (new Query())
                ->select('professional_id')
                ->from('professional_expertise')
                ->where(['in', 'expertise_id', [991, 1402, 1400]])
                ->groupBy('professional_id')
                ->having('COUNT(DISTINCT expertise_id) > 1')
                ->column();

            echo "נמצאו " . count($professionalsWithDuplicates) . " professionals עם duplicates פוטנציאליים.\n";

            $deletedDuplicates = 0;
            $insertedNew = 0;

            foreach ($professionalsWithDuplicates as $professionalId) {
                $has1400 = ProfessionalExpertise::find()
                    ->where(['professional_id' => $professionalId, 'expertise_id' => 1400])
                    ->exists();

                $deleted = ProfessionalExpertise::deleteAll([
                    'professional_id' => $professionalId,
                    'expertise_id' => [991, 1402]
                ]);
                $deletedDuplicates += $deleted;

                if (!$has1400) {
                    $newRecord = new ProfessionalExpertise();
                    $newRecord->professional_id = $professionalId;
                    $newRecord->expertise_id = 1400;
                    if ($newRecord->save(false)) {
                        $insertedNew++;
                    }
                }
            }

            $remainingUpdates = ProfessionalExpertise::updateAll(
                ['expertise_id' => 1400],
                [
                    'and',
                    ['in', 'expertise_id', [991, 1402]],
                    ['not in', 'professional_id', $professionalsWithDuplicates]
                ]
            );

            $updatedPediatric = 0;
            if (!empty($pediatricProfessionalIds)) {
                $updatedPediatric = Professional::updateAll(
                    ['is_pediatric' => 1],
                    ['in', 'id', $pediatricProfessionalIds]
                );
            }

            $added948 = 0;
            foreach ($pediatricProfessionalIds as $professionalId) {
                $has948 = ProfessionalExpertise::find()
                    ->where(['professional_id' => $professionalId, 'expertise_id' => 948])
                    ->exists();

                if (!$has948) {
                    $newRecord = new ProfessionalExpertise();
                    $newRecord->professional_id = $professionalId;
                    $newRecord->expertise_id = 948;
                    if ($newRecord->save(false)) {
                        $added948++;
                    }
                }
            }

            $transaction->commit();

            echo "נמחקו $deletedDuplicates רשומות duplicate.\n";
            echo "נוצרו $insertedNew רשומות חדשות עם expertise_id = 1400.\n";
            echo "עודכנו $remainingUpdates רשומות נוספות ב-professional_expertise.\n";
            echo "עודכנו $updatedPediatric רשומות ב-professional (is_pediatric).\n";
            echo "נוספו $added948 רשומות חדשות עם expertise_id = 948.\n";
            echo "הפעולה הושלמה בהצלחה!\n";
        } catch (\Exception $e) {
            $transaction->rollBack();
            echo "שגיאה: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function actionAddMainCare()
    {
        $careIds = [236];
        echo "Start...\n";
        
        $professionals = (new Query())
            ->select('professional_id')
            ->from('professional_care')
            ->where(['care_id' => $careIds])
            ->groupBy('professional_id')
            ->column();
        
        echo "Found " . count($professionals) . " professionals...\n";
        
        $existingProfessionals = (new Query())
            ->select('professional_id')
            ->from('professional_main_care')
            ->where([
                'professional_id' => $professionals,
                'main_care_id' => 3
            ])
            ->column();
        
        $toAdd = array_diff($professionals, $existingProfessionals);
        
        if (!empty($toAdd)) {
            $rows = array_map(function($pid) {
                return [$pid, 3];
            }, $toAdd);
            
            Yii::$app->db->createCommand()
                ->batchInsert('professional_main_care', 
                    ['professional_id', 'main_care_id'], 
                    $rows)
                ->execute();
        }
        
        echo "Done. Added " . count($toAdd) . " new rows.\n";
    }
}
