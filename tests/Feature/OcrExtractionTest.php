<?php

use App\Jobs\ExtractOcrStructure;
use App\Jobs\OcrMedia;
use App\Models\Media;
use App\Support\LmStudio;
use App\Support\ReceiptExtractor;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function lmChatReply(string $content): array
{
    return [
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
    ];
}

beforeEach(function () {
    config()->set('services.lm_studio.enabled', true);
    config()->set('services.lm_studio.base_url', 'http://lm-studio.test/v1');
    config()->set('services.lm_studio.model', 'qwen2.5-coder-7b-instruct');
    config()->set('services.lm_studio.timeout', 30);
});

it('LmStudio returns assistant content on 2xx', function () {
    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply('{"ok":true}')),
    ]);

    $content = (new LmStudio)->chat([['role' => 'user', 'content' => 'hi']]);

    expect($content)->toBe('{"ok":true}');
    Http::assertSent(function ($req) {
        $body = json_decode($req->body(), true);

        return str_ends_with($req->url(), '/chat/completions')
            && $body['model'] === 'qwen2.5-coder-7b-instruct'
            && $body['messages'][0]['content'] === 'hi';
    });
});

it('LmStudio returns null on transport error', function () {
    Http::fake([
        'lm-studio.test/*' => Http::response('boom', 500),
    ]);

    expect((new LmStudio)->chat([['role' => 'user', 'content' => 'hi']]))->toBeNull();
});

it('LmStudio returns null when disabled', function () {
    config()->set('services.lm_studio.enabled', false);
    Http::fake();
    expect((new LmStudio)->chat([['role' => 'user', 'content' => 'hi']]))->toBeNull();
    Http::assertNothingSent();
});

it('ReceiptExtractor normalizes a clean JSON reply', function () {
    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply(json_encode([
            'kind' => 'receipt',
            'vendor' => 'Whole Foods',
            'amount' => 42.17,
            'currency' => 'usd',
            'issued_on' => '2026-04-10',
            'due_on' => null,
            'tax_amount' => 3.05,
            'line_items' => [
                ['description' => 'Bananas', 'amount' => 2.50, 'quantity' => 1],
                ['description' => 'Milk', 'amount' => 5.10, 'quantity' => 2],
            ],
            'category_suggestion' => 'GROCERIES',
            'confidence' => 0.91,
        ]))),
    ]);

    $out = (new ReceiptExtractor(new LmStudio))->extract("Whole Foods\nBananas 2.50\nMilk 5.10\nTotal $42.17");

    expect($out)->not->toBeNull()
        ->and($out['kind'])->toBe('receipt')
        ->and($out['vendor'])->toBe('Whole Foods')
        ->and($out['amount'])->toBe(42.17)
        ->and($out['currency'])->toBe('USD')
        ->and($out['issued_on'])->toBe('2026-04-10')
        ->and($out['due_on'])->toBeNull()
        ->and($out['tax_amount'])->toBe(3.05)
        ->and($out['line_items'])->toHaveCount(2)
        ->and($out['line_items'][0]['description'])->toBe('Bananas')
        ->and($out['category_suggestion'])->toBe('GROCERIES');
});

it('ReceiptExtractor strips markdown fences around JSON', function () {
    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply("```json\n{\"vendor\":\"Shell\",\"amount\":45.12,\"currency\":\"USD\",\"issued_on\":\"2026-03-01\",\"kind\":\"receipt\",\"line_items\":[]}\n```")),
    ]);

    $out = (new ReceiptExtractor(new LmStudio))->extract('Shell Gas');

    expect($out)->not->toBeNull()
        ->and($out['vendor'])->toBe('Shell')
        ->and($out['amount'])->toBe(45.12);
});

it('ReceiptExtractor drops invalid dates and bad currency codes', function () {
    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply(json_encode([
            'vendor' => 'Mystery',
            'amount' => '19.99',
            'currency' => 'DOLLARS',
            'issued_on' => '2026-02-31',
            'due_on' => 'not a date',
            'line_items' => [],
        ]))),
    ]);

    $out = (new ReceiptExtractor(new LmStudio))->extract('some text');

    expect($out['amount'])->toBe(19.99)
        ->and($out['currency'])->toBeNull()
        ->and($out['issued_on'])->toBeNull()
        ->and($out['due_on'])->toBeNull();
});

it('ReceiptExtractor returns null when model output is not JSON', function () {
    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply('I refuse to answer.')),
    ]);

    expect((new ReceiptExtractor(new LmStudio))->extract('some text'))->toBeNull();
});

it('ReceiptExtractor short-circuits on empty OCR text', function () {
    Http::fake();
    expect((new ReceiptExtractor(new LmStudio))->extract("   \n "))->toBeNull();
    Http::assertNothingSent();
});

it('ExtractOcrStructure writes ocr_extracted on success', function () {
    authedInHousehold();

    $m = Media::create([
        'disk' => 'local', 'path' => 'r.jpg', 'original_name' => 'r.jpg',
        'mime' => 'image/jpeg', 'size' => 1,
        'ocr_status' => 'done', 'ocr_text' => "Acme\nTotal $9.00", 'extraction_status' => 'pending',
    ]);

    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply(json_encode([
            'vendor' => 'Acme', 'amount' => 9.0, 'currency' => 'USD',
            'issued_on' => '2026-04-01', 'kind' => 'receipt', 'line_items' => [],
        ]))),
    ]);

    app(ExtractOcrStructure::class, ['mediaId' => $m->id])->handle(app(ReceiptExtractor::class));

    $fresh = $m->fresh();
    expect($fresh->extraction_status)->toBe('done')
        ->and($fresh->ocr_extracted['vendor'])->toBe('Acme')
        ->and($fresh->ocr_extracted['amount'])->toEqual(9.0);
});

