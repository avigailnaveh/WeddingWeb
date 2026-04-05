<?php

namespace app\commands;

use yii\console\Controller;
use app\models\ProfessionalAddress;
use Yii;
use yii\db\Query;

class MergeProfessionalsController extends Controller
{
    
    public function actionMerge()
    {
        // כאן תשני את ה־IDs שצריך לאחד
        // $ids = [138865,168703];
        $ids = [126814,148053];

        if (count($ids) < 2) {
            echo "חייבים לפחות שני IDs למיזוג.\n";
            return;
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            // וידוא שכל ה-IDs קיימים
            $existingIds = (new Query())
                ->select('id')
                ->from('professional')
                ->where(['IN', 'id', $ids])
                ->column();
            
            if (count($existingIds) !== count($ids)) {
                $missing = array_diff($ids, $existingIds);
                echo "שגיאה: IDs לא קיימים: " . implode(',', $missing) . "\n";
                return;
            }

            // קביעת ה-ID המועדף
            $mainId = $this->getPreferredProfessionalId($ids);
            $duplicateIds = array_diff($ids, [$mainId]);

            echo "ID ראשי שנבחר: {$mainId}\n";
            echo "IDs למחיקה: " . implode(',', $duplicateIds) . "\n";


            // רשימת הטבלאות שצריך לעדכן
            $tablesToUpdate = [
                'professional_address'            => ['unique' => [
                    'city',
                    'street',
                    'house_number',
                    'type',
                    'phone',
                    'phone_2',
                    'phone_3',
                    'phone_4',
                    'mobile',
                    'lat',
                    'lng',
                    'street_google',
                    'number_house_google',
                    'city_google',
                    'neighborhood_google',
                    'type_location_google',
                    'display_address'
                ]],
                'professional_care'               => ['unique' => ['care_id']],
                'professional_company'            => ['unique' => ['company_id']],
                'professional_expertise'          => ['unique' => ['expertise_id']],
                'professional_insurance'          => ['unique' => ['insurance_id']],
                'professional_language'           => ['unique' => ['language_id']],
                'professional_localities'         => ['unique' => ['localities_id']],
                'professional_main_care'          => ['unique' => ['main_care_id']],
                'professional_main_specialization'=> ['unique' => ['main_specialization_id']],
                'professional_unions'             => ['unique' => ['unions_id']],
            ];

            foreach ($tablesToUpdate as $table => $meta) {
                echo "מעבד טבלה: {$table}\n";

                // שלב 0: מחיקת כפילויות פוטנציאליות לפני העדכון כדי למנוע שגיאת UNIQUE
                if (!empty($meta['unique'])) {
                    $deleted = $this->deletePreExistingDuplicates($table, $meta['unique'], $mainId, $duplicateIds);
                    if ($deleted > 0) {
                        echo "  - נמחקו {$deleted} רשומות כפולות מראש\n";
                    }
                }

                // שלב 1: עדכון כל הרשומות של הדופליקטים ל-mainId
                $updated = $db->createCommand()
                    ->update($table, ['professional_id' => $mainId], ['IN', 'professional_id', $duplicateIds])
                    ->execute();
                
                if ($updated > 0) {
                    echo "  - עודכנו {$updated} רשומות\n";
                }

                // שלב 2: מחיקת כפילויות אם נשארו לאחר המיזוג
                if (!empty($meta['unique'])) {
                    $removed = $this->removeDuplicates($table, $meta['unique'], $mainId);
                    if ($removed > 0) {
                        echo "  - נמחקו {$removed} כפילויות לאחר המיזוג\n";
                    }
                }
            }

            // מחיקת הרשומות הכפולות מטבלת professional
            $db->createCommand()
                ->delete('professional', ['IN', 'id', $duplicateIds])
                ->execute();

            $transaction->commit();
            echo "\n✓ בוצע מיזוג בהצלחה: נשמר ID {$mainId} ומחקנו את: " . implode(',', $duplicateIds) . "\n";

        } catch (\Exception $e) {
            $transaction->rollBack();
            echo "\n✗ שגיאה במיזוג: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
        }
    }


