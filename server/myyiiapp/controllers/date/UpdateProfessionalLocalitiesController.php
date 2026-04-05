<?php

namespace app\commands;

use Yii;
use yii\console\Controller;

class UpdateProfessionalLocalitiesController extends Controller
{
    
    public function actionUpdateLocalities() {

        $csvPath = Yii::getAlias('@app/web/uploads/professional_address_matched.csv');
        // $unmatchedOutPath = 'unmatched_localities.csv';

        if (!is_file($csvPath)) {
            echo "הקובץ לא נמצא: {$csvPath}\n";
            return 1;
        }

        // טען מפה: City_Normal (מנורמל) -> id
        $cityToId = $this->loadCityMap($csvPath);
        if (empty($cityToId)) {
            echo "לא נטען אף ערך תקין מה־CSV. בדוק כותרות/תווים/מפריד.\n";
            return 1;
        }

        // כל הכתובות שהשדה city_google לא ריק 
        $rows = Yii::$app->db->createCommand(
            "SELECT DISTINCT professional_id, city_google
             FROM professional_address
             WHERE city_google IS NOT NULL AND city_google <> ''"
        )->queryAll();

        $added = 0;
        $skippedNoMatch = 0;
        $skippedDuplicate = 0;

        // אוסף השורות שלא נמצאה להן התאמה כדי להוציא ל-CSV נפרד
        $unmatchedRecords = [];

        foreach ($rows as $r) {
            $pid = (int)$r['professional_id'];
            $cityOriginal = (string)$r['city_google'];
            $city = $this->normalizeCity($cityOriginal);

            if ($city === '') {
                // לא מחפשים התאמה לשמות ריקים
                continue;
            }

            if (!isset($cityToId[$city])) {
                // לא נמצאה התאמה ב־CSV — נרשום לרשימת הפלט
                $skippedNoMatch++;
                $unmatchedRecords[] = [
                    'professional_id'         => $pid,
                    'city_google_original'    => $cityOriginal,
                    'city_google_normalized'  => $city,
                ];
                continue;
            }

            $localityId = (int)$cityToId[$city];

            // הכנסה אם לא קיים 
            $exists = Yii::$app->db->createCommand(
                "SELECT COUNT(*) FROM professional_localities
                 WHERE professional_id = :pid AND localities_id = :lid",
                [':pid' => $pid, ':lid' => $localityId]
            )->queryScalar();

            if ((int)$exists === 0) {
                Yii::$app->db->createCommand(
                    "INSERT INTO professional_localities (professional_id, localities_id)
                     VALUES (:pid, :lid)",
                    [':pid' => $pid, ':lid' => $localityId]
                )->execute();
                $added++;
            } else {
                $skippedDuplicate++;
            }
        }

        // כתיבת פלט אי-התאמות ל-CSV
        // $this->writeUnmatchedCsv($unmatchedRecords, $unmatchedOutPath);

        echo "בוצעו {$added} החדרות. דילגתי על {$skippedNoMatch} כתובות ללא התאמה ב־CSV ו־{$skippedDuplicate} כפילויות.\n";
        // echo "קובץ אי-התאמות נכתב ל: {$unmatchedOutPath} (סה\"כ " . count($unmatchedRecords) . " שורות).\n";
        return 0;
    }

    /**
     * טוען את ה־CSV למפה: City_Normal (מנורמל) -> id
     * עמיד לשורות עם עמודות חסרות/עודפות ול־BOM.
     */
    private function loadCityMap(string $csvPath)
    {
        $map = [];

        if (($h = fopen($csvPath, 'r')) === false) {
            echo "נכשל בפתיחת הקובץ לקריאה: {$csvPath}\n";
            return $map;
        }

        $headers = fgetcsv($h);
        if ($headers === false) {
            echo "לא נמצאה שורת כותרות ב־CSV.\n";
            fclose($h);
            return $map;
        }

        // הסרת BOM מהשם הראשון אם קיים
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }

