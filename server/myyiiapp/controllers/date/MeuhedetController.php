<?php

namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use yii\db\Command;
use yii\db\Expression;
use Yii;
use RuntimeException;

class MeuhedetController extends Controller
{
    /** קאש של אינדקס ה־CSV (נבנה פעם אחת לכל קובץ) */
    private $expertiseIndex = [];      // specialization(norm) => ['expertise_ids'=>..., 'is_children'=>...]
    private $mainIndex = [];           // specialistcert(norm) => ['main_ids'=>..., 'ischildren'=>...]
    private $expertiseCsvPath = null;
    private $mainCsvPath = null;

    public function actionImportMeuhedet()
    {
        $categories = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('category')->all(),
            'name',
            'id'
        );

        $expertiseList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('expertise')->all(),
            'name',
            'id'
        );

        $languagesList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('speaking_language')->all(),
            'name',
            'id'
        );

        $rows = (new Query())->from('meuhedet_')->each(200);
        echo "\nהתחיל...";
        $countUpdate = 0;
        $countNew = 0;

        // ממיר ערכים טקסטואליים/מספריים לבוליאני 0/1
        $toBool = function ($v) {
            $s = strtolower(trim((string)$v));
            return $s === '1' || $s === 'true' || $s === 'yes';
        };

        foreach ($rows as $row) {
            $excludeWords = [
                'רופא מצוות',
                'צוות המרפאה',
                'אחות אחראית',
                'צוות',
                'מנהל',
                'אחות',
                'עובדת סוציאלית',
                'מרפאת',
                'מרפאה',
            ];

            // שדות מטבלת Meuhedet
            $fullName            = trim($row['Name'] ?? '');
            $specialization      = trim($row['Specialization'] ?? '');
            $specialistCert      = trim($row['SpecialistCert'] ?? '');
            $licenseFromMeuhedet = trim($row['LicenseNumber'] ?? '');
            $title               = trim($row['Title'] ?? '');
            $gender              = trim($row['GenderFacet'] ?? '');
            $languages           = trim($row['Languages'] ?? '');
            $phoneNumbers        = trim($row['PhoneNumbers'] ?? '');
            $address             = trim($row['Address'] ?? '');
            $city                = trim($row['City'] ?? '');
            $houseNumber         = trim($row['HouseNumber'] ?? '');

            // בדיקת מילות החרגה בשם מלא
            foreach ($excludeWords as $word) {
                if ($fullName !== '' && mb_stripos($fullName, $word) !== false) {
                    continue 2;
                }
            }

            // === נרמול התמחויות מ־CSV ===
            $expRes = $this->getExpertiseFromCsv('meuhedet_expertise_goodone - Sheet1.csv', $specialization ?? '');
            $expertiseIdsFromFile = $expRes['expertise_ids'] ?? '';
            $expertiseIsChildren  = $expRes['is_children'] ?? 0;

            $mainRes = $this->getMainFromCsv('meuhedet_main_expertise_goodone - Sheet1.csv', $specialistCert ?? '');
            $mainIdsFromFile = $mainRes['main_ids'] ?? '';
            $mainIsChildren  = $mainRes['ischildren'] ?? 0;

            // מחליפים את המחרוזות המקוריות למחרוזות IDs לשימוש בהמשך
            $specialization = $expertiseIdsFromFile;
            $specialistCert = $mainIdsFromFile;

            if (!empty($licenseFromMeuhedet)) {
                $foundProfessional = Professional::find()
                    ->innerJoin('professional_company', 'professional_company.professional_id = professional.id')
                    ->where(['professional.license_id' => $licenseFromMeuhedet])
                    ->andWhere(['professional_company.company_id' => 1]) // מאוחדת
                    ->one();

                if ($foundProfessional) {
                    // אם נמצא רופא תואם, מעדכנים אותו ישירות
                    $foundProfessional->full_name  = $fullName;
                    $foundProfessional->first_name = $fullName;
                    $foundProfessional->title      = $title;

                    // המרה לבוליאני בצורה אחידה
                    if ($toBool($mainIsChildren) || $toBool($expertiseIsChildren)) {
                        $foundProfessional->is_pediatric = true;
                    }

                    // עדכון מגדר
                    if ($gender !== '') {
                        if ($gender === 'זכר') {
                            $foundProfessional->gender = 1;
                        } elseif ($gender === 'נקבה') {
                            $foundProfessional->gender = 2;
                        } else {
                            $foundProfessional->gender = null;
                        }
                    }

                    if (!$foundProfessional->save()) {
                        echo "\nשגיאה בעדכון רופא קיים";
                        print_r($foundProfessional->getErrors());
                    } else {
                        $countUpdate++;
                        echo "\nרופא עודכן בהצלחה";
                        // לעדכן גם קשרים/כתובות/שפות
                        $this->updateProfessionalDetails(
                            $foundProfessional,
                            $row,
                            $categories,
                            $expertiseList,
                            $languagesList,
                            $specialization,   // CSV של expertise_ids
                            $specialistCert    // CSV של main_ids
                        );
                    }
                } else {
                    // אם לא נמצא רופא תואם, מחפשים את כל הרופאים עם ההתאמות הבסיסיות
                    $foundProfessionals = Professional::find()
                        ->where(['license_id' => $licenseFromMeuhedet])
                        ->orWhere(['license_id_v1' => $licenseFromMeuhedet])
                        ->orWhere(['license_id_v2' => $licenseFromMeuhedet])
                        ->all();

                    if (!empty($foundProfessionals)) {
                        $nameMatchedProfessionals = [];
                        foreach ($foundProfessionals as $foundProf) {
                            if ($this->isNameMatchByFullName($foundProf, $fullName)) {
                                $nameMatchedProfessionals[] = $foundProf;
                            }
                        }

                        if (!empty($nameMatchedProfessionals)) {
                            foreach ($nameMatchedProfessionals as $professional) {
                                $professional->full_name  = $fullName;
                                $professional->first_name = $fullName;
                                $professional->title      = $title;

                                if ($toBool($mainIsChildren) || $toBool($expertiseIsChildren)) {
                                    $professional->is_pediatric = true;
                                }

                                if ($professional->license_id !== $licenseFromMeuhedet) {
                                    if (
                                        $professional->license_id_v1 === $licenseFromMeuhedet ||
                                        $professional->license_id_v2 === $licenseFromMeuhedet
                                    ) {
                                        $professional->license_id_v0 = $licenseFromMeuhedet;
                                    }
                                }

                                if ($gender !== '') {
                                    if ($gender === 'זכר') {
                                        $professional->gender = 1;
                                    } elseif ($gender === 'נקבה') {
                                        $professional->gender = 2;
                                    } else {
                                        $professional->gender = null;
                                    }
                                }

                                if (!$professional->save()) {
                                    echo "\nשגיאה בעדכון רופא קיים";
                                    print_r($professional->getErrors());
                                } else {
                                    $countUpdate++;
                                }

                                $this->updateProfessionalDetails(
                                    $professional,
                                    $row,
                                    $categories,
                                    $expertiseList,
                                    $languagesList,
                                    $specialization, // CSV expertise_ids
                                    $specialistCert  // CSV main_ids
                                );
                            }
                        } else {
                            // יצירת מקצוען חדש (יש רופאים עם אותו רישיון, אבל בלי התאמת שם)
                            $professional = new Professional();
                            $professional->full_name  = $fullName;
                            $professional->first_name = $fullName;
                            $professional->title      = $title;

                            if ($toBool($mainIsChildren) || $toBool($expertiseIsChildren)) {
                                $professional->is_pediatric = true;
                            }

                            if ($gender !== '') {
                                if ($gender === 'זכר') {
                                    $professional->gender = 1;
                                } elseif ($gender === 'נקבה') {
                                    $professional->gender = 2;
                                } else {
                                    $professional->gender = null;
                                }
                            }

                            // לקבוע license_id ולהפיק גרסאות
                            $professional->license_id = $licenseFromMeuhedet;

                            $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromMeuhedet);
                            $licenseNoDash  = str_replace('-', '', $licenseNumeric);
                            $licenseParts   = explode('-', $licenseNumeric);
                            if (count($licenseParts) === 2) {
                                $prefix         = preg_replace('/\D/', '', $licenseParts[0]);
                                $suffix         = $licenseParts[1];
                                $license_id_v1  = $prefix . $suffix;
                                $license_id_v2  = $suffix;
                            } else {
                                $license_id_v1  = $licenseNoDash;
                                $license_id_v2  = $licenseNoDash;
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
                            } else {
                                $countNew++;
                            }

                            $this->updateProfessionalDetails(
                                $professional,
                                $row,
                                $categories,
                                $expertiseList,
                                $languagesList,
                                $specialization, // CSV expertise_ids
                                $specialistCert  // CSV main_ids
                            );
                        }
                    } else {
                        echo "\nיוצר רופא חדש - לא נמצאו כלל תואמים";
                        $professional = new Professional();
                        $professional->full_name  = $fullName;
                        $professional->first_name = $fullName;
                        $professional->title      = $title;

                        if ($toBool($mainIsChildren) || $toBool($expertiseIsChildren)) {
                            $professional->is_pediatric = true;
                        }

                        if ($gender !== '') {
                            if ($gender === 'זכר') {
                                $professional->gender = 1;
                            } elseif ($gender === 'נקבה') {
                                $professional->gender = 2;
                            } else {
                                $professional->gender = null;
                            }
                        }

                        // שימוש נכון במשתנה + לקבוע license_id
                        $professional->license_id = $licenseFromMeuhedet;

                        // חישוב v1 ו-v2
                        $licenseNumeric = preg_replace('/[^0-9\-]/', '', $licenseFromMeuhedet);
                        $licenseNoDash  = str_replace('-', '', $licenseNumeric);
                        $parts          = explode('-', $licenseNumeric);
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
                        } else {
                            $countNew++;
                        }

                        // ואז לעדכן את הפרטים
                        $this->updateProfessionalDetails(
                            $professional,
                            $row,
                            $categories,
                            $expertiseList,
                            $languagesList,
                            $specialization, // CSV expertise_ids
                            $specialistCert  // CSV main_ids
                        );
                    }
                }
            } else {
                // רישיון ריק
                $nameMatches = $this->findProfessionalsByFullName($fullName);

                $professional = new Professional();
                $professional->full_name  = $fullName;
                $professional->first_name = $fullName;
                $professional->title      = $title;

                if ($toBool($mainIsChildren) || $toBool($expertiseIsChildren)) {
                    $professional->is_pediatric = true;
                }

                if ($gender !== '') {
                    if ($gender === 'זכר') {
                        $professional->gender = 1;
                    } elseif ($gender === 'נקבה') {
                        $professional->gender = 2;
                    } else {
                        $professional->gender = null;
                    }
                }

                if (!empty($nameMatches)) {
                    $newIds = array_map(fn($m) => $m->id, $nameMatches);
                    $existingIdsRaw = $professional->same_license_different_name ?? '';
                    $existingIds = array_filter(array_map('trim', explode(',', $existingIdsRaw)));
                    $allIds = array_unique(array_merge($existingIds, $newIds));
                    $professional->same_license_different_name = implode(',', $allIds);
                }

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת רופא חדש ללא רישיון";
                    print_r($professional->getErrors());
                    continue;
                } else {
                    $countNew++;
                }

                $this->updateProfessionalDetails(
                    $professional,
                    $row,
                    $categories,
                    $expertiseList,
                    $languagesList,
                    $specialization, // CSV expertise_ids
                    $specialistCert  // CSV main_ids
                );
            }
        }

        echo "נוצרו רופאים חדשים:  $countNew \n";
        echo "עודכנו רופאים :  $countUpdate \n";
        echo "\n\n ייבוא הסתיים בהצלחה.\n";
    }

    public function actionUpdateData()
    {
        $data = (new Query())
            ->select('*')
            ->from('professional')
            ->where(['not', ['full_name' => null]])
            ->andWhere(['first_name' => new \yii\db\Expression('full_name')])
            ->andWhere(['not', ['last_name' => '']])
            ->andWhere(['not', ['last_name' => null]])
            ->all();

        foreach ($data as $row) {
            $updated_first_name = str_replace($row['last_name'], '', $row['first_name']);
            $updated_first_name = trim($updated_first_name);
            $first_name_parts = explode(' ', $updated_first_name);
            $updated_first_name = array_pop($first_name_parts);

            Yii::$app->db->createCommand()
                ->update('professional', ['first_name' => $updated_first_name], ['id' => $row['id']])
                ->execute();
        }

        echo "העדכון הושלם.\n";
    }

    private function normalize($name)
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name);
        return $name;
    }

    private function isNameMatchByFullName($professional, string $meuhedetFullName): bool
    {
        $profFull = trim($professional->full_name ?: (trim(($professional->first_name ?? '') . ' ' . ($professional->last_name ?? ''))));
        $meuFull  = trim($meuhedetFullName);

        $professionalArray        = array_map([$this, 'normalize'], preg_split('/\s+/', $profFull, -1, PREG_SPLIT_NO_EMPTY));
        $matchedProfessionalArray = array_map([$this, 'normalize'], preg_split('/\s+/', $meuFull, -1, PREG_SPLIT_NO_EMPTY));

        $matches = 0;
        foreach ($professionalArray as $part) {
            if (in_array($part, $matchedProfessionalArray, true)) {
                $matches++;
            }
        }
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

    function parseIds($str)
    {
        $str = trim((string)$str);
        if ($str === '' || preg_match('/^(null|nan)$/i', $str)) {
            return [];
        }
        $parts = preg_split('/[,\|]+/', $str);
        $set = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (!ctype_digit($p)) {
                $p = preg_replace('/\D+/', '', $p);
            }
            $val = (int)$p;
            if ($val > 0) $set[$val] = true;
        }
        return array_keys($set);
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$expertiseList, &$languagesList, $specialization, $specialistCert)
    {
        $mainSpecializationCleaned = trim((string)$specialistCert);   // CSV main_ids
        $specializationCleaned     = trim((string)$specialization);   // CSV expertise_ids

        echo "בדוק אם ריק: $specializationCleaned \n";

        $mainIds      = $this->parseIds($mainSpecializationCleaned);
        $expertiseIds = $this->parseIds($specializationCleaned);

        if (empty($mainIds) && empty($expertiseIds)) {
            echo "\nאין נתוני התמחות לעדכון";
            return;
        }

        // אימות מזהים שמורים
        $validMainIds = [];
        foreach ($mainIds as $mid) {
            $exists = (new \yii\db\Query())->from('main_specialization')->where(['id' => $mid])->exists();
            if ($exists) $validMainIds[] = $mid;
            else echo "\nאזהרה: main_specialization_id $mid לא קיים — דילוג.";
        }

        $validExpertiseIds = [];
        foreach ($expertiseIds as $eid) {
            $exists = (new \yii\db\Query())->from('expertise')->where(['id' => $eid])->exists();
            if ($exists) $validExpertiseIds[] = $eid;
            else echo "\nאזהרה: expertise_id $eid לא קיים — דילוג.";
        }

        // professional_main_specialization
        foreach ($validMainIds as $mid) {
            $exists = (new \yii\db\Query())->from('professional_main_specialization')->where([
                'professional_id'        => $professional->id,
                'main_specialization_id' => $mid,
            ])->exists();
            if (!$exists) {
                \Yii::$app->db->createCommand()->insert('professional_main_specialization', [
                    'professional_id'        => $professional->id,
                    'main_specialization_id' => $mid,
                ])->execute();
                echo "\nקושר רופא להתמחות ראשית (ID: $mid)";
            }
        }

        // professional_expertise
        foreach ($validExpertiseIds as $eid) {
            $exists = (new \yii\db\Query())->from('professional_expertise')->where([
                'professional_id' => $professional->id,
                'expertise_id'    => $eid,
            ])->exists();
            if (!$exists) {
                \Yii::$app->db->createCommand()->insert('professional_expertise', [
                    'professional_id' => $professional->id,
                    'expertise_id'    => $eid,
                ])->execute();
                echo "\nקושר רופא לתת-התמחות (ID: $eid)";
            }
        }

        // קישור בין טבלאות ראשית/משנית (אם חסר)
        foreach ($validMainIds as $mid) {
            foreach ($validExpertiseIds as $eid) {
                $exists = (new \yii\db\Query())->from('main_specialization_expertise')->where([
                    'main_specialization_id' => $mid,
                    'expertise_id'           => $eid,
                ])->exists();

                if (!$exists) {
                    \Yii::$app->db->createCommand()->insert('main_specialization_expertise', [
                        'main_specialization_id' => $mid,
                        'expertise_id'           => $eid,
                    ])->execute();
                    echo "\nקושר התמחות ראשית (ID: $mid) לתת-התמחות (ID: $eid)";
                } else {
                    echo "\nקישור כבר קיים בין התמחות ראשית (ID: $mid) לתת-התמחות (ID: $eid)";
                }
            }
        }

        // עדכון חברות - מאוחדת
        $companyName = 'מאוחדת';
        $company = (new Query())->select(['id'])->from('company')->where(['name' => $companyName])->one();
        if (!$company) {
            Yii::$app->db->createCommand()->insert('company', ['name' => $companyName])->execute();
            $companyId = Yii::$app->db->getLastInsertID();
        } else {
            $companyId = $company['id'];
        }

        $companyExists = (new Query())->from('professional_company')->where([
            'professional_id' => $professional->id,
            'company_id'      => $companyId,
        ])->exists();

        if (!$companyExists) {
            Yii::$app->db->createCommand()->insert('professional_company', [
                'professional_id' => $professional->id,
                'company_id'      => $companyId,
            ])->execute();
        }

        // עדכון כתובת
        $city      = trim($row['City'] ?? '');
        $street    = trim($row['Address'] ?? '');
        $numberRaw = trim($row['HouseNumber'] ?? '');
        $number    = is_numeric($numberRaw) ? (int)$numberRaw : null;

        $address = ProfessionalAddress::findOne([
            'professional_id' => $professional->id,
            'city'            => $city,
            'street'          => $street,
            'house_number'    => $number,
        ]);

        $phones = [];
        $phoneNumbers = $row['PhoneNumbers'] ?? '';
        $otherPhones = explode('|', $phoneNumbers); // המפריד נשאר |
        foreach ($otherPhones as $p) {
            $cleaned = self::cleanPhone($p);
            if ($cleaned) $phones[] = $cleaned;
        }
        $phones = array_values(array_unique($phones));

        if (!$address) {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city'            => $city,
                'street'          => $street,
                'house_number'    => $number,
            ]);
            $existingPhones = [];
        } else {
            $existingPhones = [];
            foreach (['phone', 'phone_2', 'phone_3', 'phone_4'] as $field) {
                $val = trim($address->$field ?? '');
                if ($val !== '') $existingPhones[] = $val;
            }
        }

        $mergedPhones = array_values(array_unique(array_merge($existingPhones, $phones)));
        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];
        foreach ($phoneFields as $index => $field) {
            $address->$field = $mergedPhones[$index] ?? null;
        }

        $address->type = $companyName;
        $address->save();

        // עדכון שפות
        $langs = explode('|', $row['Languages'] ?? '');
        foreach ($langs as $lang) {
            $lang = trim($lang);
            if ($lang === '') continue;

            if (!isset($languagesList[$lang])) {
                Yii::$app->db->createCommand()->insert('speaking_language', [
                    'name' => $lang,
                ])->execute();
                $newId = Yii::$app->db->getLastInsertID();
                $languagesList[$lang] = $newId;
                echo "\n✅ שפה חדשה נוספה: {$lang}";
            }

            $langId = $languagesList[$lang];
            $exists = (new Query())->from('professional_language')->where([
                'professional_id' => $professional->id,
                'language_id'     => $langId,
            ])->exists();

            if (!$exists) {
                Yii::$app->db->createCommand()->insert('professional_language', [
                    'professional_id' => $professional->id,
                    'language_id'     => $langId,
                ])->execute();
            }
        }
    }

    public static function cleanPhone($phone)
    {
        if (!$phone || preg_match('/[a-zA-Z@]/', $phone)) {
            return null;
        }
        $phone = explode('/', $phone)[0];
        return preg_replace('/[^0-9*]/', '', $phone);
    }

    /*** ---- החלפה מלאה: טעינת CSV והפקת אינדקסים ---- ***/

    private function getExpertiseFromCsv(string $csvPath, string $specialization): array
    {
        // טען פעם ראשונה או אם הוחלף נתיב
        if ($this->expertiseCsvPath !== $csvPath || empty($this->expertiseIndex)) {
            $this->expertiseIndex = $this->loadExpertiseCsvIndex($csvPath);
            $this->expertiseCsvPath = $csvPath;
        }

        if (empty($specialization)) {
            return ['expertise_ids' => '', 'is_children' => 0];
        }

        $terms = preg_split('/[,\;\|]/u', (string)$specialization, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_map('trim', $terms);

        $allIds = [];
        $isChildAny = 0;

        foreach ($terms as $term) {
            $norm = $this->normalizeText($term);
            if (!isset($this->expertiseIndex[$norm])) continue;

            $expIds = preg_split('/\s*,\s*/', (string)$this->expertiseIndex[$norm]['expertise_ids'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($expIds as $id) if ($id !== '') $allIds[] = $id;

            $childRaw = strtolower(trim((string)$this->expertiseIndex[$norm]['is_children']));
            if (in_array($childRaw, ['1','true','yes'], true)) $isChildAny = 1;
        }

        $allIds = array_values(array_unique($allIds));
        return [
            'expertise_ids' => implode(',', $allIds),
            'is_children'   => (int)$isChildAny,
        ];
    }

    private function getMainFromCsv(string $csvPath, string $specialistCert): array
    {
        if ($this->mainCsvPath !== $csvPath || empty($this->mainIndex)) {
            $this->mainIndex = $this->loadMainCsvIndex($csvPath);
            $this->mainCsvPath = $csvPath;
        }

        if (empty($specialistCert)) {
            return ['main_ids' => '', 'ischildren' => 0];
        }

        $terms = preg_split('/[,\;\|]/u', (string)$specialistCert, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_map('trim', $terms);

        $allIds = [];
        $isChildAny = 0;

        foreach ($terms as $term) {
            $norm = $this->normalizeText($term);
            if (!isset($this->mainIndex[$norm])) continue;

            $ids = preg_split('/\s*,\s*/', (string)$this->mainIndex[$norm]['main_ids'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($ids as $id) if ($id !== '') $allIds[] = $id;

            $childRaw = strtolower(trim((string)$this->mainIndex[$norm]['ischildren']));
            if (in_array($childRaw, ['1','true','yes'], true)) $isChildAny = 1;
        }

        $allIds = array_values(array_unique($allIds));
        return [
            'main_ids'   => implode(',', $allIds),
            'ischildren' => (int)$isChildAny,
        ];
    }

    /** טוען CSV של התמחויות משנה -> אינדקס */
    private function loadExpertiseCsvIndex(string $csvPath): array
    {
        $h = $this->openCsv($csvPath, $delimiter);
        $header = fgetcsv($h, 0, $delimiter);
        if ($header === false) {
            fclose($h);
            throw new RuntimeException("CSV is empty: {$csvPath}");
        }

        $norm = function ($s) { return strtolower(trim(preg_replace('/\s+/', '', (string)$s))); };
        $idx = [];
        foreach ($header as $i => $hname) {
            $idx[$norm($hname)] = $i;
        }

        foreach (['specialization','expertise_ids','is_children'] as $req) {
            if (!isset($idx[$req])) {
                fclose($h);
                throw new RuntimeException("Missing required column '{$req}' in CSV header: {$csvPath}");
            }
        }

        $map = [];
        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            $specVal = trim((string)($row[$idx['specialization']] ?? ''));
            if ($specVal === '') continue;
            $key = $this->normalizeText($specVal);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'expertise_ids' => (string)($row[$idx['expertise_ids']] ?? ''),
                    'is_children'   => (string)($row[$idx['is_children']] ?? '0'),
                ];
            }
        }
        fclose($h);
        return $map;
    }

    /** טוען CSV של התמחויות ראשיות -> אינדקס */
    private function loadMainCsvIndex(string $csvPath): array
    {
        $h = $this->openCsv($csvPath, $delimiter);
        $header = fgetcsv($h, 0, $delimiter);
        if ($header === false) {
            fclose($h);
            throw new RuntimeException("CSV is empty: {$csvPath}");
        }

        $norm = function ($s) { return strtolower(trim(preg_replace('/\s+/', '', (string)$s))); };
        $idx = [];
        foreach ($header as $i => $hname) {
            $idx[$norm($hname)] = $i;
        }

        foreach (['specialistcert','main_ids','ischildren'] as $req) {
            if (!isset($idx[$req])) {
                fclose($h);
                throw new RuntimeException("Missing required column '{$req}' in CSV header: {$csvPath}");
            }
        }

        $map = [];
        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            $specVal = trim((string)($row[$idx['specialistcert']] ?? ''));
            if ($specVal === '') continue;
            $key = $this->normalizeText($specVal);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'main_ids'   => (string)($row[$idx['main_ids']] ?? ''),
                    'ischildren' => (string)($row[$idx['ischildren']] ?? '0'),
                ];
            }
        }
        fclose($h);
        return $map;
    }

    /**
     * פותח קובץ CSV עם זיהוי אוטומטי של מפריד (',' או ';').
     * מחזיר resource פתוח + מציב את $delimiter בהתאמה ע"י reference.
     */
    private function openCsv(string $csvPath, ?string &$delimiter)
    {
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            throw new RuntimeException("CSV file not found or unreadable: {$csvPath}");
        }
        $h = fopen($csvPath, 'r');
        if ($h === false) {
            throw new RuntimeException("Failed to open CSV: {$csvPath}");
        }

        // זיהוי מפריד על בסיס שורת כותרת
        $delimiter = ',';
        $firstLine = fgets($h);
        if ($firstLine !== false) {
            $comma = substr_count($firstLine, ',');
            $semi  = substr_count($firstLine, ';');
            if ($semi > $comma) {
                $delimiter = ';';
            }
        }
        rewind($h);
        return $h;
    }

    /** נרמול טקסט להשוואה מדויקת יותר */
    private function normalizeText(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = str_replace(["\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "־"], '-', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}
