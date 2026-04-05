<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class ThunderbitMohalimController extends Controller {

    public function actionImportThunderbitMohalim()
    {
        echo "\nהתחלת הפונקציה...";

        $careData = (new Query())->select(['id', 'name'])->from('care')->all();

        $careList = [];

        foreach ($careData as $care) {
            $careList[$care['name']] = $care['id'];
        }

        $mainCareData = (new Query())->select(['id', 'name'])->from('main_care')->all();

        $mainCareList = [];

        foreach ($mainCareData as $mainCare) {
            $mainCareList[$mainCare['name']] = $mainCare['id'];
        }

        $rows = (new Query())->from('_thunderbit_mohalim')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        foreach ($rows as $row) {
            // if( $counter == 5) continue;
            $counter++;
            $street = trim($row['Address'] ?? '');
            $idNumber = trim($row['license'] ?? '');
            $fullName = trim($row['Title'] ?? '');
            $phone = trim($row['Phone number'] ?? '');
            $id = trim($row['id'] ?? '');
            $categories = 'מוהל';
            $hasDrTitle = false;
            $hasProfTitle = false;

            $fullNameParts = explode(' ', trim($fullName));

            for ($i = 0; $i < 2; $i++) {
                if (empty($fullNameParts[0])) {
                    break;
                }

                if (preg_match('/^ד(?:[״"\']|&quot;)?ר\.?$/u', $fullNameParts[0])) {
                    $hasDrTitle = true;
                    array_shift($fullNameParts);
                    continue;
                }

                if (preg_match('/^(?:פרופ(?:[״"\']|&quot;)?\.?|פרופסור)$/u', $fullNameParts[0])) {
                    $hasProfTitle = true;
                    array_shift($fullNameParts);
                    continue;
                }

                break;
            }

            if ($hasProfTitle) {
                $hasDrTitle = false;
            }

            $fullName = implode(' ', $fullNameParts);


            // if (empty($phoneNumbers)) {
            //     echo "\nדילוג על שורה - אין מספר טלפון";
            //     continue;
            // }

            $foundProfessional = null;

            if (!empty($id)) {
                $foundProfessional = $this->findProfessionalByID($id);

            } elseif (!empty($idNumber) && empty($foundProfessional)) {
                $foundProfessional = $this->findProfessionalByLicenseID($idNumber);

            } elseif (!empty($phone) && empty($foundProfessional)) {
                $foundProfessional = $this->findProfessionalByPhone($phone);
            } elseif(empty($foundProfessional)){
                $foundProfessional = $this->findProfessionalByName($fullName);
            }

                
            if ($foundProfessional) {
                echo "\nנמצא מקצוען קיים עם טלפון תואם";
                $foundProfessional->full_name = $fullName;
                $foundProfessional->first_name = $fullName;
                $foundProfessional->is_therapist = 1;
                $foundProfessional->gender = 1;

                if(empty($foundProfessional->phone) && !empty($phone)){
                    $foundProfessional->phone = $this->cleanPhone($phone);
                }
   
                if($hasProfTitle){
                    $foundProfessional->title = "פרופ'";
                }elseif ($hasDrTitle) {
                    $foundProfessional->title = 'ד"ר';
                }

                if (!$foundProfessional->save()) {
                    echo "\nשגיאה בעדכון מקצוען קיים";                        
                    print_r($foundProfessional->getErrors());
                } else {
                    echo "\nמקצוען עודכן בהצלחה";
                    $this->updateProfessionalDetails($foundProfessional, $row, $street, $phone, $categories,$careList ,$mainCareList);
                }
            
            } else {
                echo "\nיוצר מקצוען חדש";
                    
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $fullName;
                $professional->is_therapist = 1;
                $professional->gender = 1;
                if(!empty($idNumber)){
                    $professional->license_id = 'm-' . $idNumber;
                }
                
                if(empty($professional->phone) && !empty($phone)){
                    $professional->phone = $this->cleanPhone($phone);
                }

                if($hasProfTitle){
                    $professional->title = "פרופ'";
                }elseif ($hasDrTitle) {
                    $professional->title = 'ד"ר';
                }

                if (!$professional->save()) {
                    echo "\nשגיאה בשמירת מקצוען חדש";
                    print_r($professional->getErrors());
                    continue;
                }

                $this->updateProfessionalDetails($professional, $row, $street, $phone, $categories ,$careList ,$mainCareList);
            }
        }

        echo "\n\n ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. \n";
    }

    private function updateProfessionalDetails($professional, $row, $street, $phone, $categories ,&$careList ,&$mainCareList)
    {

        if (!empty($categories)) {
            $specializations = explode('|', $categories);
            foreach ($specializations as $spec) {
                $cleaned = trim($spec);
                if ($cleaned === '') continue;
                $careId = null;
                $mainCareId = null;
                    if (isset($careList[$cleaned])) {
                        $careId = $careList[$cleaned];
                    } else {
                        Yii::$app->db->createCommand()->insert('care', [
                            'name' => $cleaned,
                        ])->execute();
                        $careId = Yii::$app->db->getLastInsertID();
                        $careList[$cleaned] = $careId;
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


                    if (isset($mainCareList[$cleaned])) {
                        $mainCareId = $mainCareList[$cleaned];
                    }

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

                
            }
        }

        $unionsName = 'thunderbitMohalim';
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

        $this->updateProfessionalAddress($professional, $row, $street, $phone);

    }
    
    
    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalAddress($professional, $row, $street, $phones)
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


        if (empty($street) && empty($phones)) {
            echo "\n אין ערים וגם אין טלפונים - לא נוספו כתובות.";
            return;
        }

        $phoneFields = ['phone', 'phone_2', 'phone_3', 'phone_4'];

        if (!empty($street)) {
            $cities = preg_split('/\r?\n/', $street); 
            foreach ($cities as $cityName) {
                $cityName = trim($cityName);
                if (empty($cityName)) continue;

                $exists = ProfessionalAddress::find()
                    ->where([
                        'professional_id' => $professional->id,
                        'street' => $cityName,
                    ])
                    ->exists();

                if ($exists) {
                    echo "\nכתובת כבר קיימת – מדלג: {$cityName}\n";
                    continue;
                }

                $address = new ProfessionalAddress([
                    'professional_id' => $professional->id,
                    'street' => $cityName,
                    'type' => 'thunderbitMohalim',
                    'display_address' => 0,
                ]);

                foreach ($phoneFields as $index => $field) {
                    $address->$field = $phones[$index] ?? null;
                }

                if (!$address->save()) {
                    echo "\nשגיאה בשמירת כתובת לעיר\n";
                    print_r($address->getErrors());
                } else {
                    echo "\nכתובת נוספה לעיר\n";
                }
            }
        } else {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'street' => null,
                'type' => 'thunderbitMohalim',
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
        if (strpos($phone, '+39') === 0) {
            return null;
        }

        $phone = preg_replace('/^(?:\+?972)/', '0', $phone);

        $phone = explode('/', $phone)[0];

        return preg_replace('/[^0-9*]/', '', $phone);
    }

    private function findProfessionalByID($id){

        if(empty($id)){
            return null;
        }

        return Professional::findOne((int)$id);
    }

    private function findProfessionalByLicenseID($license)
    {
        if (empty($license)) {
            return null;
        }

        $professional = Professional::findOne(['license_id' => 'm-' . $license]);

        if ($professional !== null) {
            return $professional;
        }

        return Professional::findOne(['license_id' => $license]);

    }

    private function findProfessionalByName($fullName)
    {
        if (empty($fullName)) {
            return null;
        }

        $fullName = trim(preg_replace('/\s+/', ' ', $fullName));

        $parts = explode(' ', $fullName);

        $names = [$fullName];

        if (count($parts) > 1) {
            $reversed = implode(' ', array_reverse($parts));
            $names[] = $reversed;
        }

        $results = Professional::find()
            ->where(['full_name' => $names])
            ->limit(2)
            ->all();

        if (count($results) !== 1) {
            return null;
        }

        return $results[0];
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
            if ($cleaned) { 
                $cleanedPhones[] = $cleaned;
            }
        }

        if (empty($cleanedPhones)) {
            return null;
        }

        $conditions = ['or'];
        foreach ($cleanedPhones as $phone) {
            $conditions[] = ['phone' => $phone];
        }

        $professional = Professional::find()->where($conditions)->one();
        
        if ($professional) {
            echo "\nנמצא מקצוען לפי טלפון בטבלה הראשית: " . implode(', ', $cleanedPhones);
            return $professional;
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