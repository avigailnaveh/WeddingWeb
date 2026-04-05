<?php
namespace app\commands;

use yii\console\Controller;
use yii\db\Query;
use Yii;

class LicenseCompareController extends Controller
{
    public function actionCompare()
    {
        echo "התחלתי בתהליך השוואת הנתונים בין טבלת professional לטבלת clalit_..." . PHP_EOL;

        // מספר רשומות לכל "חבילה"
        $batchSize = 20;
        $lastId = 0;

        // שם הקובץ המלא
        $filename = 'license_comparison_results_With_contained_fields2.csv';

        // פתיחת קובץ CSV פעם אחת בלבד
        $file = fopen($filename, 'w');
        fputcsv($file, ['ID', 'First Name', 'Last Name', 'License ID', 'Clalit License', 'Clalit First Name', 'Clalit Last Name', 'Match Type']);

        // חיפוש כל הרשומות בטבלה professional בכמה חלקים
        while (true) {
            // ביצוע השאילתה להשגת נתונים מהטבלאות עבור 20 רשומות
            $results = (new Query())
                ->select([
                    'p.id',
                    'p.first_name',
                    'p.last_name',
                    'p.license_id',
                    'c.license',
                    'c.efirstname',
                    'c.elastname'
                ])
                ->from('professional p')
                ->innerJoin('clalit_ c', 'CAST(REPLACE(p.license_id, "-", "") AS CHAR) = CAST(REPLACE(c.license, "-", "") AS CHAR)')
                ->where(['>', 'p.id', $lastId])
                ->limit($batchSize)
                ->all();

            // אם אין תוצאות, יצא מהלולאה
            if (empty($results)) {
                break;
            }

            // עדכון ה-lastId
            $lastId = end($results)['id'];

            // אחסון שורות תוצאות להכתבה בבת אחת
            $rowsToWrite = [];

            // עיבוד כל השורות שנמצאו
            foreach ($results as $row) {
                $licenseId = $row['license_id'];
                $clalitLicense = $row['license'];
                $firstName = $row['first_name'];
                $lastName = $row['last_name'];
                $clalitFirstName = $row['efirstname'];
                $clalitLastName = $row['elastname'];

                // התאמה בין ה-IDs - בודקים את כל האפשרויות
                $matchType = $this->determineMatchType($licenseId, $clalitLicense);

                // הסרת רווחים, סימנים מיוחדים ותווים לא רצויים מהשמות
                $cleanFirstName = $this->cleanString($firstName);
                $cleanLastName = $this->cleanString($lastName);
                $cleanClalitFirstName = $this->cleanString($clalitFirstName);
                $cleanClalitLastName = $this->cleanString($clalitLastName);

                // נבדוק את כל האפשרויות של מספרי הרישוי
                $possibleLicenses = [
                    $licenseId,
                    str_replace("-", "", $licenseId), // Remove "-"
                    substr($licenseId, strpos($licenseId, "-") + 1) // Remove Prefix before "-"
                ];

                // עבור כל אפשרות של מספר רישוי בודקים אם יש התאמה
                foreach ($possibleLicenses as $possibleLicense) {
                    if ($this->determineMatchType($possibleLicense, $clalitLicense) != 'No Match' && 
                        (
                            (strpos($cleanClalitFirstName, $cleanFirstName) !== false || strpos($cleanFirstName, $cleanClalitFirstName) !== false) &&
                            (strpos($cleanClalitLastName, $cleanLastName) !== false || strpos($cleanLastName, $cleanClalitLastName) !== false)
                        )
                    ) {
                        $rowsToWrite[] = [
                            $row['id'], 
                            $row['first_name'], 
                            $row['last_name'], 
                            $row['license_id'], 
                            $row['license'], 
                            $row['efirstname'], 
                            $row['elastname'],
                            $this->determineMatchType($possibleLicense, $clalitLicense)
                        ];
                    }
                }

                // כל 100 שורות, הדפס הודעה
                if (($lastId % 100) == 0) {
                    echo "הושוו " . $lastId . " שורות עד כה..." . PHP_EOL;
                }
            }

            // כתיבה לקובץ במנות
            if (!empty($rowsToWrite)) {
                foreach ($rowsToWrite as $data) {
                    fputcsv($file, $data);
                }
            }
        }

        fclose($file);
        echo "הנתונים נשמרו בהצלחה בקובץ: " . $filename . PHP_EOL;
    }

    // פונקציה להסיר רווחים, סימנים מיוחדים ותווים לא רצויים מהשמות
    private function cleanString($string)
    {
        // הסרת רווחים מההתחלה והסוף
        $string = trim($string);

        // הסרת רווחים פנימיים מיותרים
        $string = preg_replace('/\s+/', ' ', $string);

        // הסרת סימנים מיוחדים (כולל מרכאות, פסיקים, נקודות וכו')
        $string = preg_replace('/[^a-zA-Zא-ת\s]/', '', $string);  // שים לב כאן נוספה תמיכה גם בעברית

        // המרת כל האותיות לאותיות קטנות לצורך השוואה אחידה
        return strtolower($string);
    }

    // פונקציה לבדוק סוג ההתאמה
    private function determineMatchType($licenseId, $clalitLicense)
    {
        if ($licenseId == $clalitLicense) {
            return 'Exact Match';
        }
        elseif (str_replace("-", "", $licenseId) == $clalitLicense) {
            return 'Match After Removing "-"';
        }
        elseif (substr(strrchr($licenseId, "-"), 1) == $clalitLicense) {
            return 'Match After Removing Prefix Before "-"';
        }
        return 'No Match';
    }
}
