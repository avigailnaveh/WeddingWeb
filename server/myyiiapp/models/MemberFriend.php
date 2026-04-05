<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "member_friend".
 *
 * @property int $id
 * @property string $member_id
 * @property string $friend_member_id
 */
class MemberFriend extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'member_friend';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['member_id', 'friend_member_id'], 'required'],
            [['member_id', 'friend_member_id'], 'string', 'max' => 255],
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
            'friend_member_id' => 'Friend Member ID',
        ];
    }

}
