<?php

namespace App\Support\Statements;

use App\Support\Csv;
use App\Support\Pdf;
use App\Support\Statements\Parsers\Csv\AmexCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\AmexCreditCsvParser;
use App\Support\Statements\Parsers\Csv\CitiCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\CitiCreditCsvParser;
use App\Support\Statements\Parsers\Csv\OnPointCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\OnPointCreditCsvParser;
use App\Support\Statements\Parsers\Csv\PaypalCsvParser;
use App\Support\Statements\Parsers\Csv\WellsFargoCheckingCsvParser;
use App\Support\Statements\Parsers\Csv\WellsFargoCreditCsvParser;
use App\Support\Statements\Parsers\Pdf\AmexCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\AmexCreditStatementParser;
use App\Support\Statements\Parsers\Pdf\CitiCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\CitiCreditStatementParser;
use App\Support\Statements\Parsers\Pdf\OnPointCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\OnPointCreditStatementParser;
use App\Support\Statements\Parsers\Pdf\WellsFargoCheckingStatementParser;
use App\Support\Statements\Parsers\Pdf\WellsFargoCreditStatementParser;

/**
 * Routes an uploaded file to the right parser. The user is spared choosing
 * — registry detects format (by extension → mime), extracts content once,
 * asks each parser "is this yours?", picks the first match.
 */
class ParserRegistry
{
    /**
     * @return array<int, StatementParser>
     */
    public function parsers(): array
    {
        return [
            // PDF parsers
            new WellsFargoCheckingStatementParser,
            new WellsFargoCreditStatementParser,
            new CitiCheckingStatementParser,
            new CitiCreditStatementParser,
            new AmexCheckingStatementParser,
            new AmexCreditStatementParser,
            new OnPointCheckingStatementParser,
            new OnPointCreditStatementParser,
            // CSV parsers — PayPal listed first because its fingerprint is
            // distinctive enough to match quickly and shortcut the others.
            new PaypalCsvParser,
            new WellsFargoCheckingCsvParser,
            new WellsFargoCreditCsvParser,
            new CitiCheckingCsvParser,
            new CitiCreditCsvParser,
            new AmexCheckingCsvParser,
            new AmexCreditCsvParser,
            new OnPointCheckingCsvParser,
            new OnPointCreditCsvParser,
        ];
    }

    /**
     * Parse a file at the given absolute path. Returns null when no parser
     * recognizes the format — the UI renders that as "unrecognized".
     */
    public function parseFile(string $absolutePath): ?ParsedStatement
    {
        $format = $this->detectFormat($absolutePath);
        if ($format === null) {
            return null;
        }

        $content = $format === 'pdf'
            ? Pdf::extractText($absolutePath)
            : Csv::parse($absolutePath);

        foreach ($this->parsers() as $parser) {
            if (! $parser->supports($format)) {
                continue;
            }
            if ($parser->fingerprint($content)) {
                return $parser->parse($content);
            }
        }

        return null;
    }

    /**
     * Parse raw content directly (without disk IO). Handy for testing.
     *
     * @param  string|array{headers: array<int, string>, rows: array<int, array<string, string>>}  $content
     */
    public function parseContent(string $format, string|array $content): ?ParsedStatement
    {
        foreach ($this->parsers() as $parser) {
            if (! $parser->supports($format)) {
                continue;
            }
            if ($parser->fingerprint($content)) {
                return $parser->parse($content);
            }
        }

        return null;
    }

    private function detectFormat(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'pdf';
        }
        if ($ext === 'csv' || $ext === 'tsv') {
            return 'csv';
        }

        return null;
    }
}
