<?php
namespace app\commands;

use yii\console\Controller;
use yii\helpers\Console;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Yii;

class SpecializationLinkController extends Controller
{
    public function actionExpertiseTable()
    {
        $filePath = 'Medical_Specifications.xlsx';

        if (!file_exists($filePath)) {
            echo " קובץ לא נמצא: $filePath\n";
            return;
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $mainSpecCols = ['התמחות ראשית1', 'התמחות ראשית2'];
        $expertiseCols = [
            'NormalSpecialization - new1',
            'NormalSpecialization - new2',
            'NormalSpecialization - new3',
            'NormalSpecialization - new4',
            'NormalSpecialization - new5',
        ];

        $headers = array_map('trim', $rows[1]);
        unset($rows[1]);

        $db = Yii::$app->db;
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            $mainSpecs = [];
            $expertises = [];

            foreach ($mainSpecCols as $colName) {
                foreach ($headers as $col => $name) {
                    if (trim($name) === $colName && !empty($row[$col])) {
                        $mainSpecs[] = trim($row[$col]);
                    }
                }
            }

            foreach ($expertiseCols as $colName) {
                foreach ($headers as $col => $name) {
                    if (trim($name) === $colName && !empty($row[$col])) {
                        $expertises[] = trim($row[$col]);
                    }
                }
            }

            foreach ($mainSpecs as $mainName) {
                $mainId = $db->createCommand("SELECT id FROM main_specialization WHERE name = :name")
                    ->bindValue(':name', $mainName)
                    ->queryScalar();

                if (!$mainId) {
                    echo "לא נמצא main_specialization: $mainName\n";
                    continue;
                }

                foreach ($expertises as $expName) {
                    $expId = $db->createCommand("SELECT id FROM expertise WHERE name = :name")
                        ->bindValue(':name', $expName)
                        ->queryScalar();

                    if (!$expId) {
                        echo "לא נמצא expertise: $expName\n";
                        continue;
                    }

                    // בדיקת כפילות
                    $exists = $db->createCommand("
                        SELECT 1 FROM main_specialization_expertise
                        WHERE main_specialization_id = :mid AND expertise_id = :eid
                    ")->bindValues([':mid' => $mainId, ':eid' => $expId])->queryScalar();

                    if (!$exists) {
                        $db->createCommand()->insert('main_specialization_expertise', [
                            'main_specialization_id' => $mainId,
                            'expertise_id' => $expId,
                        ])->execute();
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        echo "הסתיים: נוספו $inserted קשרים חדשים, $skipped דולגו (כפולים או חסרים)\n";
    }

    public function actionCareTable()
    {
        $filePath = 'filtered_specializations.xlsx';

        if (!file_exists($filePath)) {
            echo " קובץ לא נמצא: $filePath\n";
            return;
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $mainSpecCols = ['התמחות ראשית 1', 'התמחות ראשית 2'];
        $expertiseCols = [
            'התמחות מנורמלת1',
            'התמחות מנורמלת2',
            'התמחות מנורמלת3',
            'התמחות מנורמלת4',
            'התמחות מנורמלת5',
            'התמחות מנורמלת6',
            'התמחות מנורמלת7',
            'התמחות מנורמלת8',
            'התמחות מנורמלת9',
            'התמחות מנורמלת10',
        ];

        $headers = array_map('trim', $rows[1]);
        unset($rows[1]);

        $db = Yii::$app->db;
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            $mainSpecs = [];
            $expertises = [];

            foreach ($mainSpecCols as $colName) {
                foreach ($headers as $col => $name) {
                    if (trim($name) === $colName && !empty($row[$col])) {
                        $mainSpecs[] = trim($row[$col]);
                    }
                }
            }

            foreach ($expertiseCols as $colName) {
                foreach ($headers as $col => $name) {
                    if (trim($name) === $colName && !empty($row[$col])) {
                        $expertises[] = trim($row[$col]);
                    }
                }
            }

            foreach ($mainSpecs as $mainName) {
                $mainId = $db->createCommand("SELECT id FROM main_care WHERE name = :name")
                    ->bindValue(':name', $mainName)
                    ->queryScalar();

                if (!$mainId) {
                    echo "לא נמצא main_care: $mainName\n";
                    continue;
                }

                foreach ($expertises as $expName) {
                    $expId = $db->createCommand("SELECT id FROM care WHERE name = :name")
                        ->bindValue(':name', $expName)
                        ->queryScalar();

                    if (!$expId) {
                        echo "לא נמצא care: $expName\n";
                        continue;
                    }

                    // בדיקת כפילות
                    $exists = $db->createCommand("
                        SELECT 1 FROM main_care_sub_care
                        WHERE main_care_id = :mid AND care_id = :eid
                    ")->bindValues([':mid' => $mainId, ':eid' => $expId])->queryScalar();

                    if (!$exists) {
                        $db->createCommand()->insert('main_care_sub_care', [
                            'main_care_id' => $mainId,
                            'care_id' => $expId,
                        ])->execute();
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        echo "הסתיים: נוספו $inserted קשרים חדשים, $skipped דולגו (כפולים או חסרים)\n";
    }
}
