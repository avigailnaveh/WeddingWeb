<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\Professional;
use app\models\ProfessionalAddress;
use yii\db\Query; 

class UpdatePhoneNumberController extends Controller
{
    public function actionUpdatePhones(): int
    {
        $insuranceNonPrivate = [
            'מגדל','מנורה','הכשרה','הפניקס','כלל','הראל','אילון',
            'מגדל רפואת שיניים','הראל רפואת שיניים','מנורה רפואת שיניים','הפניקס רופאת שיניים',
        ];

        $healthFunds = [
            'מאוחדת','כללית','מכבי','לאומית',
            'כללית מושלם','מכבי משלים','לאומית משלים','איכילוב וולנס'
        ];

        $associations = [
            'אגודה לטיפול זוגי','יה"ת','החברה הפיסכו אנליטית','האיגוד הישראלי לפסיכוטרפיה',
            'אגודה לקלינאיות תקשורת','איזי','האיגוד הישראלי לפסיכודרמה','מ.ר.ח.ב','ההסתדרות לרפואת שיניים','SpeechTherapist','Therapwiz','easyPsychologists'
        ];

        $insurancePrivate = ['פרטי'];
        $otherType = 'אחר';

        $updated = 0;
        $count = 0;
        foreach (Professional::find()->each(200) as $prof) {
            $currentPhone = trim((string)$prof->phone);
            $digits = preg_replace('/\D+/', '', $currentPhone);

            // בדיקה שהטלפון מתחיל ב-07
            if ($digits === '' || substr($digits, 0, 2) !== '07') {
                continue;
            }
            if($count == 10)continue;
            $count++;
            echo $digits . "\n";
            echo $count . "\n";
            
            $chosen = $this->chooseAddressForPhone(
                (int)$prof->id,
                $insuranceNonPrivate,
                $insurancePrivate,
                $healthFunds,
                $associations,
                $otherType
            );

            // אין כתובת רלוונטית עם טלפון -> לא מעדכן כלום
            if ($chosen === null) {
                continue;
            }

            // נבחר טלפון "מיטבי" מהכתובת שנבחרה
            $allowAsterisk = $this->addressHasOnlyAsteriskPhones($chosen);
            $allow07 = !$this->addressHasNonAsteriskNon07Phone($chosen);
            $bestPhone = $this->pickBestPhoneFromAddress($chosen, $allowAsterisk, $allow07);

            // לא נמצא שום מספר לשמירה -> לא מעדכן
            if ($bestPhone === null) {
                continue;
            }

            $current = trim((string)$prof->phone);
            $bestHasAsterisk = (mb_strpos($bestPhone, '*') !== false);
            $bestStartsWith07 = $this->phoneStartsWith07($bestPhone);
            $currentIsEmpty = ($current === '');

            $shouldUpdate = false;

            if ($currentIsEmpty) {
                // אם השדה ריק/NULL - לשים גם אם יש 07 או *
                $shouldUpdate = true;
            } else {
                // יש ערך קיים שמתחיל ב-07
                // לדרוס רק אם המספר החדש לא כולל * ולא מתחיל ב-07
                if (!$bestHasAsterisk && !$bestStartsWith07 && $current !== $bestPhone) {
                    $shouldUpdate = true;
                }
                // אפשר גם לדרוס עם 07 אם הקיים הוא 07 והחדש טוב יותר (05)
                else if (!$bestHasAsterisk && $bestStartsWith07 && $this->phoneStartsWith05($bestPhone) && $current !== $bestPhone) {
                    $shouldUpdate = true;
                }
            }

            if ($shouldUpdate) {
                $prof->phone = $bestPhone;
                if ($prof->save(false, ['phone'])) {
                    $updated++;
                    echo "הטלפון הזה עודכן\n";
                }
            }
        }

        echo "Phones updated for {$updated} professionals.\n";
        return 0;
    }

