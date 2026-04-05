<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "main_specialization".
 *
 * @property int $id
 * @property string $name
 * @property string|null $english_name
 * @property int|null $category_id
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $long_desc_title
 * @property string|null $long_desc
 */
class MainSpecialization extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'main_specialization';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['english_name', 'category_id', 'seo_title', 'seo_description', 'long_desc_title', 'long_desc'], 'default', 'value' => null],
            [['name'], 'required'],
            [['category_id'], 'integer'],
            [['long_desc'], 'string'],
            [['name', 'english_name', 'seo_title', 'long_desc_title'], 'string', 'max' => 255],
            [['seo_description'], 'string', 'max' => 512],
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
            'category_id' => 'Category ID',
            'seo_title' => 'Seo Title',
            'seo_description' => 'Seo Description',
            'long_desc_title' => 'Long Desc Title',
            'long_desc' => 'Long Desc',
        ];
    }

}
