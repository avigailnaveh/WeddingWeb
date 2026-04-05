<?php  

namespace app\commands;

use Yii;
use yii\db\Query;
use yii\console\Controller;
use app\models\Professional;
use app\models\ProfessionalLanguage;
use app\models\ProfessionalExpertise;
use app\models\ProfessionalCompany;
use app\models\ProfessionalAddress;
use app\models\ProfessionalInsurance;

class MergeController extends Controller
{
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function isNameMatchByFullName($professional, $matchedProfessional)
    {
        if (empty($professional->full_name)) {
            $professional->full_name = $professional->first_name . ' ' . $professional->last_name;
        }

        if (empty($matchedProfessional->full_name)) {
            $matchedProfessional->full_name = $matchedProfessional->first_name . ' ' . $matchedProfessional->last_name;
        }

        $professionalArray = preg_split('/\s+/', trim($professional->full_name ?? ''));
        $matchedProfessionalArray = preg_split('/\s+/', trim($matchedProfessional->full_name));

        $professionalArray = array_map([$this, 'normalize'], $professionalArray);
        $matchedProfessionalArray = array_map([$this, 'normalize'], $matchedProfessionalArray);

        print_r($professionalArray);
        print_r($matchedProfessionalArray);
        // בדוק אם כל חלק מהשם המקצועי נמצא בשני
        $matches = 0;

        foreach ($professionalArray as $profPart) {
            if (in_array($profPart, $matchedProfessionalArray)) {
                $matches++;
            }
        }

        // אם יש לפחות שני חלקים תואמים
        return $matches >= 2;
    }



    public function actionImportMerge()
    {
        echo "Starting professional merge process...\n";
        
        $professionals = Professional::find()->all(); 
        $matchingProfessionals = [];
        $processedCount = 0;
        $mergedCount = 0;

        foreach ($professionals as $professional) {
            $processedCount++;
            
            if (empty($professional->license_id)) {
                continue;
            }

            if (empty($professional->full_name)) {
                $professional->full_name = $professional->first_name . ' ' . $professional->last_name;
            }

            $matchingProfessionalsByLicense = Professional::find()
                ->where(['or', 
                    ['license_id' => $professional->license_id],
                    ['license_id_v1' => $professional->license_id],
                    ['license_id_v2' => $professional->license_id]
                ])
                ->andWhere(['!=', 'id', $professional->id])
                ->all();


            $matchedRecords = [];

            foreach ($matchingProfessionalsByLicense as $matchedProfessional) {
                echo "Checking if names match between professional ID {$professional->id} and matched professional ID {$matchedProfessional->id}...\n";
                if (!empty($matchedProfessional->license_id) && $this->isNameMatchByFullName($professional, $matchedProfessional)) {
                    echo "Match found between professional ID {$professional->id} and matched professional ID {$matchedProfessional->id}.\n";
                    $matchedRecords[] = $matchedProfessional;
                }
            }

            if (!empty($matchedRecords)) {
                // Update information from matched records
                foreach ($matchedRecords as $matchedProfessional) {
                    $professional->full_name = $professional->full_name ?: $matchedProfessional->full_name;
                    $professional->license_id = $professional->license_id ?: $matchedProfessional->license_id;
                    break; // Stop after the first update
                }

                // Merge professional details
                $this->mergeProfessionalDetails($professional, $matchedRecords);
                $professional->save();

                // Delete matched duplicate records
                foreach ($matchedRecords as $matchedProfessional) {
                    $matchedProfessional->delete();
                }

                $mergedCount++;
                echo "Merged professional ID {$professional->id} with " . count($matchedRecords) . " duplicates\n";

                $matchingProfessionals[] = [
                    'professional' => $professional,
                    'matchedProfessionals' => $matchedRecords
                ];
            }
            
            if ($processedCount % 100 == 0) {
                echo "Processed {$processedCount} professionals...\n";
            }
        }

        echo "Merge process completed!\n";
        echo "Total professionals processed: {$processedCount}\n";
        echo "Total merges performed: {$mergedCount}\n";
        
        return 0; // Success exit code
    }

    private function mergeProfessionalDetails($professional, $matchedRecords)
    {
        $this->mergeUniqueRecords($professional, $matchedRecords, 'ProfessionalLanguage', 'professional_id');
        $this->mergeUniqueRecords($professional, $matchedRecords, 'ProfessionalExpertise', 'professional_id');
        $this->mergeUniqueRecords($professional, $matchedRecords, 'ProfessionalCompany', 'professional_id');
        $this->mergeUniqueRecords($professional, $matchedRecords, 'ProfessionalAddress', 'professional_id');
        $this->mergeUniqueRecords($professional, $matchedRecords, 'ProfessionalInsurance', 'professional_id');
    }

