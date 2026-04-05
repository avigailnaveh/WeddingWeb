<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "main_care_sub_care".
 *
 * @property int $id
 * @property int $main_care_id
 * @property int $care_id
 */
class MainCareSubCare extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'main_care_sub_care';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['main_care_id', 'care_id'], 'required'],
            [['main_care_id', 'care_id'], 'integer'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'main_care_id' => 'Main Care ID',
            'care_id' => 'Care ID',
        ];
    }

}
