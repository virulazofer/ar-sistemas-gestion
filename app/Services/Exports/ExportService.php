<?php

namespace App\Services\Exports;

use App\Services\AuditLogger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>|array<string, mixed>>  $rows
     */
    public function toCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        $filename = $this->ensureExtension($filename, 'csv');

        $response = response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, $this->normalizeRow($row, $headers), ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);

        $this->audit->log('export_generated', null, null, [
            'format' => 'csv',
            'filename' => $filename,
            'rows' => count($rows),
        ], 'Exportación CSV generada');

        return $response;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>|array<string, mixed>>  $rows
     */
    public function toXlsx(string $filename, array $headers, array $rows): BinaryFileResponse
    {
        $filename = $this->ensureExtension($filename, 'xlsx');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Datos');

        foreach (array_values($headers) as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        $r = 2;
        foreach ($rows as $row) {
            $values = $this->normalizeRow($row, $headers);
            foreach (array_values($values) as $col => $value) {
                $sheet->setCellValue([$col + 1, $r], $value);
            }
            $r++;
        }

        $temp = tempnam(sys_get_temp_dir(), 'export_');
        if ($temp === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para Excel.');
        }
        $tempXlsx = $temp.'.xlsx';
        @unlink($temp);

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempXlsx);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $this->audit->log('export_generated', null, null, [
            'format' => 'xlsx',
            'filename' => $filename,
            'rows' => count($rows),
        ], 'Exportación Excel generada');

        return response()->download($tempXlsx, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>|array<string, mixed>>  $rows
     */
    public function toPdf(string $title, array $headers, array $rows): Response
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildPdfHtml($title, $headers, $rows));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title) ?: 'reporte';
        $filename = $safeName.'.pdf';

        $this->audit->log('export_generated', null, null, [
            'format' => 'pdf',
            'filename' => $filename,
            'rows' => count($rows),
        ], 'Exportación PDF generada');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>|array<string, mixed>>  $rows
     */
    private function buildPdfHtml(string $title, array $headers, array $rows): string
    {
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $thead = '';
        foreach ($headers as $h) {
            $thead .= '<th>'.$esc($h).'</th>';
        }

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($this->normalizeRow($row, $headers) as $cell) {
                $tbody .= '<td>'.$esc($cell).'</td>';
            }
            $tbody .= '</tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
  h1 { font-size: 16px; margin: 0 0 12px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; }
  th { background: #eee; font-weight: bold; }
  tr:nth-child(even) td { background: #f8f8f8; }
</style>
</head>
<body>
  <h1>{$esc($title)}</h1>
  <table>
    <thead><tr>{$thead}</tr></thead>
    <tbody>{$tbody}</tbody>
  </table>
</body>
</html>
HTML;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $row
     * @param  list<string>  $headers
     * @return list<mixed>
     */
    private function normalizeRow(array $row, array $headers): array
    {
        if (array_is_list($row)) {
            return array_values($row);
        }

        $out = [];
        foreach ($headers as $key) {
            // Preferir claves exactas; si no, valores en orden de keys del row
            $out[] = $row[$key] ?? '';
        }

        // Si ninguna clave coincidió, usar valores en orden
        if (count(array_filter($out, fn ($v) => $v !== '' && $v !== null)) === 0 && $row !== []) {
            return array_values($row);
        }

        return $out;
    }

    private function ensureExtension(string $filename, string $ext): string
    {
        if (! str_ends_with(strtolower($filename), '.'.$ext)) {
            return $filename.'.'.$ext;
        }

        return $filename;
    }
}