    private function mergeUniqueRecords($professional, $matchedRecords, $modelName, $relationField)
    {
        if ($modelName == 'ProfessionalCategory') {
            return; // Skip ProfessionalCategory
        }

        $modelClass = "app\\models\\{$modelName}";
        $existingRecords = $modelClass::find()->where([$relationField => $professional->id])->all();

        foreach ($matchedRecords as $matchedProfessional) {
            $matchedRecordArray = $modelClass::find()->where([$relationField => $matchedProfessional->id])->all();

            foreach ($matchedRecordArray as $matchedRecord) {
                // Debugging: check if expertise_id is available
                echo "Checking expertise_id for record: {$matchedRecord->id} in model {$modelName}\n";
                if (property_exists($matchedRecord, 'expertise_id')) {
                    echo "expertise_id found: {$matchedRecord->expertise_id}\n";
                } else {
                    echo "expertise_id NOT found for record: {$matchedRecord->id}\n";
                }

                // Check for duplicates in the existing records
                if (!$this->isDuplicateRecord($existingRecords, $matchedRecord, $modelName)) {
                    // No duplicate, update values
                    $matchedRecord->{$relationField} = $professional->id;

                    if ($modelName == 'ProfessionalExpertise' && !empty($matchedRecord->expertise_id)) {
                        $existingRecordCheck = $modelClass::find()
                            ->where(['professional_id' => $professional->id])
                            ->andWhere(['expertise_id' => $matchedRecord->expertise_id])
                            ->exists();

                        if ($existingRecordCheck) {
                            echo "Duplicate entry found for expertise ID {$matchedRecord->expertise_id} and professional ID {$professional->id}. Skipping update.\n";
                            continue; // Skip update if record exists
                        }
                    }

                    if (!$matchedRecord->save()) {
                        echo "Warning: Could not save {$modelName} record ID {$matchedRecord->id}: " . json_encode($matchedRecord->errors) . "\n";
                    }
                } else {
                    // Record already exists, delete duplicate
                    $matchedRecord->delete();
                    echo "Deleted duplicate {$modelName} record ID {$matchedRecord->id}\n";
                }
            }
        }
    }


    private function isDuplicateRecord($existingRecords, $matchedRecord, $modelName)
    {
        switch ($modelName) {
            case 'ProfessionalLanguage':
                foreach ($existingRecords as $existingRecord) {
                    if ($existingRecord->language_id == $matchedRecord->language_id) {
                        return true;
                    }
                }
                break;
                
            case 'ProfessionalExpertise':
                // Check for duplicates based on expertise_id
                foreach ($existingRecords as $existingRecord) {
                    if ($existingRecord->expertise_id == $matchedRecord->expertise_id) {
                        return true;
                    }
                }
                break;
                
            case 'ProfessionalCompany':
                foreach ($existingRecords as $existingRecord) {
                    if ($existingRecord->company_id == $matchedRecord->company_id) {
                        return true;
                    }
                }
                break;
                
            case 'ProfessionalAddress':
                // Check for duplicates based on address fields
                foreach ($existingRecords as $existingRecord) {
                    if ($existingRecord->city == $matchedRecord->city &&
                        $existingRecord->house_number == $matchedRecord->house_number &&
                        $existingRecord->street == $matchedRecord->street &&
                        $existingRecord->clinic_id == $matchedRecord->clinic_id &&
                        $existingRecord->type == $matchedRecord->type &&
                        $existingRecord->phone == $matchedRecord->phone &&
                        $existingRecord->phone_2 == $matchedRecord->phone_2 &&
                        $existingRecord->phone_3 == $matchedRecord->phone_3 &&
                        $existingRecord->phone_4 == $matchedRecord->phone_4 &&
                        $existingRecord->mobile == $matchedRecord->mobile) {
                        return true;
                    }
                }
                break;
                
            case 'ProfessionalInsurance':
                foreach ($existingRecords as $existingRecord) {
                    if ($existingRecord->insurance_id == $matchedRecord->insurance_id) {
                        return true;
                    }
                }
                break;
        }
        
        return false;
    }

