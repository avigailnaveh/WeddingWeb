<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "main_specialization_expertise".
 *
 * @property int $id
 * @property int $main_specialization_id
 * @property int $expertise_id
 */
class MainSpecializationExpertise extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'main_specialization_expertise';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['main_specialization_id', 'expertise_id'], 'required'],
            [['main_specialization_id', 'expertise_id'], 'integer'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'main_specialization_id' => 'Main Specialization ID',
            'expertise_id' => 'Expertise ID',
        ];
    }

}