it('ExtractOcrStructure marks skipped when OCR text is empty', function () {
    authedInHousehold();

    $m = Media::create([
        'disk' => 'local', 'path' => 'blank.jpg', 'original_name' => 'blank.jpg',
        'mime' => 'image/jpeg', 'size' => 1,
        'ocr_status' => 'done', 'ocr_text' => '', 'extraction_status' => 'pending',
    ]);

    app(ExtractOcrStructure::class, ['mediaId' => $m->id])->handle(app(ReceiptExtractor::class));

    expect($m->fresh()->extraction_status)->toBe('skipped');
});

it('ExtractOcrStructure marks failed when extractor returns null', function () {
    authedInHousehold();

    $m = Media::create([
        'disk' => 'local', 'path' => 'garbage.jpg', 'original_name' => 'garbage.jpg',
        'mime' => 'image/jpeg', 'size' => 1,
        'ocr_status' => 'done', 'ocr_text' => 'some text', 'extraction_status' => 'pending',
    ]);

    Http::fake([
        'lm-studio.test/*' => Http::response(lmChatReply('not json at all')),
    ]);

    app(ExtractOcrStructure::class, ['mediaId' => $m->id])->handle(app(ReceiptExtractor::class));

    expect($m->fresh()->extraction_status)->toBe('failed');
});

it('ExtractOcrStructure is a no-op when LM Studio is disabled', function () {
    authedInHousehold();
    config()->set('services.lm_studio.enabled', false);

    $m = Media::create([
        'disk' => 'local', 'path' => 'x.jpg', 'original_name' => 'x.jpg',
        'mime' => 'image/jpeg', 'size' => 1,
        'ocr_status' => 'done', 'ocr_text' => 'text', 'extraction_status' => null,
    ]);

    Http::fake();
    app(ExtractOcrStructure::class, ['mediaId' => $m->id])->handle(app(ReceiptExtractor::class));

    expect($m->fresh()->extraction_status)->toBeNull();
    Http::assertNothingSent();
});

it('OcrMedia dispatches ExtractOcrStructure when enabled', function () {
    Storage::fake('local');
    authedInHousehold();

    Storage::disk('local')->put('scans/r.jpg', 'fake');
    $m = Media::create([
        'disk' => 'local', 'path' => 'scans/r.jpg', 'original_name' => 'r.jpg',
        'mime' => 'image/jpeg', 'size' => 4, 'ocr_status' => 'pending',
    ]);

    Process::fake(fn () => Process::result(output: 'Total $12.00'));
    Bus::fake();

    (new OcrMedia($m->id))->handle();

    $fresh = $m->fresh();
    expect($fresh->ocr_status)->toBe('done')
        ->and($fresh->extraction_status)->toBe('pending');

    Bus::assertDispatched(ExtractOcrStructure::class, fn ($job) => $job->mediaId === $m->id);
});

it('OcrMedia does not dispatch extraction when LM Studio is disabled', function () {
    Storage::fake('local');
    authedInHousehold();
    config()->set('services.lm_studio.enabled', false);

    Storage::disk('local')->put('r.jpg', 'fake');
    $m = Media::create([
        'disk' => 'local', 'path' => 'r.jpg', 'original_name' => 'r.jpg',
        'mime' => 'image/jpeg', 'size' => 4, 'ocr_status' => 'pending',
    ]);

    Process::fake(fn () => Process::result(output: 'hi'));
    Bus::fake();

    (new OcrMedia($m->id))->handle();

    expect($m->fresh()->extraction_status)->toBeNull();
    Bus::assertNotDispatched(ExtractOcrStructure::class);
});

it('ocr:extract-structure queues pending-extraction rows', function () {
    authedInHousehold();

    $queued = Media::create([
        'disk' => 'local', 'path' => 'queued.jpg', 'original_name' => 'q.jpg',
        'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'done', 'ocr_text' => 'text',
    ]);
    Media::create([
        'disk' => 'local', 'path' => 'skip.jpg', 'original_name' => 's.jpg',
        'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'done', 'ocr_text' => 'text',
        'extraction_status' => 'done',
    ]);
    // No ocr_text → must not queue
    Media::create([
        'disk' => 'local', 'path' => 'untexted.jpg', 'original_name' => 'u.jpg',
        'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'done',
    ]);

    Bus::fake();
    $this->artisan('ocr:extract-structure')->assertExitCode(0);

    Bus::assertDispatchedTimes(ExtractOcrStructure::class, 1);
    Bus::assertDispatched(ExtractOcrStructure::class, fn ($j) => $j->mediaId === $queued->id);
});

it('ocr:extract-structure short-circuits when LM Studio is disabled', function () {
    authedInHousehold();
    config()->set('services.lm_studio.enabled', false);

    Media::create([
        'disk' => 'local', 'path' => 'q.jpg', 'original_name' => 'q.jpg',
        'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'done', 'ocr_text' => 'text',
    ]);

    Bus::fake();
    $this->artisan('ocr:extract-structure')->assertExitCode(0);
    Bus::assertNothingDispatched();
});

it('ocr:extract-structure dry-run reports without queuing', function () {
    authedInHousehold();

    Media::create([
        'disk' => 'local', 'path' => 'q.jpg', 'original_name' => 'q.jpg',
        'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'done', 'ocr_text' => 'text',
    ]);

    Bus::fake();
    $this->artisan('ocr:extract-structure', ['--dry-run' => true])->assertExitCode(0);
    Bus::assertNothingDispatched();
});