    private function updateExistingRecord($professional, $matchedRecord, $modelName)
    {
        if ($modelName == 'ProfessionalLanguage') {
            if (empty($matchedRecord->language)) {
                $matchedRecord->language = $professional->language;
            }
            $matchedRecord->save();
        }
    }
    public function actionMergeSpecialtiesToCare()
    {
        $db = Yii::$app->db;

        // שלב 1: הבאת כל ההתמחויות משתי הטבלאות
        $stSpecialties = (new \yii\db\Query())->from('st_specialties')->all();
        $care = (new \yii\db\Query())->from('care')->all();

        // שלב 2: יצירת מיפוי של שם התמחות ל-ID ב-care
        $careMap = [];
        foreach ($care as $row) {
            $careMap[trim($row['name'])] = $row['id'];
        }

        // שלב 3: יצירת מיפוי בין id של st_specialties ל-id החדש ב-care
        $stToCareMap = [];

        foreach ($stSpecialties as $specialty) {
            $name = trim($specialty['name']);
            if (isset($careMap[$name])) {
                // התמחות כבר קיימת
                $stToCareMap[$specialty['id']] = $careMap[$name];
            } else {
                // התמחות לא קיימת - ניצור אותה
                $db->createCommand()->insert('care', ['name' => $name])->execute();
                $newCareId = $db->getLastInsertID();
                $careMap[$name] = $newCareId;
                $stToCareMap[$specialty['id']] = $newCareId;
            }
        }

        // שלב 4: הבאת כל החיבורים הקיימים בין professional ל-st_specialties
        $professionalSpecialties = (new \yii\db\Query())->from('professional_st_specialties')->all();

        // שלב 5: הכנסת החיבורים החדשים ל-professional_care
        foreach ($professionalSpecialties as $row) {
            $professionalId = $row['professional_id'];
            $stSpecialtyId = $row['st_specialties_id'];

            if (isset($stToCareMap[$stSpecialtyId])) {
                $careId = $stToCareMap[$stSpecialtyId];

                // בדיקה אם כבר קיים החיבור הזה כדי לא לשכפל
                $exists = (new \yii\db\Query())
                    ->from('professional_care')
                    ->where([
                        'professional_id' => $professionalId,
                        'care_id' => $careId
                    ])
                    ->exists();

                if (!$exists) {
                    $db->createCommand()->insert('professional_care', [
                        'professional_id' => $professionalId,
                        'care_id' => $careId
                    ])->execute();
                }
            }
        }

        echo "הפעולה הושלמה בהצלחה.";
    }

    public function actionRun()
    {
        $db        = Yii::$app->db;
        $sourceIds = [4, 33];
        $targetId  = 3;

        $tx = $db->beginTransaction();
        try {
            // ========= professional_main_care =========
            foreach ($sourceIds as $src) {
                // מביאים את כל ה-professional_id שיש להם main_care_id = $src
                $q = (new Query())
                    ->select(['id', 'professional_id'])
                    ->from('professional_main_care')
                    ->where(['main_care_id' => $src]);

                foreach ($q->batch(1000, $db) as $batch) {
                    foreach ($batch as $row) {
                        $professionalId = (int)$row['professional_id'];
                        $rowId          = (int)$row['id'];

                        // בדיקה אם כבר קיים קשר (professional_id, 3)
                        $exists = (new Query())
                            ->from('professional_main_care')
                            ->where([
                                'professional_id' => $professionalId,
                                'main_care_id'    => $targetId,
                            ])->exists($db);

                        if ($exists) {
                            // אם קיים – מוחקים את השורה עם 4/33 למניעת כפילויות
                            $db->createCommand()
                                ->delete('professional_main_care', ['id' => $rowId])
                                ->execute();
                        } else {
                            // אם לא קיים – מעדכנים את השורה ל-3
                            $db->createCommand()
                                ->update('professional_main_care',
                                    ['main_care_id' => $targetId],
                                    ['id' => $rowId]
                                )->execute();
                        }
                    }
                }
            }

            // ========= main_care_sub_care =========
            foreach ($sourceIds as $src) {
                $q = (new Query())
                    ->select(['id', 'care_id'])
                    ->from('main_care_sub_care')
                    ->where(['main_care_id' => $src]);

                foreach ($q->batch(1000, $db) as $batch) {
                    foreach ($batch as $row) {
                        $subCareId = (int)$row['care_id'];
                        $rowId     = (int)$row['id'];

                        // האם כבר קיים קשר (3, care_id)?
                        $exists = (new Query())
                            ->from('main_care_sub_care')
                            ->where([
                                'main_care_id' => $targetId,
                                'care_id'  => $subCareId,
                            ])->exists($db);

                        if ($exists) {
                            // קיים – מוחקים את השורה של 4/33
                            $db->createCommand()
                                ->delete('main_care_sub_care', ['id' => $rowId])
                                ->execute();
                        } else {
                            // לא קיים – מעדכנים ל-3
                            $db->createCommand()
                                ->update('main_care_sub_care',
                                    ['main_care_id' => $targetId],
                                    ['id' => $rowId]
                                )->execute();
                        }
                    }
                }
            }

            // ========= מחיקת ה-ID-ים המיותרים מטבלת main_care =========
            $db->createCommand()
               ->delete('main_care', ['id' => $sourceIds])
               ->execute();

            $tx->commit();
            echo "✅ מיזוג הושלם (4,33 ⇒ 3) ללא מודלים, עם Query/Command בלבד.\n";
        } catch (\Throwable $e) {
            $tx->rollBack();
            echo "❌ כשל במיזוג: {$e->getMessage()}\n";
            return self::EXIT_CODE_ERROR;
        }

        return self::EXIT_CODE_NORMAL;
    }

}
