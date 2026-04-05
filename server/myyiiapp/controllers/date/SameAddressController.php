<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\ProfessionalAddress;

class SameAddressController extends Controller
{
    public function actionImportIndex()
    {
        ProfessionalAddress::updateAll(['display_address' => 0]);

        $professionalAddresses = ProfessionalAddress::find()
            ->orderBy('professional_id, id')
            ->all();

        // מקבץ את הנתונים לפי professional_id
        $groupedAddresses = [];
        foreach ($professionalAddresses as $address) {
            $groupedAddresses[$address->professional_id][] = $address;
        }

        // עובר על כל קבוצה של professional_id
        foreach ($groupedAddresses as $professionalId => $addresses) {
            
            // בודק אם יש יותר מכתובת אחת לאותו professional
            if (count($addresses) > 1) {
                
                // קבוצות כתובות עם אותם lng ו lat
                $coordinateGroups = [];
                
                foreach ($addresses as $address) {
                    // רק אם lng ו lat לא שווים 0
                    if ($address->lng != 0 && $address->lat != 0) {
                        $key = $address->lng . ',' . $address->lat;
                        $coordinateGroups[$key][] = $address;
                    }
                }
                
                // עובר על כל קבוצת קואורדינטות
                foreach ($coordinateGroups as $coordinates => $sameLocationAddresses) {
                    
                    // אם יש יותר מכתובת אחת באותו מיקום
                    if (count($sameLocationAddresses) > 1) {
                        
                        // מעדכן את display_address ל-0 לכל הכתובות
                        foreach ($sameLocationAddresses as $address) {
                            $address->display_address = 1;
                            $address->save();
                        }
                        
                        $sameLocationAddresses[0]->display_address = 0;
                        $sameLocationAddresses[0]->save();
                        
                        echo "עודכן professional_id: $professionalId - " . count($sameLocationAddresses) . " כתובות באותו מיקום\n";
                    }
                }
            }
        }

        echo "סיום עדכון הכתובות\n";
       
        
    }
}

