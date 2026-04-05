<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class IchilovwellnessController extends Controller {

    public function actionImportIchilovwellness()
    {
        echo "\nהתחלת הפונקציה...";

        $careList = ArrayHelper::map(
            (new Query())->select(['id', 'name'])->from('care')->all(),
            'name',
            'id'
        );
        echo "\nנטענו " . count($careList) . " התמחויות טיפול";

        $rows = (new Query())->from('_ichilov_wellness')->each(200);
        echo "\n התחיל עיבוד נתונים...";
        
        $counter = 0;
        $nameMatchedProfessionals = [];
        $updatedCount = 0;
        $createdCount = 0;
        
        foreach ($rows as $row) {
            $counter++;
            echo "\nמעבד שורה מספר: {$counter}";
            
            $fullName = trim($row['name'] ?? '');
            $firstName = trim($row['name'] ?? '');
            $specialistCert = trim($row['category'] ?? '');
            $about = trim($row['about'] ?? '');
            $phoneNumbers = trim($row['phone'] ?? '');
            $address = trim($row['address'] ?? '');
            $city = trim($row['city'] ?? '');
            $unionsId = $this->getUnionsIdByName('איכילוב וולנס');

            // בדיקה אם כבר קיים רופא עם אותו שם מאיכילוב וולנס
            $existingProfessional = $this->findExistingProfessional($fullName, $unionsId);
            
            if ($existingProfessional) {
                // עדכון רופא קיים
                $professional = $existingProfessional;
                $professional->about = $about; // עדכון התיאור
                
                if ($professional->save()) {
                    $updatedCount++;
                    echo "\nעודכן רופא קיים: {$fullName}";
                } else {
                    echo "\nשגיאה בעדכון רופא קיים";
                    print_r($professional->getErrors());
                    continue;
                }
            } else {
                // יצירת רופא חדש
                $professional = new Professional();
                $professional->full_name = $fullName;
                $professional->first_name = $firstName;
                $professional->about = $about;
                $professional->license_id = 'nm';
                
                if ($professional->save()) {
                    $createdCount++;
                    echo "\nנוצר רופא חדש: {$fullName}";
                } else {
                    echo "\nשגיאה ביצירת רופא חדש";
                    print_r($professional->getErrors());
                    continue;
                }
            }

            $nameMatchedProfessionals[] = $professional;

            // נוסיף את המשתנים החסרים
            $categories = [];
            $languagesList = [];
            $specialization = '';
            
            $this->updateProfessionalDetails($professional, $row, $categories, $careList, $languagesList, $specialization, $specialistCert);
        }

        echo "\n\n❖❖❖ ייבוא הסתיים בהצלחה. עובדו {$counter} שורות. ❖❖❖";
        echo "\n❖❖❖ נוצרו {$createdCount} רופאים חדשים, עודכנו {$updatedCount} רופאים קיימים. ❖❖❖\n";
    }

    /**
     * מחפש רופא קיים לפי שם ושייכות לאיכילוב וולנס
     */
    private function findExistingProfessional($fullName, $unionsId)
    {
        // חיפוש רופא עם אותו שם שמשויך לאיכילוב וולנס
        $professionalId = (new Query())
            ->select('p.id')
            ->from('professional p')
            ->innerJoin('professional_unions pu', 'p.id = pu.professional_id')
            ->where([
                'p.full_name' => $fullName,
                'pu.unions_id' => $unionsId
            ])
            ->scalar();

        if ($professionalId) {
            return Professional::findOne($professionalId);
        }

        return null;
    }

    private function normalize($name)
    {
        $name = mb_strtolower(trim($name)); 
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name); 
        return $name;
    }

    private function updateProfessionalDetails($professional, $row, $categories, &$careList, &$languagesList, $specialization, $specialistCert)
    {
        // עדכון התמחויות טיפול - הן מ-Specialization והן מ-SpecialistCert
        $specializations = [];

        // נוסיף גם את הספציאליזציה הרגילה
        if (!empty($specialization)) {
            $specializations[] = $specialization;
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

        // עיבוד כל ההתמחויות טיפול
        foreach ($specializations as $spec) {
            $cleaned = trim($spec);
            if ($cleaned === '') continue;

            // תמיד יטופל כהתמחות טיפול (גם אם קיים בקטגוריה)
            if (!isset($careList[$cleaned])) {
                Yii::$app->db->createCommand()->insert('care', [
                    'name' => $cleaned,
                ])->execute();
                $newId = Yii::$app->db->getLastInsertID();
                $careList[$cleaned] = $newId;
                echo "\nהתמחות טיפול חדשה נוספה: $cleaned";
            }

            $careId = $careList[$cleaned];
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

        $unionsName = 'איכילוב וולנס';
        $ins = (new Query())
            ->select('id')
            ->from('unions')
            ->where(['name' => $unionsName])
            ->one();

        if (!$ins) {
            Yii::$app->db->createCommand()
                ->insert('unions', ['name' => $unionsName])
                ->execute();
            $unionsId = Yii::$app->db->getLastInsertID();
        } else {
            $unionsId = $ins['id'];
        }

        $exists = (new Query())
            ->from('professional_unions')
            ->where([
                'professional_id' => $professional->id,
                'unions_id' => $unionsId,
            ])->exists();

        if (!$exists) {
            Yii::$app->db->createCommand()->insert('professional_unions', [
                'professional_id' => $professional->id,
                'unions_id' => $unionsId,
            ])->execute();
        }

        // עדכון כתובת
        $city = trim($row['city'] ?? '');
        $street = trim($row['address'] ?? '');
        
        // נתקן את המשתנה החסר numberRaw
        $numberRaw = ''; // צריך לקבל מהנתונים או לחלץ מהכתובת
        $number = is_numeric($numberRaw) ? (int)$numberRaw : null;

        $address = ProfessionalAddress::findOne([
            'professional_id' => $professional->id,
            'city' => $city,
            'street' => $street,
        ]);

        $phones = [];
        $phoneNumbers = $row['phone'] ?? '';
        $otherPhones = explode('|', $phoneNumbers);
        foreach ($otherPhones as $p) {
            $cleaned = self::cleanPhone($p);
            if ($cleaned) $phones[] = $cleaned;
        }
        $phones = array_values(array_unique($phones));

        if (!$address) {
            $address = new ProfessionalAddress([
                'professional_id' => $professional->id,
                'city' => $city,
                'street' => $street,
                'house_number' => $number,
                'type' => 'איכילוב וולנס',
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

        if (!$address->save()) {
            echo "\nשגיאה בשמירת כתובת";
            print_r($address->getErrors());
        }
    }

    private function getUnionsIdByName($unionsName)
    {
        // חיפוש אם האיגוד כבר קיים
        $ins = (new Query())
            ->select('id')
            ->from('unions')
            ->where(['name' => $unionsName])
            ->one();

        // אם לא נמצא, נוסיף אותו
        if (!$ins) {
            Yii::$app->db->createCommand()
                ->insert('unions', ['name' => $unionsName])
                ->execute();
            return Yii::$app->db->getLastInsertID(); // נקבל את ה-ID החדש שנוסף
        }

        return $ins['id']; // אם כבר קיים, נחזיר את ה-ID שלו
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

        // אם מספר הטלפון לא מתחיל ב-0, להוסיף 0
        if (substr($phone, 0, 1) !== '0') {
            $phone = '0' . $phone;
        }

        return preg_replace('/[^0-9*]/', '', $phone);
    }

}