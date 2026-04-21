<?php

use App\Models\Tag;
use App\Models\TagRule;
use Livewire\Livewire;

it('page renders with empty state and a New rule button', function () {
    authedInHousehold();
    $this->get(route('fiscal.tag_rules'))
        ->assertOk()
        ->assertSee(__('No tag rules yet.'))
        ->assertSee(__('New rule'));
});

it('creates a tag rule via the inspector', function () {
    authedInHousehold();
    $tag = Tag::create(['name' => 'coffee', 'slug' => 'coffee']);

    Livewire::test('inspector')
        ->call('openInspector', 'tag_rule')
        ->set('tag_rule_tag_id', $tag->id)
        ->set('tag_rule_pattern_type', 'contains')
        ->set('tag_rule_pattern', 'starbucks')
        ->set('tag_rule_active', true)
        ->call('save');

    expect(TagRule::where('tag_id', $tag->id)->count())->toBe(1);
});

it('edits a tag rule via the inspector', function () {
    authedInHousehold();
    $tag = Tag::create(['name' => 'travel', 'slug' => 'travel']);
    $rule = TagRule::forceCreate(['tag_id' => $tag->id, 'pattern_type' => 'contains', 'pattern' => 'flight', 'active' => true]);

    Livewire::test('inspector')
        ->call('openInspector', 'tag_rule', $rule->id)
        ->assertSet('tag_rule_pattern', 'flight')
        ->set('tag_rule_pattern', 'flights|hotels')
        ->set('tag_rule_pattern_type', 'regex')
        ->call('save');

    $fresh = $rule->fresh();
    expect($fresh->pattern)->toBe('flights|hotels')
        ->and($fresh->pattern_type)->toBe('regex');
});

it('filter by pattern_type narrows the list', function () {
    authedInHousehold();
    $tag = Tag::create(['name' => 'x', 'slug' => 'x']);
    TagRule::forceCreate(['tag_id' => $tag->id, 'pattern_type' => 'contains', 'pattern' => 'XCONTAINSY', 'active' => true]);
    TagRule::forceCreate(['tag_id' => $tag->id, 'pattern_type' => 'regex', 'pattern' => 'XREGEXY', 'active' => true]);

    $response = $this->get(route('fiscal.tag_rules').'?type=regex')->assertOk();
    $response->assertSeeText('XREGEXY');
    $response->assertDontSeeText('XCONTAINSY');
});
