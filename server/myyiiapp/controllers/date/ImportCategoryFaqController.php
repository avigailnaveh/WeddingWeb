<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Expression;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportCategoryFaqController extends Controller
{
    /**
     * Usage:
     * php yii import-category-faq
     */
    public function actionIndex()
    {
        $excelPath = Yii::getAlias('@app/web/uploads/e2gyvAdPpdZXk5W6iB_17670944452182.xlsx');

        if (!is_file($excelPath)) {
            echo "Excel not found: {$excelPath}\n";
            return;
        }

        echo "Reading Excel: {$excelPath}\n";

        try {
            $spreadsheet = IOFactory::load($excelPath);
        } catch (\Throwable $e) {
            echo "Failed to read Excel: " . $e->getMessage() . "\n";
            return;
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            echo "Excel seems empty (no data rows).\n";
            return;
        }

        // Row 1 is headers
        $headerRow = array_shift($rows);

        // Build: header_name => column_letter
        $colMap = $this->buildColumnMap($headerRow);

        $requiredHeaders = [
            'קטגוריה',
            'תיאור קטגוריה',
            'שאלה 1',
            'תשובה 1',
        ];

        foreach ($requiredHeaders as $h) {
            if (!isset($colMap[$h])) {
                echo "Missing required column header: {$h}\n";
                echo "Found headers: " . implode(', ', array_keys($colMap)) . "\n";
                return ExitCode::DATAERR;
            }
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();

        $updatedMainSpec = 0;
        $insertedFaq = 0;
        $skippedFaqPairs = 0;
        $missingMainSpec = 0;
        $count = 0;
        try {
            foreach ($rows as $i => $row) {
                
                // Excel data row number (since we removed header)
                $excelRowNum = $i + 2;

                $categoryName = $this->getCell($row, $colMap, 'קטגוריה');
                if ($categoryName === null || $categoryName === '') {
                    // Empty row
                    continue;
                }

                $categoryName = $this->normalizeText($categoryName);
                if ($categoryName === '') {
                    echo "Row {$excelRowNum}: Empty category name\n";
                    continue;
                }

                // Update main_care.long_desc from "תיאור קטגוריה"
                $longDesc = $this->getCell($row, $colMap, 'תיאור קטגוריה');
                $longDesc = $this->normalizeText($longDesc);

                if ($longDesc !== '') {
                    $affected = $db->createCommand()->update(
                        'main_care',
                        ['long_desc' => $longDesc],
                        ['name' => $categoryName]
                    )->execute();

                    if ($affected > 0) {
                        $updatedMainSpec += $affected;
                    } else {
                        // No row found to update
                        $missingMainSpec++;
                        echo "Row {$excelRowNum}: main_care not found for name={$categoryName}\n";
                    }
                }

                // Get category_id for FAQ inserts
                $mainSpec = (new \yii\db\Query())
                    ->select('id')
                    ->from('main_care')
                    ->where(['name' => $categoryName])
                    ->one();

                if (!$mainSpec || !isset($mainSpec['id']) || $mainSpec['id'] === null) {
                    echo "Row {$excelRowNum}: Cannot insert FAQ - main_care not found for name={$categoryName}\n";
                    continue;
                }

                $categoryId = (int)$mainSpec['id'];
                if ($categoryId <= 0) {
                    echo "Row {$excelRowNum}: Invalid id for name={$categoryName}\n";
                    continue;
                }

                // Insert FAQ rows for question/answer 1..10
                for ($n = 1; $n <= 10; $n++) {
                    $qHeader = "שאלה {$n}";
                    $aHeader = "תשובה {$n}";

                    // If columns for higher numbers don't exist, just skip
                    if (!isset($colMap[$qHeader]) || !isset($colMap[$aHeader])) {
                        continue;
                    }

                    $question = $this->normalizeText($this->getCell($row, $colMap, $qHeader));
                    $answer   = $this->normalizeText($this->getCell($row, $colMap, $aHeader));

                    // אם אין כלום בזוג - לא מכניסים רשומה
                    if ($question === '' && $answer === '') {
                        $skippedFaqPairs++;
                        continue;
                    }

                    $db->createCommand()->insert('category_faq', [
                        'category_id'   => $categoryId,
                        'category_type' => 1,
                        'question'      => $question !== '' ? $question : null,
                        'answer'        => $answer !== '' ? $answer : null,
                        
                    ])->execute();

                    $insertedFaq++;
                }
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            echo "FAILED: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            return;
        }

        echo "Done.\n";
        echo "main_care updated rows: {$updatedMainSpec}\n";
        echo "main_care missing rows: {$missingMainSpec}\n";
        echo "category_faq inserted rows: {$insertedFaq}\n";
        echo "category_faq skipped empty pairs: {$skippedFaqPairs}\n";

        return;
    }

    private function buildColumnMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $colLetter => $headerName) {
            $headerName = $this->normalizeHeader($headerName);
            if ($headerName !== '') {
                $map[$headerName] = $colLetter;
            }
        }
        return $map;
    }

    private function normalizeHeader($value): string
    {
        $v = $this->normalizeText($value);
        return $v;
    }

    private function getCell(array $row, array $colMap, string $header)
    {
        $col = $colMap[$header] ?? null;
        if ($col === null) {
            return null;
        }
        return $row[$col] ?? null;
    }

    private function normalizeText($value): string
    {
        if ($value === null) {
            return '';
        }
        // PhpSpreadsheet sometimes returns rich text objects
        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            $value = (string)$value;
        }

        // normalize whitespace
        $value = str_replace("\xC2\xA0", ' ', $value); // nbsp
        $value = trim($value);
        // collapse multi spaces
        $value = preg_replace('/[ \t]+/u', ' ', $value);
        return $value ?? '';
    }
}