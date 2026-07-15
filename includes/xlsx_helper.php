<?php
/**
 * 轻量级 Excel (.xlsx) 读写工具
 * 纯PHP实现，使用内置 ZipArchive + SimpleXML，无外部依赖
 */

/**
 * 生成 .xlsx 文件并下载
 * @param array $headers 表头 ['A列名','B列名',...]
 * @param array $rows 数据行 [['A','B',...],...]
 * @param string $filename 下载文件名
 */
function xlsx_export($headers, $rows, $filename = 'export.xlsx') {
    // ========== 阶段1：输出缓冲彻底清理 ==========
    // 递归关闭所有输出缓冲并丢弃内容（防止PHP错误/警告混入xlsx二进制）
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    // 开启一个新的输出缓冲，确保任何后续意外输出都被捕获
    @ob_start();

    // ========== 阶段2：构建工作表XML ==========
    $spreadsheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';

    // 表头行
    $spreadsheet .= '<row r="1">';
    foreach ($headers as $ci => $h) {
        $cell = chr(65 + $ci) . '1';
        $spreadsheet .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . xml_safe($h) . '</t></is></c>';
    }
    $spreadsheet .= '</row>';

    // 数据行
    foreach ($rows as $ri => $row) {
        $r = $ri + 2;
        $spreadsheet .= '<row r="' . $r . '">';
        foreach ($row as $ci => $val) {
            $cell = chr(65 + $ci) . $r;
            if (is_numeric($val) && !is_string_numeric_looking($val)) {
                $spreadsheet .= '<c r="' . $cell . '"><v>' . $val . '</v></c>';
            } else {
                $spreadsheet .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . xml_safe((string)$val) . '</t></is></c>';
            }
        }
        $spreadsheet .= '</row>';
    }

    $spreadsheet .= '</sheetData></worksheet>';

    // ========== 阶段3：构建xlsx（ZIP包） ==========
    $tmpDir = __DIR__ . '/../uploads/tmp/';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $tmpFile = $tmpDir . 'xlsx_' . uniqid() . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @ob_end_clean();
        die('无法创建Excel文件');
    }

    // [Content_Types].xml — 必须包含workbook和styles的声明
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');

    // _rels/.rels — 包级关系
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    // xl/_rels/workbook.xml.rels — 文档级关系
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');

    // xl/workbook.xml
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>');

    // xl/worksheets/sheet1.xml
    $zip->addFromString('xl/worksheets/sheet1.xml', $spreadsheet);

    // xl/sharedStrings.xml
    $zip->addFromString('xl/sharedStrings.xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"/>');

    // xl/styles.xml — 必要的最小样式表
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>');

    $zip->close();

    // ========== 阶段4：输出文件 ==========
    $fileSize = filesize($tmpFile);
    @ob_clean(); // 清空缓冲区

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($tmpFile);
    @unlink($tmpFile);

    @ob_end_flush();
    exit;
}

/**
 * 读取 .xlsx 文件并返回 [headers, rows]
 * @param string $filePath 上传文件临时路径
 * @return array ['headers'=>[...], 'rows'=>[[...],...]]
 */
function xlsx_import($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception('无法打开Excel文件');
    }

    // 读取共享字符串表
    $sharedStrings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $xml = simplexml_load_string($ss);
        $ns = $xml->getNamespaces(true);
        $ssXml = $xml->children($ns[''] ?? '');
        foreach ($ssXml->si as $si) {
            $sharedStrings[] = (string)($si->t ?? '');
        }
    }

    // 读取工作表
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheet) {
        $zip->close();
        throw new Exception('未找到工作表数据');
    }

    $xml = simplexml_load_string($sheet);
    $ns = $xml->getNamespaces(true);
    $rows = $xml->children($ns[''] ?? '')->sheetData->row ?? [];

    $result = ['headers' => [], 'rows' => []];
    $isFirst = true;

    foreach ($rows as $row) {
        $rowData = [];
        foreach ($row->c as $c) {
            $r = (string)$c['r'];
            $col = preg_replace('/[0-9]/', '', $r);
            $colIdx = ord($col) - 65;

            // 填充空列
            while (count($rowData) < $colIdx) {
                $rowData[] = '';
            }

            $t = (string)($c['t'] ?? '');
            if ($t === 's') {
                $idx = (int)($c->v ?? 0);
                $rowData[] = $sharedStrings[$idx] ?? '';
            } elseif ($t === 'inlineStr') {
                $rowData[] = (string)($c->is->t ?? '');
            } else {
                $rowData[] = (string)($c->v ?? '');
            }
        }

        if ($isFirst) {
            $result['headers'] = $rowData;
            $isFirst = false;
        } else {
            // 跳过全空行
            if (implode('', $rowData) !== '') {
                $result['rows'][] = $rowData;
            }
        }
    }

    $zip->close();
    return $result;
}

function xml_safe($s) {
    return htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
}

function is_string_numeric_looking($v) {
    if (is_string($v) && strlen($v) > 0 && $v[0] === '0' && strlen($v) > 1 && $v[1] !== '.') return true;
    return false;
}
