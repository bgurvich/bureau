<?php

use App\Support\Csv;

it('skips preamble rows and locks onto the modal-column header', function () {
    // Shape mirrors Costco Anywhere Visa's "Year to date" export — a
    // "Time period of report:" metadata row + blank line before the
    // real column header.
    $body = <<<'CSV'
"Time period of report:","Jan. 01, 2026 (09:00 AM) to Apr. 22, 2026 (09:00 AM)"

Date,Description,Debit,Credit,Category
"Apr 09, 2026","TARGET 00014191 PORTLAND OR","7.78","","Merchandise"
"Jan 18, 2026","IMPERIAL LLC PORTLAND OR","7.99","","Merchandise"
CSV;

    $path = tempnam(sys_get_temp_dir(), 'csv_preamble_');
    file_put_contents($path, $body);

    try {
        $parsed = Csv::parse($path);

        expect($parsed['headers'])->toBe(['Date', 'Description', 'Debit', 'Credit', 'Category'])
            ->and($parsed['rows'])->toHaveCount(2)
            ->and($parsed['rows'][0]['Description'])->toBe('TARGET 00014191 PORTLAND OR')
            ->and($parsed['rows'][0]['Debit'])->toBe('7.78');
    } finally {
        @unlink($path);
    }
});

it('treats the first row as headers when there is no preamble', function () {
    $body = "Date,Description,Amount\n03/05/2026,COFFEE,-4.50\n03/06/2026,REFUND,5.00\n";
    $path = tempnam(sys_get_temp_dir(), 'csv_noamble_');
    file_put_contents($path, $body);

    try {
        $parsed = Csv::parse($path);

        expect($parsed['headers'])->toBe(['Date', 'Description', 'Amount'])
            ->and($parsed['rows'])->toHaveCount(2);
    } finally {
        @unlink($path);
    }
});
