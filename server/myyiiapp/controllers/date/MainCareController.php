<?php  
namespace app\commands;

use PhpOffice\PhpSpreadsheet\IOFactory;
use yii\console\Controller;
use yii\helpers\Console;
use Yii;

class MainCareController extends \yii\console\Controller
{
    public function actionImportSpecializations()
    {
        $filePath = 'Therapist_Main_Specializations_final (2).xlsx';  // נתיב הקובץ שלך
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // זה יוצר מערך שבו המפתחות הם אותיות העמודות

        $headers = $rows[1]; // השורה הראשונה מכילה את שמות העמודות
        unset($rows[1]); // מסיר את השורה הראשונה מכיוון שהיא כבר ב-header

        // הדפסת שמות העמודות כדי לוודא שאתה מתייחס לשמות הנכונים
        print_r($headers); 

        foreach ($rows as $row) {
            // השתמש במפתחות העמודות הנכונים על פי המערך שנוצר
            $mainSpecialization1 = $row['B']; // התמחות ראשית1 היא בעמודה B
            $mainSpecialization2 = $row['C']; // התמחות ראשית2 היא בעמודה C

            // הוספת התמחות ראשית1 אם לא קיימת
            if ($mainSpecialization1) {
                $this->addSpecialization($mainSpecialization1);
            }

            // הוספת התמחות ראשית2 אם לא קיימת
            if ($mainSpecialization2) {
                $this->addSpecialization($mainSpecialization2);
            }
        }
    }



    private function addSpecialization($specializationName)
    {
        $existingSpecialization = Yii::$app->db->createCommand('SELECT * FROM main_care WHERE name = :specialization')
            ->bindValue(':specialization', $specializationName)
            ->queryOne();


        if (!$existingSpecialization) {
            Yii::$app->db->createCommand()->insert('main_care', [
                'name' => $specializationName,
            ])->execute();
        }
    }
    public function actionLinkProfessionalCareFromExcel()
    {
        $filePath = 'updated_file1.xlsx';
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); 

        $headers = $rows[1]; 
        unset($rows[1]);

        foreach ($rows as $row) {
            $normalizedSpecialization1 = $row['D']; 
            $normalizedSpecialization2 = $row['E']; 

            $careId1 = Yii::$app->db->createCommand('SELECT id FROM care WHERE name = :specialization')
                ->bindValue(':specialization', $normalizedSpecialization1)
                ->queryScalar();

            $careId2 = Yii::$app->db->createCommand('SELECT id FROM care WHERE name = :specialization')
                ->bindValue(':specialization', $normalizedSpecialization2)
                ->queryScalar();

            if ($careId1) {
                $professionals1 = Yii::$app->db->createCommand('SELECT professional_id FROM professional_care WHERE care_id = :care_id')
                    ->bindValue(':care_id', $careId1)
                    ->queryAll();

                foreach ($professionals1 as $professional) {
                    $this->linkProfessionalToMainCare($professional['professional_id'], $row['B']);  
                }
            }

            if ($careId2) {
                $professionals2 = Yii::$app->db->createCommand('SELECT professional_id FROM professional_care WHERE care_id = :care_id')
                    ->bindValue(':care_id', $careId2)
                    ->queryAll();

                foreach ($professionals2 as $professional) {
                    $this->linkProfessionalToMainCare($professional['professional_id'], $row['C']);  
                }
            }
        }
    }

    private function linkProfessionalToMainCare($professionalId, $mainCareSpecialization)
    {
        $mainCareId = Yii::$app->db->createCommand('SELECT id FROM main_care WHERE name = :specialization')
            ->bindValue(':specialization', $mainCareSpecialization)
            ->queryScalar();

        if ($mainCareId) {
            Yii::$app->db->createCommand()->insert('professional_main_care', [
                'professional_id' => $professionalId,
                'main_care_id' => $mainCareId,
            ])->execute();
        }
    }

    // public function actionIndex()
    // {
    //     $filePath = 'updated_file1.xlsx';

    //     if (!file_exists($filePath)) {
    //         echo " קובץ לא נמצא: $filePath\n";
    //         return;
    //     }

    //     $spreadsheet = IOFactory::load($filePath);
    //     $sheet = $spreadsheet->getActiveSheet();
    //     $rows = $sheet->toArray(null, true, true, true);

    //     $mainSpecCols = ['התמחות ראשית1', 'התמחות ראשית2'];
    //     $expertiseCols = [
    //         'התמחות מנורמלת1',
    //         'התמחות מנורמלת2',
    //     ];

    //     $headers = array_map('trim', $rows[1]);
    //     unset($rows[1]);

    //     $db = Yii::$app->db;
    //     $inserted = 0;
    //     $skipped = 0;

    //     foreach ($rows as $i => $row) {
    //         $mainSpecs = [];
    //         $expertises = [];

    //         foreach ($mainSpecCols as $colName) {
    //             foreach ($headers as $col => $name) {
    //                 if (trim($name) === $colName && !empty($row[$col])) {
    //                     $mainSpecs[] = trim($row[$col]);
    //                 }
    //             }
    //         }

    //         foreach ($expertiseCols as $colName) {
    //             foreach ($headers as $col => $name) {
    //                 if (trim($name) === $colName && !empty($row[$col])) {
    //                     $expertises[] = trim($row[$col]);
    //                 }
    //             }
    //         }

    //         foreach ($mainSpecs as $mainName) {
    //             $mainId = $db->createCommand("SELECT id FROM main_care WHERE name = :name")
    //                 ->bindValue(':name', $mainName)
    //                 ->queryScalar();

    //             if (!$mainId) {
    //                 echo "לא נמצא main_care: $mainName\n";
    //                 continue;
    //             }

    //             foreach ($expertises as $expName) {
    //                 $expId = $db->createCommand("SELECT id FROM care WHERE name = :name")
    //                     ->bindValue(':name', $expName)
    //                     ->queryScalar();

    //                 if (!$expId) {
    //                     echo "לא נמצא care: $expName\n";
    //                     continue;
    //                 }

    //                 // בדיקת כפילות
    //                 $exists = $db->createCommand("
    //                     SELECT 1 FROM main_care_expertise
    //                     WHERE main_specialization_id = :mid AND expertise_id = :eid
    //                 ")->bindValues([':mid' => $mainId, ':eid' => $expId])->queryScalar();

    //                 if (!$exists) {
    //                     $db->createCommand()->insert('main_specialization_expertise', [
    //                         'main_specialization_id' => $mainId,
    //                         'expertise_id' => $expId,
    //                     ])->execute();
    //                     $inserted++;
    //                 } else {
    //                     $skipped++;
    //                 }
    //             }
    //         }
    //     }

    //     echo "הסתיים: נוספו $inserted קשרים חדשים, $skipped דולגו (כפולים או חסרים)\n";
    // }

}
