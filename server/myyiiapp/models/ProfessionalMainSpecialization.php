<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_main_specialization".
 *
 * @property int $id
 * @property int $professional_id
 * @property int $main_specialization_id
 */
class ProfessionalMainSpecialization extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_main_specialization';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['professional_id', 'main_specialization_id'], 'required'],
            [['professional_id', 'main_specialization_id'], 'integer'],
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
            'main_specialization_id' => 'Main Specialization ID',
        ];
    }

}
