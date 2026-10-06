<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Writes a report (ReportService shape) to a right-to-left .xlsx file.
 * Money columns are real numbers so Excel can sum and sort them.
 */
final class ExcelExporter
{
    /**
     * @param  array{columns: array<string, string>, rows: iterable<array<string, mixed>>, totals: array<string, mixed>, money: array<int, string>}  $report
     */
    public static function download(array $report, string $title, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $keys = array_keys($report['columns']);
        $decimals = (int) settings('currency.decimals', 0);
        $moneyStyle = (new Style)->setFormat($decimals > 0 ? '#,##0.'.str_repeat('0', $decimals) : '#,##0');
        $boldMoney = (new Style)->setFontBold()->setFormat($moneyStyle->getFormat());

        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr($title, 0, 31));
        $sheet->setSheetView((new SheetView)->setRightToLeft(true));
        $sheet->setColumnWidthForRange(20, 1, max(1, count($keys)));

        $writer->addRow(Row::fromValues([$title], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([settings('store.name').' — '.now()->format('Y-m-d H:i')]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_values($report['columns']), (new Style)->setFontBold()->setBackgroundColor('E5E7EB')));

        foreach ($report['rows'] as $row) {
            $writer->addRow(new Row(array_map(
                fn (string $key) => self::cell($row[$key] ?? '', in_array($key, $report['money'], true), $moneyStyle),
                $keys,
            )));
        }

        if ($report['totals'] !== []) {
            $cells = [];
            foreach ($keys as $i => $key) {
                $cells[] = match (true) {
                    $i === 0 => Cell::fromValue('الإجمالي', (new Style)->setFontBold()),
                    array_key_exists($key, $report['totals']) => self::cell($report['totals'][$key], in_array($key, $report['money'], true), $boldMoney),
                    default => Cell::fromValue(''),
                };
            }
            $writer->addRow(new Row($cells));
        }

        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private static function cell(mixed $value, bool $money, Style $moneyStyle): Cell
    {
        if ($money && is_numeric($value)) {
            return Cell::fromValue((float) $value, $moneyStyle);
        }
        if (is_int($value)) {
            return Cell::fromValue($value);
        }

        return Cell::fromValue((string) $value);
    }
}
