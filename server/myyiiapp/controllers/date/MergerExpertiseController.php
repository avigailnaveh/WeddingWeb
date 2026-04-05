<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Transaction;
use yii\db\Query;


class MergerExpertiseController extends Controller
{
    /**
     * איחוד התמחויות מקונסול:
     * הרצה:
     * php yii merger-expertise/merge
     */
    public function actionMerge()
    {
        $keepId   = 484;
        $mergeIds = [552];

        $mergeIds = array_map('intval', (array)$mergeIds);
        $mergeIds = array_unique(array_diff($mergeIds, [$keepId]));

        if (empty($mergeIds)) {
            $this->stderr("No merge IDs provided.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction(Transaction::SERIALIZABLE);

        try {
            // לבדוק שההתמחות הראשית קיימת
            $keepCare = $db->createCommand(
                'SELECT * FROM care WHERE id = :id',
                [':id' => $keepId]
            )->queryOne();

            if ($keepCare === false) {
                throw new \RuntimeException("care with id {$keepId} not found");
            }

            // מצא את כל המקצוענים הייחודיים שמקושרים לכל ההתמחויות
            $allCareIds = array_merge([$keepId], $mergeIds);
            $uniqueProfessionals = $db->createCommand(
                'SELECT DISTINCT professional_id FROM professional_care 
                WHERE care_id IN (' . implode(',', $allCareIds) . ')'
            )->queryColumn();

            $this->stdout("Found " . count($uniqueProfessionals) . " unique professionals.\n");

            // מחק את כל הקשרים של ההתמחויות למיזוג
            $deletedLinks = $db->createCommand()->delete('professional_care', [
                'care_id' => $mergeIds
            ])->execute();

            $this->stdout("Deleted {$deletedLinks} old links.\n");

            // הוסף רק קשרים ייחודיים ל-keepId (אם לא קיימים)
            $addedCount = 0;
            foreach ($uniqueProfessionals as $profId) {
                $exists = $db->createCommand(
                    'SELECT COUNT(*) FROM professional_care 
                    WHERE professional_id = :prof AND care_id = :care',
                    [':prof' => $profId, ':care' => $keepId]
                )->queryScalar();
                
                if ($exists == 0) {
                    $db->createCommand()->insert('professional_care', [
                        'professional_id' => $profId,
                        'care_id' => $keepId
                    ])->execute();
                    $addedCount++;
                }
            }

            $this->stdout("Added {$addedCount} new links to are {$keepId}.\n");

            // מחק את ההתמחויות שאוחדו
            foreach ($mergeIds as $mergeId) {
                $mergeCare = $db->createCommand(
                    'SELECT * FROM care WHERE id = :id',
                    [':id' => $mergeId]
                )->queryOne();

                if ($mergeCare !== false) {
                    $db->createCommand()->delete('care', ['id' => $mergeId])->execute();
                    $this->stdout("Deleted care {$mergeId}.\n");
                } else {
                    $this->stdout("care {$mergeId} not found, skipping delete.\n");
                }
            }

            $transaction->commit();

            $this->stdout("Merge completed successfully.\n");
            return ExitCode::OK;

        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr("ERROR: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }


    public function actionImportCare()
    {

        $filePath = Yii::getAlias('@app/web/uploads/professional_hebpsy_filled.csv');

        echo "start..\n";
        if (!file_exists($filePath)) {
            echo "File not found\n";
            return "File not found: {$filePath}";
        }

        $db = Yii::$app->db;

        // מיפוי של care.name => care.id
        $careMap = (new Query())
            ->select(['id', 'name'])
            ->from('care')
            ->indexBy('name')
            ->column();

        if (empty($careMap)) {
            echo "No cares found in table `care`\n";
            return "No cares found in table `care`";
        }

        // care_id שצריך להוסיף לכולם פעם אחת (פסיכולוגיה)
        $defaultCareId = 77;

        if (($handle = fopen($filePath, 'r')) === false) {
            echo "Cannot open file\n";
            return "Cannot open file: {$filePath}";
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            echo "CEmpty CSV file\n";
            return "Empty CSV file";
        }

        // מפה: שם עמודה => אינדקס
        $headerMap = [];
        foreach ($header as $idx => $name) {
            $name = trim($name);
            if ($name !== '') {
                $headerMap[$name] = $idx;
            }
        }

        if (!isset($headerMap['id']) || !isset($headerMap['care'])) {
            fclose($handle);
            echo "CSV must contain columns: professional_id, care";
            return "CSV must contain columns: professional_id, care";
        }

        $transaction = $db->beginTransaction();

        $rowsCount = 0;
        $insertedCare = 0;
        $insertedMainCare = 0;
        $insertedCare77 = 0;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowsCount++;

                $professionalId = (int)$row[$headerMap['id']];
                $careField      = trim((string)$row[$headerMap['care']]);

                if (!$professionalId) {
                    continue;
                }

                // 1) קישור לטבלת professional_main_care (main_care_id = 3) אם לא קיים
                $existsMain = (new Query())
                    ->from('professional_main_care')
                    ->where([
                        'professional_id' => $professionalId,
                        'main_care_id'    => 3,
                    ])
                    ->exists();

                if (!$existsMain) {
                    $db->createCommand()->insert('professional_main_care', [
                        'professional_id' => $professionalId,
                        'main_care_id'    => 3,
                    ])->execute();
                    $insertedMainCare++;
                }

                // 2) קישורים לטבלת professional_care לפי ה-care בקובץ (מופרד ב-|)
                if ($careField !== '') {
                    $careNames = array_filter(array_map('trim', explode('|', $careField)));

                    foreach ($careNames as $careName) {
                        if ($careName === '') {
                            continue;
                        }

                        if (!isset($careMap[$careName])) {
                            // אם השם לא קיים בטבלת care – מדלגים
                            continue;
                        }

                        $careId = (int)$careMap[$careName];

                        $existsCare = (new Query())
                            ->from('professional_care')
                            ->where([
                                'professional_id' => $professionalId,
                                'care_id'         => $careId,
                            ])
                            ->exists();

                        if (!$existsCare) {
                            $db->createCommand()->insert('professional_care', [
                                'professional_id' => $professionalId,
                                'care_id'         => $careId,
                            ])->execute();
                            $insertedCare++;
                        }
                    }
                }

                // 3) לוודא שלכל מי שבקובץ יש גם קישור ל-care_id = 77 פעם אחת בלבד
                $existsCare77 = (new Query())
                    ->from('professional_care')
                    ->where([
                        'professional_id' => $professionalId,
                        'care_id'         => $defaultCareId,
                    ])
                    ->exists();

                if (!$existsCare77) {
                    $db->createCommand()->insert('professional_care', [
                        'professional_id' => $professionalId,
                        'care_id'         => $defaultCareId,
                    ])->execute();
                    $insertedCare77++;
                }
            }

            fclose($handle);
            $transaction->commit();
        } catch (\Throwable $e) {
            fclose($handle);
            $transaction->rollBack();
            // בשביל דיבאג:
            echo "Error: " . $e->getMessage() . "\n";
            return "Error: " . $e->getMessage();
        }
        echo "Done. Rows: {$rowsCount}, new professional_care: {$insertedCare}, new main_care(3): {$insertedMainCare}, new care_id=77: {$insertedCare77}\n";
    }

}