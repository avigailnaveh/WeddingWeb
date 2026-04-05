<?php  
namespace app\commands;  

use yii\console\Controller; 
use yii\helpers\ArrayHelper; 
use app\models\Professional; 
use app\models\ProfessionalAddress; 
use yii\db\Query; 
use Yii;  

class ClalitController extends Controller {     
    
    public function actionImportClalit()
    {
        $categories = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('category')->all(),
            'name',
            'id'
        );

        $expertiseList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('expertise')->all(),
            'name',
            'id'
        );

        $languagesList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
            'name',
            'id'
        );

        $rows = (new Query())->from('_clalit2')->each(200);
        echo "\nהתחיל...";

        foreach ($rows as $row) {
            
            $excludeWords = [
                'רופא מצוות',
                'צוות המרפאה',
                'אחות אחראית',
                'צוות',
                'מנהל',
                'אחות',
                'עובדת סוציאלית',
                'מרפאת',
                'מרפאה'
            ];

            $needsMatchByName = [
                'אבחון פסיכו-סוציאלי על ידי עובדת סוציאלית',
                'עבודה סוציאלית',
                'עבודה סוציאלית ילדים',
                'שיקום לב - התעמלות שיקומית',
                'פיזיותרפיה',
                'פיזיותרפיה ילדים',
                'פיזיותרפיה לחולי CF',
                'פיזיותרפיה - לימפאדמה',
                'פיזיותרפיה - שיקום רצפת האגן',
                'תזונה קלינית',
                'תזונה קלינית  - סוכרת הריונית',
                'תזונה קלינית - בריאטריה',
                'תזונה קלינית - גסטרו',
                'תזונה קלינית - הפרעות אכילה',
                'תזונה קלינית - ילדים',
                'תזונה קלינית - נפרולוגיה',
                'ייעוץ הנקה',
                'נטורופתיה',
                'עיסוי הוליסטי',
                'רפלקסולוגיה',
                'שחיה טיפולית (הידרותרפיה)',
                'טווינא',
                'כירופרקטיקה'
            ];

            $firstName = trim($row['efirstname'] ?? '');
            $lastName = trim($row['elastname'] ?? '');

            foreach ($excludeWords as $word) {
                if (mb_stripos($firstName, $word) !== false) {
                    continue 2;
                }
            }

            $desc = trim($row['eservicedesription'] ?? '');
            $licenseFromClalit = trim($row['license'] ?? '');

            echo "licenseFromClalit: " . $licenseFromClalit;

            $isNeedsMatchByName = in_array($desc, $needsMatchByName);

            if (!empty($licenseFromClalit)) {
                $foundProfessionals = Professional::find()
                    ->where(['license_id' => $licenseFromClalit])
                    ->orWhere(['license_id_v1' => $licenseFromClalit])
                    ->orWhere(['license_id_v2' => $licenseFromClalit])
                    ->all();

                if (!empty($foundProfessionals)) {
                    echo "is not new professional" ;
                    $nameMatchedProfessionals = [];
                    foreach ($foundProfessionals as $foundProf) {
                        if ($this->isNameMatch($foundProf, $firstName, $lastName)) {
                            $nameMatchedProfessionals[] = $foundProf;
                        }
                    }

                    if (!empty($nameMatchedProfessionals)) {
                        echo "is not new professional and the name match";
                        foreach ($nameMatchedProfessionals as $professional) {
                            $professional->first_name = $firstName;
                            $professional->last_name = $lastName;
                            $professional->title = trim($row['title'] ?? '');

                            if (
                                $professional->license_id_v1 === $licenseFromClalit ||
                                $professional->license_id_v2 === $licenseFromClalit
                            ) {
                                $professional->license_id_v0 = $licenseFromClalit;
                            }

                            if (isset($row['esexdescription'])) {
                                $genderValue = trim($row['esexdescription']);
                                if ($genderValue !== '') {
                                    $professional->gender = $genderValue;
                                }
                            }
                            if (!$professional->save()) {
                                echo "\nשגיאה בעדכון רופא קיים";
                                print_r($professional->getErrors());
                            }
                            $this->ensureMainSpecialization($professional->id, 4);
                            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $desc);
                        }
                    } else {
                        echo "is not new professional and the name not match";
                        // יצירת מקצוען חדש
                        $professional = new Professional();
                        $professional->first_name = $firstName;
                        $professional->last_name = $lastName;
                        $professional->title = trim($row['title'] ?? '');

                        if ($isNeedsMatchByName) {
                            $professional->license_id = 'nm-' . $licenseFromClalit;
                        } else {
                            $professional->license_id = $licenseFromClalit;
                        }

                        if (isset($row['esexdescription'])) {
                            $genderValue = trim($row['esexdescription']);
                            if ($genderValue !== '') {
                                $professional->gender = $genderValue;
                            }
                        }

                        // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                        $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromClalit);
                        $licenseNoDash = str_replace('-', '', $licenseNumeric);

                        $licenseParts = explode('-', $licenseNumeric);
                        if (count($licenseParts) === 2) {
                            $prefix = preg_replace('/\D/', '', $licenseParts[0]);
                            $suffix = $licenseParts[1];
                            $license_id_v1 = $prefix . $suffix;
                            $license_id_v2 = $suffix;
                        } else {
                            $license_id_v1 = $licenseNoDash;
                            $license_id_v2 = $licenseNoDash;
                        }

                        $professional->license_id_v1 = $license_id_v1;
                        $professional->license_id_v2 = $license_id_v2;

                        $foundIds = array_map(fn($fp) => $fp->id, $foundProfessionals);
                        $foundIds = array_unique($foundIds);
                        $professional->same_license_different_name = implode(',', $foundIds);

                        if (!$professional->save()) {
                            echo "\nשגיאה בשמירת רופא חדש עם רישיון שונה";
                            print_r($professional->getErrors());
                            continue;
                        }
                        $this->ensureMainSpecialization($professional->id, 4);
                        $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $desc);
                    }
                } else {
                    // יצירת מקצוען חדש ללא התאמות קיימות
                    echo "is new professional";
                    $professional = new Professional();
                    $professional->first_name = $firstName;
                    $professional->last_name = $lastName;
                    $professional->title = trim($row['title'] ?? '');

                    if ($isNeedsMatchByName) {
                        $professional->license_id = 'nm-' . $licenseFromClalit;
                    } else {
                        $professional->license_id = $licenseFromClalit;
                    }

                    if (isset($row['esexdescription'])) {
                        $genderValue = trim($row['esexdescription']);
                        if ($genderValue !== '') {
                            $professional->gender = $genderValue;
                        }
                    }

                    // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                    $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromClalit);
                    $licenseNoDash = str_replace('-', '', $licenseNumeric);

                    $licenseParts = explode('-', $licenseNumeric);
                    if (count($licenseParts) === 2) {
                        $prefix = preg_replace('/\D/', '', $licenseParts[0]);
                        $suffix = $licenseParts[1];
                        $license_id_v1 = $prefix . $suffix;
                        $license_id_v2 = $suffix;
                    } else {
                        $license_id_v1 = $licenseNoDash;
                        $license_id_v2 = $licenseNoDash;
                    }

                    $professional->license_id_v1 = $license_id_v1;
                    $professional->license_id_v2 = $license_id_v2;

                    if (!$professional->save()) {
                        echo "\nשגיאה בשמירת רופא חדש";
                        print_r($professional->getErrors());
                        continue;
                    }
                    $this->ensureMainSpecialization($professional->id, 4);
                    $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $desc);
                }
            } else {
                // רישיון ריק
                $nameMatches = $this->findProfessionalsByName($firstName, $lastName);

                $professional = new Professional();
                $professional->first_name = $firstName;
                $professional->last_name = $lastName;
                $professional->title = trim($row['title'] ?? '');

                if ($isNeedsMatchByName) {
                    $professional->license_id = 'nm';
                }

                if (isset($row['esexdescription'])) {
                    $genderValue = trim($row['esexdescription']);
                    if ($genderValue !== '') {
                        $professional->gender = $genderValue;
                    }
                }

                if (!empty($nameMatches)) {
                    $newIds = array_map(fn($m) => $m->id, $nameMatches);
                    $existingIdsRaw = $professional->same_license_different_name ?? '';
                    $existingIds = array_filter(array_map('trim', explode(',', $existingIdsRaw)));
                    $allIds = array_unique(array_merge($existingIds, $newIds));
                    $professional->same_license_different_name = implode(',', $allIds);
                }

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת רופא חדש ללא רישיון";
                    print_r($professional->getErrors());
                    continue;
                }
                $this->ensureMainSpecialization($professional->id, 4);
                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $desc);
            }
        }

        echo "\n\n ייבוא הסתיים בהצלחה.\n";
    }

    
    private function isNameMatch($professional, $clalitFirstName, $clalitLastName)
    {
        // פונקציה לניקוי תווים לפני השוואה
        $normalize = function ($name) {
            $name = mb_strtolower(trim($name));
            // משאיר רק תווים בעברית, באנגלית ובספרות, מסיר את כל השאר
            $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name);
            return $name;
        };


        // פיצול למילים לפי רווח (בלי ניקוי עדיין)
        $professionalArray = preg_split('/\s+/', trim(($professional->first_name ?? '') . ' ' . ($professional->last_name ?? '')));
        $clalitArray = preg_split('/\s+/', trim($clalitFirstName . ' ' . $clalitLastName));

        $matches = 0;
        foreach ($professionalArray as $profPart) {
            foreach ($clalitArray as $clalitPart) {
                if ($normalize($profPart) === $normalize($clalitPart)) {
                    $matches++;
                    if ($matches >= 2) {
                        return true;
                    }
                    break; // עצירת בדיקה של אותו profPart אם כבר נמצא לו התאמה
                }
            }
        }

        return false;
    }


    private function findProfessionalsByName($firstName, $lastName)
    {
        $professionals = Professional::find()->each(200);
        $matches = [];

        foreach ($professionals as $professional) {
            if ($this->isNameMatch($professional, $firstName, $lastName)) {
                $matches[] = $professional;
            }
        }

        return $matches;
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, &$languagesList, $desc)
    {
        // עדכון קטגוריה (רק אם קיימת)
        $cleaned = trim($desc);
        if ($cleaned !== '') {
            if (isset($categories[$cleaned])) {
                $categoryId = $categories[$cleaned];
                $exists = (new Query())->from('professional_categories')->where([
                    'professional_id' => $professional->id,
                    'category_id' => $categoryId,
                ])->exists();
                if (!$exists) {
                    Yii::$app->db->createCommand()->insert('professional_categories', [
                        'professional_id' => $professional->id,
                        'category_id' => $categoryId,
                    ])->execute();
                }
            }

            // תמיד יטופל כמומחיות (גם אם קיים בקטגוריה)
            if (!isset($expertiseList[$cleaned])) {
                Yii::$app->db->createCommand()->insert('expertise', [
                    'name' => $cleaned,
                    'category_id' => null,
                    'sub_category_id' => null,
                ])->execute();
                $newId = Yii::$app->db->getLastInsertID();
                $expertiseList[$cleaned] = $newId;
                echo "\n✅ תחום מומחיות חדש נוסף: $cleaned";
            }

            $expertiseId = $expertiseList[$cleaned];
            $exists = (new Query())->from('professional_expertise')->where([
                'professional_id' => $professional->id,
                'expertise_id' => $expertiseId,
            ])->exists();
            if (!$exists) {
                Yii::$app->db->createCommand()->insert('professional_expertise', [
                    'professional_id' => $professional->id,
                    'expertise_id' => $expertiseId,
                ])->execute();
            }
        }

        // עדכון חברות – נשאר כמו שהיה
        $companyName = null;
        if ($row['eagreementheb'] === 'רופאים של כללית') {
            $companyName = 'כללית';
        } elseif ($row['eagreementheb'] === 'רופאים של כללית מושלם') {
            $companyName = 'כללית מושלם';
        }

        if ($companyName !== null) {
            $company = (new Query())
                ->select(['id'])
                ->from('company')
                ->where(['name' => $companyName])
                ->one();

            if (!$company && $companyName === 'כללית מושלם') {
                Yii::$app->db->createCommand()->insert('company', [
                    'name' => $companyName,
                ])->execute();
                $companyId = Yii::$app->db->getLastInsertID();
            } elseif ($company) {
                $companyId = $company['id'];
            }

            if (isset($companyId)) {
                $companyExists = (new Query())->from('professional_company')->where([
                    'professional_id' => $professional->id,
                    'company_id' => $companyId,
                ])->exists();

                if (!$companyExists) {
                    Yii::$app->db->createCommand()->insert('professional_company', [
                        'professional_id' => $professional->id,
                        'company_id' => $companyId,
                    ])->execute();
                }
            }
        }

        // עדכון כתובת – ללא שינוי
        $city = trim($row['edeptcityname'] ?? '');
        $street = trim($row['edeptstreetname'] ?? '');
        $numberRaw = trim($row['edepthouse'] ?? '');
        $number = is_numeric($numberRaw) ? (int)$numberRaw : null;

        $address = ProfessionalAddress::findOne([
            'professional_id' => $professional->id,
            'city' => $city,
            'street' => $street,
            'house_number' => $number,
        ]);

        $phones = [];
        $otherPhones = explode('|', $row['edeptphones'] ?? '');
        foreach ($otherPhones as $p) {
            $cleaned = self::cleanPhone($p);
            if ($cleaned) $phones[] = $cleaned;
        }
        $phones = array_values(array_unique($phones));

        if (!$address) {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => $city,
                'street' => $street,
                'house_number' => $number,
            ]);
            $existingPhones = [];
        } else {
            $existingPhones = [];
            foreach (['phone', 'phone_2', 'phone_3', 'phone_4'] as $field) {
                $val = trim($address->$field ?? '');
                if ($val !== '') {
                    $existingPhones[] = $val;
                }
            }
        }

        $mergedPhones = array_values(array_unique(array_merge($existingPhones, $phones)));
        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];
        foreach ($phoneFields as $index => $field) {
            $address->$field = $mergedPhones[$index] ?? null;
        }

        $address->type = $companyName ?? null;
        $address->save();

        // עדכון שפות – ללא שינוי
        $langs = explode('|', $row['languages'] ?? '');
        foreach ($langs as $lang) {
            $lang = trim($lang);
            if (!$lang) continue;

            if (!isset($languagesList[$lang])) {
                Yii::$app->db->createCommand()->insert('speaking_language', [
                    'name' => $lang,
                ])->execute();
                $newId = Yii::$app->db->getLastInsertID();
                $languagesList[$lang] = $newId;
                echo "\n✅ שפה חדשה נוספה: {$lang}";
            }

            $langId = $languagesList[$lang];
            $exists = (new Query())->from('professional_language')->where([
                'professional_id' => $professional->id,
                'language_id' => $langId,
            ])->exists();
            if (!$exists) {
                Yii::$app->db->createCommand()->insert('professional_language', [
                    'professional_id' => $professional->id,
                    'language_id' => $langId,
                ])->execute();
            }
        }
    }

    private function ensureMainSpecialization(int $professionalId, int $mainId = 4): void
    {
        $exists = (new Query())
            ->from('professional_main_specialization')
            ->where([
                'professional_id' => $professionalId,
                'main_specialization_id' => $mainId,
            ])->exists();

        if (!$exists) {
            Yii::$app->db->createCommand()->insert('professional_main_specialization', [
                'professional_id' => $professionalId,
                'main_specialization_id' => $mainId,
            ])->execute();
        }
    }



    public static function cleanPhone($phone)
    {
        if (!$phone || preg_match('/[a-zA-Z@]/', $phone)) {
            return null;
        }

        $phone = explode('/', $phone)[0];
        return preg_replace('/[^0-9*]/', '', $phone);
    }


    public function actionUpdateLanguagesOnly(): void
    {
        $languagesList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
            'name',
            'id'
        );

        $rows = (new Query())->from('_clalit2')->each(200);
        echo "\n[שפות בלבד] התחיל...";

        foreach ($rows as $row) {
            $firstName = trim($row['efirstname'] ?? '');
            $lastName  = trim($row['elastname'] ?? '');
            $license   = trim($row['license'] ?? '');

            $matchedProfessionals = [];

            if ($license !== '') {
                $foundProfessionals = Professional::find()
                    ->where(['license_id'   => $license])
                    ->orWhere(['license_id_v1' => $license])
                    ->orWhere(['license_id_v2' => $license])
                    ->all();

                if (empty($foundProfessionals)) {
                    echo "\n[דלג] לא נמצאה התאמה לפי רישיון: {$license} ({$firstName} {$lastName})";
                    continue;
                }

                foreach ($foundProfessionals as $p) {
                    if ($this->isNameMatch($p, $firstName, $lastName)) {
                        $matchedProfessionals[] = $p;
                    }
                }

                if (empty($matchedProfessionals)) {
                    echo "\n[דלג] נמצאו לפי רישיון אבל אין התאמה בשם: {$license} ({$firstName} {$lastName})";
                    continue;
                }
            } else {
                $matchedProfessionals = $this->findProfessionalsByName($firstName, $lastName);
                if (empty($matchedProfessionals)) {
                    echo "\n[דלג] אין רישיון ולא נמצאה התאמה בשם: ({$firstName} {$lastName})";
                    continue;
                }
            }

            $langsRaw = $row['languages'] ?? '';
            $langs = array_filter(array_map('trim', explode(',', $langsRaw)));
            if (empty($langs)) {
                echo "\n[מידע] אין שפות לעדכן עבור: ({$firstName} {$lastName})";
                continue;
            }

            foreach ($matchedProfessionals as $professional) {
                foreach ($langs as $lang) {
                    if ($lang === '') continue;

                    if (!isset($languagesList[$lang])) {
                        Yii::$app->db->createCommand()->insert('speaking_language', [
                            'name' => $lang,
                        ])->execute();
                        $languagesList[$lang] = (int)Yii::$app->db->getLastInsertID();
                        echo "\n נוצרה שפה חדשה: {$lang}";
                    }

                    $langId = $languagesList[$lang];

                    $exists = (new Query())->from('professional_language')->where([
                        'professional_id' => $professional->id,
                        'language_id'     => $langId,
                    ])->exists();

                    if (!$exists) {
                        Yii::$app->db->createCommand()->insert('professional_language', [
                            'professional_id' => $professional->id,
                            'language_id'     => $langId,
                        ])->execute();
                        echo "\nנוספה שפה '{$lang}' ל־Professional #{$professional->id}";
                    }
                }
            }
        }

        echo "\n[שפות בלבד] הסתיים.\n";
    }

}