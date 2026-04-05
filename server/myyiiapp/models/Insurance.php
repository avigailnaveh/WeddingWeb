<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "insurance".
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $english_name
 */
class Insurance extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'insurance';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['name', 'english_name'], 'default', 'value' => null],
            [['name'], 'string', 'max' => 100],
            [['english_name'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'english_name' => 'English Name',
        ];
    }

}
