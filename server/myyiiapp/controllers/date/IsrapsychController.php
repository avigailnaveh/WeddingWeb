<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class IsrapsychController extends Controller {

    public function actionImportIsrapsych()
    {
        echo "\nהתחלת הפונקציה...";
        

        $languagesList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($languagesList) . " שפות";

        $rows = (new Query())->from('_israpsych')->each(200);
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
            $mainSpecialization = '';
            $languages = '';
            $phoneNumbers = trim($row['phone'] ?? ''); 
            $city = trim($row['address'] ?? '');
            $about = trim($row['about'] ?? '');
            $title = trim($row['title'] ?? '');
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
                if(!empty($title) && empty($foundProfessional->title)){
                    $foundProfessional->title = $title;
                }
                if(!empty($about) && empty($foundProfessional->about)){
                    $foundProfessional->about = $about;
                }


                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $languagesList, $city);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                if(!empty($title) && empty($professional->title)){
                    $professional->title = $title;
                }
                $professional->license_id = 'nm';
                if(!empty($about) && empty($professional->about)){
                    $professional->about = $about;
                }
                
                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $languagesList, $city);
            }
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }


    private function updateProfessionalDetails($professional, $row, &$languagesList, $city)
    {
        $unionsName = 'האיגוד הישראלי לפסיכוטרפיה';
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
        $phoneRaw = trim($row['phone'] ?? '');
        $phones = [];
        
        if (!empty($phoneRaw)) {
            // מספרי טלפונים מופרדים ב-|
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

        // עיבוד הכתובות - מופרדות בירידת שורה או ב-|
        if (!empty($city)) {
            $addresses = preg_split('/\n|\|/', $city);
            foreach ($addresses as $address) {
                $address = trim($address);
                if (empty($address)) continue;

                $professionalAddress = new ProfessionalAddress([
                    'professional_id' => $professional->id,
                    'street' => $address,
                    'type' => 'האיגוד הישראלי לפסיכוטרפיה',
                ]);

                foreach ($phoneFields as $index => $field) {
                    $professionalAddress->$field = $phones[$index] ?? null;
                }

                if (!$professionalAddress->save()) {
                    echo "\nשגיאה בשמירת כתובת: {$address}";
                    print_r($professionalAddress->getErrors());
                } else {
                    echo "\nכתובת נוספה: {$address} עם טלפונים: " . implode(', ', $phones);
                }
            }
        } else {
            $professionalAddress = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'street' => null,
                'type' => 'האיגוד הישראלי לפסיכוטרפיה',
            ]);

            foreach ($phoneFields as $index => $field) {
                $professionalAddress->$field = $phones[$index] ?? null;
            }

            if (!$professionalAddress->save()) {
                echo "\n שגיאה בשמירת כתובת ללא עיר";
                print_r($professionalAddress->getErrors());
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

        // ניקוי תווים אחרי /
        $phone = explode('/', $phone)[0];

        // השארת רק ספרות ו-*
        $cleaned = preg_replace('/[^0-9*]/', '', $phone);
        
        return $cleaned;
    }

    private function findProfessionalByPhone($phoneNumbers)
    {
        if (empty($phoneNumbers)) {
            return null;
        }

        // מספרי טלפונים מופרדים ב-|
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

        // חיפוש לפי כל הטלפונים - אם אחד תואם זה אומר שהכל תואם
        foreach ($cleanedPhones as $phone) {
            $conditions = ['or'];
            $conditions[] = ['phone' => $phone];
            $conditions[] = ['phone_2' => $phone];
            $conditions[] = ['phone_3' => $phone];
            $conditions[] = ['phone_4' => $phone];

            $addressResult = (new Query())
                ->select(['professional_id'])
                ->from('professional_address')
                ->where($conditions)
                ->one();

            if ($addressResult) {
                $professionalId = $addressResult['professional_id'];
                $professional = Professional::findOne($professionalId);

                if ($professional) {
                    echo "\nנמצא מקצוען לפי טלפון: {$phone}";
                    return $professional;
                } else {
                    echo "\nנמצא professional_id בכתובת, אך הוא לא קיים בטבלת professional: $professionalId";
                }
            }
        }

        return null;
    }
}