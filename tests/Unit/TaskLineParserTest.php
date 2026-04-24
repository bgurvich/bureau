<?php

declare(strict_types=1);

use App\Support\TaskLineParser;
use Carbon\CarbonImmutable;

it('returns null for blank and whitespace lines', function () {
    expect(TaskLineParser::parseLine(''))->toBeNull();
    expect(TaskLineParser::parseLine('   '))->toBeNull();
});

it('extracts a single hashtag and leaves the title clean', function () {
    $r = TaskLineParser::parseLine('Buy milk #groceries');
    expect($r['title'])->toBe('Buy milk');
    expect($r['tags'])->toBe(['groceries']);
});

it('extracts multiple hashtags and dedupes', function () {
    $r = TaskLineParser::parseLine('Tidy garage #home #home #weekend');
    expect($r['title'])->toBe('Tidy garage');
    expect($r['tags'])->toBe(['home', 'weekend']);
});

it('extracts @contact patterns', function () {
    $r = TaskLineParser::parseLine('Call @alice about the invoice @bob');
    expect($r['title'])->toBe('Call about the invoice');
    expect($r['contact_patterns'])->toBe(['alice', 'bob']);
});

it('does not treat hyphenated words as dates', function () {
    $r = TaskLineParser::parseLine('Fix a-b-c naming in repo');
    expect($r['title'])->toBe('Fix a-b-c naming in repo');
    expect($r['due_at'])->toBeNull();
});

it('does not treat bare M/D fragments as dates (phone-like numbers stay in title)', function () {
    $r = TaskLineParser::parseLine('Call 06/15 a couple times');
    expect($r['title'])->toBe('Call 06/15 a couple times');
    expect($r['due_at'])->toBeNull();
});

it('extracts "by M/D" and coerces to current year at 09:00', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Book dentist by 6/15');
    expect($r['title'])->toBe('Book dentist');
    expect($r['due_at'])->toBe('2026-06-15 09:00:00');
});

it('accepts "by MM/DD" with zero padding', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Book dentist by 06/15');
    expect($r['due_at'])->toBe('2026-06-15 09:00:00');
});

it('is case-insensitive on the by prefix', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Ship feature BY 5/3');
    expect($r['due_at'])->toBe('2026-05-03 09:00:00');
});

it('rolls past by M/D to next year', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Renew vehicle reg by 3/1');
    expect($r['due_at'])->toBe('2027-03-01 09:00:00');
});

it('keeps today as today, not next year', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Ship release by 4/23');
    expect($r['due_at'])->toBe('2026-04-23 09:00:00');
});

it('ignores invalid by M/D (Feb 30)', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Note by 2/30 edge case');
    expect($r['due_at'])->toBeNull();
    expect($r['title'])->toBe('Note by 2/30 edge case');
});

it('handles all token types on one line', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    $r = TaskLineParser::parseLine('Call @alice about taxes #admin P2 by 5/3');
    expect($r['title'])->toBe('Call about taxes');
    expect($r['tags'])->toBe(['admin']);
    expect($r['contact_patterns'])->toBe(['alice']);
    expect($r['priority'])->toBe(2);
    expect($r['due_at'])->toBe('2026-05-03 09:00:00');
});

it('extracts P1..P5 priority tokens', function () {
    $r = TaskLineParser::parseLine('Call mom P1');
    expect($r['priority'])->toBe(1);
    expect($r['title'])->toBe('Call mom');

    $r = TaskLineParser::parseLine('Renew passport P5');
    expect($r['priority'])->toBe(5);

    $r = TaskLineParser::parseLine('No priority here');
    expect($r['priority'])->toBeNull();
});

it('does not treat P6+ or embedded P2 as priority', function () {
    $r = TaskLineParser::parseLine('Upgrade to P6 rev');
    expect($r['priority'])->toBeNull();

    $r = TaskLineParser::parseLine('Check CP2 housing'); // embedded — no word boundary before P
    expect($r['priority'])->toBeNull();
});

it('parseBlock drops empty lines and returns one row per kept line', function () {
    $text = "First #foo\n\nSecond @bob\n  \nThird";
    $rows = TaskLineParser::parseBlock($text);
    expect($rows)->toHaveCount(3);
    expect($rows[0]['title'])->toBe('First');
    expect($rows[1]['title'])->toBe('Second');
    expect($rows[2]['title'])->toBe('Third');
});
