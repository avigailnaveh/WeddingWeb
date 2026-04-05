<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class FnxController extends Controller {

    public function actionImportFnx()
{
    echo "\n🔍 התחלת הפונקציה...";
    
    $categories = ArrayHelper::map(
        (new Query())->select(['id', 'name'])->from('category')->all(),
        'name',
        'id'
    );
    echo "\n📊 נטענו " . count($categories) . " קטגוריות";

    $expertiseList = ArrayHelper::map(
        (new Query())->select(['id', 'name'])->from('expertise')->all(),
        'name',
        'id'
    );
    echo "\n🎯 נטענו " . count($expertiseList) . " תחומי מומחיות";

    $languagesList = ArrayHelper::map(
        (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
        'name',
        'id'
    );
    echo "\n🗣️ נטענו " . count($languagesList) . " שפות";

    $rows = (new Query())->from('_fnx')->each(200);
    echo "\n🚀 התחיל עיבוד נתונים...";
    
    $counter = 0;
    foreach ($rows as $row) {
        $counter++;
        echo "\n⏳ מעבד שורה מספר: {$counter}";
        
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
            "הפרעות קשב וריכוז",
            "תכנית ביקור בריא",
        ];

        // שדות מטבלת Meuhedet
        $fullName = trim($row['doctorName'] ?? '');
        $specialization = trim($row['expertise'] ?? '');
        $specialistCert = trim($row['subExpertise'] ?? '');
        $licenseFromMeuhedet = trim($row['licenceNumber'] ?? '');
        $title = trim($row['title'] ?? '');
        $gender = trim($row['gender'] ?? '');
        $languages = trim($row['languages'] ?? '');
        $phoneNumbers = trim($row['clinicPhoneSecondary'] ?? '');
        $address = trim($row['clinicStreesName'] ?? '');
        $city = trim($row['clinicCity'] ?? '');
        $houseNumber = trim($row['clinicHouseNumber'] ?? '');

        echo "\n👤 מעבד: {$fullName} | רישיון: {$licenseFromMeuhedet}";

        // בדיקת מילות החרגה בשם מלא
        $shouldSkip = false;
        foreach ($excludeWords as $word) {
            if (mb_stripos($fullName, $word) !== false) {
                echo "\n❌ מדלג על {$fullName} בגלל המילה: {$word}";
                $shouldSkip = true;
                break;
            }
        }
        
        if ($shouldSkip) {
            continue;
        }

        // בדיקה אם ההתמחות מחייבת התאמה לפי שם
        $isNeedsMatchByName = in_array($specialization, $needsMatchByName) || in_array($specialistCert, $needsMatchByName);

        if (!empty($licenseFromMeuhedet)) {
            echo "\n🔍 מחפש לפי רישיון: {$licenseFromMeuhedet}";
            
            $foundProfessional = Professional::find()
                    ->innerJoin('professional_insurance', 'professional_insurance.professional_id = professional.id')
                    ->where(['professional.license_id' => $licenseFromHarel])
                    ->andWhere(['professional_insurance.insurance_id' => 5]) 
                    ->one();

            if ($foundProfessional) {
                echo "\n✅ נמצא רופא קיים עם רישיון תואם";
                // אם נמצא רופא תואם, מעדכנים אותו ישירות
                $foundProfessional->full_name = $fullName;
                $foundProfessional->first_name = $fullName;
                $foundProfessional->title = $title;

                // עדכון נוסף של שדות
                if ($gender !== '') {
                    $foundProfessional->gender = $gender;
                }

                if (!$foundProfessional->save()) {
                    echo "\n❌ שגיאה בעדכון רופא קיים";
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\n✅ רופא עודכן בהצלחה";
                }
            } else {
                echo "\n🔍 לא נמצא רופא עם רישיון תואם, מחפש חלופות...";
                // אם לא נמצא רופא תואם, מחפשים את כל הרופאים עם ההתאמות הבסיסיות
                $foundProfessionals = Professional::find()
                    ->where(['license_id' => $licenseFromMeuhedet])
                    ->orWhere(['license_id_v1' => $licenseFromMeuhedet])
                    ->orWhere(['license_id_v2' => $licenseFromMeuhedet])
                    ->all();

                if (!empty($foundProfessionals)) {
                    echo "\n🔍 נמצאו " . count($foundProfessionals) . " רופאים עם רישיון דומה";
                    $nameMatchedProfessionals = [];
                    foreach ($foundProfessionals as $foundProf) {
                        // אם שמו תואם (על פי ההשוואה לפי השם המלא)
                        if ($this->isNameMatchByFullName($foundProf, $fullName)) {
                            $nameMatchedProfessionals[] = $foundProf;
                        }
                    }

                    if (!empty($nameMatchedProfessionals)) {
                        echo "\n✅ נמצאו " . count($nameMatchedProfessionals) . " רופאים עם שם תואם";
                        foreach ($nameMatchedProfessionals as $professional) {
                            $professional->full_name = $fullName;
                            $professional->first_name = $fullName;
                            $professional->title = $title;

                            if ($professional->license_id !== $licenseFromMeuhedet) {
                                if (
                                    $professional->license_id_v1 === $licenseFromMeuhedet ||
                                    $professional->license_id_v2 === $licenseFromMeuhedet
                                ) {
                                    $professional->license_id_v0 = $licenseFromMeuhedet;
                                }
                            }

                            if ($gender !== '') {
                                $professional->gender = $gender;
                            }

                            if (!$professional->save()) {
                                echo "\n❌ שגיאה בעדכון רופא קיים";
                                print_r($professional->getErrors());
                            }

                            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
                        }
                    } else {
                        echo "\n➕ יוצר רופא חדש - לא נמצא שם תואם";
                        // יצירת מקצוען חדש
                        $professional = new Professional();
                        $professional->full_name = $fullName;
                        $professional->first_name = $fullName;
                        $professional->title = $title;

                        if ($isNeedsMatchByName) {
                            $professional->license_id = 'nm-' . $licenseFromMeuhedet;
                        } else {
                            $professional->license_id = $licenseFromMeuhedet;
                        }

                        if ($gender !== '') {
                            $professional->gender = $gender;
                        }

                        // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                        $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromMeuhedet);
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

                        if (trim($fullName) === '') {
                            echo "\n⚠️ שם ריק בשורה: " . json_encode($row, JSON_UNESCAPED_UNICODE);
                        }

                        if (!$professional->save()) {
                            echo "\n❌ שגיאה בשמירת רופא חדש עם רישיון שונה";
                            print_r($professional->getErrors());
                            continue;
                        }

                        $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
                    }
                }
            }
        } else {
            echo "\n📝 רישיון ריק - יוצר רופא חדש";
            // רישיון ריק
            $nameMatches = $this->findProfessionalsByFullName($fullName);

            $professional = new Professional();
            $professional->full_name = $fullName;
            $professional->first_name = $fullName;
            $professional->title = $title;

            if ($isNeedsMatchByName) {
                $professional->license_id = 'nm';
            }

            if ($gender !== '') {
                $professional->gender = $gender;
            }

            if (!empty($nameMatches)) {
                $newIds = array_map(fn($m) => $m->id, $nameMatches);
                $existingIdsRaw = $professional->same_license_different_name ?? '';
                $existingIds = array_filter(array_map('trim', explode(',', $existingIdsRaw)));
                $allIds = array_unique(array_merge($existingIds, $newIds));
                $professional->same_license_different_name = implode(',', $allIds);
            }

            if (trim($fullName) === '') {
                echo "\n⚠️ שם ריק בשורה: " . json_encode($row, JSON_UNESCAPED_UNICODE);
            }

            if (!$professional->save()) {
                echo "\n❌ שגיאה בשמירת רופא חדש ללא רישיון";
                print_r($professional->getErrors());
                continue;
            }

            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
        }
        
        // הפסקה כל 10 שורות כדי לא להציף
        if ($counter % 10 === 0) {
            echo "\n⏸️ הפסקה קצרה...";
            sleep(1);
        }
    }

    echo "\n\n🎉 ייבוא הסתיים בהצלחה. עובדו {$counter} שורות.\n";
}


    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }


    private function isNameMatchByFullName($professional, $meuhedetFullName)
    {
        if (empty($professional->full_name)) {
            $professional->full_name = $professional->first_name . ' ' . $professional->last_name;
        }

        $professionalArray = preg_split('/\s+/', trim($professional->full_name ?? ''));
        $matchedProfessionalArray = preg_split('/\s+/', trim($meuhedetFullName));

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

    private function findProfessionalsByFullName($fullName)
    {
        $professionals = Professional::find()->each(200);
        $matches = [];

        foreach ($professionals as $professional) {
            if ($this->isNameMatchByFullName($professional, $fullName)) {
                $matches[] = $professional;
            }
        }

        return $matches;
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, &$languagesList, $specialization, $specialistCert)
    {
        // עדכון התמחויות - הן מ-Specialization והן מ-SpecialistCert
        $specializations = [];

        // הוספת Specialization (פירוק לפי |)
        if (!empty($specialization)) {
            $specParts = explode('|', $specialization);
            foreach ($specParts as $spec) {
                $spec = trim($spec);
                if (!empty($spec)) {
                    $specializations[] = $spec;
                }
            }
        }

        if (!empty($specialistCert)) {
            $certParts = explode('|', $specialistCert);
            foreach ($certParts as $cert) {
                $cert = trim($cert);
                if (!empty($cert)) {
                    $specializations[] = $cert;
                }
            }
        }

        // עיבוד כל ההתמחויות
        foreach ($specializations as $spec) {
            $cleaned = trim($spec);
            if ($cleaned === '') continue;

            // בדיקה אם קיימת כקטגוריה
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

        
        $insuranceName = 'הפניקס';
        $ins = (new Query())
            ->select('id')
            ->from('insurance')
            ->where(['name' => $insuranceName])
            ->one();

        if (!$ins) {
            Yii::$app->db->createCommand()
                ->insert('insurance', ['name' => $insuranceName])
                ->execute();
            $insuranceId = Yii::$app->db->getLastInsertID();
        } else {
            $insuranceId = $ins['id'];
        }

        $exists = (new Query())
            ->from('professional_insurance')
            ->where([
                'professional_id' => $professional->id,
                'insurance_id' => $insuranceId,
            ])->exists();

        if (!$exists) {
            Yii::$app->db->createCommand()->insert('professional_insurance', [
                'professional_id' => $professional->id,
                'insurance_id' => $insuranceId,
            ])->execute();
        }

        // עדכון כתובת
        $city = trim($row['clinicCity'] ?? '');
        $street = trim($row['clinicStreetName'] ?? '');
        $numberRaw = trim($row['clinicHouseNumber'] ?? '');
        $number = is_numeric($numberRaw) ? (int)$numberRaw : null;

        $address = ProfessionalAddress::findOne([
            'professional_id' => $professional->id,
            'city' => $city,
            'street' => $street,
            'house_number' => $number,
        ]);

        $phones = [];
        $phoneNumbers = $row['clinicPhoneSecondary'] ?? '';
        $otherPhones = explode('|', $phoneNumbers); // שינוי מ-| ל-,
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
            $address->type = 'הפניקס';
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

        
        $address->save();

        // עדכון שפות
        $langs = explode('|', $row['Languages'] ?? ''); // שינוי מ-| ל-,
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

    public static function cleanPhone($phone)
    {
        if (!$phone || preg_match('/[a-zA-Z@]/', $phone)) {
            return null;
        }

        $phone = explode('/', $phone)[0];
        return preg_replace('/[^0-9*]/', '', $phone);
    }
}
