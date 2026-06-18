<?php
// backend/core/ExcelHandler.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ExcelHandler
{
    // Map of recognized header aliases → normalized key
    private static array $headerMap = [
        // Phone
        'phone'          => 'phone',
        'mobile'         => 'phone',
        'contact'        => 'phone',
        'ph no'          => 'phone',
        'ph_no'          => 'phone',
        'contact number' => 'phone',
        'mobile number'  => 'phone',
        'mob'            => 'phone',
        'cell'           => 'phone',
        'phone number'   => 'phone',
        'phonenumber'    => 'phone',

        // Name
        'name'           => 'name',
        'full name'      => 'name',
        'fullname'       => 'name',
        'customer name'  => 'name',
        'client name'    => 'name',

        // Email
        'email'          => 'email',
        'email id'       => 'email',
        'email address'  => 'email',

        // Source
        'source'         => 'source',
        'lead source'    => 'source',

        // Campaign
        'campaign'       => 'campaign',

        // City
        'city'           => 'city',
        'location'       => 'city',

        // Project
        'project'        => 'project',
        'property'       => 'project',

        // Hidden Field → Project Name
        'hidden field'   => 'hidden_field',
        'hidden_field'   => 'hidden_field',
        'hiddenfield'    => 'hidden_field',

        // Entry ID
        'entry id'       => 'entry_id',
        'entry_id'       => 'entry_id',
        'entryid'        => 'entry_id',
        'id'             => 'entry_id',

        // Country
        'country'        => 'country',

        // Refer URL
        'refer url'      => 'refer_url',
        'refer_url'      => 'refer_url',
        'referurl'       => 'refer_url',
        'referrer'       => 'refer_url',
        'referrer url'   => 'refer_url',

        // IP Address
        'ip address'     => 'ip_address',
        'ip_address'     => 'ip_address',
        'ipaddress'      => 'ip_address',
        'ip'             => 'ip_address',

        // Device
        'device'         => 'device',
        'browser device' => 'device',
        'user device'    => 'device',
        'platform'       => 'device',
        'source device'  => 'device',

        // NRI
        'nri'            => 'is_nri',
        'is nri'         => 'is_nri',
        'is_nri'         => 'is_nri',
        'lead_nri'       => 'is_nri',
        'lead nri'       => 'is_nri',

        // Status
        'status'         => 'status',

        // Created Time
        'created time'   => 'created_time',
        'created_time'   => 'created_time',
        'createdat'      => 'created_time',
        'created at'     => 'created_time',
        'lead date'      => 'created_time',
        'date created'   => 'created_time',
    ];

    /**
     * Parse uploaded Excel/CSV file into rows with normalized keys.
     */
    public static function parseUpload(string $filePath): array
    {
        // 1. Strict Mapping between Extension and Allowed MIME Types
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);
        $ext   = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $map = [
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'xls'  => ['application/vnd.ms-excel'],
            'csv'  => ['text/csv', 'application/csv', 'text/plain']
        ];

        if (!isset($map[$ext])) {
            throw new \Exception("Unsupported file extension: .$ext. Only .xlsx, .xls, and .csv are allowed.");
        }

        if (!in_array($mime, $map[$ext], true)) {
            throw new \Exception("File content mismatch: The extension .$ext does not match the detected MIME type ($mime).");
        }

        if ($ext === 'csv' && $mime === 'text/plain') {
            $sample = @file_get_contents($filePath, false, null, 0, 512);
            if ($sample && !str_contains($sample, ',') && !str_contains($sample, ';')) {
                throw new \Exception("File content mismatch: The file is pure text and does not appear to be a valid CSV.");
            }
        }

        if ($ext === 'csv') {
            return self::parseCsv($filePath);
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return [];
        }

        // First row = headers
        $rawHeaders = array_shift($rows);
        $headers    = self::normalizeHeaders($rawHeaders);

        $results = [];
        foreach ($rows as $row) {
            $mapped = [];
            foreach ($headers as $colIdx => $normalizedKey) {
                $mapped[$normalizedKey] = $row[$colIdx] ?? null;
            }
            // Skip completely empty rows
            $allEmpty = array_reduce($mapped, fn($carry, $v) => $carry && ($v === null || $v === ''), true);
            if (!$allEmpty) {
                $results[] = $mapped;
            }
        }
        return $results;
    }

    private static function parseCsv(string $filePath): array
    {
        $rows = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            $rawHeaders = null;
            $headers    = [];
            while (($line = fgetcsv($handle)) !== false) {
                if ($rawHeaders === null) {
                    $rawHeaders = $line;
                    $headers = self::normalizeHeaders($rawHeaders);
                    continue;
                }
                $mapped = [];
                foreach ($headers as $i => $key) {
                    $mapped[$key] = $line[$i] ?? null;
                }
                $rows[] = $mapped;
            }
            fclose($handle);
        }
        return $rows;
    }

    private static function normalizeHeaders(array $raw): array
    {
        $result = [];
        foreach ($raw as $i => $h) {
            $lower = strtolower(trim((string)$h));
            $result[$i] = self::$headerMap[$lower] ?? $lower;
        }
        return $result;
    }

    /**
     * Generate a downloadable Excel file for an array of leads.
     */
    public static function generateLeadsExcel(array $leads, string $title = 'Leads'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);

        // Headers
        $headers = ['#', 'Phone', 'Name', 'Email', 'City', 'Project', 'Status', 'Source', 'Remark', 'Assigned Date'];
        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

        // Data rows
        $row = 2;
        foreach ($leads as $i => $lead) {
            $sheet->fromArray([
                $i + 1,
                $lead['phone']        ?? '',
                $lead['name']         ?? '',
                $lead['email']        ?? '',
                $lead['city']         ?? '',
                $lead['project']      ?? '',
                $lead['status']       ?? '',
                $lead['first_source'] ?? '',
                $lead['remark']       ?? '',
                $lead['assigned_at']  ?? '',
            ], null, "A{$row}");

            // Highlight duplicates
            if (!empty($lead['is_duplicate'])) {
                $sheet->getStyle("A{$row}:J{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
                ]);
            }
            $row++;
        }

        // Auto-fit columns
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save to temp file
        $tmpFile = sys_get_temp_dir() . '/lead8x_' . uniqid() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);
        return $tmpFile;
    }

    /**
     * Generate a feedback template Excel for bulk status updates.
     */
    public static function generateFeedbackTemplate(array $leads): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Feedback');

        $headers = ['Phone', 'Status', 'Remark'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ]);

        $row = 2;
        foreach ($leads as $lead) {
            $sheet->fromArray([$lead['phone'], $lead['status'] ?? 'New', ''], null, "A{$row}");
            $row++;
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(40);

        $tmpFile = sys_get_temp_dir() . '/feedback_template_' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpFile);
        return $tmpFile;
    }
}
