<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class MovementController extends Controller {

    public function actionImportMovement()
    {
        echo "\nהתחלת הפונקציה...";
        $careData = (new Query())->select(['id', 'name'])->from('care')->all();

        $careList = [];

        foreach ($careData as $care) {
            $careList[$care['name']] = $care['id'];
        }

        echo "\nנטענו " . count($careList) . " תחומי מומחיות";

        $rows = (new Query())->from('_movement4life')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;

            $city = trim($row['address'] ?? '');
            $fullName = trim($row['name'] ?? '');
            $phoneNumbers = trim($row['phone'] ?? '');
            $categories = trim($row['categories'] ?? '');
            $title = '';
            $normal_care = [
                "עיסוי הריון" => "עיסוי להריון",
            ];

            if ($categories) {
                $parts = array_map('trim', explode(',', $categories));

                $mappedParts = array_map(function($part) use ($normal_care) {
                    return $normal_care[$part] ?? $part;
                }, $parts);

                $categories = implode(',', $mappedParts);
            }

            $hasDrTitle = false;
            $hasOnlyWoman = false;
            $hasOnlyMale = false;

            $fullNameParts = explode(' ', $fullName);
            if (isset($fullNameParts[0])) {
                if (preg_match('/^ד(?:[״"\']|&quot;)?ר\.?$/u', $fullNameParts[0])) {
                    array_shift($fullNameParts);
                    $fullName = implode(' ', $fullNameParts);
                    $hasDrTitle = true;
                    
                }
            }

            $fullNameParts = explode(' ', $fullName);
            if (isset($fullNameParts[0])) {
                if (preg_match('/\s*-\s*נשים\s+בלבד/u', $fullName)) {
                    $fullName = trim(preg_replace('/\s*-\s*נשים\s+בלבד/u', '', $fullName));
                    $hasOnlyWoman = true;
                }

                if (preg_match('/\s*-\s*גברים\s+בלבד/u', $fullName)) {
                    $fullName = trim(preg_replace('/\s*-\s*גברים\s+בלבד/u', '', $fullName));
                    $hasOnlyMale = true;
                }
            }

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

                if(empty($foundProfessional->phone) || $foundProfessional->phone == ''){
                    $foundProfessional->phone = $this->cleanPhone($phoneNumbers);
                }

                if ($hasDrTitle) {
                    $foundProfessional->title = 'ד"ר';
                }

                if ($hasOnlyWoman) {
                    $foundProfessional->gender_limit = 1;
                }elseif($hasOnlyMale){
                    $foundProfessional->gender_limit = 2;
                }else{
                    $foundProfessional->gender_limit = 0;
                }

                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $city, $phoneNumbers, $careList, $categories);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->license_id = 'nm';

                $professional->phone = $this->cleanPhone($phoneNumbers);

                if ($hasDrTitle) {
                    $professional->title = 'ד"ר';
                }

                 if ($hasOnlyWoman) {
                    $professional->gender_limit = 1;
                }elseif($hasOnlyMale){
                    $professional->gender_limit = 2;
                }else{
                    $professional->gender_limit = 0;
                }

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $city, $phoneNumbers, $careList, $categories);
            }
        }

        echo "\n\n ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. \n";
    }

    private function updateProfessionalDetails($professional, $row, $city, $phoneNumbers, &$careList, $categories)
    {

        if (!empty($categories)) {
            $specializations = explode(',', $categories);
            foreach ($specializations as $spec) {
                $cleaned = trim($spec);
                if ($cleaned === '') continue;
                    $careId = null;

                    if (isset($careList[$cleaned])) {
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
     

        $unionsName = 'movement4life';
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

        $this->updateProfessionalAddress($professional, $row, $city ,$phoneNumbers);

    }
    
    
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalAddress($professional, $row, $city,$phones)
    {
        
        if (!empty($phones)) {
            $phoneArray = explode('|', $phones);
            $cleanPhones = [];

            foreach ($phoneArray as $phone) {
                $cleaned = self::cleanPhone($phone);
                if ($cleaned) {
                    $cleanPhones[] = $cleaned;
                }
            }

            $phones = array_values(array_unique($cleanPhones));
        }


        if (empty($city) && empty($phones)) {
            echo "\n אין ערים וגם אין טלפונים - לא נוספו כתובות.";
            return;
        }

        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];

        if (!empty($city)) {
            $cities = preg_split('/\r?\n/', $city); 
            foreach ($cities as $cityName) {
                $cityName = trim($cityName);
                if (empty($cityName)) continue;

                $address = new ProfessionalAddress([
                    'professional_id' => $professional->id,
                    'street' => $cityName,
                    'type' => 'movement4life',
                    'display_address' => 0,
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
                'street' => null,
                'type' => 'movement4life',
                'display_address' => 0,
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
            return null;
        }

        $phone = preg_replace('/^(?:\+?972)/', '0', $phone);

        $phone = explode('/', $phone)[0];

        return preg_replace('/[^0-9*]/', '', $phone);
    }

    
    private function findProfessionalByPhone($phoneNumbers)
    {
        if (empty($phoneNumbers)) {
            return null;
        }

        $phones = explode('|', $phoneNumbers);
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

        // חיפוש כל הכתובות שמתאימות לטלפונים
        $addressResults = (new Query())
            ->select(['professional_id'])
            ->from('professional_address')
            ->where($conditions)
            ->all();

        if ($addressResults) {
            // עבור על כל הכתובות שנמצאו
            foreach ($addressResults as $addressResult) {
                $professionalId = $addressResult['professional_id'];
                $professional = Professional::findOne($professionalId);

                if ($professional) {
                    echo "\nנמצא מקצוען לפי טלפון: " . implode(', ', $cleanedPhones);
                    return $professional;
                } else {
                    echo "\nנמצא professional_id בכתובת, אך הוא לא קיים בטבלת professional: $professionalId - ממשיך לחפש...";
                }
            }
        }

        return null;
    }
}