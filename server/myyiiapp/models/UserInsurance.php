<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "user_insurance".
 *
 * @property int $id
 * @property int $member_id
 * @property int $insurance_id
 */
class UserInsurance extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'user_insurance';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['member_id', 'insurance_id'], 'required'],
            [['member_id', 'insurance_id'], 'integer'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'member_id' => 'Member ID',
            'insurance_id' => 'Insurance ID',
        ];
    }

}
