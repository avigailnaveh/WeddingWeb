<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class UpdateAddressController extends Controller
{
    public function actionImportFromCsv()
    {
        $filePath = Yii::getAlias('@app/web/uploads/professional_address_translated.csv');

        if (!file_exists($filePath)) {
            $this->stderr("❌ File not found: {$filePath}\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $this->stdout("📂 Loading CSV: {$filePath}\n");

        if (($handle = fopen($filePath, 'r')) === false) {
            $this->stderr("❌ Cannot open file\n", Console::FG_RED);
            return ExitCode::CANTCREAT;
        }

        // קוראים את שורת הכותרות
        $headers = fgetcsv($handle);
        if ($headers === false) {
            $this->stderr("❌ Empty or invalid CSV (no header row)\n", Console::FG_RED);
            fclose($handle);
            return ExitCode::DATAERR;
        }

        // מיפוי שם עמודה → אינדקס
        $headerIndex = [];
        foreach ($headers as $index => $name) {
            $name = trim($name);
            if ($name !== '') {
                $headerIndex[$name] = $index;
            }
        }

        // בודקים שכל השדות שאת צריכה קיימים
        $requiredColumns = [
            'id',
            'city_google',
            'street_google',
            'number_house_google',
            'lat',
            'lng',
        ];

        foreach ($requiredColumns as $col) {
            if (!array_key_exists($col, $headerIndex)) {
                $this->stderr("❌ Missing column '{$col}' in CSV header\n", Console::FG_RED);
                fclose($handle);
                return ExitCode::DATAERR;
            }
        }

        $db = Yii::$app->db;
        $updated = 0;
        $notFound = 0;
        $rowNumber = 1; // כבר קראנו את שורת הכותרת

        $transaction = $db->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // id
                $id = trim($row[$headerIndex['id']] ?? '');
                if ($id === '') {
                    $this->stderr("⚠ Row {$rowNumber}: empty id, skipping\n", Console::FG_YELLOW);
                    continue;
                }

                // ערכים לעדכון
                $city  = $row[$headerIndex['city_google']] ?? null;
                $street = $row[$headerIndex['street_google']] ?? null;
                $house = $row[$headerIndex['number_house_google']] ?? null;
                $lat   = $row[$headerIndex['lat']] ?? null;
                $lng   = $row[$headerIndex['lng']] ?? null;

                // אפשר לעשות trim
                $city  = $city !== null ? trim($city) : null;
                $street = $street !== null ? trim($street) : null;
                $house = $house !== null ? trim($house) : null;
                $lat   = $lat !== null ? trim($lat) : null;
                $lng   = $lng !== null ? trim($lng) : null;

                // עדכון בשאילתת UPDATE אחת
                $rows = $db->createCommand(
                    'UPDATE professional_address
                     SET city_google = :city,
                         street_google = :street,
                         number_house_google = :house,
                         lat = :lat,
                         lng = :lng
                     WHERE id = :id',
                    [
                        ':city'   => $city,
                        ':street' => $street,
                        ':house'  => $house,
                        ':lat'    => $lat,
                        ':lng'    => $lng,
                        ':id'     => $id,
                    ]
                )->execute();

                if ($rows > 0) {
                    $updated++;
                } else {
                    $notFound++;
                    $this->stdout("⚠ Row {$rowNumber}: id {$id} not found in professional_address\n", Console::FG_YELLOW);
                }
            }

            $transaction->commit();
            fclose($handle);

            $this->stdout("\n✅ Done.\n", Console::FG_GREEN);
            $this->stdout("Updated rows: {$updated}\n", Console::FG_GREEN);
            $this->stdout("IDs not found in DB: {$notFound}\n", Console::FG_YELLOW);

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            fclose($handle);
            $this->stderr("❌ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
