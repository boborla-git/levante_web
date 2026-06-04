<?php
declare(strict_types=1);

function levanteXlsxText(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function levanteXlsxColumnName(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function levanteXlsxCell(string $value, int $row, int $col, int $style = 0): string
{
    $ref = levanteXlsxColumnName($col) . $row;
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t>' . levanteXlsxText($value) . '</t></is></c>';
}

function levanteXlsxRow(array $values, int $row, int $style = 0): string
{
    $cells = '';
    $col = 1;
    foreach ($values as $value) {
        $cells .= levanteXlsxCell((string)$value, $row, $col, $style);
        $col++;
    }
    return '<row r="' . $row . '">' . $cells . '</row>';
}

function levanteOutputXlsx(string $fileBaseName, string $sheetName, array $headers, array $rows, array $columnWidths = []): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'Impossibile generare il file XLSX: estensione ZipArchive non disponibile sul server.';
        exit;
    }

    $safeSheetName = preg_replace('/[\\\/\?\*\[\]:]/', ' ', $sheetName);
    $safeSheetName = substr((string)$safeSheetName, 0, 31);
    if ($safeSheetName === '') {
        $safeSheetName = 'Foglio1';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'levante_xlsx_');
    if ($tmp === false) {
        http_response_code(500);
        echo 'Impossibile creare il file temporaneo XLSX.';
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Impossibile creare il file XLSX.';
        exit;
    }

    $sheetRows = levanteXlsxRow($headers, 1, 1);
    $rowNumber = 2;
    foreach ($rows as $row) {
        $sheetRows .= levanteXlsxRow($row, $rowNumber);
        $rowNumber++;
    }

    $colsXml = '';
    foreach ($columnWidths as $idx => $width) {
        $col = (int)$idx + 1;
        $colsXml .= '<col min="' . $col . '" max="' . $col . '" width="' . (float)$width . '" customWidth="1"/>';
    }
    if ($colsXml !== '') {
        $colsXml = '<cols>' . $colsXml . '</cols>';
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . levanteXlsxText($safeSheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $colsXml . '<sheetData>' . $sheetRows . '</sheetData></worksheet>');
    $zip->close();

    $fileName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $fileBaseName) . '_' . date('Y-m-d_H-i-s') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
