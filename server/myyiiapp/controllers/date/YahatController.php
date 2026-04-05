<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class YahatController extends Controller {

    public function actionImportYahat()
    {
        echo "\nהתחלת הפונקציה...";
        
        $categories = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('category')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($categories) . " קטגוריות";

        $careData = (new Query())->select(['id', 'name'])->from('care')->all();

        $careList = [];
        $careNormalMap = [];

        foreach ($careData as $care) {
            $careList[$care['name']] = $care['id'];
        }

        echo "\nנטענו " . count($careList) . " תחומי מומחיות";

        $languagesList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($languagesList) . " שפות";

        $rows = (new Query())->from('_yahat')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;
            echo "\nמעבד שורה מספר: {$counter}";
            
            $excludeWords = [
                'רופא מצוות',
                'צוות המרפאה',
                'אחות אחראית',
                'צוות',
                'מנהל',
                'מרפאת',
                'מרפאה'
            ];

            $fullName = trim($row['name'] ?? '');
            $mainSpecialization = trim($row['normalized_specializations'] ?? '');
            $languages = trim($row['language'] ?? '');
            $phoneNumbers = trim($row['phone'] ?? ''); 
            $city = trim($row['location'] ?? '');
            $gender = trim($row['gender'] ?? '');
            $about = trim($row['training_paragraphs'] ?? '');
            
            $shouldSkip = false;
            foreach ($excludeWords as $word) {
                if (stripos($fullName, $word) !== false) {
                    $shouldSkip = true;
                    break;
                }
            }
            
            if ($shouldSkip || empty($fullName)) {
                echo "\nדילוג על שורה - שם לא רלוונטי או ריק";
                continue;
            }
            
            $hasDrTitle = false;
            $fullNameParts = explode(' ', $fullName);
            if (isset($fullNameParts[0])) {
                if (preg_match('/^דר\.?$/u', $fullNameParts[0])) {
                    array_shift($fullNameParts);
                    $fullName = implode(' ', $fullNameParts);
                    $hasDrTitle = true;
                }
            }

            echo "\nמעבד: {$fullName} | התמחות: {$mainSpecialization}";

            if (empty($phoneNumbers)) {
                echo "\nדילוג על שורה - אין מספר טלפון";
                continue;
            }
            
            $foundProfessional = null;
            if (!empty($phoneNumbers)) {
                $foundProfessional = $this->findProfessionalByPhone($phoneNumbers);
            }
                
            if ($foundProfessional) {
                echo "\nנמצא מקצוען קיים עם טלפון תואם";
                $foundProfessional->full_name = $fullName;
                $foundProfessional->first_name = $fullName;
                if(!empty($about) && empty($foundProfessional->about)){
                    $foundProfessional->about = $about;
                }

                if (!empty($gender)) {
                    if ($gender == 'גבר') {
                        $foundProfessional->gender = 1;
                    } elseif ($gender == 'אישה') {
                        $foundProfessional->gender = 2;
                    } else {
                        $foundProfessional->gender = null; 
                    }
                }

                // בדיקת תואר אקדמי והוספה לכותרת
                if ($hasDrTitle) {
                    $foundProfessional->title = 'ד"ר';
                } else {
                    $this->updateProfessionalTitle($foundProfessional, $mainSpecialization);
                }

                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $categories, $careList, $languagesList, $mainSpecialization, $careNormalMap, $city);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->license_id = 'nm';
                if(!empty($about) && empty($professional->about)){
                    $professional->about = $about;
                }
                if (!empty($gender)) {
                    if ($gender == 'גבר') {
                        $professional->gender = 1;
                    } elseif ($gender == 'אישה') {
                        $professional->gender = 2;
                    } else {
                        $professional->gender = null; 
                    }
                }

                // בדיקת תואר אקדמי והוספה לכותרת
                if ($hasDrTitle) {
                    $professional->title = 'ד"ר';
                } else {
                    $this->updateProfessionalTitle($professional, $mainSpecialization);
                }
                
                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $categories, $careList, $languagesList, $mainSpecialization, $careNormalMap, $city);
            }
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }

    private function updateProfessionalTitle($professional, $mainSpecialization)
    {
        // בדיקה אם הכותרת ריקה או null
        if (!empty($professional->title)) {
            echo "\nכותרת כבר קיימת: {$professional->title} - לא מעדכן";
            return;
        }

        if (empty($mainSpecialization)) {
            return;
        }

        // מערך התארים לבדיקה
        $degrees = [
            'PHD' => 'ד"ר',
            'P.H.D' => 'ד"ר',
            'P.H.D.' => 'ד"ר',
            'PH.D' => 'ד"ר',
            'PH.D.' => 'ד"ר',
            'MSW' => 'MSW',
            'M.S.W' => 'MSW',
            'M.S.W.' => 'MSW',
            'M.SW' => 'MSW',
            'M.SW.' => 'MSW',
            'MA' => 'MA',
            'M.A' => 'MA',
            'M.A.' => 'MA',
            'MD' => 'MD',
            'M.D' => 'MD',
            'M.D.' => 'MD'
        ];

        $specializations = explode(',', $mainSpecialization);
        
        foreach ($specializations as $spec) {
            $cleaned = trim($spec);
            if ($cleaned === '') continue;

            foreach ($degrees as $degree => $title) {
                // בדיקת התואר עם או בלי נקודה, באותיות קטנות וגדולות
                $patterns = [
                    '/\b' . strtoupper($degree) . '\.?\b/i',
                    '/\b' . strtolower($degree) . '\.?\b/i',
                    '/\b' . ucfirst(strtolower($degree)) . '\.?\b/i'
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $cleaned)) {
                        $professional->title = $title;
                        echo "\nנמצא תואר {$degree} בהתמחות: {$cleaned} - עודכן לכותרת: {$title}";
                        return; // יוצאים מהפונקציה לאחר מציאת התואר הראשון
                    }
                }
            }
        }
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$careList, &$languagesList, $mainSpecialization, $careNormalMap, $city)
    {
        if (!empty($mainSpecialization)) {
            $specializations = explode(',', $mainSpecialization);
            foreach ($specializations as $spec) {
                $cleaned = trim($spec);
                if ($cleaned === '') continue;

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
                        echo "\nקטגוריה נוספה: $cleaned";
                    }
                }

                $careId = null;

                if (isset($careNormalMap[$cleaned])) {
                    $careId = $careNormalMap[$cleaned];
                    echo "\nהתאמה נמצאה ב-NormalSpecialization: $cleaned";
                } elseif (isset($careList[$cleaned])) {
                    $careId = $careList[$cleaned];
                    echo "\nהתאמה נמצאה ב-name: $cleaned";
                } else {
                    Yii::$app->db->createCommand()->insert('care', [
                        'name' => $cleaned,
                    ])->execute();
                    $careId = Yii::$app->db->getLastInsertID();
                    $careList[$cleaned] = $careId;
                    echo "\nתחום מומחיות חדש נוסף: $cleaned";
                }

                if ($careId) {
                    $exists = (new Query())->from('professional_care')->where([
                        'professional_id' => $professional->id,
                        'care_id' => $careId,
                    ])->exists();
                    if (!$exists) {
                        Yii::$app->db->createCommand()->insert('professional_care', [
                            'professional_id' => $professional->id,
                            'care_id' => $careId,
                        ])->execute();
                    }
                }
            }
        }

        $unionsName = 'יה"ת';
        $unionsId = $this->getunionsIdByName($unionsName);

        $exists = (new Query())->from('professional_unions')->where([
            'professional_id' => $professional->id,
            'unions_id' => $unionsId,
        ])->exists();

        if (!$exists) {
            Yii::$app->db->createCommand()->insert('professional_unions', [
                'professional_id' => $professional->id,
                'unions_id' => $unionsId,
            ])->execute();
        }

        $this->updateProfessionalAddress($professional, $row, $city);
        $this->updateProfessionalLanguages($professional, $row, $languagesList);
    }
    
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalAddress($professional, $row, $city)
    {
        $phoneRaw = trim($row['phone'] ?? ''); // תוקן: שימוש ב-$row['phone'] במקום $phoneRaw
        $phones = [];
        
        if (!empty($phoneRaw)) {
            $phoneArray = explode(',', $phoneRaw);
            foreach ($phoneArray as $phone) {
                $cleaned = self::cleanPhone($phone);
                if ($cleaned) {
                    $phones[] = $cleaned;
                }
            }
        }
        $phones = array_values(array_unique($phones));

        if (empty($city) && empty($phones)) {
            echo "\n אין ערים וגם אין טלפונים - לא נוספו כתובות.";
            return;
        }

        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];

        // תוקן: עיבוד העיר כמחרוזת ולא כמערך
        if (!empty($city)) {
            $cities = explode(',', $city); // אם יש מספר ערים מופרדות בפסיק
            foreach ($cities as $cityName) {
                $cityName = trim($cityName);
                if (empty($cityName)) continue;

                $address = new ProfessionalAddress([
                    'professional_id' => $professional->id,
                    'city' => $cityName,
                    'type' => 'יה"ת',
                ]);

                foreach ($phoneFields as $index => $field) {
                    $address->$field = $phones[$index] ?? null;
                }

                if (!$address->save()) {
                    echo "\nשגיאה בשמירת כתובת לעיר: {$cityName}";
                    print_r($address->getErrors());
                } else {
                    echo "\nכתובת נוספה לעיר: {$cityName} עם טלפונים: " . implode(', ', $phones);
                }
            }
        } else {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => null,
                'type' => 'יה"ת',
            ]);

            foreach ($phoneFields as $index => $field) {
                $address->$field = $phones[$index] ?? null;
            }

            if (!$address->save()) {
                echo "\n שגיאה בשמירת כתובת ללא עיר";
                print_r($address->getErrors());
            } else {
                echo "\n כתובת ללא עיר נוספה עם טלפונים: " . implode(', ', $phones);
            }
        }
    }

    private function updateProfessionalLanguages($professional, $row, &$languagesList)
    {
        $languages = trim($row['language'] ?? '');
        if (empty($languages)) return;

        $langs = explode(',', $languages);
        foreach ($langs as $lang) {
            $lang = trim($lang);
            if (!$lang) continue;

            if (!isset($languagesList[$lang])) {
                Yii::$app->db->createCommand()->insert('speaking_language', [
                    'name' => $lang,
                ])->execute();

                $newId = Yii::$app->db->getLastInsertID();
                $languagesList[$lang] = $newId;

                echo "\nשפה חדשה נוספה: {$lang}";
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

    private function getunionsIdByName($unionsName)
    {
        $union = (new Query())
            ->select('id')
            ->from('unions')
            ->where(['name' => $unionsName])
            ->one();

        if (!$union) {
            Yii::$app->db->createCommand()
                ->insert('unions', ['name' => $unionsName])
                ->execute();
            return Yii::$app->db->getLastInsertID();
        }

        return $union['id'];
    }

    public static function cleanPhone($phone)
    {
        if (!$phone || preg_match('/[a-zA-Z@]/', $phone)) {
            echo "\nלא נמצא מספר טלפון תקין: {$phone}";
            return null;
        }

        $phone = preg_replace('/^\+972/', '0', $phone);
        echo "\n אחרי החלפת +972 ל-0: {$phone}";

        $phone = explode('/', $phone)[0];
        echo "\n אחרי ניקוי תווים מיותרים: {$phone}";

        return preg_replace('/[^0-9*]/', '', $phone);
    }
    
    private function findProfessionalByPhone($phoneNumbers)
    {
        if (empty($phoneNumbers)) {
            return null;
        }

        $phones = explode(',', $phoneNumbers);
        $cleanedPhones = [];

        foreach ($phones as $phone) {
            $cleaned = self::cleanPhone($phone);
            if ($cleaned && strpos($cleaned, '05') === 0) { 
                $cleanedPhones[] = $cleaned;
            }
        }

        if (empty($cleanedPhones)) {
            return null;
        }

        $conditions = ['or'];
        foreach ($cleanedPhones as $phone) {
            $conditions[] = ['phone' => $phone];
            $conditions[] = ['phone_2' => $phone];
            $conditions[] = ['phone_3' => $phone];
            $conditions[] = ['phone_4' => $phone];
        }

        $addressResult = (new Query())
            ->select(['professional_id'])
            ->from('professional_address')
            ->where($conditions)
            ->one();

        if ($addressResult) {
            $professionalId = $addressResult['professional_id'];
            $professional = Professional::findOne($professionalId);

            if ($professional) {
                echo "\nנמצא מקצוען לפי טלפון: " . implode(', ', $cleanedPhones);
                return $professional;
            } else {
                echo "\nנמצא professional_id בכתובת, אך הוא לא קיים בטבלת professional: $professionalId";
            }
        }

        return null;
    }
}