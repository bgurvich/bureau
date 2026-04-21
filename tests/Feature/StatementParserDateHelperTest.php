<?php

use App\Support\Statements\Parsers\Csv\CsvParserHelpers;
use App\Support\Statements\Parsers\Pdf\PdfParserHelpers;

// Regression: Carbon::parse('') and Carbon::parse(null) silently return
// TODAY. Both helpers used to fall through to that branch when the format
// loop failed, so any parser piping an empty/null date cell into them
// wrote today's date into Transaction.occurred_on — making every import
// land in the current month regardless of the statement period. We
// short-circuit empty inputs to null now.

it('PDF date helper returns null, not today, for an empty string', function () {
    $parser = new class
    {
        use PdfParserHelpers {
            PdfParserHelpers::date as public callDate;
        }
    };

    expect($parser::callDate(''))->toBeNull()
        ->and($parser::callDate('   '))->toBeNull()
        ->and($parser::callDate('1/22/2026')?->toDateString())->toBe('2026-01-22')
        ->and($parser::callDate('not a date'))->toBeNull();
});

it('CSV date helper returns null, not today, for an empty or null cell', function () {
    $parser = new class
    {
        use CsvParserHelpers {
            CsvParserHelpers::date as public callDate;
        }
    };

    expect($parser->callDate(null))->toBeNull()
        ->and($parser->callDate(''))->toBeNull()
        ->and($parser->callDate('   '))->toBeNull()
        ->and($parser->callDate('1/22/2026')?->toDateString())->toBe('2026-01-22')
        ->and($parser->callDate('not a date'))->toBeNull();
});
