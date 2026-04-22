<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Contact;
use App\Models\Tag;
use App\Support\ContactsCsvImporter;

it('exports every contact as a CSV with the expected columns', function () {
    authedInHousehold();
    $groc = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $c = Contact::create([
        'kind' => 'org',
        'display_name' => 'Costco',
        'organization' => 'Costco Wholesale',
        'emails' => ['ap@costco.com', 'billing@costco.com'],
        'phones' => ['+1-555-0100'],
        'is_vendor' => true,
        'favorite' => true,
        'match_patterns' => "costco\nwholesale",
        'category_id' => $groc->id,
        'notes' => 'Club card 001',
    ]);
    $vip = Tag::firstOrCreate(['slug' => 'vip'], ['name' => 'VIP']);
    $c->tags()->attach($vip->id);

    $response = $this->get(route('relationships.contacts.export'));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();
    expect($csv)->toContain('display_name,kind,organization')
        ->and($csv)->toContain('Costco')
        ->and($csv)->toContain('Costco Wholesale')
        ->and($csv)->toContain('ap@costco.com')
        ->and($csv)->toContain('Groceries')
        ->and($csv)->toContain('costco|wholesale')
        ->and($csv)->toContain('vip');
});

it('imports a new contact from CSV with every column populated', function () {
    authedInHousehold();
    Category::create(['kind' => 'expense', 'name' => 'Utilities', 'slug' => 'utilities']);

    $csv = "display_name,kind,organization,first_name,last_name,email,phone,favorite,is_vendor,is_customer,tax_id,category,match_patterns,tags,notes\n"
        .'PG&E,org,"Pacific Gas & Electric",,,ap@pge.com,+1-800-743-5000,0,1,0,EIN123,Utilities,pacific gas|pge,"utilities,bills",Power company'."\n";

    $summary = ContactsCsvImporter::import($csv);
    expect($summary['created'])->toBe(1)
        ->and($summary['merged'])->toBe(0)
        ->and($summary['errors'])->toBe([]);

    $c = Contact::where('display_name', 'PG&E')->first();
    expect($c)->not->toBeNull()
        ->and($c->kind)->toBe('org')
        ->and($c->organization)->toBe('Pacific Gas & Electric')
        ->and((bool) $c->is_vendor)->toBeTrue()
        ->and($c->match_patterns)->toContain('pacific gas')
        ->and($c->match_patterns)->toContain('pge')
        ->and($c->tags->pluck('slug')->all())->toContain('utilities');
});

it('merges into an existing contact by display_name without clobbering non-empty fields', function () {
    authedInHousehold();
    $groc = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);

    // Pre-existing contact: organization already set, no email / phone /
    // category / match_patterns / tags yet.
    $existing = Contact::create([
        'kind' => 'org',
        'display_name' => 'Costco',
        'organization' => 'Costco Wholesale',
    ]);

    $csv = "display_name,kind,organization,first_name,last_name,email,phone,favorite,is_vendor,is_customer,tax_id,category,match_patterns,tags,notes\n"
        .'costco,org,"SHOULD NOT OVERWRITE",,,ap@costco.com,+1-555-0100,0,1,0,,Groceries,costco|warehouse,"vip",Added on import'."\n";

    $summary = ContactsCsvImporter::import($csv);
    expect($summary['merged'])->toBe(1)->and($summary['created'])->toBe(0);

    $fresh = $existing->fresh(['tags']);
    expect($fresh->organization)->toBe('Costco Wholesale') // not overwritten
        ->and($fresh->emails[0] ?? null)->toBe('ap@costco.com')
        ->and($fresh->phones[0] ?? null)->toBe('+1-555-0100')
        ->and($fresh->category_id)->toBe($groc->id)
        ->and($fresh->notes)->toBe('Added on import')
        ->and((bool) $fresh->is_vendor)->toBeTrue()
        // "costco" skipped as noise (it's the display-name fingerprint);
        // "warehouse" is a novel pattern and gets appended.
        ->and($fresh->match_patterns)->toContain('warehouse')
        ->and($fresh->tags->pluck('slug')->all())->toContain('vip');
});

it('is idempotent: re-importing the same CSV is a no-op on the second pass', function () {
    authedInHousehold();

    $csv = "display_name,kind,organization\n"
        .'Acme,org,Acme Corp'."\n";

    $first = ContactsCsvImporter::import($csv);
    $second = ContactsCsvImporter::import($csv);

    expect($first['created'])->toBe(1)
        ->and($first['merged'])->toBe(0)
        ->and($second['created'])->toBe(0)
        ->and($second['merged'])->toBe(1);

    expect(Contact::where('display_name', 'Acme')->count())->toBe(1);
});

it('round-trips contact_roles via CSV export/import and unions additively on merge', function () {
    authedInHousehold();

    // Existing contact with a single role.
    $existing = Contact::create([
        'kind' => 'person',
        'display_name' => 'Aunt Sue',
        'contact_roles' => ['family'],
    ]);

    // CSV adds emergency_contact + an invalid slug (must be dropped silently).
    $csv = "display_name,roles\n"
        .'Aunt Sue,"family,emergency_contact,not_a_real_role"'."\n";

    ContactsCsvImporter::import($csv);

    $fresh = $existing->fresh();
    expect($fresh->contact_roles)->toContain('family')
        ->and($fresh->contact_roles)->toContain('emergency_contact')
        ->and($fresh->contact_roles)->not->toContain('not_a_real_role');

    // New contact from CSV carries only the valid slugs.
    $csvNew = "display_name,roles\n"
        .'New Pal,"friend,colleague,garbage"'."\n";
    ContactsCsvImporter::import($csvNew);

    $new = Contact::where('display_name', 'New Pal')->first();
    expect($new->contact_roles)->toBe(['friend', 'colleague']);
});

it('dedups match_patterns case-insensitively on merge', function () {
    authedInHousehold();

    $existing = Contact::create([
        'kind' => 'org',
        'display_name' => 'Netflix',
        'match_patterns' => 'netflix.com',
    ]);

    $csv = "display_name,match_patterns\n"
        .'Netflix,NETFLIX.COM|netflix subscription'."\n";

    ContactsCsvImporter::import($csv);

    $lines = preg_split('/\r?\n/', (string) $existing->fresh()->match_patterns);
    // Only the truly-new pattern is appended; the case-variant duplicate is skipped.
    expect($lines)->toBe(['netflix.com', 'netflix subscription']);
});
