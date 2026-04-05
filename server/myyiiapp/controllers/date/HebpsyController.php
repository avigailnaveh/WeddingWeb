<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class HebpsyController extends Controller {

    public function actionImportHebpsy()
    {
        echo "\nהתחלת הפונקציה...";
        
        $categories = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('category')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($categories) . " קטגוריות";

        // קודם טען את כל ה expertises עם שני השדות: name ו-NormalSpecialization
        $careData = (new Query())->select(['id', 'name'])->from('care')->all();

        $expertiseList = [];
        $expertiseNormalMap = []; // נשאר ריק כי אין שדה NormalSpecialization

        foreach ($careData as $care) {
            $expertiseList[$care['name']] = $care['id'];
       }


        $languagesList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($languagesList) . " שפות";

        $rows = (new Query())->from('_hebpsy')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            $counter++;
            // echo "\nמעבד שורה מספר: {$counter}";
            
            $excludeWords = [
                'רופא מצוות',
                'צוות המרפאה',
                'אחות אחראית',
                'צוות',
                'מנהל',
                'אחות',
                'מרפאת',
                'מרפאה'
            ];

            $needsMatchByName = [
                'פסיכולוג',
                'פסיכולוגית',
                'פסיכולוג/ית',
                'רופא',
                'רופאה',
                'רופא/ה',
                'פסיכיאטרית',
                'פסיכיאטר',
                'פסיכיאטר/ית'
            ];

         
            $profession = trim($row['profession'] ?? '');
            $mainSpecialization = trim($row['mainspecialization'] ?? '');
            $treatmentMethods = trim($row['treatment_methods'] ?? '');
            $licenseFromAyalon = trim($row['license_number'] ?? '');
            $languages = trim($row['languages'] ?? '');
            $phoneNumbers = trim($row['phone_number'] ?? ''); 
            $city = trim($row['location'] ?? '');
            $about = trim($row['about'] ?? '');
            $gender = trim($row['gender'] ?? '');
            $unionsId = $this->getunionsIdByName('פסיכולוגיה עברית');

            $nameRaw = trim($row['name'] ?? '');
            $prefixes = ['ד"ר', "ד'ר", "פרופ'"];
            $title = '';
            $fullName = $nameRaw;
            $firstName = $nameRaw;

            foreach ($prefixes as $prefix) {
                if (mb_strpos($nameRaw, $prefix) === 0) {
                    $title = $prefix;
                    $cleanedName = trim(mb_substr($nameRaw, mb_strlen($prefix)));
                    $fullName = $cleanedName;
                    $firstName = $cleanedName;
                    break;
                }
            }

            $originalName = trim($row['name'] ?? '');
            $prefixes = ['ד"ר', "ד'ר", "פרופ'"];
            $title = null;
            $fullName = $originalName;

            foreach ($prefixes as $prefix) {
                if (mb_strpos($originalName, $prefix) === 0) {
                    $title = $prefix;
                    $fullName = trim(mb_substr($originalName, mb_strlen($prefix))); // מחק את הקידומת מהשם
                    break;
                }
            }

            $firstName = $fullName;

            // תיקון טעות כתיב נפוצה
            if (trim($mainSpecialization) === 'טיפול באומנות') {
                $mainSpecialization = 'טיפול באמנות';
            }
            if (trim($mainSpecialization) === 'אח/ות מוסמכת') {
                $mainSpecialization = 'אחיות וסיעוד';
            }

            echo "\nמעבד: {$fullName} | מקצוע: {$profession} | רישיון: {$licenseFromAyalon}";

            // בדיקת מילות החרגה בשם מלא
            $shouldSkip = false;
            foreach ($excludeWords as $word) {
                if (mb_stripos($fullName, $word) !== false) {
                    echo "\nמדלג על {$fullName} בגלל המילה: {$word}";
                    $shouldSkip = true;
                    break;
                }
            }
            
            if ($shouldSkip) {
                continue;
            }

            // בדיקה אם זה פסיכולוג או רופא - לבדוק לפי רישיון
            $isPsychologistOrDoctor = false;
            
            // בדיקה אם ההתמחות מחייבת התאמה לפי שם
            $isNeedsMatchByName = in_array($profession, $needsMatchByName) || in_array($mainSpecialization, $needsMatchByName);

            if ($isPsychologistOrDoctor && !empty($licenseFromAyalon)) {
                // בדיקה לפי רישיון עבור פסיכולוג/רופא
                echo "\nמחפש לפי רישיון: {$licenseFromAyalon}";
                
                $foundProfessional = $this->findProfessionalByLicense($licenseFromAyalon, $unionsId);

                if ($foundProfessional) {
                    echo "\nנמצא רופא קיים עם רישיון תואם";
                    $foundProfessional->full_name = $fullName;
                    $foundProfessional->first_name = $firstName;
                    if(!empty($about)){
                        $foundProfessional->about = $about;
                    }

                    if (!$foundProfessional->save()) {
                        echo "\nשגיאה בעדכון רופא קיים";
                        print_r($foundProfessional->getErrors());
                    } else {
                        echo "\nרופא עודכן בהצלחה";
                        $this->updateProfessionalDetails($foundProfessional, $row, $categories, $expertiseList, $languagesList, $mainSpecialization, $treatmentMethods,$expertiseNormalMap);
                    }
                } else {
                    echo "\nלא נמצא רופא עם רישיון תואם, מחפש חלופות...";
                    $foundProfessionals = $this->findAllProfessionalsByLicense($licenseFromAyalon);

                    if (!empty($foundProfessionals)) {
                        echo "\nנמצאו " . count($foundProfessionals) . " רופאים עם רישיון דומה";
                        $nameMatchedProfessionals = [];
                        foreach ($foundProfessionals as $foundProf) {
                            if ($this->isNameMatchByFullName($foundProf, $fullName)) {
                                $nameMatchedProfessionals[] = $foundProf;
                            }
                        }

                        if (!empty($nameMatchedProfessionals)) {
                            echo "\nנמצאו " . count($nameMatchedProfessionals) . " רופאים עם שם תואם";
                            foreach ($nameMatchedProfessionals as $professional) {
                                $professional->full_name = $fullName;
                                $professional->first_name = $firstName;
                                if(!empty($about)){
                                    $professional->about = $about;
                                }

                                if ($professional->license_id !== $licenseFromAyalon) {
                                    if (
                                        $professional->license_id_v1 === $licenseFromAyalon ||
                                        $professional->license_id_v2 === $licenseFromAyalon
                                    ) {
                                        $professional->license_id_v0 = $licenseFromAyalon;
                                    }
                                }

                                if (!$professional->save()) {
                                    echo "\nשגיאה בעדכון רופא קיים";
                                    print_r($professional->getErrors());
                                }

                                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $mainSpecialization, $treatmentMethods,$expertiseNormalMap);
                            }
                        } else {
                            echo "\nיוצר רופא חדש - לא נמצא שם תואם";
                            $professional = $this->createNewProfessional($fullName, $firstName, $title, $about, $gender, $licenseFromAyalon, $isNeedsMatchByName, $foundProfessionals);
                            if ($professional) {
                                $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $mainSpecialization, $treatmentMethods,$expertiseNormalMap);
                            }
                        }
                    } else {
                        echo "\nיוצר רופא חדש - לא נמצאו כלל תואמים";
                        $professional = $this->createNewProfessional($fullName, $firstName, $title, $about, $gender, $licenseFromAyalon, $isNeedsMatchByName, []);
                        if ($professional) {
                            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $mainSpecialization, $treatmentMethods,$expertiseNormalMap);
                        }
                    }
                }
            } else {
                // אם לא פסיכולוג/רופא או אין רישיון - חיפוש רק לפי טלפון
                echo "\nמחפש לפי טלפון בלבד";
                
                $foundProfessional = null;
                if (!empty($phoneNumbers)) {
                    $foundProfessional = $this->findProfessionalByPhone($phoneNumbers);
                }
                
                if ($foundProfessional) {
                    echo "\nנמצא מקצוען קיים עם טלפון תואם";
                    $foundProfessional->full_name = $fullName;
                    $foundProfessional->first_name = $firstName;
                    if(!empty($about)){
                        $foundProfessional->about = $about;
                    }

                    if (!$foundProfessional->save()) {
                        echo "\nשגיאה בעדכון מקצוען קיים";
                        print_r($foundProfessional->getErrors());
                    } else {
                        echo "\nמקצוען עודכן בהצלחה";
                        $this->updateProfessionalDetails($foundProfessional, $row, $categories, $expertiseList, $languagesList, $mainSpecialization, $treatmentMethods,$expertiseNormalMap);
                    }
            
                } else {
                    echo "\nיוצר מקצוען חדש";
                    $nameMatches = $this->findProfessionalsByFullName($fullName);
                    
                    $professional = new Professional();
                    $professional->full_name = $fullName;
                    $professional->first_name = $firstName;
                    $professional->title = $title;
                    if(!empty($about)){
                        $professional->about = $about;
                    }

                    if (!empty($gender)) {
                        if ($gender == 'זכר') {
                            $professional->gender = 1;
                        } elseif ($gender == 'נקבה') {
                            $professional->gender = 2;
                        } else {
                            $professional->gender = null;
                        }
                    }

                    if (!$isNeedsMatchByName) {
                        $professional->license_id = 'nm-' . $licenseFromAyalon;
                    } else {
                        $professional->license_id = $licenseFromAyalon;
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
                        echo "\nשגיאה בשמירת מקצוען חדש";
                        print_r($professional->getErrors());
                        continue;
                    }

                    $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $mainSpecialization, $treatmentMethods,$expertiseNormalMap);
                }
            }
            
            // הפסקה כל 10 שורות כדי לא להציף
            if ($counter % 10 === 0) {
                echo "\n⏸הפסקה קצרה...";
                sleep(1);
            }
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
    }

    // פונקציה ליצירת מקצוען חדש
    private function createNewProfessional($fullName, $firstName, $title, $about, $gender, $licenseFromAyalon, $isNeedsMatchByName, $foundProfessionals)
    {
        $professional = new Professional();
        $professional->full_name = $fullName;
        $professional->first_name = $firstName;
        $professional->title = $title;
        if(!empty($about)){
            $professional->about = $about;
        }

        if (!$isNeedsMatchByName) {
            $professional->license_id = 'nm-' . $licenseFromAyalon;
        } else {
            $professional->license_id = $licenseFromAyalon;
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

        if (!empty($foundProfessionals)) {
            $foundIds = array_map(fn($fp) => $fp->id, $foundProfessionals);
            $foundIds = array_unique($foundIds);
            $professional->same_license_different_name = implode(',', $foundIds);
        }

        if (!empty($gender)) {
            if ($gender == 'זכר') {
                $professional->gender = 1;
            } elseif ($gender == 'נקבה') {
                $professional->gender = 2;
            } else {
                $professional->gender = null;
            }
        }

        if (!$professional->save()) {
            echo "\nשגיאה בשמירת מקצוען חדש";
            print_r($professional->getErrors());
            return null;
        }

        return $professional;
    }

    // פונקציה מתוקנת לעדכון פרטי המקצוען
    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, &$languagesList, $mainSpecialization, $treatmentMethods, $expertiseNormalMap)
    {
        if (!empty($mainSpecialization)) {
            $specializations = explode('|', $mainSpecialization);
            foreach ($specializations as $spec) {
                $cleaned = trim($spec);
                if ($cleaned === '') continue;

                // טיפול בקטגוריות
                if (isset($categories[$cleaned])) {
                    $categoryId = $categories[$cleaned];
                    $exists = (new \yii\db\Query())->from('professional_categories')->where([
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

                // טיפול במומחיות (care)
                $careId = null;

                if (isset($expertiseNormalMap[$cleaned])) {
                    $careId = $expertiseNormalMap[$cleaned];
                    echo "\nהתאמה נמצאה ב-NormalSpecialization: $cleaned";
                } elseif (isset($expertiseList[$cleaned])) {
                    $careId = $expertiseList[$cleaned];
                    echo "\nהתאמה נמצאה ב-name: $cleaned";
                } else {
                    Yii::$app->db->createCommand()->insert('care', [
                        'name' => $cleaned,
                    ])->execute();
                    $careId = Yii::$app->db->getLastInsertID();
                    $expertiseList[$cleaned] = $careId;
                    echo "\nתחום מומחיות חדש נוסף: $cleaned";
                }

                if ($careId) {
                    $exists = (new \yii\db\Query())->from('professional_care')->where([
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

        // עדכון טיפולים - מ-treatment_methods
        if (!empty($treatmentMethods)) {
            $treatments = explode('|', $treatmentMethods);
            foreach ($treatments as $treatment) {
                $treatment = trim($treatment);
                if (empty($treatment)) continue;

                // קודם חפש/צור את הטיפול בטבלת care
                $careId = $this->getCareIdByName($treatment);
                
                if ($careId) {
                    // עכשיו בדוק אם הקשר כבר קיים בטבלת professional_care
                    $exists = (new Query())->from('professional_care')->where([
                        'professional_id' => $professional->id,
                        'care_id' => $careId,
                    ])->exists();
                    
                    if (!$exists) {
                        Yii::$app->db->createCommand()->insert('professional_care', [
                            'professional_id' => $professional->id,
                            'care_id' => $careId,
                        ])->execute();
                        echo "\nטיפול חדש נוסף: $treatment (ID: $careId)";
                    }
                } else {
                    echo "\nשגיאה: לא ניתן למצוא/ליצור טיפול: $treatment";
                }
            }
        }

        // הוספת ביטוח
        $unionsName = 'פסיכולוגיה עברית';
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

        // עדכון כתובת וטלפונים
        $this->updateProfessionalAddress($professional, $row);
        
        // עדכון שפות
        $this->updateProfessionalLanguages($professional, $row, $languagesList);
    }
    private function findProfessionalByLicense($licenseFromAyalon, $unionsId)
    {
        // תחילה בדיקה רגילה - רישיון זהה
        $foundProfessional = Professional::find()
            ->innerJoin('professional_unions', 'professional_unions.professional_id = professional.id')
            ->where(['professional.license_id' => $licenseFromAyalon])
            ->andWhere(['professional_unions.unions_id' => $unionsId]) 
            ->one();

        if ($foundProfessional) {
            return $foundProfessional;
        }

        // אם יש מקף ברישיון, בדיקות נוספות
        if (strpos($licenseFromAyalon, '-') !== false) {
            echo "\nמזוהה מקף ברישיון, מבצע בדיקות נוספות...";
            
            // הכנה של וריאציות של הרישיון
            $licenseWithoutDash = str_replace('-', '', $licenseFromAyalon);
            $licenseParts = explode('-', $licenseFromAyalon);
            $rightPart = count($licenseParts) > 1 ? $licenseParts[1] : '';

            // בדיקה 1: רישיון ללא מקף נמצא ב-license_id
            $foundProfessional = Professional::find()
                ->innerJoin('professional_unions', 'professional_unions.professional_id = professional.id')
                ->where(['professional.license_id' => $licenseWithoutDash])
                ->andWhere(['professional_unions.unions_id' => $unionsId])
                ->one();

            if ($foundProfessional) {
                echo "\nנמצא התאמה: רישיון ללא מקף";
                return $foundProfessional;
            }

            // בדיקה 2: החלק הימני בלבד (אחרי המקף) נמצא ב-license_id
            if (!empty($rightPart)) {
                $foundProfessional = Professional::find()
                    ->innerJoin('professional_unions', 'professional_unions.professional_id = professional.id')
                    ->where(['professional.license_id' => $rightPart])
                    ->andWhere(['professional_unions.unions_id' => $unionsId])
                    ->one();

                if ($foundProfessional) {
                    echo "\nנמצא התאמה: החלק הימני של הרישיון";
                    return $foundProfessional;
                }
            }
        }

        return null;
    }
    private function getCareIdByName($careName)
    {
        // חפש אם הטיפול כבר קיים בטבלת care
        $care = (new Query())
            ->select('id')
            ->from('care')
            ->where(['name' => $careName])
            ->one();

        if ($care) {
            return $care['id'];
        }

        // אם לא נמצא, צור טיפול חדש
        try {
            Yii::$app->db->createCommand()
                ->insert('care', ['name' => $careName])
                ->execute();
            $newId = Yii::$app->db->getLastInsertID();
            echo "\nטיפול חדש נוצר בטבלת care: $careName (ID: $newId)";
            return $newId;
        } catch (Exception $e) {
            echo "\nשגיאה ביצירת טיפול חדש: " . $e->getMessage();
            return null;
        }
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

    private function updateProfessionalAddress($professional, $row)
    {
        $cityRaw = trim($row['location'] ?? '');
        $phoneRaw = trim($row['phone_number'] ?? '');

        // פיצול ערים
        $cities = array_filter(array_map('trim', explode('|', $cityRaw)));

        // ניקוי טלפונים
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

        // בדיקה: אם אין ערים וגם אין טלפונים → לא נמשיך
        if (empty($cities) && empty($phones)) {
            echo "\n❌ אין ערים וגם אין טלפונים - לא נוספו כתובות.";
            return;
        }

        // שדות הטלפון האפשריים בכתובת
        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];

        // אם יש ערים → נוסיף כתובת לכל עיר
        if (!empty($cities)) {
            foreach ($cities as $city) {
                $city = trim($city);
                if (empty($city)) continue;

                $address = new ProfessionalAddress([
                    'professional_id' => $professional->id,
                    'city' => $city,
                    'type' => 'פסיכולוגיה עברית',
                ]);

                foreach ($phoneFields as $index => $field) {
                    $address->$field = $phones[$index] ?? null;
                }

                if (!$address->save()) {
                    echo "\n❌ שגיאה בשמירת כתובת לעיר: {$city}";
                    print_r($address->getErrors());
                } else {
                    echo "\n✅ כתובת נוספה לעיר: {$city} עם טלפונים: " . implode(', ', $phones);
                }
            }
        } 
        // אם אין ערים אבל יש טלפונים → נוסיף כתובת כללית
        else {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => null,
                'type' => 'פסיכולוגיה עברית',
            ]);

            foreach ($phoneFields as $index => $field) {
                $address->$field = $phones[$index] ?? null;
            }

            if (!$address->save()) {
                echo "\n❌ שגיאה בשמירת כתובת ללא עיר";
                print_r($address->getErrors());
            } else {
                echo "\n✅ כתובת ללא עיר נוספה עם טלפונים: " . implode(', ', $phones);
            }
        }
    }




    private function updateProfessionalLanguages($professional, $row, &$languagesList)
    {
        $languages = trim($row['languages'] ?? '');
        if (empty($languages)) return;

        $langTranslations = [
            'English' => 'אנגלית',
            'עברית' => 'עברית',
            'français' => 'צרפתית',
            'русский' => 'רוסית',
            'Español' => 'ספרדית',
            'عربيه' => 'ערבית',
            'Deutsch' => 'גרמנית',
            'italiano' => 'איטלקית',
            'ייִדיש' => 'יידיש',
            'ქართული' => 'גאורגית',
            'Polski' => 'פולנית',
            'românesc' => 'רומנית',
            'הונגרית' => 'הונגרית',
            'فارسی' => 'פרסית',
            'አማርኛ' => 'אמהרית'
        ];

        $langs = explode('|', $languages);
        foreach ($langs as $lang) {
            $lang = trim($lang);
            if (!$lang) continue;

            $translatedLang = $langTranslations[$lang] ?? $lang;

            if (!isset($languagesList[$translatedLang])) {
                Yii::$app->db->createCommand()->insert('speaking_language', [
                    'name' => $translatedLang,
                ])->execute();
                $newId = Yii::$app->db->getLastInsertID();
                $languagesList[$translatedLang] = $newId;
                echo "\nשפה חדשה נוספה: {$translatedLang}";
            }

            $langId = $languagesList[$translatedLang];
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
        // חיפוש אם חברת הביטוח כבר קיימת
        $ins = (new Query())
            ->select('id')
            ->from('unions')
            ->where(['name' => $unionsName])
            ->one();

        // אם לא נמצאה, נוסיף אותה
        if (!$ins) {
            Yii::$app->db->createCommand()
                ->insert('unions', ['name' => $unionsName])
                ->execute();
            return Yii::$app->db->getLastInsertID(); // נקבל את ה-ID החדש שנוסף
        }

        return $ins['id']; // אם כבר קיימת, נחזיר את ה-ID שלה
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
    private function isPsychologist($profession)
    {
        $psychologistTerms = ['פסיכולוג', 'פסיכולוגית', 'פסיכולוג/ית'];
        foreach ($psychologistTerms as $term) {
            if (mb_stripos($profession, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isDoctor($profession)
    {
        $doctorTerms = ['רופא', 'רופאה', 'רופא/ה', 'פסיכיאטר', 'פסיכיאטרית', 'פסיכיאטר/ית'];
        foreach ($doctorTerms as $term) {
            if (mb_stripos($profession, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    private function findProfessionalByPhone($phoneNumbers)
    {
        if (empty($phoneNumbers)) {
            return null;
        }

        // פיצול מספרים לפי | וניקוי
        $phones = explode('|', $phoneNumbers);
        $cleanedPhones = [];

        foreach ($phones as $phone) {
            $cleaned = self::cleanPhone($phone);
            if ($cleaned) {
                $cleanedPhones[] = $cleaned;
            }
        }

        if (empty($cleanedPhones)) {
            return null;
        }

        // בניית תנאי OR לכל אחד מהטלפונים ולכל אחד מהשדות
        $conditions = ['or'];
        foreach ($cleanedPhones as $phone) {
            $conditions[] = ['phone' => $phone];
            $conditions[] = ['phone_2' => $phone];
            $conditions[] = ['phone_3' => $phone];
            $conditions[] = ['phone_4' => $phone];
        }

        $addressResult = (new \yii\db\Query())
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