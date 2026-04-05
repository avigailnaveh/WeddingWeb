<?php
namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;
use yii\helpers\Console;

class UpdateEnglishNamesController extends Controller
{
   
    public function actionIndex()
    {
        
        // if (!is_dir($dir)) {
        //     echo "Directory not found: $dir\n";
        //     return;
        // }

        // $files = $this->findTranslatedCsvFiles($dir);
        // if (empty($files)) {
        //     echo "No *_translated.csv files found in: $dir\n";
        //     return;
        // }

        // print_r($files);

        // foreach ($files as $filePath) {
        $filePath = Yii::getAlias('@app/web/uploads/english_translation/titles_translated.csv');

            $filenameNoExt = pathinfo($filePath, PATHINFO_FILENAME); 
            // if($filenameNoExt == 'localities_translated' || $filenameNoExt == 'company_translated' || $filenameNoExt == 'care_translated')return;
            // $table = $this->tableFromFilename($filenameNoExt);
            $table = 'professional';

            echo "\nProcessing: $filePath\n";
            echo "Target table: $table\n";

            $rows = $this->loadCsv($filePath);

            if (empty($rows)) {
               echo "CSV is empty. Skipping.\n";
                return;
            }

            // Validate required columns in header
            // if (!array_key_exists('id', $rows[0])) {
            //     echo "Missing required columns (must include): id, english_name\n";
            //     return;
            // }

            $db = Yii::$app->db;
            $tx = $db->beginTransaction();

            try {
                // Ensure table exists
                if ($db->schema->getTableSchema($table, true) === null) {
                    throw new \RuntimeException("Table does not exist: $table");
                }

                $updated = 0;

                foreach ($rows as $i => $row) {
                    $english_title = $this->normalizeString($row['english_title'] ?? null);
                    $title = $this->normalizeString($row['title'] ?? null);

                    if ($title === null || $title === '') {
                        // throw new \RuntimeException("Row #".($i+1)." missing title");
                        continue;
                    }

                    if ($english_title === null || $english_title === '') {
                        throw new \RuntimeException("Row #".($i+1)." missing english_title for title='{$title}'");
                    }

                    // Update all professionals with this title
                    $affected = $db->createCommand()->update(
                        $table,
                        ['english_title' => $english_title],
                        ['title' => $title]
                    )->execute();

                    // אם את באמת רוצה "לא לדלג" במקרה שאין אף רופא עם title הזה:
                    if ($affected < 1) {
                        throw new \RuntimeException("Title '{$title}' not found in {$table}. Aborting (no skipping).");
                    }

                    $updated += $affected;
                }


                $tx->commit();
               echo "OK: Updated $updated rows in $table\n";

            } catch (\Throwable $e) {
                $tx->rollBack();
                echo "ERROR: ".$e->getMessage()."\n";
                return;
            }
        // }

       echo "\nAll CSV files processed successfully.\n";
        return ;
    }

    private function findTranslatedCsvFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $name = $file->getFilename();
            if (preg_match('/_translated\.csv$/i', $name)) {
                $out[] = $file->getPathname();
            }
        }

        sort($out);
        return $out;
    }

    private function tableFromFilename(string $filename): string
    {
        // company_translated => company
        if (preg_match('/^(.*)_translated$/u', $filename, $m)) {
            return $m[1];
        }

        // fallback: cut until _translated if exists
        $pos = mb_strpos($filename, '_translated');
        if ($pos !== false) {
            return mb_substr($filename, 0, $pos);
        }

        throw new \RuntimeException("Filename does not contain _translated: $filename");
    }

    private function loadCsv(string $filePath): array
    {
        $rows = [];
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open CSV: $filePath");
        }

        $header = null;

        while (($data = fgetcsv($fh)) !== false) {
            if ($header === null) {
                // handle UTF-8 BOM in first header cell
                if (isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$data[0]);
                }
                $header = array_map([$this, 'normalizeHeader'], $data);
                continue;
            }

            // skip totally empty lines
            if (count($data) === 1 && trim((string)$data[0]) === '') {
                continue;
            }

            $row = [];
            foreach ($header as $idx => $col) {
                $row[$col] = $data[$idx] ?? null;
            }
            $rows[] = $row;
        }

        fclose($fh);
        return $rows;
    }

    private function normalizeHeader(string $h): string
    {
        $h = trim($h);
        $h = strtolower($h);

        // normalize common variants
        if ($h === 'englishname') $h = 'english_name';
        if ($h === 'english name') $h = 'english_name';

        return $h;
    }

    private function normalizeInt($v): ?int
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!preg_match('/^\d+$/', $v)) return null;
        return (int)$v;
    }

    private function normalizeString($v): ?string
    {
        if ($v === null) return null;
        return trim((string)$v);
    }
}
