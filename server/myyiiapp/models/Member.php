<?php

namespace app\models;

use yii\db\ActiveRecord;

class Member extends ActiveRecord
{
    public static function tableName()
    {
        return 'member';
    }

    public function getProfessional()
    {
        return $this->hasOne(Professional::class, ['id' => 'professional_id']);
    }
}