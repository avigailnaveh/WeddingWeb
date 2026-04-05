<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use SplFileObject;
use PhpOffice\PhpSpreadsheet\IOFactory;

class UpdateLocalitiesTableController extends Controller
{
    public function actionImportFromExcel()
    {
        $filePath = 'cleaned_output.xlsx'; // עדכון נתיב לקובץ ה-Excel

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        // הלולאה מוסיפה את הנתונים לטבלה localities
        foreach ($data as $row) {
            if (empty($row[0])) {
                continue; // מדלג על שורות ריקות
            }

            // הכנסת נתונים לטבלה
            Yii::$app->db->createCommand()->insert('localities', [
                'naf_name' => $row[0], // שם נפה
                'naf_symbol' => $row[1], // סמל נפה
                'district_symbol' => $row[2], // סמל מחוז
                'district_name' => $row[3], // שם מחוז
                'city_symbol' => $row[4], // סמל יישוב
                'city_name' => $row[5], // שם יישוב
            ])->execute();
        }

        echo "הנתונים הועלו בהצלחה!\n";
    }

    public function actionFromCsv(): int
    {
        $filePath = 'professional_address_city_normal_filled_v3 - professional_address_city_normal_filled_v3.csv';
        if (!is_file($filePath) || !is_readable($filePath)) {
            $this->stderr("הקובץ לא נמצא או לא קריא: {$filePath}\n");
            return ExitCode::NOINPUT;
        }

        // פתיחת הקובץ במצב CSV
        $csv = new SplFileObject($filePath);
        $csv->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::SKIP_EMPTY
            | SplFileObject::DROP_NEW_LINE
        );
        $csv->setCsvControl(','); // שנה במידת הצורך (למשל ';')

        // קריאת כותרות
        if ($csv->eof()) {
            $this->stderr("הקובץ ריק.\n");
            return ExitCode::DATAERR;
        }
        $headers = $csv->fgetcsv();
        if ($headers === null || $headers === false) {
            $this->stderr("לא ניתן לקרוא כותרות מהקובץ.\n");
            return ExitCode::DATAERR;
        }

        // ניקוי BOM ושיוך אינדקסים
        $headers = array_map(function ($h) {
            $h = (string)$h;
            // הסרת BOM אם קיים בעמודה הראשונה
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            return mb_strtolower(trim($h));
        }, $headers);

        $idxId = array_search('id', $headers, true);
        $idxCityNormal = array_search('city_normal', $headers, true);

        if ($idxId === false || $idxCityNormal === false) {
            $this->stderr("נדרשות עמודות בשם 'id' ו-'city_normal' בכותרת הקובץ.\n");
            return ExitCode::DATAERR;
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        $updated = 0;
        $missing = 0;
        $skipped = 0;
        $lineNo  = 1; // כולל שורת כותרת

        $cmd = $db->createCommand('UPDATE professional_address SET city_normal = :city_normal WHERE id = :id');

        try {
            foreach ($csv as $row) {
                $lineNo++;

                if ($row === null || $row === false) {
                    continue;
                }

                // לעיתים מגיעות שורות ריקות בסוף הקובץ
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }

                // הבטחת גודל מערך כמו מספר הכותרות
                if (count($row) < max($idxId, $idxCityNormal) + 1) {
                    $skipped++;
                    continue;
                }

                $id = trim((string)$row[$idxId]);
                $cityNormal = (string)$row[$idxCityNormal]; // לפי הדרישה—לעדכן בדיוק לערך שבקובץ

                if ($id === '') {
                    $skipped++;
                    continue;
                }

                // עדכון
                $affected = $cmd->bindValues([
                    ':id' => $id,
                    ':city_normal' => $cityNormal, // אם רוצים לרשום NULL כשערך ריק: ($cityNormal === '' ? null : $cityNormal)
                ])->execute();

                if ($affected > 0) {
                    $updated += $affected; // אמור להיות 1 לשורה
                } else {
                    // לא נמצאה שורה עם id מתאים
                    $missing++;
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr("שגיאה: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("סיום.\n");
        $this->stdout("עודכנו:  {$updated}\n");
        $this->stdout("לא נמצאו (id חסר בטבלה): {$missing}\n");
        $this->stdout("דולגו (שורה בעייתית/חסרה): {$skipped}\n");

        return ExitCode::OK;
    }
}

