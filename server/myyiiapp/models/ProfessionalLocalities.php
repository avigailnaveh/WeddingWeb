<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "professional_localities".
 *
 * @property int $id
 * @property int $localities_id
 * @property int $professional_id
 */
class ProfessionalLocalities extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'professional_localities';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['localities_id', 'professional_id'], 'required'],
            [['localities_id', 'professional_id'], 'integer'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'localities_id' => 'Localities ID',
            'professional_id' => 'Professional ID',
        ];
    }

}
