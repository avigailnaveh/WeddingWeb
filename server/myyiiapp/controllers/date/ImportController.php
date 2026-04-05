<?php

namespace app\commands;
use yii\console\Controller;
use yii\helpers\ArrayHelper;

use app\models\Recommendation;
use app\models\Professional;
use app\models\ProfessionalAddress;

use Yii;

class ImportController extends Controller
{

    public function actionIndex()
    {

        $categories = ArrayHelper::map(Yii::$app->db->createCommand('SELECT id, name FROM category')->queryAll(), 'id', 'name');

        // print_r($categories);

        $rows = Yii::$app->db->createCommand('SELECT * FROM _migdal WHERE duplicateID = 0')->queryAll();
        $licenseCount = 0;
        $noLicenseCount = 0;
        $needCheck = 0;

        $categoryOK = 0;
        $categoryNotOK = 0;

        $nameOK = 0;
        $nameNotOK = 0;    
        $validateCount = 0;    
        $notValidateCount = 0;  

        $findPhone = 0; 
        $saved = 0; 

        $problems = [];
        foreach ($rows as $row) {
            $specialization = trim($row['specialization']); // trim to avoid whitespace issues

            

            if (in_array($specialization, $categories, true)) {
                $categoryOK++;
                $category_id = array_search($specialization, $categories, true);

                $id = $row['id'];
                $license_id = strpos($row['num'], '-') === false ? '1-'.trim($row['num']) : trim($row['num']);
                $phone = trim($row['phone']);
                $phone = self::normalizePhoneNumber($phone);
                $fName = trim($row['fName']);
                $lName = trim($row['lName']);
                $address = trim($row['address']);
                $degree = trim($row['degree']);

                if($address && strlen($address) > 3) {
                    $addressArr = self::parseAddress($address);
                }


                $model = Professional::find()->andWhere(['license_id' => $license_id])->one();

                if($model) {
                    $licenseCount++;
                    /*if($model->category_id == $category_id) {
                        $categoryOK++;
                    } else {
                        $categoryNotOK++;
                        // echo "ID: {$model->id}, category_id: {$model->category_id}, migdalId: {$id}, mogdalCategoryId: {$category_id} \n";
                    }

                    if(trim($model->last_name) == $lName) {
                        $nameOK++;
                    } else {
                        $nameNotOK++;
                        echo "ID: {$model->id}, last_name: {$model->last_name}, migdalId: {$id}, lName: {$lName} \n";
                    }   */                 
                } else {
                    $noLicenseCount++;

                    $model = new Professional();
                    $model->license_id = $license_id;
                    $model->first_name = $fName;
                    $model->last_name = $lName;

                    /*$query2 = Professional::find()->andWhere(['first_name' => $row['fName'], 'last_name' => $row['lName']]);
                    if($query2->count()) {
                        $needCheck++;
                        $mod = $query2->one();
                        echo "needCheck: ID: $id, id: $mod->id \n";

                    }*/
                    //$problems[] = ['id' => $id, ]
                }

                $model->category_id = $category_id;
                $model->title = $degree; 
                

                $model->skipAfterSave = true;

                if($model->validate() && $model->save()) {
                    $saved++;
                    $sql = "INSERT IGNORE INTO professional_insurance (professional_id, insurance_id) VALUES (:professional_id, :insurance_id)";

                    Yii::$app->db->createCommand($sql, [
                        ':professional_id' => $model->id,
                        ':insurance_id' => 1,
                    ])->execute();             

                    $validateCount++;
                    
                    if($phone) {

                        $adrQuery = ProfessionalAddress::find()->where(['professional_id' => $model->id, 'phone' => $phone]);
                        if(!$adrQuery->count()) {
                            $adrModel = new ProfessionalAddress();
                            $adrModel->professional_id = $model->id;
                            $adrModel->phone = $phone;
                            $adrModel->city = $addressArr['city'] ?? '';
                            $adrModel->house_number = $addressArr['number'] ?? '';
                            $adrModel->street = $addressArr['street'] ?? '';

                            if($adrModel->validate() && $adrModel->save()) {

                            } else {
                                $notValidateCount++;
                            // print_r($adrModel->errors);

                                echo "phone: $adrModel->phone; license_id: {$license_id}; \n";
                            }
                        }
                    }

                }



            }


            
        }

       echo "categoryOK: $categoryOK; saved: {$saved}; \n";
       // echo "licenseCount: $licenseCount; categoryOK: {$categoryOK};  noLicenseCount: {$noLicenseCount}; needCheck: {$needCheck}; nameOK: {$nameOK}; nameNotOK: {$nameNotOK} \n";
    }


    public static function normalizePhoneNumber($phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (!ctype_digit($phone)) {
            return false;
        } elseif (str_starts_with($phone, '972')) {
            $local = '0' . substr($phone, 3);
            return $local;
        } elseif (str_starts_with($phone, '0') && strlen($phone) === 10) {
            return $phone;
        } else {
           return false;
        }
    }


    public static function parseAddress($address) {
        $address = trim($address);
        $pattern = '/^(?:(.+?)\s+(\d+))?(?:,\s*(.+))?$/u';
        if (preg_match($pattern, $address, $matches)) {
            return [
                'street' => isset($matches[1]) ? trim($matches[1]) : null,
                'number' => isset($matches[2]) ? trim($matches[2]) : null,
                'city'   => isset($matches[3]) ? trim($matches[3]) : null,
            ];
        }

        return [
            'street' => null,
            'number' => null,
            'city'   => $address !== '' ? $address : null,
        ];
    }
}