<?php

namespace app\models;

use yii\db\ActiveRecord;

class ProfessionalExpertise extends ActiveRecord
{
    public static function tableName()
    {
        return 'professional_expertise';
    }

    public function getExpertise()
    {
        return $this->hasOne(Expertise::class, ['id' => 'expertise_id']);
    }

    public function getProfessional()
    {
        return $this->hasOne(Professional::class, ['id' => 'professional_id']);
    }
}
