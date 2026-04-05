<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class EasyStController extends Controller {

    public function actionImportEasySt()
    {
        echo "\nהתחלת הפונקציה...";
        
        $categories = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('category')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($categories) . " קטגוריות";

        $st_specialtiesData = (new Query())->select(['id', 'name'])->from('st_specialties')->all();

        $st_specialtiesList = [];

        foreach ($st_specialtiesData as $st_specialties) {
            $st_specialtiesList[$st_specialties['name']] = $st_specialties['id'];
        }

        echo "\nנטענו " . count($st_specialtiesList) . " תחומי מומחיות";


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

        $rows = (new Query())->from('_easy_speech_therapy')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;
            echo "\nמעבד שורה מספר: {$counter}";

            $fullName = trim($row['name'] ?? '');
            $mainSpecialization = trim($row['name2'] ?? '');
            $phoneNumbers = trim($row['phones'] ?? ''); 
            $city = trim($row['city'] ?? '');
            $street = trim($row['street'] ?? '');
            $houseNumber = trim($row['house_number'] ?? '');
            $title = null;

            if ($mainSpecialization === 'מרכז טיפולי') {
                echo "\nדילוג על שורה - שם ההתמחות הוא מרכז טיפולי";
                continue;  // דילוג על השורה הנוכחית
            }
            if (preg_match('/(קלינאי תקשורת\s*)?מנוהל$/u', $fullName)) {
                $fullName = preg_replace('/(קלינאי תקשורת\s*)?מנוהל$/u', '', $fullName);
                $fullName = trim($fullName);
            }

            $fullName = preg_replace('/קלינאי תקשורת/u', '', $fullName);

            $fullName = preg_replace('/טיפול בדיבור מנוהל/u', '', $fullName);

            $fullName = trim($fullName);

            if (preg_match('/^ד"ר\s*/u', $fullName)) {
                $title = 'ד"ר';
                $fullName = preg_replace('/^ד"ר\s*/u', '', $fullName);
            }

            if (preg_match("/גב'\s*/u", $fullName)) {
                $title = "גב'";
                $fullName = preg_replace("/גב'\s*/u", '', $fullName, 1);
            }

           if (preg_match('/\.?M\.?A\.?/iu', $fullName)) {
                $title = 'MA';
                $fullName = preg_replace('/\.?M\.?A\.?\s*/iu', '', $fullName);
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
                if(!empty($title)){
                    $foundProfessional->title = $title;
                }

                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $categories, $st_specialtiesList, $languagesList, $mainSpecialization, $careList, $city, $street , $houseNumber);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->license_id = 'nm';
               
                if(!empty($title)){
                    $professional->title = $title;
                }
                
                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $categories, $st_specialtiesList, $languagesList, $mainSpecialization, $careList, $city, $street, $houseNumber);
            }
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$st_specialtiesList, &$languagesList, $mainSpecialization, &$careList, $city, $street, $houseNumber)
    {
        if (!empty($mainSpecialization)) {
            $specializations = explode('|', $mainSpecialization);
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
                if($cleaned === 'קלינאות תקשורת'){
                    $st_specialtiesId = null;

                    if (isset($st_specialtiesList[$cleaned])) {
                        $st_specialtiesId = $st_specialtiesList[$cleaned];
                        echo "\nהתאמה נמצאה ב-name: $cleaned";
                    } else {
                        Yii::$app->db->createCommand()->insert('st_specialties', [
                            'name' => $cleaned,
                        ])->execute();
                        $st_specialtiesId = Yii::$app->db->getLastInsertID();
                        $st_specialtiesList[$cleaned] = $st_specialtiesId;
                        echo "\nתחום מומחיות חדש נוסף: $cleaned";
                    }

                    if ($st_specialtiesId) {
                        $exists = (new Query())->from('professional_st_specialties')->where([
                            'professional_id' => $professional->id,
                            'st_specialties_id' => $st_specialtiesId,
                        ])->exists();
                        if (!$exists) {
                            Yii::$app->db->createCommand()->insert('professional_st_specialties', [
                                'professional_id' => $professional->id,
                                'st_specialties_id' => $st_specialtiesId,
                            ])->execute();
                        }
                    }
                }else{

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
        }

        $unionsName = 'איזי';
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

        $this->updateProfessionalAddress($professional, $row, $city, $street , $houseNumber);
    }
    
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalAddress($professional, $row, $city, $street, $houseNumber)
    {
        $phoneRaw = trim($row['phones'] ?? '');
        $phones = [];
        
        if (!empty($phoneRaw)) {
            $phoneArray = explode('|', $phoneRaw);
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
        
            $city = trim($city);

            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => $city,
                'street' => $street,
                'house_number' => $houseNumber,
                'type' => 'איזי',
            ]);

           foreach ($phoneFields as $index => $field) {
                $address->$field = $phones[$index] ?? null;
            }

            if (!$address->save()) {
                 echo "\nשגיאה בשמירת כתובת לעיר: {$city}";
                print_r($address->getErrors());
            } else {
                echo "\nכתובת נוספה לעיר: {$city} עם טלפונים: " . implode(', ', $phones);
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