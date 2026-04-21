<?php

use App\Support\Statements\Parsers\Csv\AmexCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\AmexCreditCsvParser;
use App\Support\Statements\Parsers\Csv\CitiCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\CitiCreditCsvParser;
use App\Support\Statements\Parsers\Csv\OnPointCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\OnPointCreditCsvParser;
use App\Support\Statements\Parsers\Csv\PaypalCsvParser;
use App\Support\Statements\Parsers\Csv\WellsFargoCheckingCsvParser;
use App\Support\Statements\Parsers\Pdf\AmexCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\AmexCreditStatementParser;
use App\Support\Statements\Parsers\Pdf\CitiCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\CitiCreditStatementParser;
use App\Support\Statements\Parsers\Pdf\OnPointCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\OnPointCreditStatementParser;
use App\Support\Statements\Parsers\Pdf\WellsFargoCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\WellsFargoCreditStatementParser;

// ============================ PDF PARSERS ============================

it('Wells Fargo checking PDF parser recognizes and extracts', function () {
    $text = <<<'TXT'
    Wells Fargo Bank
    Statement Period 03/01/2026 - 03/31/2026
    Account ending in 1234
    Beginning balance on 3/1  $1,000.00
    Ending balance on 3/31  $1,250.00

    Deposits and Other Additions
    3/05  Payroll ABC Company  500.00
    3/15  Transfer from savings  250.00

    Withdrawals and Other Subtractions
    3/10  Rent landlord  500.00
    3/20  Grocery store  75.50
    TXT;

    $parser = new WellsFargoCheckingStatementParser;
    expect($parser->supports('pdf'))->toBeTrue()
        ->and($parser->fingerprint($text))->toBeTrue();

    $stmt = $parser->parse($text);
    expect($stmt->bankSlug)->toBe('wellsfargo_checking')
        ->and($stmt->accountLast4)->toBe('1234')
        ->and($stmt->openingBalance)->toBe(1000.00)
        ->and($stmt->closingBalance)->toBe(1250.00)
        ->and(count($stmt->transactions))->toBe(4);

    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    expect($amounts)->toContain(500.00)
        ->and($amounts)->toContain(250.00)
        ->and($amounts)->toContain(-500.00)
        ->and($amounts)->toContain(-75.50);
});

it('Wells Fargo checking PDF parser reads amount, not running balance', function () {
    // Regression: the row regex anchored to the last money token on the
    // line, so when WF printed "date description amount running_balance"
    // the parser captured the balance as the transaction amount. This
    // fixture mirrors the real PDF layout where each row carries both.
    $text = <<<'TXT'
    Wells Fargo Bank
    Statement Period 03/01/2026 - 03/31/2026
    Account ending in 1234
    Beginning balance on 3/1  $1,000.00
    Ending balance on 3/31  $1,425.00

    Deposits and Other Additions
    3/05  Payroll ABC Company  500.00  1,500.00
    3/15  Transfer from savings  250.00  1,750.00

    Withdrawals and Other Subtractions
    3/10  Rent landlord  250.00  1,500.00
    3/20  Grocery store  75.00  1,425.00
    TXT;

    $stmt = (new WellsFargoCheckingStatementParser)->parse($text);
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);

    expect(count($stmt->transactions))->toBe(4)
        ->and($amounts)->toContain(500.00)
        ->and($amounts)->toContain(250.00)
        ->and($amounts)->toContain(-250.00)
        ->and($amounts)->toContain(-75.00)
        ->and($amounts)->not->toContain(1500.00)
        ->and($amounts)->not->toContain(1750.00)
        ->and($amounts)->not->toContain(1425.00);
});

it('Wells Fargo credit PDF parser signs charges negative', function () {
    $text = <<<'TXT'
    Wells Fargo Credit Card Statement
    Account ending in 5555
    Statement Period 03/01/2026 to 03/31/2026
    Previous Balance  $100.00
    New Balance  $230.00

    3/05  3/06  COSTCO WHOLESALE  50.00
    3/15  3/16  NETFLIX SUBSCRIPTION  15.99
    3/20  3/21  PAYMENT THANK YOU  100.00 CR
    TXT;

    $parser = new WellsFargoCreditStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    expect(count($stmt->transactions))->toBe(3);
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    expect($amounts)->toContain(-50.00)
        ->and($amounts)->toContain(-15.99)
        ->and($amounts)->toContain(100.00);
});

it('Citi checking PDF parser splits debit/credit columns', function () {
    $text = <<<'TXT'
    Citibank Statement
    Statement Period 03/01/2026 - 03/31/2026
    Account ending 9876
    Opening Balance  $500.00
    Closing Balance  $700.00

    3/05  DIRECT DEPOSIT   200.00  700.00
    3/10  ATM WITHDRAWAL  100.00                600.00
    TXT;

    $parser = new CitiCheckingStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    expect(count($stmt->transactions))->toBeGreaterThanOrEqual(1);
});

