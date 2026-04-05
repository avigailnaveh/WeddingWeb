<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "chat_results".
 *
 * @property int $id
 * @property int|null $member_id
 * @property string|null $specialty
 * @property string|null $care
 * @property string|null $name
 * @property int|null $is_pediatric
 * @property string $created
 */
class ChatResults extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'chat_results';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['member_id', 'specialty', 'care', 'name'], 'default', 'value' => null],
            [['is_pediatric'], 'default', 'value' => 0],
            [['member_id', 'is_pediatric'], 'integer'],
            [['created'], 'safe'],
            [['specialty'], 'string', 'max' => 64],
            [['care', 'name'], 'string', 'max' => 255],
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
            'specialty' => 'Specialty',
            'care' => 'Care',
            'name' => 'Name',
            'is_pediatric' => 'Is Pediatric',
            'created' => 'Created',
        ];
    }

}