    protected function getPreferredProfessionalId(array $ids)
    {
        // ספירת המלצות לכל professional
        $recommendations = (new Query())
            ->select(['professional_id', 'cnt' => 'COUNT(*)'])
            ->from('recommendation')
            ->where(['IN', 'professional_id', $ids])
            ->groupBy('professional_id')
            ->orderBy(['cnt' => SORT_DESC, 'professional_id' => SORT_ASC])
            ->all();

        // אם יש המלצות, נבחר את זה עם הכי הרבה המלצות
        if (!empty($recommendations)) {
            return (int)$recommendations[0]['professional_id'];
        }

        // אם אין המלצות, נבחר את הראשון ברשימה
        return reset($ids);
    }

    protected function removeDuplicates(string $table, array $uniqueColumns, int $mainId)
    {
        $db = Yii::$app->db;
        $totalDeleted = 0;

        // קיבוץ לפי professional_id + עמודות ייחודיות
        $groupCols = array_merge(['professional_id'], $uniqueColumns);

        $duplicates = (new Query())
            ->select([
                'keep_id' => 'MIN(id)',
                'ids'     => 'GROUP_CONCAT(id)',
                'cnt'     => 'COUNT(*)'
            ])
            ->from($table)
            ->where(['professional_id' => $mainId])
            ->groupBy($groupCols)
            ->having(['>', 'cnt', 1])
            ->all();

        foreach ($duplicates as $dup) {
            $allIds = explode(',', $dup['ids']);
            $keepId = $dup['keep_id'];
            $toDelete = array_diff($allIds, [$keepId]);
            
            if (!empty($toDelete)) {
                $db->createCommand()
                    ->delete($table, ['IN', 'id', $toDelete])
                    ->execute();
                $totalDeleted += count($toDelete);
            }
        }

        return $totalDeleted;
    }


    protected function deletePreExistingDuplicates(string $table, array $uniqueColumns, int $mainId, array $duplicateIds)
    {
        $db = Yii::$app->db;
        $totalDeleted = 0;

        // שליפת כל השורות הקיימות של ה-mainId
        $existing = (new Query())
            ->select($uniqueColumns)
            ->from($table)
            ->where(['professional_id' => $mainId])
            ->all();

        if (empty($existing)) {
            return 0;
        }

        // עבור כל שורה קיימת ב-mainId, מוחק שורות זהות אצל ה-duplicateIds
        foreach ($existing as $row) {
            // בניית תנאי WHERE מורכב
            $where = ['AND', ['IN', 'professional_id', $duplicateIds]];
            
            foreach ($uniqueColumns as $col) {
                if ($row[$col] === null) {
                    // טיפול ב-NULL
                    $where[] = ['IS', $col, null];
                } else {
                    $where[] = [$col => $row[$col]];
                }
            }
            
            $deleted = $db->createCommand()
                ->delete($table, $where)
                ->execute();
            
            $totalDeleted += $deleted;
        }

        return $totalDeleted;
    }
    
    
    /**
     * מיזוג מרובה מקובץ CSV
     * שימוש: php yii merge-professionals/merge-from-csv path/to/file.csv
     */
    public function actionMergeFromCsv($csvPath = 'grouped_ids_by_group_id_v3.csv')
    {
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            echo "קובץ לא נמצא או לא קריא: {$csvPath}\n";
            return 1;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            echo "לא ניתן לפתוח את הקובץ לקריאה\n";
            return 1;
        }

        // דילוג על הכותרות
        $headers = fgetcsv($handle);
        $lineNumber = 1;
        $successCount = 0;
        $errorCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            $dupGroupId = $row[0] ?? null;
            $idsString  = $row[1] ?? null;

            if ($idsString === null) {
                echo "שורה {$lineNumber}: חסרה עמודת IDs עבור dup_group_id={$dupGroupId}\n";
                $errorCount++;
                continue;
            }

            $ids = json_decode($idsString, true);
            if (!is_array($ids) || empty($ids)) {
                echo "שורה {$lineNumber}: שגיאה בהמרת מערך בשורה עם dup_group_id={$dupGroupId}\n";
                $errorCount++;
                continue;
            }

            // המרת מחרוזות למספרים והסרת כפילויות מקדימות
            $ids = array_values(array_unique(array_map('intval', $ids)));

            echo "\n--- שורה {$lineNumber}: מעבד קבוצת dup_group_id={$dupGroupId} עם IDs: " . implode(',', $ids) . " ---\n";

