<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_company".
 *
 * @property int $id
 * @property int|null $professional_id
 * @property int|null $company_id
 */
class ProfessionalCompany extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_company';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['professional_id', 'company_id'], 'default', 'value' => null],
            [['professional_id', 'company_id'], 'integer'],
            [['professional_id', 'company_id'], 'unique', 'targetAttribute' => ['professional_id', 'company_id']],
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
            'company_id' => 'Company ID',
        ];
    }

}
