<?php

namespace app\models;

use yii\db\ActiveRecord;

class Expertise extends ActiveRecord
{
    public static function tableName()
    {
        return 'expertise';
    }
}
