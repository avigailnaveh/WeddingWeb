<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "recommendation".
 *
 * @property int $id
 * @property int|null $member_id
 * @property int|null $professional_id
 * @property string|null $category_id
 * @property string|null $rec_description
 * @property int $status
 *
 * @property RecommendationAnalysis $recommendationAnalysis
 */
class Recommendation extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'recommendation';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['member_id', 'professional_id', 'category_id', 'rec_description'], 'default', 'value' => null],
            [['status'], 'default', 'value' => 1],
            [['member_id', 'professional_id', 'status'], 'integer'],
            [['category_id'], 'string', 'max' => 3],
            [['rec_description'], 'string', 'max' => 1273],
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
            'professional_id' => 'Professional ID',
            'category_id' => 'Category ID',
            'rec_description' => 'Rec Description',
            'status' => 'Status',
        ];
    }

    /**
     * Gets query for [[RecommendationAnalysis]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getRecommendationAnalysis()
    {
        return $this->hasOne(RecommendationAnalysis::class, ['recommendation_id' => 'id']);
    }

}
