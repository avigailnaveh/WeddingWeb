<?php

namespace app\commands;

use yii\console\Controller;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Yii;

class MainSpecializationController extends Controller
{
    public function actionIndex()
    {
        $excelPath = 'Medical_Specifications_FINAL_FULLY_FILLED_EXPLICIT-cleaned.xlsx';

        if (!file_exists($excelPath)) {
            echo "קובץ אקסל לא נמצא ב: $excelPath\n";
            return;
        }

        // שליפת הנתונים מהאקסל
        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headers = array_map('trim', $rows[1]);
        unset($rows[1]);

        $columnMap = [
            'התמחות ראשית1',
            'התמחות ראשית2',
        ];

        $columnLetters = [];
        foreach ($headers as $letter => $title) {
            if (in_array($title, $columnMap)) {
                $columnLetters[] = $letter;
            } elseif (strtolower(trim($title)) === 'name') {
                $nameColumnLetter = $letter;
            }
        }

        if (!isset($nameColumnLetter)) {
            echo " לא נמצאה עמודת name\n";
            return;
        }

        $specializations = []; // [name => [mainSpec1, mainSpec2]]
        foreach ($rows as $row) {
            $name = trim($row[$nameColumnLetter] ?? '');
            if ($name === '') continue;

            $specs = [];
            foreach ($columnLetters as $colLetter) {
                $value = trim($row[$colLetter] ?? '');
                if ($value !== '') {
                    $specs[] = $value;
                }
            }

            if (!empty($specs)) {
                $specializations[$name] = $specs;
            }
        }

        $db = Yii::$app->db;

        foreach ($rows as $row) {
            $name = trim($row[$nameColumnLetter] ?? '');
            if ($name === '') continue;

            foreach ($columnLetters as $colLetter) {
                $mainSpec = trim($row[$colLetter] ?? '');
                if ($mainSpec === '') continue;

                // יצירת ההתמחות הראשית אם לא קיימת
                $mainSpecId = $db->createCommand('SELECT id FROM main_specialization WHERE name = :name')
                    ->bindValue(':name', $mainSpec)
                    ->queryScalar();

                if (!$mainSpecId) {
                    $db->createCommand()->insert('main_specialization', ['name' => $mainSpec])->execute();
                    $mainSpecId = $db->getLastInsertID();
                    echo "נוצרה התמחות ראשית: $mainSpec (ID: $mainSpecId)\n";
                }

                // שליפת רופאים שהתמחות שלהם היא $name (כלומר ההתמחות המשנית)
                $professionalIds = $db->createCommand('
                    SELECT DISTINCT professional_id 
                    FROM professional_expertise_copy 
                    JOIN expertise_copy ON expertise_copy.id = professional_expertise_copy.expertise_id 
                    WHERE expertise_copy.name = :name
                ')
                    ->bindValue(':name', $name)
                    ->queryColumn();

                foreach ($professionalIds as $profId) {

                    $alreadyHasMain = $db->createCommand('
                        SELECT 1 FROM professional_main_specialization 
                        WHERE professional_id = :pid LIMIT 1
                    ')->bindValue(':pid', $profId)->queryScalar();

                    if ($alreadyHasMain) {
                        echo " הרופא $profId כבר מקושר להתמחות ראשית – דילוג על כל קשרים נוספים\n";
                        continue;
                    }

                    $alreadyLinked = $db->createCommand('
                        SELECT 1 FROM professional_main_specialization 
                        WHERE professional_id = :pid AND main_specialization_id = :mid LIMIT 1
                    ')
                        ->bindValues([':pid' => $profId, ':mid' => $mainSpecId])
                        ->queryScalar();

                    if ($alreadyLinked) {
                        echo "קשר כבר קיים בין $profId ל־$mainSpec\n";
                        continue;
                    }

                    $db->createCommand()->insert('professional_main_specialization', [
                        'professional_id' => $profId,
                        'main_specialization_id' => $mainSpecId,
                    ])->execute();

                    echo "נוצר קשר בין $profId ל־$mainSpec\n";
                }
            }
        }
        
    }

    public function actionExport()
    {
        $db = Yii::$app->db;
        $outputFile = 'main_specialization_3_results.csv';

        // שליפת כל הרופאים עם main_specialization_id = 3
        $professionals = $db->createCommand('
            SELECT professional_id 
            FROM professional_main_specialization 
            WHERE main_specialization_id = :main_id
        ')->bindValue(':main_id', 3)->queryColumn();

        // פתיחת קובץ CSV
        $fp = fopen($outputFile, 'w');
        fputcsv($fp, ['professional_id', 'expertise_names']);

        foreach ($professionals as $profId) {
            // שליפת שמות התתי־התמחויות (name)
            $subSpecNames = $db->createCommand('
                SELECT ec.name
                FROM professional_expertise pe
                JOIN expertise ec ON pe.expertise_id = ec.id
                WHERE pe.professional_id = :prof_id
            ')->bindValue(':prof_id', $profId)->queryColumn();

            // כתיבה לשורה בקובץ
            fputcsv($fp, [$profId, implode('; ', $subSpecNames)]);
        }

        fclose($fp);
        echo "✅ הקובץ נוצר בהצלחה: $outputFile\n";
    }
}
