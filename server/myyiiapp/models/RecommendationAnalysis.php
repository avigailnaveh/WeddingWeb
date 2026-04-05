<?php
namespace app\models;

use yii\db\ActiveRecord;

/**
 * This is the model class for table "recommendation_analysis".
 *
 * @property int $id
 * @property int $recommendation_id
 * @property string|null $sentiment
 * @property float|null $sentiment_confidence
 * @property string|null $doctor_name
 * @property string|null $doctor_title
 * @property string|null $hmo
 * @property string|null $insurance
 * @property string|null $languages_json
 * @property string|null $specialties_json
 * @property string|null $topics_json
 * @property string|null $extracted_entities_json
 * @property string|null $doctor_metrics_json
 * @property int|null $works_with_children
 * @property string|null $children_signal
 * @property string|null $children_evidence
 * @property string|null $model_version
 * @property string|null $created_at
 */
class RecommendationAnalysis extends ActiveRecord
{
    public static function tableName()
    {
        return 'recommendation_analysis';
    }

    public function rules()
    {
        return [
            [['recommendation_id'], 'required'],
            [['recommendation_id', 'works_with_children'], 'integer'],
            [['sentiment_confidence'], 'number'],
            [['created_at'], 'safe'],

            [['sentiment'], 'string', 'max' => 10],
            [['doctor_name'], 'string', 'max' => 255],
            [['doctor_title'], 'string', 'max' => 50],
            [['hmo'], 'string', 'max' => 100],
            [['insurance'], 'string', 'max' => 150],
            [['children_signal'], 'string', 'max' => 30],
            [['children_evidence'], 'string', 'max' => 500],
            [['model_version'], 'string', 'max' => 120],

            [['languages_json','specialties_json','topics_json','extracted_entities_json','doctor_metrics_json'], 'string'],
        ];
    }
}
