<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * CSV downloads for the reports.
 *
 * Streamed rather than built in memory. A year's sales or a full valuation is
 * tens of thousands of rows, and the shop's VPS has 1 GB of RAM shared with
 * MySQL - assembling the whole file as a string first is how the export
 * becomes a 502 exactly when the accountant needs it.
 *
 * Two details that look like superstition but are not:
 *
 *   - The UTF-8 BOM. Excel on Windows reads a BOM-less UTF-8 file as the local
 *     code page, which turns every Urdu shop name and every em dash into
 *     mojibake. The till is a Windows machine and these files are opened in
 *     Excel, so the BOM goes in.
 *
 *   - Formula injection. A value beginning =, +, - or @ is executed by Excel
 *     when the file is opened. Item and customer names are free text typed by
 *     staff, so they are prefixed with a tab, which Excel drops on display but
 *     which stops it treating the cell as a formula.
 */
class Csv
{
    /**
     * @param  list<string>  $headers  the first row, written verbatim
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::escape(...), $row));

                // Hand each row to the browser as it is written rather than
                // letting PHP's buffer decide. On a long export this is the
                // difference between a download that starts immediately and
                // one that looks hung until it finishes.
                flush();
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Neutralise a leading character Excel would read as the start of a formula. */
    private static function escape(string|int|float|null $value): string
    {
        $value = (string) $value;

        // A negative amount starts with '-' and must stay a number, or every
        // shortfall in a variance column arrives in Excel as text and will not
        // add up. Numbers are never formulas, so they are left alone.
        if (is_numeric($value)) {
            return $value;
        }

        if ($value !== '' && str_contains('=+-@', $value[0])) {
            return "\t".$value;
        }

        return $value;
    }
}
