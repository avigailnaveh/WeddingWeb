<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_address".
 *
 * @property int $id
 * @property int $professional_id
 * @property string|null $city
 * @property int|null $house_number
 * @property string|null $street
 * @property int|null $clinic_id
 * @property string|null $type
 * @property string|null $phone
 * @property string|null $phone_2
 * @property string|null $phone_3
 * @property string|null $phone_4
 * @property string|null $mobile
 */
class ProfessionalAddress extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_address';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['city', 'house_number', 'street', 'clinic_id', 'type', 'phone', 'phone_2', 'phone_3', 'phone_4', 'mobile'], 'default', 'value' => null],
            [['professional_id'], 'required'],
            [['professional_id', 'house_number', 'clinic_id'], 'integer'],
            [['city'], 'string', 'max' => 250],
            [['street', 'phone_2', 'phone_3', 'phone_4'], 'string', 'max' => 255],
            [['type'], 'string', 'max' => 100],
            [['phone', 'mobile'], 'string', 'max' => 10],
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
            'city' => 'City',
            'house_number' => 'House Number',
            'street' => 'Street',
            'clinic_id' => 'Clinic ID',
            'type' => 'Type',
            'phone' => 'Phone',
            'phone_2' => 'Phone 2',
            'phone_3' => 'Phone 3',
            'phone_4' => 'Phone 4',
            'mobile' => 'Mobile',
        ];
    }

}