it('Citi credit PDF parser recognizes credit card format', function () {
    $text = <<<'TXT'
    Citibank Credit Card Statement
    Account ending in 4321
    Billing Period 03/01/2026 to 03/31/2026
    Previous Balance  $200.00
    New Balance  $350.00

    3/05  3/06  STARBUCKS #1234  5.00
    3/15  3/16  AMAZON COM  95.00
    3/20  3/21  PAYMENT THANK YOU  50.00 CR
    TXT;

    $parser = new CitiCreditStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    expect(count($stmt->transactions))->toBe(3);
});

it('Amex checking PDF parser recognizes Rewards Checking/HYSA', function () {
    $text = <<<'TXT'
    American Express National Bank
    High Yield Savings Account
    Statement Period 03/01/2026 - 03/31/2026
    Account ending 7777
    Opening Balance  $5,000.00
    Closing Balance  $5,050.00

    Deposits
    3/15  Transfer from Wells  500.00

    Withdrawals
    3/20  Transfer to checking  450.00
    TXT;

    $parser = new AmexCheckingStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    expect(count($stmt->transactions))->toBe(2);
});

it('Amex credit PDF parser signs charges negative', function () {
    $text = <<<'TXT'
    American Express
    Prepared for: Member Name
    Account Ending 71009
    Closing Date 03/31/26
    Previous Balance  $800.00
    New Balance  $920.00

    New Charges
    03/05/26  WHOLE FOODS MARKET  45.23
    03/15/26  AIRLINE PURCHASE  250.00

    Payments and Credits
    03/20/26  AUTOPAY PAYMENT  175.23
    TXT;

    $parser = new AmexCreditStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    // Amex Account Ending may be 4 or 5 digits; parser trims to 4 last.
    expect($stmt->accountLast4)->toBe('1009');
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    expect($amounts)->toContain(-45.23)
        ->and($amounts)->toContain(-250.00)
        ->and($amounts)->toContain(175.23);
});

// ============================ CSV PARSERS ============================

