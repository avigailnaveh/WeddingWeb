<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_main_care".
 *
 * @property int $id
 * @property int $professional_id
 * @property int $main_care_id
 */
class ProfessionalMainCare extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_main_care';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['professional_id', 'main_care_id'], 'required'],
            [['professional_id', 'main_care_id'], 'integer'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'professional_id' => 'Professional ID',
            'main_care_id' => 'Main Care ID',
        ];
    }

}
