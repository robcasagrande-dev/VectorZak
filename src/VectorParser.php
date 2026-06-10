<?php
namespace VectorZak;

use PhpOffice\PhpSpreadsheet\IOFactory;

class VectorParser {
    public function parse($filePath) {
        // Since we don't have composer installed locally, check if class exists
        // In the real cPanel environment, vendor/autoload.php will be loaded
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new \Exception("PhpSpreadsheet library is missing. Did you run 'composer install'?");
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $headerMap = [];
        $data = [];
        $errors = [];
        
        $isFirstRow = true;
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(FALSE); 
            
            $rowData = [];
            foreach ($cellIterator as $cell) {
                // In a real scenario, we might need to handle Excel dates correctly
                // using PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()
                // but we will stick to string values for now.
                $rowData[] = $cell->getFormattedValue(); 
            }
            
            if ($isFirstRow) {
                foreach ($rowData as $index => $value) {
                    $headerMap[trim($value)] = $index;
                }
                $isFirstRow = false;
                continue;
            }
            
            // Extract using headerMap
            $roomRaw = $this->getVal($rowData, $headerMap, 'Nombre Cliente');
            $invoice = $this->getVal($rowData, $headerMap, 'Factura');
            $amountRaw = $this->getVal($rowData, $headerMap, 'Total Pagado');
            $date = $this->getVal($rowData, $headerMap, 'Fecha');
            $time = $this->getVal($rowData, $headerMap, 'Hrs');
            $waiter = $this->getVal($rowData, $headerMap, 'Vendedor');
            $table = $this->getVal($rowData, $headerMap, 'Mesa');
            
            // Clean amount and convert to float
            $amount = (float) preg_replace('/[^0-9.]/', '', $amountRaw);
            
            // Rule: Check < 1000 silently ignore
            if ($amount < 1000) {
                continue;
            }
            
            // Rule: Validate Room Identifier
            // Simple validation: must not be empty and should have some numeric room indicator
            // The prompt gave examples like "16-HABITACION" or "16 - HABITACION"
            if (empty($roomRaw) || !preg_match('/\d+/', $roomRaw)) {
                $errors[] = "Fila saltada (Factura $invoice): Falta el Identificador de Habitación o formato inválido ('$roomRaw').";
                continue;
            }
            
            // Clean up the room identifier to just the number for easier API matching (optional, depends on PMS)
            preg_match('/\d+/', $roomRaw, $matches);
            $roomNumber = $matches[0] ?? $roomRaw;
            
            $data[] = [
                'room_raw' => $roomRaw,
                'room_number' => $roomNumber,
                'invoice' => $invoice,
                'amount' => $amount,
                'date' => $date,
                'time' => $time,
                'waiter' => $waiter,
                'table' => $table
            ];
        }
        
        return [
            'data' => $data,
            'errors' => $errors
        ];
    }
    
    private function getVal($row, $headerMap, $colName) {
        if (isset($headerMap[$colName]) && isset($row[$headerMap[$colName]])) {
            return trim($row[$headerMap[$colName]]);
        }
        return '';
    }
}