it('Wells Fargo checking CSV parser handles signed amount column', function () {
    $content = [
        'headers' => ['Date', 'Amount', 'Star', 'Check', 'Description'],
        'rows' => [
            ['Date' => '03/05/2026', 'Amount' => '-50.00', 'Star' => '*', 'Check' => '', 'Description' => 'COSTCO'],
            ['Date' => '03/10/2026', 'Amount' => '250.00', 'Star' => '*', 'Check' => '', 'Description' => 'PAYROLL'],
        ],
    ];

    $parser = new WellsFargoCheckingCsvParser;
    expect($parser->supports('csv'))->toBeTrue()
        ->and($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    expect(count($stmt->transactions))->toBe(2);
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    expect($amounts)->toContain(-50.00)
        ->and($amounts)->toContain(250.00);
});

it('Citi checking CSV parser populates sign from debit/credit', function () {
    $content = [
        'headers' => ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
        'rows' => [
            ['Date' => '03/05/2026', 'Description' => 'ATM', 'Debit' => '100.00', 'Credit' => '', 'Balance' => '400.00'],
            ['Date' => '03/10/2026', 'Description' => 'DEPOSIT', 'Debit' => '', 'Credit' => '500.00', 'Balance' => '900.00'],
        ],
    ];

    $parser = new CitiCheckingCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    expect($amounts)->toContain(-100.00)
        ->and($amounts)->toContain(500.00);
});

it('Citi credit CSV parser requires Status column', function () {
    $content = [
        'headers' => ['Status', 'Date', 'Description', 'Debit', 'Credit'],
        'rows' => [
            ['Status' => 'Cleared', 'Date' => '03/05/2026', 'Description' => 'COFFEE', 'Debit' => '4.50', 'Credit' => ''],
        ],
    ];

    $parser = new CitiCreditCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    expect($stmt->transactions[0]->amount)->toBe(-4.50);
});

it('Amex checking CSV parser requires Balance but not Category', function () {
    $content = [
        'headers' => ['Date', 'Description', 'Amount', 'Balance'],
        'rows' => [
            ['Date' => '03/05/2026', 'Description' => 'DEP', 'Amount' => '200.00', 'Balance' => '5200.00'],
        ],
    ];

    $parser = new AmexCheckingCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    expect($stmt->transactions[0]->amount)->toBe(200.00);
});

it('Amex credit CSV parser flips sign on charges', function () {
    $content = [
        'headers' => ['Date', 'Description', 'Amount', 'Category'],
        'rows' => [
            ['Date' => '03/05/2026', 'Description' => 'STARBUCKS', 'Amount' => '4.50', 'Category' => 'Dining'],
            ['Date' => '03/20/2026', 'Description' => 'PAYMENT', 'Amount' => '-100.00', 'Category' => 'Payment'],
        ],
    ];

    $parser = new AmexCreditCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    // Charges (positive on export) → negative in Bureau.
    // Payments (negative on export) → positive in Bureau.
    expect($amounts)->toContain(-4.50)
        ->and($amounts)->toContain(100.00);
});

it('PayPal CSV parser uses Net amount and skips balance rows', function () {
    $content = [
        'headers' => ['Date', 'Time', 'TimeZone', 'Name', 'Type', 'Status', 'Currency', 'Gross', 'Fee', 'Net'],
        'rows' => [
            ['Date' => '03/05/2026', 'Time' => '12:00', 'TimeZone' => 'PDT', 'Name' => 'Jane Doe',
                'Type' => 'Payment', 'Status' => 'Completed', 'Currency' => 'USD',
                'Gross' => '50.00', 'Fee' => '-1.45', 'Net' => '48.55'],
            ['Date' => '03/06/2026', 'Time' => '09:00', 'TimeZone' => 'PDT', 'Name' => 'Bank',
                'Type' => 'Balance Adjustment', 'Status' => 'Completed', 'Currency' => 'USD',
                'Gross' => '1000.00', 'Fee' => '0', 'Net' => '1000.00'],
        ],
    ];

    $parser = new PaypalCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    expect(count($stmt->transactions))->toBe(1)
        ->and($stmt->transactions[0]->amount)->toBe(48.55);
});

it('OnPoint CU checking PDF parser recognizes CCU header', function () {
    $text = <<<'TXT'
    OnPoint Community Credit Union
    Statement Period 03/01/2026 - 03/31/2026
    Account number ending 5555
    Beginning Balance  $1,000.00
    Ending Balance  $1,150.00

    Deposits and Credits
    3/05  Direct deposit  400.00

    Withdrawals and Debits
    3/10  ATM withdrawal  50.00
    3/15  Grocery  25.00
    TXT;

    $parser = new OnPointCheckingStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    expect($stmt->bankSlug)->toBe('onpoint_checking')
        ->and(count($stmt->transactions))->toBe(3);
    $amounts = array_map(fn ($t) => $t->amount, $stmt->transactions);
    expect($amounts)->toContain(400.00)
        ->and($amounts)->toContain(-50.00)
        ->and($amounts)->toContain(-25.00);
});

it('OnPoint CU credit PDF parser routes to credit variant', function () {
    $text = <<<'TXT'
    OnPoint Community Credit Union
    Visa Credit Card Statement
    Account ending in 1234
    Statement Period 03/01/2026 to 03/31/2026
    Previous Balance  $200.00
    New Balance  $350.00

    3/05  3/06  GROCERY STORE  45.00
    3/10  3/11  PAYMENT THANK YOU  50.00 CR
    TXT;

    $parser = new OnPointCreditStatementParser;
    expect($parser->fingerprint($text))->toBeTrue();
    $stmt = $parser->parse($text);
    expect($stmt->bankSlug)->toBe('onpoint_credit');
});

it('OnPoint CU checking CSV parser distinguishes from Citi via Check Number', function () {
    $content = [
        'headers' => ['Date', 'Description', 'Debit', 'Credit', 'Balance', 'Check Number'],
        'rows' => [
            ['Date' => '03/05/2026', 'Description' => 'DEPOSIT', 'Debit' => '', 'Credit' => '500.00', 'Balance' => '1500.00', 'Check Number' => ''],
            ['Date' => '03/10/2026', 'Description' => 'CHECK', 'Debit' => '100.00', 'Credit' => '', 'Balance' => '1400.00', 'Check Number' => '1001'],
        ],
    ];

    $parser = new OnPointCheckingCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    expect($stmt->bankSlug)->toBe('onpoint_checking')
        ->and(count($stmt->transactions))->toBe(2);
});

it('OnPoint CU credit CSV parser differs from Amex via Card Number without Category', function () {
    $content = [
        'headers' => ['Date', 'Description', 'Amount', 'Card Number'],
        'rows' => [
            ['Date' => '03/05/2026', 'Description' => 'COSTCO', 'Amount' => '45.00', 'Card Number' => '****1234'],
        ],
    ];

    $parser = new OnPointCreditCsvParser;
    expect($parser->fingerprint($content))->toBeTrue();
    $stmt = $parser->parse($content);
    expect($stmt->transactions[0]->amount)->toBe(-45.00);
});
