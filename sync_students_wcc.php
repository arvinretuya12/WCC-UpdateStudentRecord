<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ValueRange;

// ================= CONFIGURATION =================
$spreadsheetId = '1ipBOLOBFCVqhEmyaITp_qg7Avl7NcmlgtLhhtZHNsDg'; // <--- PASTE YOUR ID HERE
// =================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Please use the form to access this script.");
}

echo "<h3>Processing...</h3>";

try {
    // 1. Handle File Upload
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] != 0) {
        throw new Exception("File upload failed.");
    }

    $targetSheetName = $_POST['sheet_name'];
    $uploadedFile = 'uploads/' . basename($_FILES['excel_file']['name']);

    if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $uploadedFile)) {
        throw new Exception("Could not move uploaded file.");
    }

    // 2. Setup Google Client
    $client = new Client();
    $client->setApplicationName('Student Sync Script');
    $client->setScopes([Sheets::SPREADSHEETS]);
    $client->setAuthConfig('student-sync-app-wcc-68ccf7749e2b.json');
    $service = new Sheets($client);

    // 3. Read Excel
    $spreadsheet = IOFactory::load($uploadedFile);
    $excelData = $spreadsheet->getActiveSheet()->toArray();
    
    $excelNames = [];
    for ($i = 18; $i < count($excelData); $i++) {
        $name = trim(strtoupper($excelData[$i][2] ?? ''));
        if (!empty($name)) {
            $excelNames[] = $name;
        }
    }
    sort($excelNames);

    // 4. Read Google Sheet
    $range = $targetSheetName . "!B6:B"; 
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $gsheetRows = $response->getValues();

    $gsheetNames = [];
    if ($gsheetRows) {
        foreach ($gsheetRows as $row) {
            $gsheetNames[] = trim(strtoupper($row[0] ?? ''));
        }
    }

    // 5. Sync Logic
    $sheetRowIndex = 0; 
    $physicalRowPointer = 6; 
    
    $sheetId = getSheetIdByName($service, $spreadsheetId, $targetSheetName);

    echo "<div style='font-family:monospace; background:#f4f4f4; padding:15px; border-radius:5px;'>";

    foreach ($excelNames as $excelName) {
        
        while (isset($gsheetNames[$sheetRowIndex]) && $gsheetNames[$sheetRowIndex] === '') {
            $sheetRowIndex++;
            $physicalRowPointer++;
        }

        $currentGSheetName = $gsheetNames[$sheetRowIndex] ?? null;

        if ($excelName === $currentGSheetName) {
            $sheetRowIndex++;     
            $physicalRowPointer++; 
        } else {
            echo "INSERTING: <strong>$excelName</strong> at row " . ($physicalRowPointer) . "<br>";
            
            $newRowIndex = $physicalRowPointer - 1; // 0-based index for API

            // A. Insert Empty Row
            insertRow($service, $spreadsheetId, $sheetId, $newRowIndex);
            
            // B. Full Copy from Neighbor (Formula + Format + Values)
            // This forces Google to auto-increment the formula references (C5 -> C6)
            $sourceRowIndex = ($newRowIndex > 5) ? ($newRowIndex - 1) : ($newRowIndex + 1);
            copyFullRow($service, $spreadsheetId, $sheetId, $sourceRowIndex, $newRowIndex);
            
            // C. Sanitize the New Row 
            // Read the row we just filled, keep formulas, delete hardcoded values
            sanitizeRow($service, $spreadsheetId, $targetSheetName, $physicalRowPointer);

            // D. Overwrite Name (Column B)
            writeCell($service, $spreadsheetId, $targetSheetName, $physicalRowPointer, $excelName);

            $physicalRowPointer++;
        }
    }
    echo "</div>";
    echo "<h3 style='color:green'>Sync Complete!</h3>";
    echo "<a href='wcc-update-record.php'>Go Back</a>";

    unlink($uploadedFile);

} catch (Exception $e) {
    echo '<h3 style="color:red">Error: ' . $e->getMessage() . '</h3>';
}

// ================= HELPER FUNCTIONS =================

function insertRow($service, $spreadsheetId, $sheetId, $rowIndex) {
    $request = new BatchUpdateSpreadsheetRequest([
        'requests' => [
            'insertDimension' => [
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'ROWS',
                    'startIndex' => $rowIndex, 
                    'endIndex' => $rowIndex + 1
                ],
                'inheritFromBefore' => false 
            ]
        ]
    ]);
    $service->spreadsheets->batchUpdate($spreadsheetId, $request);
}

function copyFullRow($service, $spreadsheetId, $sheetId, $sourceIndex, $targetIndex) {
    // We copy PASTE_NORMAL so Google handles the reference updates (C5 becomes C6)
    $request = new BatchUpdateSpreadsheetRequest([
        'requests' => [
            'copyPaste' => [
                'source' => [
                    'sheetId' => $sheetId,
                    'startRowIndex' => $sourceIndex,
                    'endRowIndex' => $sourceIndex + 1,
                ],
                'destination' => [
                    'sheetId' => $sheetId,
                    'startRowIndex' => $targetIndex,
                    'endRowIndex' => $targetIndex + 1,
                ],
                'pasteType' => 'PASTE_NORMAL' 
            ]
        ]
    ]);
    $service->spreadsheets->batchUpdate($spreadsheetId, $request);
}

function sanitizeRow($service, $spreadsheetId, $sheetName, $rowNumber) {
    // 1. Read the newly created row (which currently has unwanted copied values)
    $range = "$sheetName!A$rowNumber:$rowNumber"; // Read whole row
    $params = ['valueRenderOption' => 'FORMULA']; // IMPORTANT: Get raw formulas
    
    $response = $service->spreadsheets_values->get($spreadsheetId, $range, $params);
    $rowValues = $response->getValues();
    
    if (empty($rowValues)) return;
    
    $rowData = $rowValues[0];
    $cleanedData = [];

    // 2. Loop through every cell
    foreach ($rowData as $cellValue) {
        $cellValue = (string)$cellValue;
        
        // If it starts with '=', it's a formula (like =SUM(C6:Z6)). Keep it!
        if (substr($cellValue, 0, 1) === '=') {
            $cleanedData[] = $cellValue;
        } 
        // If it's empty, keep it empty.
        elseif ($cellValue === '') {
            $cleanedData[] = "";
        }
        // If it's a hardcoded value (like "95" or "Present"), DELETE IT.
        else {
            $cleanedData[] = ""; 
        }
    }

    // 3. Write the cleaned data back
    $body = new ValueRange(['values' => [$cleanedData]]);
    $updateParams = ['valueInputOption' => 'USER_ENTERED'];
    $service->spreadsheets_values->update($spreadsheetId, $range, $body, $updateParams);
}

function writeCell($service, $spreadsheetId, $sheetName, $rowNumber, $value) {
    $range = "$sheetName!B$rowNumber";
    $body = new ValueRange(['values' => [[$value]]]);
    $params = ['valueInputOption' => 'RAW'];
    $service->spreadsheets_values->update($spreadsheetId, $range, $body, $params);
}

function getSheetIdByName($service, $spreadsheetId, $sheetName) {
    $meta = $service->spreadsheets->get($spreadsheetId);
    foreach ($meta->getSheets() as $sheet) {
        if ($sheet->getProperties()->getTitle() === $sheetName) {
            return $sheet->getProperties()->getSheetId();
        }
    }
    throw new Exception("Sheet name '$sheetName' not found.");
}
?>