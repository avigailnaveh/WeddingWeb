<?php
namespace app\commands;

use Yii;
use yii\db\ActiveRecord;
use yii\console\Controller;
use yii\console\ExitCode;
use app\models\Professional;
use yii\db\Transaction;
use yii\db\IntegrityException;
use app\models\ProfessionalExpertise;
use app\models\ProfessionalMainSpecialization;

class MaintenanceController extends Controller
{
    
    public $limit = 0;
    public $ids = '';

    public function options($actionID)
    {
        return ['limit', 'ids'];
    }

    public function optionAliases()
    {
        return [
            'l' => 'limit',
            'i' => 'ids',
        ];
    }

    public function actionCleanFirstNames()
    {
        $query = Professional::find()
            ->where(['not', ['first_name' => null]])
            ->andWhere(['<>', 'first_name', ''])
            ->andWhere(['not', ['last_name' => null]])
            ->andWhere(['<>', 'last_name', '']);

        if (!empty($this->ids)) {
            $idList = array_filter(array_map('intval', explode(',', $this->ids)));
            if ($idList) {
                $query->andWhere(['id' => $idList]);
            }
        }

        if ((int)$this->limit > 0) {
            $query->limit((int)$this->limit);
        }

        $totalScanned = 0;
        $totalChanged = 0;

        echo "=== MODE: COMMIT (השינויים יישמרו בפועל) ===\n\n";

        foreach ($query->each(500) as $pro) {
            $totalScanned++;

            $first = self::normalize($pro->first_name);
            $last  = self::normalize($pro->last_name);

            if ($first === '' || $last === '') {
                continue;
            }

            $newFirst = self::removeEdgeWords($first, $last);

            if ($newFirst !== null && $newFirst !== '' && $newFirst !== $first) {
                $pro->first_name = $newFirst;
                $pro->save(false, ['first_name']);
                $totalChanged++;

                printf("ID %-6s | '%s' [last: %s] → '%s'\n",
                    $pro->id, $first, $last, $newFirst
                );
            }
        }

        echo "\nנסקרו: {$totalScanned}, עודכנו בפועל: {$totalChanged}\n";

        return ExitCode::OK;
    }

    private static function removeEdgeWords(string $first, string $last): ?string
    {
        $firstWords = self::splitWords($first);
        $lastWords  = self::splitWords($last);
        $fw = count($firstWords);
        $lw = count($lastWords);

        if ($fw === 0 || $lw === 0 || $fw < $lw) {
            return null;
        }

        $changed = false;

        if ($fw > $lw && array_slice($firstWords, 0, $lw) === $lastWords) {
            $firstWords = array_slice($firstWords, $lw);
            $changed = true;
        }
        elseif ($fw > $lw && array_slice($firstWords, -$lw) === $lastWords) {
            $firstWords = array_slice($firstWords, 0, -$lw);
            $changed = true;
        }
        elseif ($fw === $lw + 1) {
            if (array_slice($firstWords, 0, $lw) === $lastWords) {
                $firstWords = array_slice($firstWords, $lw);
                $changed = true;
            } elseif (array_slice($firstWords, -$lw) === $lastWords) {
                $firstWords = array_slice($firstWords, 0, -$lw);
                $changed = true;
            }
        }

        if ($changed) {
            $joined = trim(implode(' ', $firstWords));
            return $joined === '' ? null : $joined;
        }

        return null;
    }

    private static function normalize(string $s): string
    {
        $s = trim($s);
        return preg_replace('/\s+/u', ' ', $s);
    }

    private static function splitWords(string $s): array
    {
        $s = self::normalize($s);
        return $s === '' ? [] : preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    }

}
