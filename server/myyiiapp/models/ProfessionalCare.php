<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_care".
 *
 * @property int $id
 * @property int $professional_id
 * @property int $care_id
 */
class ProfessionalCare extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_care';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['professional_id', 'care_id'], 'required'],
            [['professional_id', 'care_id'], 'integer'],
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
            'care_id' => 'Care ID',
        ];
    }

}