        // מאתרים את האינדקסים לעמודות הנדרשות
        $idxCity = array_search('city_google', $headers, true);
        $idxId   = array_search('id', $headers, true);

        if ($idxCity === false || $idxId === false) {
            echo "הכותרות החסרות: חייבים 'city_google' ו־'id'. כותרות בפועל: " . implode(',', $headers) . "\n";
            fclose($h);
            return $map;
        }

        $lineNo = 1; // אחרי הכותרות
        while (($row = fgetcsv($h)) !== false) {
            $lineNo++;

            // מדלגים על שורות ריקות
            if ($row === [null] || $row === [] || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }

            // אם לשורה יש פחות עמודות מהנדרש — נדלג ולא נקריס
            if (!array_key_exists($idxCity, $row) || !array_key_exists($idxId, $row)) {
                continue;
            }

            $cityRaw = (string)$row[$idxCity];
            $idRaw   = (string)$row[$idxId];

            $city = $this->normalizeCity($cityRaw);
            $id   = trim($idRaw);

            if ($city === '' || $id === '') {
                continue;
            }

            $map[$city] = $id;
        }

        fclose($h);
        return $map;
    }

    /**
     * כותב קובץ CSV של כל השורות שלא נמצאה להן התאמה.
     *
     * @param array  $rows    מערך של ['professional_id'=>int,'city_normal_original'=>string,'city_normal_normalized'=>string]
     * @param string $outPath נתיב קובץ הפלט
     */
    private function writeUnmatchedCsv(array $rows, string $outPath): void
    {
        // פתיחה לכתיבה; נדרוס אם קיים
        $fp = @fopen($outPath, 'w');
        if ($fp === false) {
            echo "אזהרה: נכשל בפתיחת קובץ הפלט לכתיבה: {$outPath}\n";
            return;
        }

        // כתיבת BOM כדי ש-Excel יציג עברית תקינה
        fwrite($fp, "\xEF\xBB\xBF");

        // כותרות
        fputcsv($fp, ['professional_id', 'city_normal_original', 'city_normal_normalized']);

        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['professional_id'],
                (string)$r['city_normal_original'],
                (string)$r['city_normal_normalized'],
            ]);
        }

        fclose($fp);
    }

    /**
     * נירמול שם עיר: הסרת NBSP, קיטום, דחיסת רווחים מרובים.
     */
    private function normalizeCity(string $s): string
    {
        // המרת NBSP לרווח רגיל
        $s = str_replace("\xC2\xA0", ' ', $s);
        // קיטום
        $s = trim($s);
        // המרת סדרות רווחים לרווח יחיד
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s ?? '';
    }
    public function actionUpdateCitiesFromCsv()
    {
        // נתיב הקובץ שלך
        $filePath = 'professional_address (8).csv';

        try {
            // קריאה לפונקציה שתעדכן את העיר מתוך הקובץ
            $result = $this->updateCityFromCSV($filePath);
            echo $result;
        } catch (ErrorException $e) {
            echo "שגיאה: " . $e->getMessage();
        }
    }
    private function updateCityFromCsv($filePath)
    {
        
        try {
            // פותחים את הקובץ
            if (($handle = fopen($filePath, 'r')) !== false) {
                // מחמירים על השורה הראשונה אם יש כותרות
                $header = fgetcsv($handle);

                // קריאת כל שורה בקובץ
                while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                    $id = $data[0];  // העמודה הראשונה עבור id
                    $city_normal = $data[3];  // העמודה השנייה עבור city_normal

                    // עדכון העיר בטבלת professional_address
                    Yii::$app->db->createCommand()
                        ->update('professional_address', ['city_normal' => $city_normal], ['id' => $id])
                        ->execute();
                }

                fclose($handle);  // סגירת הקובץ
            } else {
                throw new Exception("לא ניתן לפתוח את הקובץ.");
            }

            return "הנתונים עודכנו בהצלחה!";
        } catch (Exception $e) {
            return "אירעה שגיאה: " . $e->getMessage();
        }
    }

}
