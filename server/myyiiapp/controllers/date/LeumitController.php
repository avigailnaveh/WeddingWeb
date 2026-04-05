<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class LeumitController extends Controller {

    public function actionImportLeumit()
{
    echo "\nהתחלת הפונקציה...";
    
    $categories = ArrayHelper::map(
        (new Query())->select(['id', 'name'])->from('category')->all(),
        'name',
        'id'
    );
    echo "\nנטענו " . count($categories) . " קטגוריות";

    $expertiseList = ArrayHelper::map(
        (new Query())->select(['id', 'name', 'NormalSpecialization'])->from('expertise')->all(),
        'name',
        'id'
    );
    
    // יצירת מפה נוספת עבור NormalSpecialization
    $expertiseNormalMap = [];
    $expertiseData = (new Query())->select(['id', 'name', 'NormalSpecialization'])->from('expertise')->all();
    foreach ($expertiseData as $expertise) {
        if (!empty($expertise['NormalSpecialization'])) {
            $expertiseNormalMap[$expertise['NormalSpecialization']] = $expertise['id'];
        }
        $expertiseList[$expertise['name']] = $expertise['id'];
    }

    $languagesList = ArrayHelper::map(
        (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
        'name',
        'id'
    );
    echo "\nנטענו " . count($languagesList) . " שפות";

    $rows = (new Query())->from('_leumit_mashlim')->each(200);
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
            'אחות',
            'מרפאת',
            'מרפאה'
        ];

        $needsMatchByName = [
            "תכנית ביקור בריא",
        ];

        $fullName = trim($row['name'] ?? '');
        $firstName = trim($row['name'] ?? '');
        $specialization = trim($row['specialty'] ?? '');
        $specialistCert = trim($row['additional_specialty'] ?? '');
        $licenseFromAyalon = trim($row['license_number'] ?? '');
        $title = trim($row['title'] ?? '');
        $languages = trim($row['languages'] ?? '');
        $phoneNumbers = trim($row['phone_numbers'] ?? '');
        $address = trim($row['address'] ?? '');
        $companyId = $this->getcompanyIdByName('לאומית משלים');

        echo "\nמעבד: {$fullName} | רישיון: {$licenseFromAyalon}";

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

        // בדיקה אם ההתמחות מחייבת התאמה לפי שם
        $isNeedsMatchByName = in_array($specialization, $needsMatchByName) || in_array($specialistCert, $needsMatchByName);

        if (!empty($licenseFromAyalon)) {
            echo "\nמחפש לפי רישיון: {$licenseFromAyalon}";
            
            // בדיקה מתקדמת של רישיון עם התמודדות עם מקפים
            $foundProfessional = $this->findProfessionalByLicense($licenseFromAyalon, $companyId);

            if ($foundProfessional) {
                echo "\nנמצא רופא קיים עם רישיון תואם";
                // אם נמצא רופא תואם, מעדכנים אותו ישירות
                $foundProfessional->full_name = $fullName;
                $foundProfessional->first_name = $firstName;
                $foundProfessional->title = $title;

                // שים לב לקוד המוסך שיכניס 1 או 2 על פי המגדר
                if (!empty($gender)) {
                    if ($gender == 'זכר') {
                        $foundProfessional->gender = 1;  // זכר
                    } elseif ($gender == 'נקבה') {
                        $foundProfessional->gender = 2;  // נקבה
                    } else {
                        $foundProfessional->gender = null; // במידה ולא נמצא מגדר תואם
                    }
                }


                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון רופא קיים";
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nרופא עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
                }
            } else {
                echo "\nלא נמצא רופא עם רישיון תואם, מחפש חלופות...";
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
                            $professional->full_name = $fullName;
                            $professional->first_name = $firstName;
                            $professional->title = $title;

                            if ($professional->license_id !== $licenseFromAyalon) {
                                if (
                                    $professional->license_id_v1 === $licenseFromAyalon ||
                                    $professional->license_id_v2 === $licenseFromAyalon
                                ) {
                                    $professional->license_id_v0 = $licenseFromAyalon;
                                }
                            }

                            if (!empty($gender)) {
                                if ($gender == 'זכר') {
                                    $professional->gender = 1;  // זכר
                                } elseif ($gender == 'נקבה') {
                                    $professional->gender = 2;  // נקבה
                                } else {
                                    $professional->gender = null; // במידה ולא נמצא מגדר תואם
                                }
                            }


                            if (!$professional->save()) {
                                echo "\nשגיאה בעדכון רופא קיים";
                                print_r($professional->getErrors());
                            }

                            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
                        }
                    } else {
                        echo "\nיוצר רופא חדש - לא נמצא שם תואם";
                        // יצירת מקצוען חדש
                        $professional = new Professional();
                        $professional->full_name = $fullName;
                        $professional->first_name = $firstName;
                        $professional->title = $title;

                        if ($isNeedsMatchByName) {
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

                        $foundIds = array_map(fn($fp) => $fp->id, $foundProfessionals);
                        $foundIds = array_unique($foundIds);
                        $professional->same_license_different_name = implode(',', $foundIds);

                        // שים לב לקוד המוסך שיכניס 1 או 2 על פי המגדר
                        if (!empty($gender)) {
                            if ($gender == 'זכר') {
                                $professional->gender = 1;  // זכר
                            } elseif ($gender == 'נקבה') {
                                $professional->gender = 2;  // נקבה
                            } else {
                                $professional->gender = null; // במידה ולא נמצא מגדר תואם
                            }
                        }


                        if (!$professional->save()) {
                            echo "\nשגיאה בשמירת רופא חדש עם רישיון שונה";
                            print_r($professional->getErrors());
                            continue;
                        }

                        $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
                    }
                }else{
                    echo "\nיוצר רופא חדש - לא נמצאו כלל תואמים";
                    $professional = new Professional();
                    $professional->full_name = $fullName;
                    $professional->first_name = $firstName;
                    $professional->title = $title;

                    // מגדירים license_id כמו במצב של התאמה לפי שם
                    if ($isNeedsMatchByName) {
                        $professional->license_id = 'nm-' . $licenseFromAyalon;
                    } else {
                        $professional->license_id = $licenseFromAyalon;
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

                    if (!empty($gender)) {
                            if ($gender == 'זכר') {
                                $professional->gender = 1;  // זכר
                            } elseif ($gender == 'נקבה') {
                                $professional->gender = 2;  // נקבה
                            } else {
                                $professional->gender = null; // במידה ולא נמצא מגדר תואם
                            }
                        }

                    if (!$professional->save()) {
                        echo "\nשגיאה בשמירת רופא חדש";
                        print_r($professional->getErrors());
                        continue;
                    }

                    // ואז כמובן לעדכן את הפרטים
                    $this->updateProfessionalDetails(
                        $professional, $row, $categories, $expertiseList, $languagesList, 
                        $specialization, $specialistCert
                    );
                }
            }
        } else {
            echo "\nרישיון ריק - יוצר רופא חדש";
            // רישיון ריק
            $nameMatches = $this->findProfessionalsByFullName($fullName);

            $professional = new Professional();
            $professional->full_name = $fullName;
            $professional->first_name = $firstName;
            $professional->title = $title;

            // שים לב לקוד המוסך שיכניס 1 או 2 על פי המגדר
            if (!empty($gender)) {
                if ($gender == 'זכר') {
                    $professional->gender = 1;  // זכר
                } elseif ($gender == 'נקבה') {
                    $professional->gender = 2;  // נקבה
                } else {
                    $professional->gender = null; // במידה ולא נמצא מגדר תואם
                }
            }


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

            if (trim($fullName) === '') {
                echo "\nשם ריק בשורה: " . json_encode($row, JSON_UNESCAPED_UNICODE);
            }

            if (!$professional->save()) {
                echo "\nשגיאה בשמירת רופא חדש ללא רישיון";
                print_r($professional->getErrors());
                continue;
            }

            $this->updateProfessionalDetails($professional, $row, $categories, $expertiseList, $languagesList, $specialization, $specialistCert);
        }
        
        // הפסקה כל 10 שורות כדי לא להציף
        if ($counter % 10 === 0) {
            echo "\n⏸הפסקה קצרה...";
            sleep(1);
        }
    }

    echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖\n";
}

    private function findProfessionalByLicense($licenseFromAyalon, $companyId)
    {
        // תחילה בדיקה רגילה - רישיון זהה
        $foundProfessional = Professional::find()
            ->innerJoin('professional_company', 'professional_company.professional_id = professional.id')
            ->where(['professional.license_id' => $licenseFromAyalon])
            ->andWhere(['professional_company.company_id' => $companyId]) 
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
                ->innerJoin('professional_company', 'professional_company.professional_id = professional.id')
                ->where(['professional.license_id' => $licenseWithoutDash])
                ->andWhere(['professional_company.company_id' => $companyId])
                ->one();

            if ($foundProfessional) {
                echo "\nנמצא התאמה: רישיון ללא מקף";
                return $foundProfessional;
            }

            // בדיקה 2: החלק הימני בלבד (אחרי המקף) נמצא ב-license_id
            if (!empty($rightPart)) {
                $foundProfessional = Professional::find()
                    ->innerJoin('professional_company', 'professional_company.professional_id = professional.id')
                    ->where(['professional.license_id' => $rightPart])
                    ->andWhere(['professional_company.company_id' => $companyId])
                    ->one();

                if ($foundProfessional) {
                    echo "\nנמצא התאמה: החלק הימני של הרישיון";
                    return $foundProfessional;
                }
            }
        }

        return null;
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

    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, &$languagesList, $specialization, $specialistCert)
    {
        // עדכון התמחויות - הן מ-Specialization והן מ-SpecialistCert
        $specializations = [];

        // הוספת Specialization (פירוק לפי |)
        if (!empty($specialization)) {
            $specParts = explode('|', $specialization);
            foreach ($specParts as $spec) {
                $spec = trim($spec);
                if (!empty($spec)) {
                    $specializations[] = $spec;
                }
            }
        }

        if (!empty($specialistCert)) {
            $certParts = explode('|', $specialistCert);
            foreach ($certParts as $cert) {
                $cert = trim($cert);
                if (!empty($cert)) {
                    $specializations[] = $cert;
                }
            }
        }

        // עיבוד כל ההתמחויות
        foreach ($specializations as $spec) {
            $cleaned = trim($spec);
            if ($cleaned === '') continue;

            // בדיקה אם קיימת כקטגוריה
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
                }
            }
            // בדיקה עבור מומחיות עם סדר עדיפויות
            $expertiseId = null;
            
            // בדיקה ראשונה: האם יש התאמה לשדה NormalSpecialization
            if (isset($expertiseNormalMap[$cleaned])) {
                $expertiseId = $expertiseNormalMap[$cleaned];
                echo "\nהתאמה נמצאה ב-NormalSpecialization: $cleaned";
            }
            // בדיקה שנייה: האם יש התאמה לשדה name
            else if (isset($expertiseList[$cleaned])) {
                $expertiseId = $expertiseList[$cleaned];
                echo "\nהתאמה נמצאה ב-name: $cleaned";
            }
            // אם לא נמצאה התאמה, יוצר התמחות חדשה
            else {
                Yii::$app->db->createCommand()->insert('expertise', [
                    'name' => $cleaned,
                    'category_id' => null,
                    'sub_category_id' => null,
                ])->execute();
                $expertiseId = Yii::$app->db->getLastInsertID();
                $expertiseList[$cleaned] = $expertiseId;
                echo "\nתחום מומחיות חדש נוסף: $cleaned";
            }

            // הוספת הקשר בין המקצוען לתחום המומחיות
            if ($expertiseId) {
                $exists = (new Query())->from('professional_expertise')->where([
                    'professional_id' => $professional->id,
                    'expertise_id' => $expertiseId,
                ])->exists();
                if (!$exists) {
                    Yii::$app->db->createCommand()->insert('professional_expertise', [
                        'professional_id' => $professional->id,
                        'expertise_id' => $expertiseId,
                    ])->execute();
                }
            }
            // תמיד יטופל כמומחיות (גם אם קיים בקטגוריה)
            // if (!isset($expertiseList[$cleaned])) {
            //     Yii::$app->db->createCommand()->insert('expertise', [
            //         'name' => $cleaned,
            //         'category_id' => null,
            //         'sub_category_id' => null,
            //     ])->execute();
            //     $newId = Yii::$app->db->getLastInsertID();
            //     $expertiseList[$cleaned] = $newId;
            //     echo "\nתחום מומחיות חדש נוסף: $cleaned";
            // }

            // $expertiseId = $expertiseList[$cleaned];
            // $exists = (new Query())->from('professional_expertise')->where([
            //     'professional_id' => $professional->id,
            //     'expertise_id' => $expertiseId,
            // ])->exists();
            // if (!$exists) {
            //     Yii::$app->db->createCommand()->insert('professional_expertise', [
            //         'professional_id' => $professional->id,
            //         'expertise_id' => $expertiseId,
            //     ])->execute();
            // }
            
        }

        $companyName = 'לאומית משלים';
        $ins = (new Query())
            ->select('id')
            ->from('company')
            ->where(['name' => $companyName])
            ->one();

        if (!$ins) {
            Yii::$app->db->createCommand()
                ->insert('company', ['name' => $companyName])
                ->execute();
            $companyId = Yii::$app->db->getLastInsertID();
        } else {
            $companyId = $ins['id'];
        }

        $exists = (new Query())
            ->from('professional_company')
            ->where([
                'professional_id' => $professional->id,
                'company_id' => $companyId,
            ])->exists();

        if (!$exists) {
            Yii::$app->db->createCommand()->insert('professional_company', [
                'professional_id' => $professional->id,
                'company_id' => $companyId,
            ])->execute();
        }

        // עדכון כתובת
        $street = trim($row['address'] ?? '');

        $address = ProfessionalAddress::findOne([
            'professional_id' => $professional->id,
            'city' => '',
            'street' => $street,
            'house_number' => '',
        ]);

       $phones = [];
        $phoneNumbers = $row['phone_numbers'] ?? '';
        $otherPhones = explode('|', $phoneNumbers); // שינוי מ-| ל-,
        foreach ($otherPhones as $p) {
            $cleaned = self::cleanPhone($p);
            if ($cleaned) $phones[] = $cleaned;
        }
        $phones = array_values(array_unique($phones));

        if (!$address) {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => '',
                'street' => $street,
                'house_number' => '',
                'type' => 'לאומית משלים',
            ]);
            $existingPhones = [];
        } else {
            $existingPhones = [];
            foreach (['phone', 'phone_2', 'phone_3', 'phone_4'] as $field) {
                $val = trim($address->$field ?? '');
                if ($val !== '') {
                    $existingPhones[] = $val;
                }
            }
        }

        $mergedPhones = array_values(array_unique(array_merge($existingPhones, $phones)));
        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];
        foreach ($phoneFields as $index => $field) {
            $address->$field = $mergedPhones[$index] ?? null;
        }

        $address->save();


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

        // עדכון שפות
        $langs = explode('|', $row['languages'] ?? ''); // שינוי מ-| ל-,
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
    private function getcompanyIdByName($companyName)
    {
        // חיפוש אם חברת הביטוח כבר קיימת
        $ins = (new Query())
            ->select('id')
            ->from('company')
            ->where(['name' => $companyName])
            ->one();

        // אם לא נמצאה, נוסיף אותה
        if (!$ins) {
            Yii::$app->db->createCommand()
                ->insert('company', ['name' => $companyName])
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

}