    private function fetchAddresses(int $professionalId): array
    {
        return ProfessionalAddress::find()
            ->where(['professional_id' => $professionalId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function chooseAddressForPhone(
        int $professionalId,
        array $insuranceNonPrivate,
        array $insurancePrivate,
        array $healthFunds,
        array $associations,
        string $otherType
    ): ?ProfessionalAddress {
        $addresses = $this->fetchAddresses($professionalId);
        if (empty($addresses)) return null;

        $layers = [
            fn(ProfessionalAddress $a) => $this->typeIn($a->type, $insuranceNonPrivate),
            fn(ProfessionalAddress $a) => $this->typeIn($a->type, $associations),
            fn(ProfessionalAddress $a) => $this->normalizeType($a->type) === $this->normalizeType('פסיכולוגיה עברית'),
            fn(ProfessionalAddress $a) => $this->normalizeType($a->type) === $this->normalizeType('טיפולנט'),
            fn(ProfessionalAddress $a) => $this->typeIn($a->type, $healthFunds),
            fn(ProfessionalAddress $a) => $this->typeIn($a->type, $insurancePrivate),
            fn(ProfessionalAddress $a) => $this->normalizeType($a->type) === $this->normalizeType($otherType),
        ];

        // שלב 1: נסה קודם רק טלפונים רגילים (לא * ולא 07)
        foreach ($layers as $pred) {
            $picked = $this->pickAddressWithinLayer($addresses, $pred, false, false);
            if ($picked !== null) return $picked;
        }

        // שלב 2: אם לא נמצא, נסה טלפונים המתחילים ב-07 (אבל לא *)
        foreach ($layers as $pred) {
            $picked = $this->pickAddressWithinLayer($addresses, $pred, false, true);
            if ($picked !== null) return $picked;
        }

        // שלב 3: רק כמוצא אחרון - טלפונים עם *
        foreach ($layers as $pred) {
            $picked = $this->pickAddressWithinLayer($addresses, $pred, true, true);
            if ($picked !== null) return $picked;
        }

        return null;
    }

    private function pickAddressWithinLayer(
        array $addresses, 
        callable $predicate, 
        bool $allowAsterisk, 
        bool $allow07
    ): ?ProfessionalAddress {
        $candidates = [];
        foreach ($addresses as $addr) {
            if ($predicate($addr) && $this->addressHasAnyPhoneFiltered($addr, $allowAsterisk, $allow07)) {
                $candidates[] = $addr;
            }
        }
        if (empty($candidates)) return null;

        // עדיפות ל-05
        foreach ($candidates as $addr) {
            if ($this->addressHas05($addr, $allowAsterisk, $allow07)) {
                return $addr;
            }
        }
        return $candidates[0];
    }

    private function getPhonesRaw(ProfessionalAddress $a): array
    {
        $vals = [
            (string)$a->phone,
            (string)$a->phone_2,
            (string)$a->phone_3,
            (string)$a->phone_4,
            (string)$a->mobile,
        ];
        return array_values(array_filter(array_map(static fn($p) => trim($p ?? ''), $vals), fn($p) => $p !== ''));
    }

    private function filterPhones(array $phones, bool $allowAsterisk, bool $allow07): array
    {
        return array_values(array_filter($phones, function($p) use ($allowAsterisk, $allow07) {
            if ($p === '') return false;
            if (!$allowAsterisk && mb_strpos($p, '*') !== false) return false;
            if (!$allow07 && $this->phoneStartsWith07($p)) return false;
            return true;
        }));
    }

    private function addressHasRegularPhone(ProfessionalAddress $a): bool
    {
        foreach ($this->getPhonesRaw($a) as $p) {
            if (mb_strpos($p, '*') === false && !$this->phoneStartsWith07($p)) return true;
        }
        return false;
    }

    private function addressHasNonAsteriskNon07Phone(ProfessionalAddress $a): bool
    {
        foreach ($this->getPhonesRaw($a) as $p) {
            if (mb_strpos($p, '*') === false && !$this->phoneStartsWith07($p)) return true;
        }
        return false;
    }

    private function anyAddressHasRegularPhone(array $addresses): bool
    {
        foreach ($addresses as $a) {
            if ($this->addressHasRegularPhone($a)) return true;
        }
        return false;
    }

    private function addressHasOnlyAsteriskPhones(ProfessionalAddress $a): bool
    {
        $phones = $this->getPhonesRaw($a);
        if (empty($phones)) return false;
        foreach ($phones as $p) {
            if (mb_strpos($p, '*') === false) return false;
        }
        return true;
    }

    private function addressHasAnyPhone(ProfessionalAddress $a): bool
    {
        return !empty($this->getPhonesRaw($a));
    }

    private function addressHasAnyPhoneFiltered(ProfessionalAddress $a, bool $allowAsterisk, bool $allow07): bool
    {
        return !empty($this->filterPhones($this->getPhonesRaw($a), $allowAsterisk, $allow07));
    }

    private function normalizePhoneForCheck(string $p): string
    {
        $digits = preg_replace('/\D+/', '', $p);
        if (strpos($digits, '972') === 0) {
            $digits = '0' . substr($digits, 3);
        }
        return $digits;
    }

    private function phoneStartsWith05(string $p): bool
    {
        $d = $this->normalizePhoneForCheck($p);
        return substr($d, 0, 2) === '05';
    }

    private function phoneStartsWith07(string $p): bool
    {
        $d = $this->normalizePhoneForCheck($p);
        return substr($d, 0, 2) === '07';
    }

    private function addressHas05(ProfessionalAddress $a, bool $allowAsterisk, bool $allow07): bool
    {
        foreach ($this->filterPhones($this->getPhonesRaw($a), $allowAsterisk, $allow07) as $p) {
            if ($this->phoneStartsWith05($p)) return true;
        }
        return false;
    }

    private function pickBestPhoneFromAddress(ProfessionalAddress $a, bool $allowAsterisk = false, bool $allow07 = false): ?string
    {
        $order = ['phone','phone_2','phone_3','phone_4','mobile'];

        // קודם חפש 05 (ללא * וללא 07 אלא אם מותר)
        foreach ($order as $f) {
            $val = trim((string)$a->$f);
            if ($val === '') continue;
            if (!$allowAsterisk && mb_strpos($val, '*') !== false) continue;
            if (!$allow07 && $this->phoneStartsWith07($val)) continue;
            if ($this->phoneStartsWith05($val)) return $val;
        }

        // אם לא נמצא 05, קח כל מספר שעומד בקריטריונים
        foreach ($order as $f) {
            $val = trim((string)$a->$f);
            if ($val === '') continue;
            if (!$allowAsterisk && mb_strpos($val, '*') !== false) continue;
            if (!$allow07 && $this->phoneStartsWith07($val)) continue;
            return $val;
        }

        return null;
    }

    private function normalizeType(?string $t): string
    {
        $s = $t ?? '';
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        $s = str_replace(['"', '״', "'", "´", "`"], "'", $s);
        return mb_strtolower($s);
    }

    private function typeIn(?string $type, array $needles): bool
    {
        $type = $this->normalizeType($type);
        foreach ($needles as $n) {
            if (mb_strpos($type, $this->normalizeType($n)) !== false) {
                return true;
            }
        }
        return false;
    }

    public function actionIndex(): int
    {
        // עדכון כל הרשומות הרלוונטיות
        $rowsUpdated = Yii::$app->db->createCommand("
            UPDATE professional
            SET phone = REPLACE(mobile, '-', '')
            WHERE (phone IS NULL OR TRIM(phone) = '')
              AND mobile IS NOT NULL
              AND TRIM(mobile) != ''
        ")->execute();

        echo "עודכנו {$rowsUpdated} רשומות.\n";
        return 0;
    }
}