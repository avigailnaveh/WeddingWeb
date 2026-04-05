<?php

namespace app\commands;
use yii\console\Controller;
use yii\helpers\ArrayHelper;

use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;

use Yii;

class MaccabiController extends Controller
{
    public function actionImportMaccabi()
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

        $rows = (new Query())->from('_maccabi')->each(200);

        foreach ($rows as $row) {
            $licenseId = trim($row['license_number']);
            if (!$licenseId) continue;

            $professional = Professional::findOne(['license_id' => $licenseId]);

            if (!$professional) {
                $professional = new Professional();
                $professional->license_id = $licenseId;

                // הגדרת license_id_v1 ו-license_id_v2 לפי license_id
                if (strpos($licenseId, '-') !== false) {
                    $parts = explode('-', $licenseId, 2);
                    $professional->license_id_v1 = str_replace('-', '', $licenseId);
                    $professional->license_id_v2 = $parts[1];
                } else {
                    $professional->license_id_v1 = $licenseId;
                    $professional->license_id_v2 = $licenseId;
                }
            }

            $professional->first_name = trim($row['first_name']);
            $professional->last_name = trim($row['last_name']);
            $professional->title = trim($row['title']);

            if (isset($row['gender'])) {
                $genderValue = trim($row['gender']);
                if ($genderValue !== '' && (empty($professional->gender) || $professional->gender === null)) {
                    $professional->gender = $genderValue;
                }
            }

            if (!$professional->save()) {
                echo "\nשגיאה בשמירת רופא {$licenseId}";
                print_r($professional->getErrors());
                continue;
            }

            // שיוך קטגוריות
            for ($i = 1; $i <= 3; $i++) {
                $val = trim($row["service_name_$i"] ?? '');
                if ($val) {
                    $cleaned = preg_replace('/^\s*מומח(?:ה|ית)\s*(?:ב\s*)?/u', '', $val);
                    $cleaned = trim($cleaned);
                    if (!isset($categories[$cleaned])) {
                        Yii::$app->db->createCommand()->insert('category', [
                            'name' => $cleaned,
                        ])->execute();
                        $newId = Yii::$app->db->getLastInsertID();
                        $categories[$cleaned] = $newId;
                        echo "\n קטגוריה חדשה נוספה: $cleaned";
                    }
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
            }

            // שיוך תחומי מומחיות
            for ($i = 1; $i <= 6; $i++) {
                $val = trim($row["treat_area_$i"] ?? '');
                if ($val) {
                    $parts = array_map('trim', explode(',', $val));
                    foreach ($parts as $part) {
                        if (!$part) continue;
                        if (!isset($expertiseList[$part])) {
                            Yii::$app->db->createCommand()->insert('expertise', [
                                'name' => $part,
                                'category_id' => null,
                                'sub_category_id' => null,
                            ])->execute();
                            $newId = Yii::$app->db->getLastInsertID();
                            $expertiseList[$part] = $newId;
                            echo "\n✅ תחום מומחיות חדש נוסף: $part";
                        }
                        $expertiseId = $expertiseList[$part];
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
                }
            }

            // שיוך לחברה
            $companyExists = (new Query())->from('professional_company')->where([
                'professional_id' => $professional->id,
                'company_id' => 3,
            ])->exists();
            if (!$companyExists) {
                Yii::$app->db->createCommand()->insert('professional_company', [
                    'professional_id' => $professional->id,
                    'company_id' => 3,
                ])->execute();
            }

            // כתובת + טלפונים
            $city = trim($row['city_name']);
            $street = trim($row['street_name']);
            $numberRaw = trim($row['house_number']);
            $number = is_numeric($numberRaw) ? (int)$numberRaw : null;

            $address = ProfessionalAddress::findOne([
                'professional_id' => $professional->id,
                'city' => $city,
                'street' => $street,
                'house_number' => $number,
            ]);

            // טלפונים חדשים
            $phones = [];
            $mobile = self::cleanPhone($row['mobile_phone']);
            if ($mobile) $phones[] = $mobile;

            $otherPhones = explode('|', $row['phone_numbers'] ?? '');
            foreach ($otherPhones as $p) {
                $cleaned = self::cleanPhone($p);
                if ($cleaned) $phones[] = $cleaned;
            }

            $phones = array_values(array_unique($phones));

            // טלפונים קיימים (אם יש כתובת קיימת)
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

            // מיזוג טלפונים ללא כפילויות
            $mergedPhones = array_values(array_unique(array_merge($existingPhones, $phones)));

            // הכנסת טלפונים עד 4
            $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];
            foreach ($phoneFields as $index => $field) {
                $address->$field = $mergedPhones[$index] ?? null;
            }

            // קביעת סוג מרפאה
            $address->type = 'מכבי';

            if (!$address->save()) {
                echo "\nשגיאה בשמירת כתובת לרופא {$licenseId}";
                print_r($address->getErrors());
            }

            // שפות
            $langs = explode('|', $row['languages'] ?? '');
            foreach ($langs as $lang) {
                $lang = trim($lang);
                if (!$lang) continue;

                if (!isset($languagesList[$lang])) {
                    // הכנסת שפה חדשה
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

        echo "\n\n ייבוא הסתיים בהצלחה.\n";
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
