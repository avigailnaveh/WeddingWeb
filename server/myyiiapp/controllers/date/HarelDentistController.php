<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class HarelDentistController extends Controller {

    public function actionImportHarelDentist()
    {
        echo "\nהתחלת הפונקציה...";
        
        $mainCareData = (new Query())->select(['id', 'name'])->from('main_specialization')->all();

        $mainCareList = [];

        foreach ($mainCareData as $mainCare) {
            $mainCareList[$mainCare['name']] = $mainCare['id'];
        }

        echo "\nנטענו " . count($mainCareList) . " תחומי מומחיות";

        $careData = (new Query())->select(['id', 'name'])->from('expertise')->all();

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

        $rows = (new Query())->from('_harel_dentist')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        $countrOfFound = 0;
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;
            echo "\nמעבד שורה מספר: {$counter}";
            

            $fullName = trim($row['ddname'] ?? '');
            $mainSpecialization = 'רפואת שיניים';
            $specialization =  trim($row['normalized_specialty'] ?? '');

            $licenseFromAyalon = trim($row['license_number']);
            $title = trim($row['ddtitle'] ?? '');
            $city = trim($row['ddcity'] ?? '');
            $languages = trim($row['languages'] ?? '');
            $phoneNumbers = trim($row['ddcode'] ?? '');
            $address = trim($row['ddaddress'] ?? '');

            if($title === "דר'"){
                $title = 'ד"ר';
            }

            if (preg_match("/^(ד\"ר|דר'|'דר|דר)\s*/u", $fullName, $matches)) {
                // אם נמצא, מסיר מהשם ושם את זה ב-title
                $fullName = preg_replace("/^(ד\"ר|דר'|'דר|דר)\s*/u", '', $fullName);

                // מוסיף ל-title אם ריק
                if (empty($title)) {
                    $title = "ד\"ר";
                }
            }
            $firstName = $fullName;

            echo "\nמעבד: {$fullName} | רישיון: {$licenseFromAyalon}";

            //לא לשכוח להפריד את הtitle מה name
            if (!empty($licenseFromAyalon)) {
                echo "\nמחפש לפי רישיון: {$licenseFromAyalon}";
                
        
                    // אם לא נמצא רופא תואם, מחפשים את כל הרופאים עם ההתאמות הבסיסיות
                    $foundProfessionals = $this->findAllProfessionalsByLicense($licenseFromAyalon);
                    if (!empty($foundProfessionals)) {
                        echo "\nנמצאו " . count($foundProfessionals) . " רופאים עם רישיון דומה";
                        $nameMatchedProfessionals = [];
                        foreach ($foundProfessionals as $foundProf) {
                            // אם שמו תואם (על פי ההשוואה לפי השם המלא)
                            if ($this->isNameMatchByFullName($foundProf, $fullName)) {
                                $nameMatchedProfessionals[] = $foundProf;
                            }
                        }

                        if (!empty($nameMatchedProfessionals)) {
                            echo "\nנמצאו " . count($nameMatchedProfessionals) . " רופאים עם שם תואם";
                            foreach ($nameMatchedProfessionals as $professional) {
                                if (empty($professional->license_id)) {
                                    continue;
                                }
                                $professional->full_name = $fullName;
                                $professional->first_name = $firstName;
                                if(!empty($title)){
                                    $professional->title = $title;
                                }

                                if ($professional->license_id !== $licenseFromAyalon) {
                                    if (
                                        $professional->license_id_v1 === $licenseFromAyalon ||
                                        $professional->license_id_v2 === $licenseFromAyalon
                                    ) {
                                        $professional->license_id_v0 = $licenseFromAyalon;
                                    }
                                }

                                $countrOfFound++;

                                if (!$professional->save()) {
                                    echo "\nשגיאה בעדכון רופא קיים";
                                    print_r($professional->getErrors());
                                }

                                $this->updateProfessionalDetails($professional, $row, $careList,$mainCareList, $languagesList, $mainSpecialization, $city ,$specialization);
                            }
                        } else {
                            echo "\nיוצר רופא חדש - לא נמצא שם תואם";
                            // יצירת מקצוען חדש
                            $professional = new Professional();
                            $professional->full_name = $fullName;
                            $professional->first_name = $firstName;
                            $professional->license_id = $licenseFromAyalon;
                            if(!empty($title)){
                                $professional->title = $title;
                            }

                            // חישוב license_id_v1 ו-license_id_v2 לפי הדרישה
                            $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromAyalon);
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

                            $this->updateProfessionalDetails($professional, $row, $careList,$mainCareList, $languagesList, $mainSpecialization, $city ,$specialization);
                        }
                    }else{
                        echo "\nיוצר רופא חדש - לא נמצאו כלל תואמים";
                        $professional = new Professional();
                        $professional->full_name = $fullName;
                        $professional->first_name = $firstName;
                        $professional->license_id = $licenseFromAyalon;
                        echo "אני פה: $licenseFromAyalon  \n";
                        if(!empty($title)){
                            $professional->title = $title;
                        }

                        // חישוב v1 ו‑v2
                        $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromAyalon);
                        $licenseNoDash = str_replace('-', '', $licenseNumeric);
                        $parts = explode('-', $licenseNumeric);
                        if (count($parts) === 2) {
                            $prefix = preg_replace('/\D/', '', $parts[0]);
                            $suffix = $parts[1];
                            $professional->license_id_v1 = $prefix . $suffix;
                            $professional->license_id_v2 = $suffix;
                        } else {
                            $professional->license_id_v1 = $licenseNoDash;
                            $professional->license_id_v2 = $licenseNoDash;
                        }

                        if (!$professional->save()) {
                            echo "\nשגיאה בשמירת רופא חדש";
                            print_r($professional->getErrors());
                            continue;
                        }

                        $this->updateProfessionalDetails($professional, $row, $careList,$mainCareList, $languagesList, $mainSpecialization, $city ,$specialization);
                    }
                
            } else {
                echo "\nרישיון ריק - יוצר רופא חדש";
                // רישיון ריק
                $nameMatches = $this->findProfessionalsByFullName($fullName);

                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $firstName;
                if(!empty($title)){
                    $professional->title = $title;
                }

                if (!empty($nameMatches)) {
                    $newIds = array_map(fn($m) => $m->id, $nameMatches);
                    $existingIdsRaw = $professional->same_license_different_name ?? '';
                    $existingIds = array_filter(array_map('trim', explode(',', $existingIdsRaw)));
                    $allIds = array_unique(array_merge($existingIds, $newIds));
                    $professional->same_license_different_name = implode(',', $allIds);
                }

                if (trim($fullName) === '') {
                    echo "\nשם ריק בשורה: " . json_encode($row, JSON_UNESCAPED_UNICODE);
                }

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת רופא חדש ללא רישיון";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $careList,$mainCareList, $languagesList, $mainSpecialization, $city ,$specialization);
            }
           
        }
        echo "נמצאו $countrOfFound  התאמות";
        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }

    private function findAllProfessionalsByLicense($licenseFromAyalon)
    {
        $foundProfessionals = [];

        // חיפוש רגיל
        $regularSearch = Professional::find()
            ->where(['license_id' => $licenseFromAyalon])
            ->orWhere(['license_id_v1' => $licenseFromAyalon])
            ->orWhere(['license_id_v2' => $licenseFromAyalon])
            ->all();

        $foundProfessionals = array_merge($foundProfessionals, $regularSearch);

        // אם יש מקף ברישיון, חיפושים נוספים
        if (strpos($licenseFromAyalon, '-') !== false) {
            $licenseWithoutDash = str_replace('-', '', $licenseFromAyalon);
            $licenseParts = explode('-', $licenseFromAyalon);
            $rightPart = count($licenseParts) > 1 ? $licenseParts[1] : '';

            // חיפוש לפי רישיון ללא מקף
            $noDashSearch = Professional::find()
                ->where(['license_id' => $licenseWithoutDash])
                ->orWhere(['license_id_v1' => $licenseWithoutDash])
                ->orWhere(['license_id_v2' => $licenseWithoutDash])
                ->all();

            $foundProfessionals = array_merge($foundProfessionals, $noDashSearch);

            // חיפוש לפי החלק הימני בלבד
            if (!empty($rightPart)) {
                $rightPartSearch = Professional::find()
                    ->where(['license_id' => $rightPart])
                    ->orWhere(['license_id_v1' => $rightPart])
                    ->orWhere(['license_id_v2' => $rightPart])
                    ->all();

                $foundProfessionals = array_merge($foundProfessionals, $rightPartSearch);
            }
        }

        // הסרת כפילויות
        $uniqueProfessionals = [];
        $seenIds = [];
        foreach ($foundProfessionals as $prof) {
            if (!in_array($prof->id, $seenIds)) {
                $uniqueProfessionals[] = $prof;
                $seenIds[] = $prof->id;
            }
        }

        return $uniqueProfessionals;
    }

    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function isNameMatchByFullName($professional, $ayalonFullName)
    {

        if (empty($professional->full_name)) {
            $professional->full_name = $professional->first_name . ' ' . $professional->last_name;
        }

        $professionalArray = preg_split('/\s+/', trim($professional->full_name ?? ''));
        $matchedProfessionalArray = preg_split('/\s+/', trim($ayalonFullName));

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

    private function updateProfessionalDetails($professional, $row, &$careList, &$mainCareList, &$languagesList, $mainSpecialization, $city, $specialization)
    {

        $mainSpecializationCleaned = trim($mainSpecialization); 
        $specializationCleaned = trim($specialization); 
        echo "בדוק אם ריק: $specializationCleaned \n";

        if ($mainSpecializationCleaned === '' && $specializationCleaned === '') {
            echo "\nאין נתוני התמחות לעדכון";
            return;
        }

        $mainCareId = null;

        // === עיבוד התמחות ראשית ===
        if ($mainSpecializationCleaned !== '') {
            if (isset($mainCareList[$mainSpecializationCleaned])) {
                $mainCareId = $mainCareList[$mainSpecializationCleaned];
                echo "\nהתאמה נמצאה בהתמחות ראשית: $mainSpecializationCleaned";
            } else {
                // יצירת התמחות ראשית חדשה
                Yii::$app->db->createCommand()->insert('main_specialization', [
                    'name' => $mainSpecializationCleaned,
                ])->execute();
                $mainCareId = Yii::$app->db->getLastInsertID();
                $mainCareList[$mainSpecializationCleaned] = $mainCareId;
                echo "\nהתמחות ראשית חדשה נוספה: $mainSpecializationCleaned (ID: $mainCareId)";
            }

            // קישור לטבלת professional_main_specialization
            if ($mainCareId) {
                $exists = (new Query())->from('professional_main_specialization')->where([
                    'professional_id' => $professional->id,
                    'main_specialization_id' => $mainCareId,
                ])->exists();
                
                if (!$exists) {
                    Yii::$app->db->createCommand()->insert('professional_main_specialization', [
                        'professional_id' => $professional->id,
                        'main_specialization_id' => $mainCareId,
                    ])->execute();
                    echo "\nקושר רופא להתמחות ראשית: $mainSpecializationCleaned";
                }
            }
        }

        // === עיבוד תתי-התמחויות (EXPERTISE) ===
        if ($specializationCleaned !== '') {
            // פיצול לפי | או פסיק
            $specializationsArray = preg_split('/[|,]/', $specializationCleaned);

            foreach ($specializationsArray as $spec) {
                $specTrimmed = trim($spec);
                if ($specTrimmed === '') {
                    continue;
                }

                $careId = null;

                if (isset($careList[$specTrimmed])) {
                    $careId = $careList[$specTrimmed];
                    echo "\nהתאמה נמצאה בתת-התמחות: $specTrimmed";
                } else {
                    // יצירת תת-התמחות חדשה
                    echo "היי מפה:   $specTrimmed \n";
                    Yii::$app->db->createCommand()->insert('expertise', [
                        'name' => $specTrimmed,
                        'category_id' => null,
                        'sub_category_id' => null,
                    ])->execute();
                    $careId = Yii::$app->db->getLastInsertID();
                    $careList[$specTrimmed] = $careId;
                    echo "\nתת-התמחות חדשה נוספה: $specTrimmed (ID: $careId)";
                }

                // קישור לטבלת professional_expertise
                if ($careId) {
                    $exists = (new Query())->from('professional_expertise')->where([
                        'professional_id' => $professional->id,
                        'expertise_id' => $careId,
                    ])->exists();
                    
                    if (!$exists) {
                        Yii::$app->db->createCommand()->insert('professional_expertise', [
                            'professional_id' => $professional->id,
                            'expertise_id' => $careId,
                        ])->execute();
                        echo "\nקושר רופא לתת-התמחות: $specTrimmed";
                    }
                }

                // קישור בין התמחות ראשית לתת-התמחות
                if ($mainCareId && $careId) {
                    $exists = (new Query())->from('main_specialization_expertise')->where([
                        'main_specialization_id' => $mainCareId,
                        'expertise_id' => $careId,
                    ])->exists();
                    
                    if (!$exists) {
                        Yii::$app->db->createCommand()->insert('main_specialization_expertise', [
                            'main_specialization_id' => $mainCareId,
                            'expertise_id' => $careId,
                        ])->execute();
                        echo "\nקושר התמחות ראשית ($mainSpecializationCleaned) לתת-התמחות ($specTrimmed)";
                    } else {
                        echo "\nקישור כבר קיים בין התמחות ראשית ($mainSpecializationCleaned) לתת-התמחות ($specTrimmed)";
                    }
                } elseif ($mainCareId && !$careId) {
                    echo "\nיש התמחות ראשית אבל אין תת-התמחות - לא נוצר קישור";
                } elseif (!$mainCareId && $careId) {
                    echo "\nיש תת-התמחות אבל אין התמחות ראשית - לא נוצר קישור";
                }
            }
        }

        // === עיבוד איגוד ===
        $unionsName = 'הראל רפואת שיניים';
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
            echo "\nקושר רופא לאיגוד: $unionsName";
        }

        // עדכון כתובת
        $this->updateProfessionalAddress($professional, $row, $city);
    }


    private function updateProfessionalAddress($professional, $row, $city)
    {
        $phoneRaw = trim($row['ddphone'] ?? ''); 
        $street = trim($row['ddaddress'] ?? '');
        $phones = [];
            
        if (!empty($phoneRaw)) {
            $phoneArray = preg_split('/[|\/]/', $phoneRaw);

            foreach ($phoneArray as $phone) {
                $cleaned = self::cleanPhone($phone);
                if ($cleaned) {
                    $phones[] = $cleaned;
                }
            }
        }

        $phones = array_values(array_unique($phones));

        if (empty($city) && empty($street) && empty($phones)) {
            echo "\n אין ערים וגם אין טלפונים - לא נוספו כתובות.";
            return;
        }

        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];

        // תוקן: עיבוד העיר כמחרוזת ולא כמערך
        if (!empty($city)) {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'street' => $street,
                'city' => $city,
                'type' => 'הראל רפואת שיניים',
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
        } else {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'type' => 'הראל רפואת שיניים',
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
            echo "\nלא נמצא מספר טלפון תקין: {$phone}";
            return null;
        }

        // אם מתחיל ב-+972, להחליף ל-0
        $phone = preg_replace('/^\+972/', '0', $phone);
        echo "\n אחרי החלפת +972 ל-0: {$phone}";

        // ניקוי תווים מיותרים (למעט מספרים וכוכבית)
        $phone = explode('/', $phone)[0];
        echo "\n אחרי ניקוי תווים מיותרים: {$phone}";

        return preg_replace('/[^0-9*]/', '', $phone);
    }

}