<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class ImahotController extends Controller {

    public function actionImportImahot()
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

        $rows = (new Query())->from('_imahot')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;
            echo "\nמעבד שורה מספר: {$counter}";
    

            $fullName = trim($row['name'] ?? '');
            $mainSpecialization = 'דולה';
            $phoneNumbers = trim($row['phone'] ?? ''); 
            $city = trim($row['area'] ?? '');
            $street = '';
            $houseNumber = '';
            $about = trim($row['notes'] ?? '');
            
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

                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $categories, $careList, $languagesList, $mainSpecialization, $city ,$street, $houseNumber);
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

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $categories, $careList, $languagesList, $mainSpecialization, $city ,$street, $houseNumber);
            }
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$careList, &$languagesList, $mainSpecialization, $city ,$street, $houseNumber)
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

        $unionsName = 'אמהות';
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

        $this->updateProfessionalAddress($professional, $row, $city ,$street, $houseNumber);
        // $this->updateProfessionalLanguages($professional, $row, $languagesList);
    }
    
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalAddress($professional, $row, $city ,$street, $houseNumber)
    {
        $phoneRaw = trim($row['phone'] ?? ''); 
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

    

                $address = new ProfessionalAddress([
                    'professional_id' => $professional->id,
                    'city' => $city,
                    'street' => $street,
                    'house_number' => $houseNumber,
                    'type' => 'אמהות',
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