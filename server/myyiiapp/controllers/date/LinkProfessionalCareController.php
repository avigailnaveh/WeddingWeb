<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\db\Expression;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LinkProfessionalCareController extends Controller
{
    /*public function actionIndex()
    {
        // טוענים את קובץ ה-CSV
        $filePath = 'yahat_final_output.csv';
        if (!file_exists($filePath)) {
            echo "הקובץ לא נמצא ב: $filePath\n";
            return;
        }

        // טוענים את הנתונים מתוך קובץ ה-CSV
        $data = $this->loadCsvData($filePath);

        // עבור כל שורה ב-CSV, נבצע את הפעולות הבאות
        foreach ($data as $row) {
            // וודא שהעמודות קיימות לפני השימוש
            if (!isset($row['phone']) || !isset($row['specialization1'])) {
                echo "הנתונים בשורה לא תקינים: " . json_encode($row) . "\n";
                continue;
            }

            $phone = $row['phone'];
            $phone = $this->cleanPhone($row['phone']);
            $specialization1 = $row['specialization1'];
            $specialization2 = $row['specialization2'];

            // אם הטלפון לא ריק ומתחיל ב-05
            if (!empty($phone) && strpos($phone, '05') === 0) {
                // חיפוש ה-professional_id בטבלת Professional_address עבור כל הטלפונים האפשריים
                $professionalId = Yii::$app->db->createCommand('
                    SELECT professional_id
                    FROM professional_address
                    WHERE phone = :phone
                    OR phone_2 = :phone
                    OR phone_3 = :phone
                    OR phone_4 = :phone
                ')
                    ->bindValue(':phone', $phone)
                    ->queryScalar();

                // אם מצאנו את ה-professional_id
                if ($professionalId) {
                    // בודקים אם ה-professional_id קיים בטבלת professional
                    $existsInProfessional = Yii::$app->db->createCommand('
                        SELECT COUNT(*)
                        FROM professional
                        WHERE id = :professional_id
                    ')
                        ->bindValue(':professional_id', $professionalId)
                        ->queryScalar();

                    if ($existsInProfessional > 0) {
                        // חפש את ההתמחות הראשונה בטבלת main_care
                        if (!empty($specialization1)) {
                            $mainCareId1 = Yii::$app->db->createCommand('
                                SELECT id
                                FROM main_care
                                WHERE name = :specialization
                            ')
                                ->bindValue(':specialization', $specialization1)
                                ->queryScalar();

                            // אם ההתמחות קיימת ב-main_care
                            if ($mainCareId1) {
                                // בודק אם הקשר כבר קיים בטבלת professional_main_care
                                $existsInProfessionalMainCare = Yii::$app->db->createCommand('
                                    SELECT COUNT(*)
                                    FROM professional_main_care
                                    WHERE professional_id = :professional_id
                                    AND main_care_id = :main_care_id
                                ')
                                    ->bindValue(':professional_id', $professionalId)
                                    ->bindValue(':main_care_id', $mainCareId1)
                                    ->queryScalar();

                                // אם אין קשר, ניצור את הקשר
                                if ($existsInProfessionalMainCare == 0) {
                                    Yii::$app->db->createCommand()->insert('professional_main_care', [
                                        'professional_id' => $professionalId,
                                        'main_care_id' => $mainCareId1,
                                    ])->execute();

                                    echo "הקשר נוצר בהצלחה עבור ה-professional_id: $professionalId והתמחות ראשית1: $specialization1\n";
                                }
                            }
                        }

                        // אם ההתמחות השנייה לא ריקה, ניצור את הקשר שלה
                        if (!empty($specialization2)) {
                            $mainCareId2 = Yii::$app->db->createCommand('
                                SELECT id
                                FROM main_care
                                WHERE name = :specialization
                            ')
                                ->bindValue(':specialization', $specialization2)
                                ->queryScalar();

                            // אם ההתמחות קיימת ב-main_care
                            if ($mainCareId2) {
                                // בודק אם הקשר כבר קיים בטבלת professional_main_care
                                $existsInProfessionalMainCare = Yii::$app->db->createCommand('
                                    SELECT COUNT(*)
                                    FROM professional_main_care
                                    WHERE professional_id = :professional_id
                                    AND main_care_id = :main_care_id
                                ')
                                    ->bindValue(':professional_id', $professionalId)
                                    ->bindValue(':main_care_id', $mainCareId2)
                                    ->queryScalar();

                                // אם אין קשר, ניצור את הקשר
                                if ($existsInProfessionalMainCare == 0) {
                                    Yii::$app->db->createCommand()->insert('professional_main_care', [
                                        'professional_id' => $professionalId,
                                        'main_care_id' => $mainCareId2,
                                    ])->execute();

                                    echo "הקשר נוצר בהצלחה עבור ה-professional_id: $professionalId והתמחות ראשית2: $specialization2\n";
                                }
                            }
                        }
                    } else {
                        echo "ה-professional_id: $professionalId לא קיים בטבלת professional\n";
                    }
                } else {
                    echo "לא נמצא professional_id עבור טלפון: $phone\n";
                }
            } else {
                echo "הטלפון לא מתאים או ריק עבור שורה: " . json_encode($row) . "\n";
            }
        }
    }

    private function loadCsvData($filePath)
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // אנחנו מניחים שהשורה הראשונה היא כותרת וצריכים להתייחס לשורות מהשני
        $headers = $rows[1];
        unset($rows[1]);  // מסירים את השורה הראשונה שהיא כותרת

        $data = [];
        foreach ($rows as $row) {
            // מיפוי ידני של העמודות לפי המיקומים A, B, C, D
            $data[] = [
                'phone' => $row['A'],  // עמודה A
                'specialization1' => $row['C'],  // התמחות ראשית 1
                'specialization2' => $row['D'],  // התמחות ראשית 2
            ];
        }

        return $data;
    }

    public static function cleanPhone($phone)
    {
        if (!$phone || preg_match('/[a-zA-Z@]/', $phone)) {
            echo "\nלא נמצא מספר טלפון תקין: {$phone}";
            return null;
        }

        // אם מתחיל ב-+972, להחליף ל-0
        $phone = preg_replace('/^\+972/', '0', $phone);
        echo "\n אחרי החלפת +972 ל-0: {$phone}";

        // ניקוי תווים מיותרים (למעט מספרים וכוכבית)
        $phone = explode('/', $phone)[0];
        echo "\n אחרי ניקוי תווים מיותרים: {$phone}";

        return preg_replace('/[^0-9*]/', '', $phone);
    }*/

    public function actionIndex()
    {
        $filePath = 'hebpsy_filtered_output.csv';
        if (!file_exists($filePath)) {
            echo "הקובץ לא נמצא ב: $filePath\n";
            return;
        }

        $data = $this->loadCsvData($filePath);

        foreach ($data as $row) {
            if (!isset($row['phone']) || !isset($row['normalized'])) {
                echo "הנתונים בשורה לא תקינים: " . json_encode($row) . "\n";
                continue;
            }

            $phone = $this->cleanPhone($row['phone']);
            $specialization = $row['normalized'];

            if (!empty($phone) && strpos($phone, '05') === 0) {
                $professionalId = Yii::$app->db->createCommand('
                    SELECT professional_id
                    FROM professional_address
                    WHERE phone = :phone
                       OR phone_2 = :phone
                       OR phone_3 = :phone
                       OR phone_4 = :phone
                ')
                ->bindValue(':phone', $phone)
                ->queryScalar();

                if ($professionalId) {
                    $exists = Yii::$app->db->createCommand('
                        SELECT COUNT(*) FROM professional WHERE id = :id
                    ')
                    ->bindValue(':id', $professionalId)
                    ->queryScalar();

                    if ($exists > 0 && !empty($specialization)) {
                        $mainCareId = Yii::$app->db->createCommand('
                            SELECT id FROM main_care WHERE name = :name
                        ')
                        ->bindValue(':name', $specialization)
                        ->queryScalar();

                        if ($mainCareId) {
                            $linkExists = Yii::$app->db->createCommand('
                                SELECT COUNT(*) FROM professional_main_care
                                WHERE professional_id = :pid AND main_care_id = :cid
                            ')
                            ->bindValue(':pid', $professionalId)
                            ->bindValue(':cid', $mainCareId)
                            ->queryScalar();

                            if ($linkExists == 0) {
                                Yii::$app->db->createCommand()->insert('professional_main_care', [
                                    'professional_id' => $professionalId,
                                    'main_care_id' => $mainCareId,
                                ])->execute();

                                echo "קשר נוצר עבור professional_id: $professionalId עם התמחות: $specialization\n";
                            }
                        }
                    } else {
                        echo "professional_id $professionalId לא קיים בטבלת professional\n";
                    }
                } else {
                    echo "לא נמצא professional_id עבור טלפון: $phone\n";
                }
            } else {
                echo "טלפון לא תקין או ריק: " . json_encode($row) . "\n";
            }
        }
    }

    private function loadCsvData($filePath)
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headers = array_map('trim', $rows[1]);
        unset($rows[1]);

        $columnMap = [];
        foreach ($headers as $letter => $title) {
            $columnMap[$title] = $letter;
        }

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'phone' => $row[$columnMap['phone']] ?? null,
                'normalized' => $row[$columnMap['normalized']] ?? null,
            ];
        }

        return $data;
    }
   
  

    public static function cleanPhone($phone)
    {
        if (!$phone || preg_match('/[a-zA-Z@]/', $phone)) {
            echo "לא נמצא מספר טלפון תקין: {$phone}\n";
            return null;
        }

        $phone = preg_replace('/^\+972/', '0', $phone);
        echo "אחרי החלפת +972 ל-0: {$phone}\n";

        $phone = explode('/', $phone)[0];
        echo "אחרי ניקוי תווים מיותרים: {$phone}\n";

        return preg_replace('/[^0-9*]/', '', $phone);
    }

    public function actionAddUnionToMainCare()
    {
        // $unionId = 10;
        $unionId = 11;
        $mainCareId = 51;

        $professionals = Yii::$app->db->createCommand("
            SELECT professional_id 
            FROM professional_unions 
            WHERE unions_id = :unionId
        ")->bindValue(':unionId', $unionId)->queryAll();

        foreach ($professionals as $professional) {
            $professionalId = $professional['professional_id'];

            $exists = Yii::$app->db->createCommand("
                SELECT COUNT(*) 
                FROM professional_main_care 
                WHERE professional_id = :professionalId 
                AND main_care_id = :mainCareId
            ")->bindValues([
                ':professionalId' => $professionalId,
                ':mainCareId' => $mainCareId,
            ])->queryScalar();

            if ($exists == 0) {
                Yii::$app->db->createCommand()->insert('professional_main_care', [
                    'professional_id' => $professionalId,
                    'main_care_id' => $mainCareId,
                ])->execute();

                echo "קשר נוצר עבור professional_id: $professionalId\n";
            } else {
                echo "קשר כבר קיים עבור professional_id: $professionalId\n";
            }
        }

        echo "הפעולה הסתיימה\n";
    }
    public function actionAddUnionToMainCareEasy()
    {
        $unionId = 9;
        $mainCareId = 28;

        $professionals = Yii::$app->db->createCommand("
            SELECT professional_id 
            FROM professional_unions 
            WHERE unions_id = :unionId
        ")->bindValue(':unionId', $unionId)->queryAll();

        foreach ($professionals as $professional) {
            $professionalId = $professional['professional_id'];

            // בדוק אם יש לו care_id = 135
            $hasCare135 = Yii::$app->db->createCommand("
                SELECT COUNT(*) 
                FROM professional_care 
                WHERE professional_id = :professionalId 
                AND care_id = 135
            ")->bindValue(':professionalId', $professionalId)->queryScalar();

            if ($hasCare135 == 0) {
                echo "ל־professional_id: $professionalId אין care_id = 135 — דילוג\n";
                continue;
            }

            // בדוק אם הקשר כבר קיים
            $exists = Yii::$app->db->createCommand("
                SELECT COUNT(*) 
                FROM professional_main_care 
                WHERE professional_id = :professionalId 
                AND main_care_id = :mainCareId
            ")->bindValues([
                ':professionalId' => $professionalId,
                ':mainCareId' => $mainCareId,
            ])->queryScalar();

            if ($exists == 0) {
                Yii::$app->db->createCommand()->insert('professional_main_care', [
                    'professional_id' => $professionalId,
                    'main_care_id' => $mainCareId,
                ])->execute();

                echo "קשר נוצר עבור professional_id: $professionalId\n";
            } else {
                echo "קשר כבר קיים עבור professional_id: $professionalId\n";
            }
        }

        echo "הפעולה הסתיימה\n";
    }

    public function actionMergeCareNames()
    {
        $fromCareId = 36; // טיפול באומנות (נמחק)
        $toCareId = 7;    // טיפול באמנות (המאוחד)

        $db = Yii::$app->db;

        $professionals = $db->createCommand("
            SELECT professional_id
            FROM professional_main_care
            WHERE main_care_id = :fromCareId
        ")->bindValue(':fromCareId', $fromCareId)->queryAll();

        foreach ($professionals as $row) {
            $professionalId = $row['professional_id'];

            // בדוק אם כבר קיים קישור ל־toCareId
            $exists = $db->createCommand("
                SELECT COUNT(*) 
                FROM professional_main_care 
                WHERE professional_id = :professionalId 
                AND main_care_id = :toCareId
            ")->bindValues([
                ':professionalId' => $professionalId,
                ':toCareId' => $toCareId,
            ])->queryScalar();

            // אם לא קיים – צור קישור חדש
            if ($exists == 0) {
                $db->createCommand()->insert('professional_main_care', [
                    'professional_id' => $professionalId,
                    'main_care_id' => $toCareId,
                ])->execute();

                echo " הועבר professional_id: $professionalId ל-care_id = $toCareId\n";
            }
        }

        // שלב 2: מחיקת כל השורות עם care_id הישן
        $db->createCommand()->delete('professional_main_care', [
            'main_care_id' => $fromCareId,
        ])->execute();

        // שלב 3: מחיקת רשומת ההתמחות הישנה מטבלת main_care
        $db->createCommand()->delete('main_care', [
            'id' => $fromCareId,
        ])->execute();

        echo "האיחוד הושלם: טיפול באומנות → טיפול באמנות\n";
    }
    
}

