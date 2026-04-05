<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\Professional;
use yii\db\Query;

class UpdateSpecializationController extends Controller
{
    public function actionIndex()
    {
        $count = 0;
        $filePath = 'moveCategoryToMainSpecial.xlsx';
        
        if (!is_file($filePath) || !is_readable($filePath)) {
            echo "הקובץ לא נמצא או לא קריא: {$filePath}\n";
            return 1;
        }

        $data = $this->readExcelFile($filePath);
        if (empty($data)) {
            echo "הקובץ ריק או לא תקין\n";
            return 1;
        }

        // לולאת עיבוד לכל שורה בקובץ
        foreach ($data as $row) {
            $categoryId = $row['id'];
            $mainIds = explode(',', $row['mainid']); // פיצול ה-mainid למערך של ערכים

            // חיפוש כל השורות עם category_id מסוים
            $categories = Yii::$app->db->createCommand('
                SELECT * FROM professional_categories WHERE category_id = :category_id')
                ->bindValue(':category_id', $categoryId)
                ->queryAll();  // השתמש ב-queryAll() כדי לקבל את כל השורות

            if ($categories) {
                foreach ($categories as $category) {
                    $professionalId = $category['professional_id'];

                    // בדוק אם professional_id קיים בטבלת professional
                    $professional = Professional::findOne(['id' => $professionalId]);

                    if ($professional) {
                        // עבור על כל הערכים ב-mainid
                        foreach ($mainIds as $mainId) {
                            $mainId = trim($mainId); // מסיר רווחים מיותרים

                            // בדוק אם כבר קיימת שורה עם professional_id ו main_specialization_id בטבלה של ההתמחות
                            $existingSpecialization = Yii::$app->db->createCommand('
                                SELECT * FROM professional_main_specialization 
                                WHERE professional_id = :professional_id 
                                AND main_specialization_id = :main_specialization_id')
                                ->bindValue(':professional_id', $professionalId)
                                ->bindValue(':main_specialization_id', $mainId)
                                ->queryOne();

                            // אם לא קיימת שורה כזו, צור שורה חדשה
                            if (!$existingSpecialization) {
                                Yii::$app->db->createCommand()->insert('professional_main_specialization', [
                                    'professional_id' => $professionalId,
                                    'main_specialization_id' => $mainId,
                                ])->execute();
                                $count++;
                            }
                        }
                    }
                }
            }
        }

        echo "Row updates: $count \n";
        echo "העדכון הושלם בהצלחה\n";
        return 0;
    }

    private function readExcelFile($filePath)
    {
        // שימוש ב־PHPExcel (או PhpSpreadsheet) לקריאת הקובץ
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        // קריאת הנתונים לשדה נתונים
        $data = [];
        foreach ($sheet->getRowIterator(2) as $row) { // מתחילים מהשורה השנייה (לדלג על הכותרת)
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                $rowData[] = $cell->getValue();
            }

            // מיפוי למפתחות לפי המבנה שהצגת
            $data[] = [
                'id' => $rowData[0],
                'name' => $rowData[1],
                'description' => $rowData[2],
                'mainid' => $rowData[3],
                'kide' => $rowData[4] ?? null,
            ];
        }

        return $data;
    }



    public function actionLinkFromCsv()
    {
        $csvPath = Yii::getAlias('@app/web/uploads/professional_with_profession.csv');

        if (!file_exists($csvPath)) {
            echo "קובץ CSV לא נמצא: $csvPath\n";
            return 1;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            echo "לא ניתן לפתוח את הקובץ.\n";
            return 1;
        }

        $headerRaw = fgetcsv($handle);

        if (isset($headerRaw[0])) {
            $headerRaw[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRaw[0]);
        }

        $header = array_map('trim', $headerRaw);

        $idIndex = array_search('id', $header);
        if ($idIndex === false) {
            echo "לא נמצא שדה 'id' בכותרת הקובץ.\n";
            return 1;
        }

        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $count++;
            $professionalId = trim($row[$idIndex]);
            if (!$professionalId) {
                continue;
            }

            echo "מטפל ב־professional_id: $professionalId\n";

            // --- קישור ל professional_care (care_id = 135) ---
            $existsCare = (new \yii\db\Query())
                ->from('professional_care')
                ->where([
                    'professional_id' => $professionalId,
                    'care_id' => 135
                ])
                ->exists();

            if (!$existsCare) {
                Yii::$app->db->createCommand()->insert('professional_care', [
                    'professional_id' => $professionalId,
                    'care_id' => 135
                ])->execute();

                echo "  ✔ נוסף קישור professional_care (135)\n";
            } else {
                echo "  • כבר קיים professional_care (135)\n";
            }

            // --- קישור ל professional_main_care (main_care_id = 28) ---
            $existsMain = (new \yii\db\Query())
                ->from('professional_main_care')
                ->where([
                    'professional_id' => $professionalId,
                    'main_care_id' => 28
                ])
                ->exists();

            if (!$existsMain) {
                Yii::$app->db->createCommand()->insert('professional_main_care', [
                    'professional_id' => $professionalId,
                    'main_care_id' => 28
                ])->execute();

                echo "  ✔ נוסף קישור professional_main_care (28)\n";
            } else {
                echo "  • כבר קיים professional_main_care (28)\n";
            }

            echo "--------------------------------------\n";
        }

        fclose($handle);

        echo "סיום.\n";
        return 0;
    }

    public function actionAddMainCareSubCare()
    {
        $mainCareId = 26;

        $careIds = [
            54
        ];

        foreach ($careIds as $careId) {

            $exists = (new Query())
                ->from('main_care_sub_care')
                ->where([
                    'main_care_id' => $mainCareId,
                    'care_id'      => $careId,
                ])
                ->exists();

            if ($exists) {
                echo "קישור כבר קיים: main_care_id={$mainCareId}, care_id={$careId}\n";
                continue;
            }

            Yii::$app->db->createCommand()->insert('main_care_sub_care', [
                'main_care_id' => $mainCareId,
                'care_id'      => $careId,
            ])->execute();

            echo "נוסף קישור: main_care_id={$mainCareId}, care_id={$careId}\n";
        }

        echo "✔️ הסתיים\n";
    }
}

