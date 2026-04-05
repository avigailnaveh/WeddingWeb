<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class DulotController extends Controller {

    public function actionImportDulot()
    {
        echo "\nהתחלת הפונקציה...";
        
        $mainCareData = (new Query())->select(['id', 'name'])->from('main_care')->all();

        $mainCareList = [];

        foreach ($mainCareData as $mainCare) {
            $mainCareList[$mainCare['name']] = $mainCare['id'];
        }

        echo "\nנטענו " . count($mainCareList) . " תחומי מומחיות";

        $careData = (new Query())->select(['id', 'name'])->from('care')->all();

        $careList = [];

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

        $rows = (new Query())->from('_dulot')->each(200);
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
            $phoneNumbers = trim($row['phones'] ?? ''); 
            $mainSpecialization = 'דולה';
            $city = trim($row['location'] ?? '');
            

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
               

                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $careList,$mainCareList, $languagesList, $mainSpecialization, $city);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->license_id = 'nm';

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }
               
                $this->updateProfessionalDetails($professional, $row, $careList,$mainCareList, $languagesList, $mainSpecialization, $city );
            }
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }

    private function updateProfessionalDetails($professional, $row, &$careList,&$mainCareList, &$languagesList, $mainSpecialization, $city )
    {
       
       $cleaned = trim($mainSpecialization);
        if ($cleaned === '') return;

        $careId = null;
        $mainCareId = null;

        // === CARE (תת התמחות) ===
        if (isset($careList[$cleaned])) {
            $careId = $careList[$cleaned];
            echo "\nהתאמה נמצאה ב-care: $cleaned";
        } else {
            Yii::$app->db->createCommand()->insert('care', [
                'name' => $cleaned,
            ])->execute();
            $careId = Yii::$app->db->getLastInsertID();
            $careList[$cleaned] = $careId;
            echo "\nתחום מומחיות חדש נוסף בטבלת care: $cleaned";
        }

        // קישור לטבלת professional_care
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

        if (isset($mainCareList[$cleaned])) {
            $mainCareId = $mainCareList[$cleaned];
            echo "\nהתאמה נמצאה ב-main_care: $cleaned";
        } else {
            Yii::$app->db->createCommand()->insert('main_care', [
                'name' => $cleaned,
            ])->execute();
            $mainCareId = Yii::$app->db->getLastInsertID();
            $mainCareList[$cleaned] = $mainCareId;
            echo "\nתחום מומחיות חדש נוסף בטבלת main_care: $cleaned id $mainCareId";
        }

        // קישור לטבלת professional_main_care
        if ($mainCareId) {
            $exists = (new Query())->from('professional_main_care')->where([
                'professional_id' => $professional->id,
                'main_care_id' => $mainCareId,
            ])->exists();
            if (!$exists) {
                Yii::$app->db->createCommand()->insert('professional_main_care', [
                    'professional_id' => $professional->id,
                    'main_care_id' => $mainCareId,
                ])->execute();
            }
        }

        $unionsName = 'דולות';
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

        $this->updateProfessionalAddress($professional, $row, $city );
        // $this->updateProfessionalLanguages($professional, $row, $languagesList);
    }
    
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalAddress($professional, $row, $city )
    {
        $phoneRaw = trim($row['phones'] ?? ''); 
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
                    'type' => 'דולות',
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
                'type' => 'דולות',
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
        $languages = trim($row['languages'] ?? '');
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

        // החלפת +972 או 972 בתחילת המספר ב-0
        $phone = preg_replace('/^(?:\+?972)/', '0', $phone);
        echo "\n אחרי החלפת קידומת 972 ל-0: {$phone}";

        // ניקוי תווים אחרי /
        $phone = explode('/', $phone)[0];
        echo "\n אחרי ניקוי תווים מיותרים: {$phone}";

        // השארת רק ספרות ו-*
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