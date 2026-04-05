<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_insurance".
 *
 * @property int $id
 * @property int $professional_id
 * @property int $insurance_id
 */
class ProfessionalInsurance extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_insurance';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['professional_id', 'insurance_id'], 'required'],
            [['professional_id', 'insurance_id'], 'integer'],
            [['professional_id', 'insurance_id'], 'unique', 'targetAttribute' => ['professional_id', 'insurance_id']],
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
            'insurance_id' => 'Insurance ID',
        ];
    }

}
