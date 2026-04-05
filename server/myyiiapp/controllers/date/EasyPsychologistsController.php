<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use app\models\Care;
use yii\db\Query;
use Yii;

class EasyPsychologistsController extends Controller {

    public function actionImportEasyPsychologists()
    {
        echo "\nהתחלת הפונקציה...";
        $careData = (new Query())->select(['id', 'name'])->from('care')->all();

        $careList = [];

        foreach ($careData as $care) {
            $careList[$care['name']] = $care['id'];
        }


        $MainCareData = (new Query())->select(['id', 'name'])->from('main_care')->all();

        $MainCareList = [];

        foreach ($MainCareData as $MainCare) {
            $MainCareList[$MainCare['name']] = $MainCare['id'];
        }

        $rows = (new Query())->from('_easy_psychologists')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;

            $city = trim($row['address'] ?? '');
            $fullName = trim($row['name'] ?? '');
            $phoneNumbers = trim($row['phone'] ?? '');
            $categories = trim($row['name2'] ?? '');
            $lisence_id = trim($row['lisence_id'] ?? '');
            $title = '';
            if($categories === 'מטפלים בפסיכודרמה'){
               $categories = 'פסיכודרמה';
            }
            $hasOnlyWoman = false;
            $hasOnlyMale = false;


            $fullNameParts = preg_split('/\s+/', $fullName);

            $detectedTitle = null;

            $titlePatterns = [
                'ד"ר' => '/^ד(?:[״"\']|&quot;)?ר\.?$/u',
                'מר'   => '/^מר\.?$/u',
                'גב\'' => '/^גב[\"\'״׳]?\.?$/u',
            ];

            if (isset($fullNameParts[0])) {
                foreach ($titlePatterns as $title => $pattern) {
                    if (preg_match($pattern, $fullNameParts[0])) {

                        array_shift($fullNameParts);
                        $fullName = implode(' ', $fullNameParts);

                        $detectedTitle = $title;

                        break;
                    }
                }
            }


            if (empty($phoneNumbers)) {
                echo "\nדילוג על שורה - אין מספר טלפון";
                continue;
            }

            $foundProfessional = null;
            if (!empty($lisence_id)) {
                $foundProfessional = $this->findProfessionalByLisenceId($lisence_id);
            }elseif (!empty($phoneNumbers)) {
                $foundProfessional = $this->findProfessionalByPhone($phoneNumbers);
            }
                
            if ($foundProfessional) {
                echo "\nנמצא מקצוען קיים עם טלפון תואם";
                $foundProfessional->full_name = $fullName;
                $foundProfessional->first_name = $fullName;

                if(empty($foundProfessional->phone) || $foundProfessional->phone == ''){
                    $foundProfessional->phone = $this->cleanPhone($phoneNumbers);
                }

                if ($detectedTitle) {
                    $foundProfessional->title = $detectedTitle;
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
                    $this->updateProfessionalDetails($foundProfessional, $row, $city, $phoneNumbers, $categories, $careList, $MainCareList);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->license_id = 'nm';

                $professional->phone = $this->cleanPhone($phoneNumbers);

                if ($detectedTitle) {
                    $professional->title = $detectedTitle;
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

                $this->updateProfessionalDetails($professional, $row, $city, $phoneNumbers, $categories, $careList, $MainCareList);
            }
        }

        echo "\n\n ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. \n";
    }

    private function updateProfessionalDetails($professional, $row, $city, $phoneNumbers, $categories, &$careList, &$MainCareList)
    {

        if (!empty($categories)) {
            $specializations = explode('|', $categories);
            foreach ($specializations as $spec) {
                $cleaned = trim($spec);
                if($cleaned === 'פסיכיאטריה'){
                    $MainSpecializationId = "11";
                    $queryMainSpecialization = (new Query())->from('professional_main_specialization')->where([
                        'professional_id' => $professional->id,
                        'main_specialization_id' => $MainSpecializationId,
                    ])->exists();

                    if (!$queryMainSpecialization) {
                        Yii::$app->db->createCommand()->insert('professional_main_specialization', [
                            'professional_id' => $professional->id,
                            'main_specialization_id' => $MainSpecializationId,
                        ])->execute();
                    }

                    $ExpertiseId = "1030";
                    $queryExpertise = (new Query())->from('professional_expertise')->where([
                        'professional_id' => $professional->id,
                        'expertise_id' => $ExpertiseId,
                    ])->exists();

                    if (!$queryExpertise) {
                        Yii::$app->db->createCommand()->insert('professional_expertise', [
                            'professional_id' => $professional->id,
                            'expertise_id' => $ExpertiseId,
                        ])->execute();
                    }
                    continue;
                }

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
        
        $mainCareAllowed = [
        "פסיכולוגיה קלינית",
        "פסיכולוגיה",
        "פסיכולוגיה חינוכית",
        "פסיכותרפיה",
        "עבודה סוציאלית קלינית",
        "פסיכולוגיה התפתחותית",
        "טיפול בהבעה ויצירה",
        "טיפול באמנות",
        "פסיכיאטריה",
        "פסיכולוגיה שיקומית",
        "פסיכולוגיה רפואית",
        "ספורט שיקומי",
        "הדרכת הורים",
        "פסיכולוגיה תעסוקתית",
        "פסיכולוגיית ספורט",
        "טיפול במוזיקה",
        "ייעוץ זוגי ומשפחתי",
        "אבחון לקויי למידה",
        "פסיכולוגיה קוגניטיבית",
        "M.SW",
        "פסיכולוגיה אלטרנטיבית",
        "פסיכולוגיה חיובית",
        "ריפוי בעיסוק",
        "קלינאות תקשורת",
        "פסיכואונקולוגיה"
        ];

        if (!empty($categories)) {
            $specializations = explode('|', $categories);
            foreach ($specializations as $spec) {
                $cleaned = trim($spec);

                if ($cleaned === '' || !in_array($cleaned, $mainCareAllowed) || $cleaned === 'פסיכיאטריה') continue;     
                $MainCareId = null;

                if (isset($MainCareList[$cleaned])) {
                    $MainCareId = $MainCareList[$cleaned];
                    echo "\nהתאמה נמצאה ב-name: $cleaned";
                } else {
                    Yii::$app->db->createCommand()->insert('main_care', [
                        'name' => $cleaned,
                    ])->execute();
                    $MainCareId = Yii::$app->db->getLastInsertID();
                    $MainCareList[$cleaned] = $MainCareId;
                    echo "\nתחום מומחיות חדש נוסף: $cleaned";
                }

                if ($MainCareId) {
                    $exists = (new Query())->from('professional_main_care')->where([
                        'professional_id' => $professional->id,
                        'main_care_id' => $MainCareId,
                    ])->exists();
                    if (!$exists) {
                        Yii::$app->db->createCommand()->insert('professional_main_care', [
                            'professional_id' => $professional->id,
                            'main_care_id' => $MainCareId,
                        ])->execute();
                    }
                }
            }
        }


        $main_to_sub = [
            "פסיכולוגיה קלינית" => "פסיכולוגיה קלינית",
            "פסיכולוגיה" => "פסיכולוגיה",
            "פסיכולוגיה חינוכית" => "פסיכולוגיה חינוכית",
            "NLP" => "פסיכולוגיה | פסיכולוגיה רפואית",
            "פסיכותרפיה" => "פסיכותרפיה",
            "עבודה סוציאלית קלינית" => "עבודה סוציאלית קלינית",
            "פסיכולוגיה התפתחותית" => "פסיכולוגיה התפתחותית",
            "טיפול בהבעה ויצירה" => "טיפול בהבעה ויצירה",
            "טיפול באמנות" => "טיפול באמנות",
            "פסיכולוגיה שיקומית" => "פסיכולוגיה שיקומית",
            "פסיכולוגיה רפואית" => "פסיכולוגיה רפואית",
            "ספורט שיקומי" => "ספורט שיקומי",
            "הדרכת הורים" => "הדרכת הורים",
            "ייעוץ שינה" => "פסיכולוגיה",
            "פסיכולוגיה תעסוקתית" => "פסיכולוגיה תעסוקתית",
            "פסיכולוגיית ספורט" => "פסיכולוגיית ספורט",
            "הדרכה" => "פסיכולוגיה | פסיכולוגיה קלינית",
            "פסיכולוגיה ילדים" => "פסיכולוגיה ילדים",
            "ניתוח התנהגות" => "פסיכולוגיה",
            "CBT" => "פסיכולוגיה | פסיכותרפיה",
            "Ph.D" => "פסיכולוגיה  | פסיכולוגיה קלינית",
            "אימון אישי" => "אימון",
            "יעוץ טיפולי" => "פסיכולוגיה",
            "טראומה" => "פסיכולוגיה קלינית",
            "טיפול במוזיקה" => "טיפול במוזיקה",
            "ייעוץ זוגי ומשפחתי" => "ייעוץ זוגי ומשפחתי" ,
            "ייעוץ פסיכולוגי" => "פסיכולוגיה",
            "מטפלים בפסיכודרמה" => "פסיכודרמה",
            "אבחון לקויי למידה" => "אבחון לקויי למידה",
            "פסיכולוגיה קוגניטיבית" => "פסיכולוגיה קוגניטיבית",
            "LICBT" => "פסיכולוגיה",
            "אבחון פסיכודידקטי" => "פסיכולוגיה",
            "M.SW" => "פסיכולוגיה",
            "פסיכולוגיה אלטרנטיבית" => "פסיכולוגיה אלטרנטיבית",
            "פסיכולוגיה חיובית" => "פסיכולוגיה חיובית",
            "יעוץ חינוכי" => "פסיכולוגיה חינוכית",
            "ריפוי בעיסוק" => "ריפוי בעיסוק",
            "אימון אישי ועסקי" => "אימון",
            "קלינאות תקשורת" => "קלינאות תקשורת",
            "פסיכואונקולוגיה" => "פסיכולוגיה"
        ];

        foreach ($main_to_sub as $subName => $mainNames) {
            $sub = (new Query())
                ->select(['id'])
                ->from('care')
                ->where(['name' => trim($subName)])
                ->one();
                
            if (!$sub) {
                continue;
            }

            $mainList = array_map('trim', explode('|', $mainNames));

            foreach ($mainList as $mainName) {

                $main = (new Query())
                    ->select(['id'])
                    ->from('main_care')
                    ->where(['name' => trim($mainName)])
                    ->one();
                    
                if (!$main) {
                    continue;
                }

                $exists = (new Query())
                    ->from('main_care_sub_care')
                    ->where([
                        'main_care_id' => $main['id'],
                        'care_id' => $sub['id']
                    ])
                    ->exists();

                if ($exists) {
                    continue;
                }

                Yii::$app->db->createCommand()->insert('main_care_sub_care', [
                    'main_care_id' => $main['id'],
                    'care_id' => $sub['id']
                ])->execute();
                
            }
        }


        $unionsName = 'EasyPsychologists';
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
                    'type' => 'EasyPsychologists',
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
                'type' => 'EasyPsychologists',
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

    
    private function findProfessionalByLisenceId($licenseId)
    {
        if (empty($licenseId)) {
            return null;
        }

        $professionals = Professional::find()
            ->where(['id' => $licenseId])
            ->all();

        if (empty($professionals)) {
            return null;
        }

        if (count($professionals) === 1) {
            return $professionals[0];
        }

        return $professionals;
    }

}