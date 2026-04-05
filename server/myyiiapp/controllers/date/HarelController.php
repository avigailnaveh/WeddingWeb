<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class HarelController extends Controller {

    public function actionImportHarel()
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

        $rows = (new Query())->from('_harel')->each(200);
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
                "הפרעות קשב וריכוז",
                "תכנית ביקור בריא",
            ];

            // שדות מטבלת Meuhedet
            $fullName = trim($row['name'] ?? '');
            $specialization = trim($row['specialization'] ?? '');
            $licenseFromHarel = trim($row['license_number'] ?? '');
            $title = trim($row['title'] ?? '');
            $phoneNumber = trim($row['phone'] ?? '');
            $address = trim($row['street'] ?? '');
            $city = trim($row['scity'] ?? '');
            $houseNumber = trim($row['house_number'] ?? '');
            $gender = trim($row['gender'] ?? '');

            // בדיקת מילות החרגה בשם מלא
            foreach ($excludeWords as $word) {
                if (mb_stripos($fullName, $word) !== false) {
                    continue 2;
                }
            }

            // בדיקה אם ההתמחות מחייבת התאמה לפי שם
            $isNeedsMatchByName = in_array($specialization, $needsMatchByName);

            if (!empty($licenseFromHarel)) {
                $foundProfessional = Professional::find()
                    ->innerJoin('professional_insurance', 'professional_insurance.professional_id = professional.id')
                    ->where(['professional.license_id' => $licenseFromHarel])
                    ->andWhere(['professional_insurance.insurance_id' => 6]) 
                    ->one();

                if ($foundProfessional) {
                    // אם נמצא רופא תואם, מעדכנים אותו ישירות
                    $foundProfessional->full_name = $fullName;
                    $foundProfessional->first_name = $fullName;
                    $foundProfessional->title = $title;

                    if (!empty($gender)) {
                        $foundProfessional->gender = $gender;
                    }

                    if (!$foundProfessional->save()) {
                        echo "\nשגיאה בעדכון רופא קיים";
                        print_r($foundProfessional->getErrors());
                    } else {
                        echo "\nרופא עודכן בהצלחה";
                    }

                    $this->updateProfessionalDetails($foundProfessional, $row, $categories, $expertiseList, $specialization);
                } else {
                    // אם לא נמצא רופא תואם, מחפשים את כל הרופאים עם ההתאמות הבסיסיות
                    $foundProfessionals = Professional::find()
                        ->where(['license_id' => $licenseFromHarel])
                        ->orWhere(['license_id_v1' => $licenseFromHarel])
                        ->orWhere(['license_id_v2' => $licenseFromHarel])
                        ->all();

                    if (!empty($foundProfessionals)) {
                        $nameMatchedProfessionals = [];
                        foreach ($foundProfessionals as $foundProf) {
                            // אם שמו תואם (על פי ההשוואה לפי השם המלא)
                            if ($this->isNameMatchByFullName($foundProf, $fullName)) {
                                $nameMatchedProfessionals[] = $foundProf;
                            }
                        }

                        if (!empty($nameMatchedProfessionals)) {
                            foreach ($nameMatchedProfessionals as $professional) {
                                $professional->full_name = $fullName;
                                $professional->first_name = $fullName;
                                $professional->title = $title;

                                if ($professional->license_id !== $licenseFromHarel) {
                                    if (
                                        $professional->license_id_v1 === $licenseFromHarel ||
                                        $professional->license_id_v2 === $licenseFromHarel
                                    ) {
                                        $professional->license_id_v0 = $licenseFromHarel;
                                    }
                                }

                                if (!empty($gender)) {
                                    $professional->gender = $gender;
                                }

                                if (!$professional->save()) {
                                    echo "\nשגיאה בעדכון רופא קיים";
                                    print_r($professional->getErrors());
                                }

                                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization);
                            }
                        } else {
                            // יצירת מקצוען חדש
                            $professional = new Professional();
                            $professional->full_name = $fullName;
                            $professional->first_name = $fullName;
                            $professional->title = $title;

                            if ($isNeedsMatchByName) {
                                $professional->license_id = 'nm-' . $licenseFromHarel;
                            } else {
                                $professional->license_id = $licenseFromHarel;
                            }

                            if (!empty($gender)) {
                                $professional->gender = $gender;
                            }

                            // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                            $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromHarel);
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

                            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization);
                        }
                    }
                }
            } else {
                // רישיון ריק
                $nameMatches = $this->findProfessionalsByFullName($fullName);

                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->title = $title;

                if ($isNeedsMatchByName) {
                    $professional->license_id = 'nm';
                }

                if (!empty($gender)) {
                    $professional->gender = $gender;
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

                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization);
            }
        }

        echo "\n\n ייבוא הסתיים בהצלחה.\n";
    }

    public function actionUpdateData()
    {
        // שליפת כל השורות עם התנאים שהוזכרו
        $data = (new Query())
            ->select('*')
            ->from('professional') // שם הטבלה
            ->where(['not', ['full_name' => null]]) // full_name לא Null
            ->andWhere(['first_name' => new \yii\db\Expression('full_name')]) // first_name שווה ל-full_name
            ->andWhere(['not', ['last_name' => '']]) // last_name לא ריק
            ->andWhere(['not', ['last_name' => null]]) // last_name לא Null
            ->all(); // מבצע את השאילתה ומחזיר את התוצאות

        // עדכון ה-first_name עבור כל שורה
        foreach ($data as $row) {
            // הסרת ה-last_name מ-first_name (כולל הרווח שאחריו)
            $updated_first_name = str_replace($row['last_name'], '', $row['first_name']);
            $updated_first_name = trim($updated_first_name); // מוחק רווחים מיותרים

            // אם נשאר יותר מילה אחת ב-first_name, נשאיר רק את המילה האחרונה (ה-`last_name`)
            $first_name_parts = explode(' ', $updated_first_name);
            $updated_first_name = array_pop($first_name_parts); // בוחר רק את המילה האחרונה

            // עדכון ה-first_name בטבלה
            Yii::$app->db->createCommand()
                ->update('professional', ['first_name' => $updated_first_name], ['id' => $row['id']])
                ->execute();
        }

        echo "העדכון הושלם.\n";
    }

    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function isNameMatchByFullName($professional, $harelFullName)
    {
        if (empty($professional->full_name)) {
            $professional->full_name = $professional->first_name . ' ' . $professional->last_name;
        }

        $professionalArray = preg_split('/\s+/', trim($professional->full_name ?? ''));
        $matchedProfessionalArray = preg_split('/\s+/', trim($harelFullName));

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

    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, $specialization)
    {
        // עדכון התמחויות - פיצול לפי "|"
        $specializations = [];

        // הוספת Specialization עם פיצול לפי "|"
        if (!empty($specialization)) {
            $specs = explode('|', $specialization);
            foreach ($specs as $spec) {
                $spec = trim($spec);
                if (!empty($spec)) {
                    $specializations[] = $spec;
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

        // עדכון ביטוח
        $insuranceName = 'הראל';
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
        $city = trim($row['scity'] ?? '');
        $street = trim($row['street'] ?? '');
        $numberRaw = trim($row['house_number'] ?? '');
        $number = is_numeric($numberRaw) ? (int)$numberRaw : null;

        $address = ProfessionalAddress::findOne([
            'professional_id' => $professional->id,
            'city' => $city,
            'street' => $street,
            'house_number' => $number,
        ]);

        // טיפול בטלפון יחיד
        $cleanedPhone = self::cleanPhone($row['phone'] ?? '');

        if (!$address) {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => $city,
                'street' => $street,
                'house_number' => $number,
                'phone' => $cleanedPhone,
            ]);
        } else {
            // אם הכתובת קיימת ואין טלפון, הוסף אותו
            if (empty($address->phone) && !empty($cleanedPhone)) {
                $address->phone = $cleanedPhone;
            }
        }

        $address->type = 'הראל';
        $address->save();
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