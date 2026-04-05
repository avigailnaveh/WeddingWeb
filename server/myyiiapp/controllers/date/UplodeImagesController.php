<?php  
namespace app\commands;

use yii\console\Controller;
use yii\helpers\ArrayHelper;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query;
use Yii;

class UplodeImagesController extends Controller {

    public function actionImportUplodeImages()
    {
        $path = '@app/web/uploads/images';
        $localDirPath = Yii::getAlias($path);

        if (!is_dir($localDirPath)) {
            echo "\nהתיקייה לא קיימת: $localDirPath";
            return;
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $files = scandir($localDirPath);
        $count = 0;
        foreach ($files as $file) {
            // if($count == 4) continue;
            $count++;
            if ($file === '.' || $file === '..') {
                echo "\nמדלג על קובץ .\n";
                continue;
            }

            $filePath = $localDirPath . DIRECTORY_SEPARATOR . $file;

            if (!is_file($filePath)) {
                continue;
                echo "\nמדלג על קובץ אין לי מושג למה\n";
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExtensions)) {
                echo "\nמדלג על קובץ שאינו תמונה: $file\n";
                continue;
            }

            $fileNameOnly = pathinfo($file, PATHINFO_FILENAME);
            $ext = pathinfo($file, PATHINFO_EXTENSION);

            $parts = explode('_', $fileNameOnly, 2);

            $organization = $parts[0] ?? null;
            $phoneOrId    = $parts[1] ?? null;

            if($organization == 'tcmisrael'){
                continue;
                echo "\nמדלג על קובץ tcmisrael\n";
            }

            echo "\nעמותה: $organization \n";
            echo "\nטלפון/ID: $phoneOrId \n";
            
            $professional = Professional::find()
                ->where(['license_id' => $phoneOrId])
                ->one();

            if ($professional) {
                
                $professional->img_url = 'https://api.doctorita.co.il/uploads/images/' . $file;
                $professional->save();
            }else{
                $professional = $this->findProfessionalByPhone($phoneOrId);
                if((!empty($professional)) && count($professional) === 1) {
                    $newFileName = $organization . '_' . $professional[0]->id . '.' . $ext;
                    $newPath = Yii::getAlias('@app/web/uploads/images/' . $newFileName);
                    $localImagePath = Yii::getAlias('@app/web/uploads/images/' . $file);
                    rename($localImagePath, $newPath);
                    $professional[0]->img_url = 'https://api.doctorita.co.il/uploads/images/' . $newFileName;
                    $professional[0]->save();
                }elseif((!empty($professional)) && count($professional) > 1){
                    print_r($professional);
                }else{
                    echo "לא נמצא לפי טלפון ולא לפי id.\n";
                }

            }
            
        }
    }


    private function findProfessionalByPhone($phoneNumbers)
    {
        if (empty($phoneNumbers)) {
            return null;
        }

        $phones = explode('|', $phoneNumbers);
        $cleanedPhones = [];

        foreach ($phones as $phone) {
            $cleaned = $phone;
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
                // $professional = Professional::findOne($professionalId);
                $professional = Professional::find()
                    ->where(['id' => $professionalId])
                    ->all();

                if ($professional) {
                    echo "\nנמצא מקצוען לפי טלפון: " . implode(', ', $cleanedPhones) . "\n";
                    return $professional;
                } else {
                    echo "\nנמצא professional_id בכתובת, אך הוא לא קיים בטבלת professional: $professionalId - ממשיך לחפש...";
                }
            }
        }

        return null;
    }
    
}