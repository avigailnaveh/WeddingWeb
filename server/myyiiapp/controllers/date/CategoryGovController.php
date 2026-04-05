<?php

namespace app\commands;

use yii\console\Controller;
use PhpOffice\PhpSpreadsheet\IOFactory;
use app\models\Professional;
use Yii;

class CategoryGovController extends Controller
{
    public function actionIndex()
    {
        $csvPath = 'gov_certificates_normalized.csv';

        if (!file_exists($csvPath)) {
            echo "❌ הקובץ $csvPath לא נמצא.\n";
            return;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            echo "❌ לא הצלחתי לפתוח את הקובץ.\n";
            return;
        }

        $rawLine = fgets($handle); // קרא שורת כותרת גולמית
        $rawLine = preg_replace('/^\xEF\xBB\xBF/', '', $rawLine); // הסרת BOM
        $header = array_map('trim', str_getcsv($rawLine)); // פיצול שדות


        $licenseIdIndex = array_search('licenseId2', $header);
        $normalizedSpecialtiesIndex = array_search('normalized_specialties', $header);
        $fullNameIndex = array_search('full_name', $header);
        var_dump($licenseIdIndex);
        echo "$normalizedSpecialtiesIndex \n";
        echo "$fullNameIndex \n";
        if ($licenseIdIndex === false || $normalizedSpecialtiesIndex === false || $fullNameIndex === false) {
            echo "❌ הקובץ לא כולל את כל השדות הדרושים.\n";
            return;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $licenseId = trim($row[$licenseIdIndex]);
            $specialties = trim($row[$normalizedSpecialtiesIndex]);
            $csvFullName = trim($row[$fullNameIndex]);

            if (empty($licenseId) || empty($specialties)) {
                continue;
            }

            $foundProfessionals = $this->findAllProfessionalsByLicense($licenseId);

            if (empty($foundProfessionals)) {
                echo "⚠️ לא נמצאו רופאים עם licenseId: $licenseId\n";
                continue;
            }

            $matchedProfessionals = array_filter($foundProfessionals, function($prof) use ($csvFullName) {
                return $this->isNameMatchByFullName($prof, $csvFullName);
            });

            if (empty($matchedProfessionals)) {
                echo "⚠️ לא נמצאה התאמה בשם לרישיון $licenseId\n";
                continue;
            }

            $specialties = array_map('trim', explode(',', $specialties));

            foreach ($specialties as $specialtyName) {
                if ($specialtyName === '') continue;

                $specializationId = (new \yii\db\Query())
                    ->select('id')
                    ->from('main_specialization')
                    ->where(['name' => $specialtyName])
                    ->scalar();

                if (!$specializationId) {
                    Yii::$app->db->createCommand()->insert('main_specialization', [
                        'name' => $specialtyName,
                    ])->execute();
                    $specializationId = Yii::$app->db->getLastInsertID();
                    echo "➕ נוצרה התמחות חדשה: $specialtyName (ID: $specializationId)\n";
                }

                foreach ($matchedProfessionals as $prof) {
                    $alreadyLinked = (new \yii\db\Query())
                        ->from('professional_main_specialization')
                        ->where([
                            'professional_id' => $prof->id,
                            'main_specialization_id' => $specializationId
                        ])
                        ->exists();

                    if (!$alreadyLinked) {
                        Yii::$app->db->createCommand()->insert('professional_main_specialization', [
                            'professional_id' => $prof->id,
                            'main_specialization_id' => $specializationId,
                        ])->execute();

                        echo " קושר רופא {$prof->id} ← $specialtyName\n";
                    } else {
                        echo " כבר קיים קשר: רופא {$prof->id} ← $specialtyName\n";
                    }
                }
            }
        }

        // יצירת קובץ לרופאים בלי main_specialization אך עם expertise
        $noMainSpecProfessionals = Yii::$app->db->createCommand("
            SELECT p.id, p.license_id, p.full_name
            FROM professional p
            WHERE NOT EXISTS (
                SELECT 1 FROM professional_main_specialization pms
                WHERE pms.professional_id = p.id
            )
            AND EXISTS (
                SELECT 1 FROM professional_expertise pe
                WHERE pe.professional_id = p.id
            )
        ")->queryAll();

        foreach ($noMainSpecProfessionals as &$prof) {
            $expertiseNames = Yii::$app->db->createCommand("
                SELECT e.name
                FROM professional_expertise pe
                JOIN expertise e ON e.id = pe.expertise_id
                WHERE pe.professional_id = :pid
            ")->bindValue(':pid', $prof['id'])->queryColumn();

            $prof['expertise_names'] = implode(', ', $expertiseNames);
        }

        $outputPath = 'professionals_without_main_specialization.csv';
        $fp = fopen($outputPath, 'w');
        fputcsv($fp, ['license_id', 'id', 'full_name', 'expertise_names']);

        foreach ($noMainSpecProfessionals as $prof) {
            fputcsv($fp, [$prof['license_id'], $prof['id'], $prof['full_name'], $prof['expertise_names']]);
        }

        fclose($fp);
        echo " נוצר הקובץ: $outputPath עם רופאים ללא התמחות ראשית\n";
    }

    

    private function findAllProfessionalsByLicense($licenseFromAyalon)
    {
        $foundProfessionals = [];

        // חיפוש רגיל
        $regularSearch = Professional::find()
            ->where(['license_id' => $licenseFromAyalon])
            ->orWhere(['license_id_v1' => $licenseFromAyalon])
            ->orWhere(['license_id_v2' => $licenseFromAyalon])
            ->all();

        $foundProfessionals = array_merge($foundProfessionals, $regularSearch);

        // אם יש מקף ברישיון, חיפושים נוספים
        if (strpos($licenseFromAyalon, '-') !== false) {
            $licenseWithoutDash = str_replace('-', '', $licenseFromAyalon);
            $licenseParts = explode('-', $licenseFromAyalon);
            $rightPart = count($licenseParts) > 1 ? $licenseParts[1] : '';

            // חיפוש לפי רישיון ללא מקף
            $noDashSearch = Professional::find()
                ->where(['license_id' => $licenseWithoutDash])
                ->orWhere(['license_id_v1' => $licenseWithoutDash])
                ->orWhere(['license_id_v2' => $licenseWithoutDash])
                ->all();

            $foundProfessionals = array_merge($foundProfessionals, $noDashSearch);

            // חיפוש לפי החלק הימני בלבד
            if (!empty($rightPart)) {
                $rightPartSearch = Professional::find()
                    ->where(['license_id' => $rightPart])
                    ->orWhere(['license_id_v1' => $rightPart])
                    ->orWhere(['license_id_v2' => $rightPart])
                    ->all();

                $foundProfessionals = array_merge($foundProfessionals, $rightPartSearch);
            }
        }

        // הסרת כפילויות
        $uniqueProfessionals = [];
        $seenIds = [];
        foreach ($foundProfessionals as $prof) {
            if (!in_array($prof->id, $seenIds)) {
                $uniqueProfessionals[] = $prof;
                $seenIds[] = $prof->id;
            }
        }

        return $uniqueProfessionals;
    }
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function isNameMatchByFullName($professional, $ayalonFullName)
    {
        if (empty($professional->full_name)) {
            $professional->full_name = $professional->first_name . ' ' . $professional->last_name;
        }

        $professionalArray = preg_split('/\s+/', trim($professional->full_name ?? ''));
        $matchedProfessionalArray = preg_split('/\s+/', trim($ayalonFullName));

        $professionalArray = array_map([$this, 'normalize'], $professionalArray);
        $matchedProfessionalArray = array_map([$this, 'normalize'], $matchedProfessionalArray);

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

}
