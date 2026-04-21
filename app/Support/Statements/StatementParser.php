<?php

namespace App\Support\Statements;

/**
 * Contract for a per-bank statement parser. The registry picks one by
 * format ('pdf' or 'csv') then by fingerprint. Each parser is isolated to
 * a single bank + statement flavor (checking vs credit, which layouts
 * often differ significantly).
 */
interface StatementParser
{
    public function supports(string $format): bool;

    /**
     * Should this parser handle the given content? Expected to be cheap —
     * typically a regex on the first ~1000 chars (PDF text) or a header
     * column signature (CSV).
     *
     * @param  string|array{headers: array<int, string>, rows: array<int, array<string, string>>}  $content
     */
    public function fingerprint(string|array $content): bool;

    /**
     * Parse the full file into a DTO. Throws only for truly unrecoverable
     * input — normal "can't decode one line" → emit an empty transactions
     * array and let the UI flag it.
     *
     * @param  string|array{headers: array<int, string>, rows: array<int, array<string, string>>}  $content
     */
    public function parse(string|array $content): ParsedStatement;
}
