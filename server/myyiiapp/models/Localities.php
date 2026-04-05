<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "localities".
 *
 * @property int $id
 * @property string $city_name
 * @property string|null $english_city_name
 * @property string $city_symbol
 * @property string $district_symbol
 * @property string $district_name
 * @property string|null $english_district_name
 * @property string $naf_symbol
 * @property string $naf_name
 * @property string|null $english_naf_name
 * @property float|null $lat
 * @property float|null $lng
 */
class Localities extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'localities';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['english_city_name', 'english_district_name', 'english_naf_name', 'lat', 'lng'], 'default', 'value' => null],
            [['city_name', 'city_symbol', 'district_symbol', 'district_name', 'naf_symbol', 'naf_name'], 'required'],
            [['lat', 'lng'], 'number'],
            [['city_name', 'english_city_name', 'city_symbol', 'district_symbol', 'district_name', 'english_district_name', 'naf_symbol', 'naf_name', 'english_naf_name'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'city_name' => 'City Name',
            'english_city_name' => 'English City Name',
            'city_symbol' => 'City Symbol',
            'district_symbol' => 'District Symbol',
            'district_name' => 'District Name',
            'english_district_name' => 'English District Name',
            'naf_symbol' => 'Naf Symbol',
            'naf_name' => 'Naf Name',
            'english_naf_name' => 'English Naf Name',
            'lat' => 'Lat',
            'lng' => 'Lng',
        ];
    }

}
