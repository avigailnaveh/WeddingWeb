<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class MigdalController extends Controller {

    public function actionImportMigdal()
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

        $rows = (new Query())->from('_migdal')->each(200);
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

            // שדות מטבלת _migdal
            $firstName = trim($row['fName'] ?? '');
            $lastName = trim($row['lName'] ?? '');
            $fullName = trim($firstName . ' ' . $lastName);
            $address = trim($row['address'] ?? '');
            $phone = trim($row['phone'] ?? '');
            $licenseFromMigdal = trim($row['num'] ?? '');
            $title = trim($row['degree'] ?? '');
            $specialization = trim($row['specialization'] ?? '');
            $specialization2 = trim($row['specialization2'] ?? '');

            // בדיקת מילות החרגה בשם מלא
            foreach ($excludeWords as $word) {
                if (mb_stripos($fullName, $word) !== false) {
                    continue 2;
                }
            }

            // בדיקה אם ההתמחות מחייבת התאמה לפי שם
            $isNeedsMatchByName = in_array($specialization, $needsMatchByName) || in_array($specialization2, $needsMatchByName);

            if (!empty($licenseFromMigdal)) {
                $foundProfessional = Professional::find()
                    ->innerJoin('professional_insurance', 'professional_insurance.professional_id = professional.id')
                    ->where(['professional.license_id' => $licenseFromMigdal])
                    ->andWhere(['professional_insurance.insurance_id' => 1]) // מגדל
                    ->one();

                if ($foundProfessional) {
                    // אם נמצא רופא תואם, מעדכנים אותו ישירות
                    $foundProfessional->full_name = $fullName;
                    $foundProfessional->first_name = $firstName;
                    $foundProfessional->last_name = $lastName;
                    $foundProfessional->title = $title;

                    if (!$foundProfessional->save()) {
                        echo "\nשגיאה בעדכון רופא קיים";
                        print_r($foundProfessional->getErrors());
                    } else {
                        echo "\nרופא עודכן בהצלחה";
                        $this->updateProfessionalDetails(
                            $foundProfessional,
                            $row,
                            $categories,
                            $expertiseList,
                            $specialization,
                            $specialization2,
                            $address,
                            $phone
                        );
                    }
                } else {
                    // אם לא נמצא רופא תואם, מחפשים את כל הרופאים עם ההתאמות הבסיסיות
                    $foundProfessionals = Professional::find()
                        ->where(['license_id' => $licenseFromMigdal])
                        ->orWhere(['license_id_v1' => $licenseFromMigdal])
                        ->orWhere(['license_id_v2' => $licenseFromMigdal])
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
                                $professional->first_name = $firstName;
                                $professional->last_name = $lastName;
                                $professional->title = $title;

                                if ($professional->license_id !== $licenseFromMigdal) {
                                    if (
                                        $professional->license_id_v1 === $licenseFromMigdal ||
                                        $professional->license_id_v2 === $licenseFromMigdal
                                    ) {
                                        $professional->license_id_v0 = $licenseFromMigdal;
                                    }
                                }

                                if (!$professional->save()) {
                                    echo "\nשגיאה בעדכון רופא קיים";
                                    print_r($professional->getErrors());
                                }else{
                                    echo "\nרופא התעדכן בהצלחה";
                                    print_r($licenseFromMigdal);
                                }

                                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization, $specialization2, $address, $phone);
                            }
                        } else {
                            // יצירת מקצוען חדש - הקטע שחסר
                            $professional = new Professional();
                            $professional->full_name = $fullName;
                            $professional->first_name = $firstName;
                            $professional->last_name = $lastName;
                            $professional->title = $title;

                            if ($isNeedsMatchByName) {
                                $professional->license_id = 'nm-' . $licenseFromMigdal;
                            } else {
                                $professional->license_id = $licenseFromMigdal;
                            }

                            // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                            $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromMigdal);
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
                            } else {
                                echo "\nרופא חדש נוצר בהצלחה";
                            }

                            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization, $specialization2, $address, $phone);
                        }
                    } else {
                        // אם לא נמצאו רופאים עם הרישיון, יוצרים רופא חדש לגמרי
                        $professional = new Professional();
                        $professional->full_name = $fullName;
                        $professional->first_name = $firstName;
                        $professional->last_name = $lastName;
                        $professional->title = $title;

                        if ($isNeedsMatchByName) {
                            $professional->license_id = 'nm-' . $licenseFromMigdal;
                        } else {
                            $professional->license_id = $licenseFromMigdal;
                        }

                        // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                        $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromMigdal);
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
                        } else {
                            echo "\nרופא חדש נוצר בהצלחה";
                        }

                        $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization, $specialization2, $address, $phone);
                    }
                }
            } else {
                // רישיון ריק
                $nameMatches = $this->findProfessionalsByFullName($fullName);

                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $firstName;
                $professional->last_name = $lastName;
                $professional->title = $title;

                if ($isNeedsMatchByName) {
                    $professional->license_id = 'nm';
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
                }else{
                    echo "\nרופא חדש נוצר בהצלחה";
                }

                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $specialization, $specialization2, $address, $phone);
            }
        }

        echo "\n ייבוא הסתיים בהצלחה.\n";
    }

    public static function parseAddress($address) {
        $address = trim($address);
        
        // אם הכתובת ריקה, מחזיר ערכים ריקים
        if (empty($address)) {
            return [
                'street' => '',
                'number' => '',
                'city'   => '',
            ];
        }
        
        // נסה תחילה regex מורכב יותר
        // דוגמה: "רחוב הרצל 12, תל אביב" או "הרצל 12, תל אביב"
        $pattern = '/^(.+?)\s+(\d+)\s*,\s*(.+)$/u';
        if (preg_match($pattern, $address, $matches)) {
            return [
                'street' => trim($matches[1]),
                'number' => trim($matches[2]),
                'city'   => trim($matches[3]),
            ];
        }
        
        // נסה רק רחוב ומספר ללא עיר
        // דוגמה: "רחוב הרצל 12"
        $pattern = '/^(.+?)\s+(\d+)$/u';
        if (preg_match($pattern, $address, $matches)) {
            return [
                'street' => trim($matches[1]),
                'number' => trim($matches[2]),
                'city'   => '',
            ];
        }
        
        // נסה רק עיר עם פסיק
        // דוגמה: ", תל אביב"
        if (str_starts_with($address, ',')) {
            return [
                'street' => '',
                'number' => '',
                'city'   => trim(substr($address, 1)),
            ];
        }
        
        // אם שום דבר לא עבד, החזר הכל כעיר
        return [
            'street' => '',
            'number' => '',
            'city'   => $address,
        ];
    }

    public static function normalizePhoneNumber($phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (!ctype_digit($phone)) {
            return false;
        } elseif (str_starts_with($phone, '972')) {
            $local = '0' . substr($phone, 3);
            return $local;
        } elseif (str_starts_with($phone, '0') && strlen($phone) === 10) {
            return $phone;
        } else {
           return false;
        }
    }

    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function isNameMatchByFullName($professional, $migdalFullName)
    {
        // בדיקה אם full_name לא null ולא ריק
        if (!empty($professional->full_name)) {
            $professionalFullName = $professional->full_name;
        } else {
            // אם full_name ריק או null, משתמשים בfirst_name ו-last_name
            $professionalFullName = trim($professional->first_name . ' ' . $professional->last_name);
        }

        $professionalArray = preg_split('/\s+/', trim($professionalFullName));
        $matchedProfessionalArray = preg_split('/\s+/', trim($migdalFullName));

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

    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, $specialization, $specialization2, $address, $phone)
{
    // --- טיפול בהתמחויות ---
    $specializations = [];
    if (!empty($specialization)) {
        $specializations[] = $specialization;
    }
    if (!empty($specialization2)) {
        $specializations[] = $specialization2;
    }

    foreach ($specializations as $spec) {
        $cleaned = trim($spec);
        if ($cleaned === '') continue;

        // קטגוריה
        if (isset($categories[$cleaned])) {
            $categoryId = $categories[$cleaned];
            $exists = (new Query())
                ->from('professional_categories')
                ->where([
                    'professional_id' => $professional->id,
                    'category_id'     => $categoryId,
                ])->exists();
            if (!$exists) {
                Yii::$app->db->createCommand()
                    ->insert('professional_categories', [
                        'professional_id' => $professional->id,
                        'category_id'     => $categoryId,
                    ])->execute();
            }
        }

        // מומחיות
        if (!isset($expertiseList[$cleaned])) {
            Yii::$app->db->createCommand()
                ->insert('expertise', [
                    'name'            => $cleaned,
                    'category_id'     => null,
                    'sub_category_id' => null,
                ])->execute();
            $expertiseList[$cleaned] = Yii::$app->db->getLastInsertID();
            echo "\n✅ תחום מומחיות חדש נוסף: $cleaned";
        }
        $expertiseId = $expertiseList[$cleaned];
        $exists = (new Query())
            ->from('professional_expertise')
            ->where([
                'professional_id' => $professional->id,
                'expertise_id'    => $expertiseId,
            ])->exists();
        if (!$exists) {
            Yii::$app->db->createCommand()
                ->insert('professional_expertise', [
                    'professional_id' => $professional->id,
                    'expertise_id'    => $expertiseId,
                ])->execute();
        }
    }

    // --- טיפול בביטוח מגדל ---
    $insuranceName = 'מגדל';
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
            'insurance_id'    => $insuranceId,
        ])->exists();
    if (!$exists) {
        Yii::$app->db->createCommand()
            ->insert('professional_insurance', [
                'professional_id' => $professional->id,
                'insurance_id'    => $insuranceId,
            ])->execute();
    }

    // --- טיפול בכתובת ---
    // 1. פרוק הכתובת
    if (!empty($address) && strlen(trim($address)) > 0) {
        $addressArr = self::parseAddress(trim($address));
        echo "\n🔍 כתובת מקורית: '{$address}'";
        echo "\n📍 פירוק ל: עיר='{$addressArr['city']}', רחוב='{$addressArr['street']}', מספר='{$addressArr['number']}'";
    } else {
        $addressArr = ['street' => '', 'number' => '', 'city' => ''];
    }
    $city   = $addressArr['city']   ?? '';
    $street = $addressArr['street'] ?? '';
    $number = $addressArr['number'] ?? '';
    $city   = $city   === null ? '' : $city;
    $street = $street === null ? '' : $street;
    $number = $number === null ? '' : $number;

    // 2. נרמול טלפון
    $normalizedPhone = self::normalizePhoneNumber($phone);
    $phones = $normalizedPhone ? [$normalizedPhone] : [];

    // 3. הבאת כל הכתובות הקיימות לרופא
    $existingAddresses = ProfessionalAddress::find()
        ->where(['professional_id' => $professional->id])
        ->all();

    // 4. בדיקה אם קיימת כתובת מדויקת
    $addressRecord = null;
    foreach ($existingAddresses as $addr) {
        if (
            trim($addr->city)         === $city &&
            trim($addr->street)       === $street &&
            trim($addr->house_number) === $number &&
            $addr->type               === $insuranceName
        ) {
            $addressRecord = $addr;
            break;
        }
    }

    if (!$addressRecord) {
        // לא נמצאה כתובת — צור חדשה
        $addressRecord = new ProfessionalAddress([
            'professional_id' => $professional->id,
            'city'            => $city,
            'street'          => $street,
            'house_number'    => $number,
            'type'            => $insuranceName,
        ]);
        echo "\n✅ יוצר כתובת חדשה — עיר='{$city}', רחוב='{$street}', מס'={$number}'";
    } else {
        echo "\nℹ️ נמצאה כתובת קיימת (ID={$addressRecord->id}) — נעשה עדכון טלפונים בלבד";
    }

    // 5. מיזוג והוספת טלפונים
    $existingPhones = [];
    foreach (['phone','phone_2','phone_3','phone_4'] as $field) {
        $v = trim($addressRecord->$field ?? '');
        if ($v !== '') {
            $existingPhones[] = $v;
        }
    }
    $merged = array_values(array_unique(array_merge($existingPhones, $phones)));
    foreach (['phone','phone_2','phone_3','phone_4'] as $i => $field) {
        $addressRecord->$field = $merged[$i] ?? null;
    }
    echo "\n📞 טלפונים עכשיו: " . implode(', ', $merged);

    // 6. שמירת הכתובת
    if (!$addressRecord->save()) {
        echo "\n❌ שגיאה בשמירת כתובת:";
        print_r($addressRecord->getErrors());
    } else {
        echo "\n✅ כתובת נשמרה בהצלחה — ID: {$addressRecord->id}";
    }
}



    // פונקציה נוספת לבדיקת הכתובות במסד הנתונים
    public function actionDebugAddresses()
    {
        echo "\n=== בדיקת כתובות עם NULL ===\n";
        
        $nullAddresses = (new Query())
            ->from('professional_address')
            ->where(['or',
                ['street' => null],
                ['house_number' => null],
                ['city' => null]
            ])
            ->limit(10)
            ->all();
        
        foreach ($nullAddresses as $addr) {
            echo "ID: {$addr['id']} - עיר: '{$addr['city']}', רחוב: '{$addr['street']}', מספר: '{$addr['house_number']}'\n";
        }
    }

    // פונקציה לבדיקת הפירוק של כתובות
    public function actionTestAddressParsing()
    {
        $testAddresses = [
            'רחוב הרצל 12, תל אביב',
            'הרצל 12, תל אביב',
            'בן יהודה 45',
            ', חיפה',
            'תל אביב',
            '',
            '   ',
            'רחוב דיזנגוף 100, תל אביב יפו',
        ];
        
        echo "\n=== בדיקת פירוק כתובות ===\n";
        
        foreach ($testAddresses as $address) {
            $result = self::parseAddress($address);
            echo "כתובת: '$address'\n";
            echo "  עיר: '{$result['city']}'\n";
            echo "  רחוב: '{$result['street']}'\n"; 
            echo "  מספר: '{$result['number']}'\n";
            echo "---\n";
        }
    }
}