            $result = $this->mergeProfessionals($ids);
            if ($result) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        fclose($handle);
        
        echo "\n===========================================\n";
        echo "סיכום:\n";
        echo "הצלחות: {$successCount}\n";
        echo "שגיאות: {$errorCount}\n";
        echo "===========================================\n";
        
        return 0;
    }

    /**
     * מיזוג רשימת IDs
     * @return bool האם המיזוג הצליח
     */
    private function mergeProfessionals(array $ids): bool
    {
        if (count($ids) < 2) {
            echo "חייבים לפחות שני IDs למיזוג.\n";
            return false;
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            // וידוא שכל ה-IDs קיימים
            $existingIds = (new Query())
                ->select('id')
                ->from('professional')
                ->where(['IN', 'id', $ids])
                ->column();
            
            $missingIds = array_diff($ids, $existingIds);
            if (!empty($missingIds)) {
                echo "אזהרה: IDs לא קיימים (מדלג): " . implode(',', $missingIds) . "\n";
                $ids = array_values(array_intersect($ids, $existingIds));
                
                if (count($ids) < 2) {
                    echo "לא נשארו מספיק IDs תקינים למיזוג.\n";
                    $transaction->rollBack();
                    return false;
                }
            }

            // קביעת ה-ID המועדף לפי מספר ההמלצות (ואם אין – הראשון)
            $mainId = $this->getPreferredProfessionalId($ids);
            $duplicateIds = array_values(array_diff($ids, [$mainId]));

            if (empty($duplicateIds)) {
                echo "אין מה למזג – נשאר רק ID אחד.\n";
                $transaction->commit();
                return true;
            }

            echo "ID ראשי: {$mainId}, למחיקה: " . implode(',', $duplicateIds) . "\n";

            // טבלאות לעדכון + עמודות שמגדירות ייחודיות כדי למחוק כפילויות
            $tablesToUpdate = [
                'professional_address'             => ['unique' => [
                    'city',
                    'street',
                    'house_number',
                    'type',
                    'phone',
                    'phone_2',
                    'phone_3',
                    'phone_4',
                    'mobile',
                    'lat',
                    'lng',
                    'street_google',
                    'number_house_google',
                    'city_google',
                    'neighborhood_google',
                    'type_location_google',
                    'display_address'
                ]],
                'professional_care'                => ['unique' => ['care_id']],
                'professional_company'             => ['unique' => ['company_id']],
                'professional_expertise'           => ['unique' => ['expertise_id']],
                'professional_insurance'           => ['unique' => ['insurance_id']],
                'professional_language'            => ['unique' => ['language_id']],
                'professional_localities'          => ['unique' => ['localities_id']],
                'professional_main_care'           => ['unique' => ['main_care_id']],
                'professional_main_specialization' => ['unique' => ['main_specialization_id']],
                'professional_unions'              => ['unique' => ['unions_id']],
            ];

            foreach ($tablesToUpdate as $table => $meta) {
                $uniqueCols = $meta['unique'] ?? [];

                // שלב 0: מחיקת כפילויות פוטנציאליות מראש כדי למנוע שגיאת UNIQUE בזמן העדכון
                if (!empty($uniqueCols)) {
                    $this->deletePreExistingDuplicates($table, $uniqueCols, $mainId, $duplicateIds);
                }

                // שלב 1: עדכון כל הרשומות של ה-duplicateIds ל-mainId
                $db->createCommand()
                    ->update($table, ['professional_id' => $mainId], ['IN', 'professional_id', $duplicateIds])
                    ->execute();

                // שלב 2: מחיקת כפילויות שנשארו לאחר המיזוג (שימור MIN(id))
                if (!empty($uniqueCols)) {
                    $this->removeDuplicates($table, $uniqueCols, $mainId);
                }
            }

            // מחיקת הרשומות הכפולות מטבלת professional
            $db->createCommand()
                ->delete('professional', ['IN', 'id', $duplicateIds])
                ->execute();

            $transaction->commit();
            echo "✓ בוצע מיזוג: נשמר ID {$mainId} ומחקנו את: " . implode(',', $duplicateIds) . "\n";
            return true;

        } catch (\Throwable $e) {
            $transaction->rollBack();
            echo "✗ שגיאה במיזוג: " . $e->getMessage() . "\n";
            return false;
        }
    }
  
}