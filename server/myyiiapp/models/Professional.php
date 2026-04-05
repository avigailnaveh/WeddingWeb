<?php

namespace app\models;

use app\models\ProfessionalAddress;
use app\models\ProfessionalExpertises;
use app\models\Localities;
use app\models\Insurances;
use app\models\MainSpecialization;
use app\models\Company;
use app\models\Care;
use app\models\MainCare;
use app\models\ProfessionalLocalities;
use app\models\Member;

use yii\db\Query;
use yii\db\ActiveRecord;

class Professional extends ActiveRecord
{
    public static function tableName()
    {
        return 'professional';
    }

    public function getProfessionalExpertises()
    {
        return $this->hasMany(ProfessionalExpertise::class, ['professional_id' => 'id']);
    }

    public function getAddresses()
    {
        return $this->hasMany(ProfessionalAddress::class, ['professional_id' => 'id']);
    }

    public function getNaf()
    {
        return $this->hasMany(Localities::class, ['id' => 'localities_id'])
            ->viaTable(ProfessionalLocalities::tableName(), ['professional_id' => 'id'])
            ->select(['city_name', 'city_symbol', 'naf_name', 'naf_symbol']);
    }

    public function getCompanies()
    {
        return $this->hasMany(Company::class, ['id' => 'company_id'])
            ->viaTable(ProfessionalCompany::tableName(), ['professional_id' => 'id']);
    }

    public function getInsurances()
    {
        return $this->hasMany(Insurance::class, ['id' => 'insurance_id'])
            ->viaTable(ProfessionalInsurance::tableName(), ['professional_id' => 'id']);
    }

    public function getMainSpecialization()
    {
        return $this->hasMany(MainSpecialization::class, ['id' => 'main_specialization_id'])
            ->viaTable(ProfessionalMainSpecialization::tableName(), ['professional_id' => 'id']);
    }

    public function getMainCare()
    {
        return $this->hasMany(MainCare::class, ['id' => 'main_care_id'])
            ->viaTable(ProfessionalMainCare::tableName(), ['professional_id' => 'id']);
    }

    public function getCare()
    {
        return $this->hasMany(Care::class, ['id' => 'care_id'])
            ->viaTable(ProfessionalCare::tableName(), ['professional_id' => 'id']);
    }

    public function getExpertises()
    {
        return $this->hasMany(Expertise::class, ['id' => 'expertise_id'])
            ->viaTable(ProfessionalExpertise::tableName(), ['professional_id' => 'id']);
    }

    public function getMemberRecommendation(?Member $member)
    {
        if (!$member) return null;

        return Recommendation::find()
            ->where(['professional_id' => $this->id, 'member_id' => $member->id])
            ->andWhere(['status' => 1])
            ->one();
    }

    public function isSaved(?Member $member): bool
    {
        if (!$member) {
            return false;
        }

        return (new Query())
            ->from('saved_professionals')
            ->where([
                'professional_id' => $this->id,
                'member_id' => $member->id,
            ])
            ->exists();
    }

}
