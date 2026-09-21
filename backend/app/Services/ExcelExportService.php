<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Movement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ExcelExportService
{
    public const HEADERS = [
        'Movement ID',
        'Verification ID',
        'Student Name',
        'Roll Number',
        'Student ID',
        'Student Category',
        'Year',
        'Program',
        'Branch/Department',
        'Movement Type',
        'Movement Source',
        'Late Status',
        'Late Window Date',
        'Day Scholar After Hours',
        'Server Date (IST)',
        'Server Time (IST)',
        'Gate',
        'Destination',
        'Purpose',
        'Vehicle Present',
        'Vehicle Number',
        'Security Guard Name',
        'Security Guard ID',
        'Created At',
    ];

    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Export movements as a genuine OpenXML .xlsx workbook.
     * Uses ZipArchive and temp streaming to handle large datasets memory-safely.
     */
    public function exportXlsx(Builder $query, array $filters = [], ?User $adminUser = null, ?string $ip = null, ?string $userAgent = null): BinaryFileResponse
    {
        $filename = $this->generateFilename($filters, 'xlsx');

        // Create temporary directory for building XLSX package
        $tempZipPath = tempnam(sys_get_temp_dir(), 'sg_xlsx_') . '.xlsx';
        $tempSheetPath = tempnam(sys_get_temp_dir(), 'sg_sheet_') . '.xml';

        // 1. Write worksheet XML to temp file in streaming chunks
        $sheetHandle = fopen($tempSheetPath, 'w');
        fwrite($sheetHandle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        fwrite($sheetHandle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n");
        fwrite($sheetHandle, '  <sheetData>' . "\n");

        // Header Row (r=1, style s=1 for bold header with dark fill)
        fwrite($sheetHandle, '    <row r="1">' . "\n");
        foreach (self::HEADERS as $colIdx => $header) {
            $colLetter = $this->colLetter($colIdx);
            $cellRef = "{$colLetter}1";
            $escaped = htmlspecialchars($header, ENT_QUOTES | ENT_XML1, 'UTF-8');
            fwrite($sheetHandle, "      <c r=\"{$cellRef}\" s=\"1\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>\n");
        }
        fwrite($sheetHandle, '    </row>' . "\n");

        // Data Rows
        $rowCount = 0;
        $rowNum = 2;

        (clone $query)->chunk(200, function ($records) use ($sheetHandle, &$rowNum, &$rowCount) {
            foreach ($records as $m) {
                $rowCount++;
                $rowValues = $this->formatMovementRow($m);

                fwrite($sheetHandle, "    <row r=\"{$rowNum}\">\n");
                foreach ($rowValues as $colIdx => $val) {
                    $colLetter = $this->colLetter($colIdx);
                    $cellRef = "{$colLetter}{$rowNum}";
                    $cleanVal = $val ?? '';
                    $escaped = htmlspecialchars((string) $cleanVal, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    fwrite($sheetHandle, "      <c r=\"{$cellRef}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>\n");
                }
                fwrite($sheetHandle, "    </row>\n");
                $rowNum++;
            }
        });

        fwrite($sheetHandle, '  </sheetData>' . "\n");
        fwrite($sheetHandle, '</worksheet>' . "\n");
        fclose($sheetHandle);

        // 2. Package into OpenXML ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempSheetPath);
            throw new \RuntimeException('Unable to create temporary XLSX file.');
        }

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n"
            . '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n"
            . '  <Default Extension="xml" ContentType="application/xml"/>' . "\n"
            . '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n"
            . '  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n"
            . '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n"
            . '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
            . '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n"
            . '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
            . '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' . "\n"
            . '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . "\n"
            . '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n"
            . '  <sheets>' . "\n"
            . '    <sheet name="Movements" sheetId="1" r:id="rId1"/>' . "\n"
            . '  </sheets>' . "\n"
            . '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // xl/styles.xml
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n"
            . '  <fonts count="2">' . "\n"
            . '    <font><sz val="11"/><name val="Calibri"/></font>' . "\n"
            . '    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' . "\n"
            . '  </fonts>' . "\n"
            . '  <fills count="3">' . "\n"
            . '    <fill><patternFill patternType="none"/></fill>' . "\n"
            . '    <fill><patternFill patternType="gray125"/></fill>' . "\n"
            . '    <fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>' . "\n"
            . '  </fills>' . "\n"
            . '  <borders count="1">' . "\n"
            . '    <border><left/><right/><top/><bottom/></border>' . "\n"
            . '  </borders>' . "\n"
            . '  <cellStyleXfs count="1">' . "\n"
            . '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . "\n"
            . '  </cellStyleXfs>' . "\n"
            . '  <cellXfs count="2">' . "\n"
            . '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n"
            . '    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' . "\n"
            . '  </cellXfs>' . "\n"
            . '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // Add worksheet from stream file
        $zip->addFile($tempSheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        // Remove temp worksheet XML file
        @unlink($tempSheetPath);

        // 3. Log Audit Event
        $this->logExportAudit('xlsx', $filters, $rowCount, $adminUser, $ip, $userAgent);

        return response()->download($tempZipPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export movements as CSV streaming response for backward compatibility.
     */
    public function exportCsv(Builder $query, array $filters = [], ?User $adminUser = null, ?string $ip = null, ?string $userAgent = null): StreamedResponse
    {
        $filename = $this->generateFilename($filters, 'csv');

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        // We count records while streaming
        $rowCount = 0;

        $response = response()->stream(function () use ($query, &$rowCount) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel CSV compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, self::HEADERS);

            (clone $query)->chunk(200, function ($records) use ($handle, &$rowCount) {
                foreach ($records as $m) {
                    $rowCount++;
                    fputcsv($handle, $this->formatMovementRow($m));
                }
            });

            fclose($handle);
        }, 200, $headers);

        // Log audit event
        $this->logExportAudit('csv', $filters, null, $adminUser, $ip, $userAgent);

        return $response;
    }

    /**
     * Format a single movement row array matching HEADERS.
     */
    protected function formatMovementRow(Movement $m): array
    {
        $timestamp = Carbon::parse($m->server_timestamp)->setTimezone('Asia/Kolkata');
        $student = $m->student;
        $gate = $m->gate;
        $guard = $m->securityUser;

        return [
            $m->id,
            $m->verification_code,
            $student?->name ?? 'N/A',
            $student?->roll_number ?? 'N/A',
            $student?->student_id ?? 'N/A',
            $student?->student_type ?? ($student?->category ?? 'HOSTELLER'),
            $student?->year ?? '-',
            $student?->program ?? '-',
            $student?->department ?? '-',
            $m->type,
            $m->movement_source ?? 'QR',
            $m->is_late ? 'LATE' : 'NORMAL',
            $m->late_window_date ? Carbon::parse($m->late_window_date)->format('d-m-Y') : '-',
            $m->day_scholar_after_hours ? 'YES' : 'NO',
            $timestamp->format('d-m-Y'),
            $timestamp->format('h:i:s A'),
            $gate?->name ?? 'N/A',
            $m->destination ?? '-',
            $m->purpose ?? '-',
            $m->vehicle_present ? 'YES' : 'NO',
            $m->vehicle_number ?? '-',
            $guard?->name ?? '-',
            $guard?->id ?? '-',
            $m->created_at ? Carbon::parse($m->created_at)->setTimezone('Asia/Kolkata')->format('d-m-Y h:i:s A') : '-',
        ];
    }

    /**
     * Calculate Excel column letter (0 -> A, 25 -> Z, 26 -> AA).
     */
    protected function colLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex >= 0) {
            $letter = chr($colIndex % 26 + 65) . $letter;
            $colIndex = intdiv($colIndex, 26) - 1;
        }
        return $letter;
    }

    /**
     * Generate descriptive filename based on active filters.
     */
    public function generateFilename(array $filters, string $ext): string
    {
        $isLateFilter = !empty($filters['late']) && in_array(strtoupper((string) $filters['late']), ['1', 'TRUE', 'LATE', 'YES'], true);
        $prefix = $isLateFilter ? 'smartgate_late_entries' : 'smartgate_movements';

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $dateRange = "{$filters['start_date']}_to_{$filters['end_date']}";
        } elseif (!empty($filters['date'])) {
            $dateRange = (string) $filters['date'];
        } elseif (!empty($filters['late_window_date'])) {
            $dateRange = "late_window_{$filters['late_window_date']}";
        } else {
            $dateRange = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        }

        return "{$prefix}_{$dateRange}.{$ext}";
    }

    /**
     * Log ADMIN_MOVEMENT_EXPORT audit record.
     */
    protected function logExportAudit(string $format, array $filters, ?int $rowCount, ?User $adminUser, ?string $ip, ?string $userAgent): void
    {
        $this->auditService->log(
            action: 'ADMIN_MOVEMENT_EXPORT',
            module: 'MOVEMENT',
            status: AuditLog::STATUS_SUCCESS,
            metadata: [
                'format' => strtoupper($format),
                'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
                'record_count' => $rowCount,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'requested_at' => now('Asia/Kolkata')->toIso8601String(),
            ],
            user: $adminUser
        );
    }
}
