<?php

use App\Models\AssetValuation;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Property;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders the Properties drill-down with a latest valuation', function () {
    authedInHousehold();

    $p = Property::create([
        'kind' => 'home', 'name' => 'SF Apartment',
        'address' => ['line1' => '123 Main St', 'city' => 'San Francisco', 'region' => 'CA'],
        'acquired_on' => now()->subYears(2)->toDateString(),
        'purchase_price' => 800000, 'purchase_currency' => 'USD',
    ]);
    AssetValuation::create([
        'valuable_type' => Property::class, 'valuable_id' => $p->id,
        'as_of' => now()->toDateString(),
        'value' => 925000, 'currency' => 'USD',
        'method' => 'estimate', 'source' => 'Zillow',
    ]);

    $this->get('/properties')
        ->assertOk()
        ->assertSee('SF Apartment')
        ->assertSee('San Francisco')
        ->assertSee('925,000');
});

it('filters properties by kind', function () {
    authedInHousehold();

    Property::create(['kind' => 'home', 'name' => 'Primary home']);
    Property::create(['kind' => 'rental', 'name' => 'Rental unit']);

    $this->get('/properties?kind=rental')
        ->assertSee('Rental unit')
        ->assertDontSee('Primary home');
});

it('renders the Vehicles drill-down with make/model', function () {
    $user = authedInHousehold();

    Vehicle::create([
        'kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2019,
        'license_plate' => '8KLM123', 'license_jurisdiction' => 'CA',
        'primary_user_id' => $user->id, 'odometer' => 41200,
    ]);

    $this->get('/vehicles')
        ->assertOk()
        ->assertSee('Honda')
        ->assertSee('Civic')
        ->assertSee('8KLM123');
});

it('renders the Inventory drill-down with warranty info', function () {
    authedInHousehold();

    InventoryItem::create([
        'name' => 'Samsung Washer',
        'category' => 'appliance',
        'brand' => 'Samsung',
        'warranty_expires_on' => now()->addDays(90)->toDateString(),
        'cost_amount' => 1200,
        'cost_currency' => 'USD',
    ]);

    $this->get('/inventory')
        ->assertOk()
        ->assertSee('Samsung Washer')
        ->assertSee('appliance');
});

it('filters inventory by category', function () {
    authedInHousehold();

    InventoryItem::create(['name' => 'Telescope', 'category' => 'other']);
    InventoryItem::create(['name' => 'Sofa', 'category' => 'furniture']);

    $this->get('/inventory?category=furniture')
        ->assertSee('Sofa')
        ->assertDontSee('Telescope');
});

it('renders a photo thumbnail on inventory rows that have one attached', function () {
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'Captured item', 'category' => 'other']);
    $media = Media::create([
        'disk' => 'local',
        'path' => 'inventory-captures/fake.jpg',
        'original_name' => 'fake.jpg',
        'mime' => 'image/jpeg',
        'size' => 12345,
        'ocr_status' => 'skip',
    ]);
    $item->media()->attach($media->id, ['role' => 'photo']);

    $this->get('/inventory')
        ->assertOk()
        ->assertSee(route('media.file', $media), false);
});

it('saves inventory listing fields and filters by for-sale', function () {
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'Old bike', 'category' => 'other']);

    Livewire::test('inspector.inventory-form', ['id' => $item->id])
        ->set('inventory_is_for_sale', true)
        ->set('inventory_listing_platform', 'ebay')
        ->set('inventory_listing_asking_amount', '325.00')
        ->set('inventory_listing_asking_currency', 'USD')
        ->set('inventory_listing_url', 'https://example.com/listing/123')
        ->set('inventory_listing_posted_at', '2026-04-18')
        ->call('save');

    $fresh = $item->fresh();
    expect((bool) $fresh->is_for_sale)->toBeTrue()
        ->and($fresh->listing_platform)->toBe('ebay')
        ->and((float) $fresh->listing_asking_amount)->toBe(325.0)
        ->and($fresh->listing_url)->toBe('https://example.com/listing/123')
        ->and($fresh->listing_posted_at->toDateString())->toBe('2026-04-18');

    InventoryItem::create(['name' => 'Kept item', 'category' => 'other', 'processed_at' => now()]);

    $this->get('/inventory?status=for_sale')
        ->assertOk()
        ->assertSee('Old bike')
        ->assertSee('for sale')
        ->assertDontSee('Kept item');
});

it('accepts multiple photos in a single upload', function () {
    Storage::fake('local');
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'Lamp', 'category' => 'other']);

    Livewire::test('inspector.inventory-form', ['id' => $item->id])
        ->set('photoUpload', [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ]);

    $photos = $item->media()->wherePivot('role', 'photo')->orderByPivot('position')->get();
    expect($photos->count())->toBe(3)
        ->and($photos->pluck('pivot.position')->map(fn ($p) => (int) $p)->all())->toBe([1, 2, 3]);
});

it('lets a user attach a photo while creating a new inventory item (photo-first)', function () {
    Storage::fake('local');
    authedInHousehold();

    Livewire::test('inspector.inventory-form')  // no id — create mode
        ->set('inventory_name', 'Blue lamp')
        ->set('photoUpload', UploadedFile::fake()->image('lamp.jpg'));

    $item = InventoryItem::firstOrFail();
    expect($item->name)->toBe('Blue lamp')
        ->and($item->media()->wherePivot('role', 'photo')->count())->toBe(1);
});

it('uploads an additional photo from the Inspector at the end of the sequence', function () {
    Storage::fake('local');
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'Captured item', 'category' => 'other']);
    $existing = Media::create([
        'disk' => 'local', 'path' => 'inventory-captures/a.jpg',
        'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'ocr_status' => 'skip',
    ]);
    $item->media()->attach($existing->id, ['role' => 'photo', 'position' => 0]);

    Livewire::test('inspector.inventory-form', ['id' => $item->id])
        ->set('photoUpload', UploadedFile::fake()->image('new.jpg'));

    expect($item->media()->wherePivot('role', 'photo')->count())->toBe(2);
    $newest = $item->media()->wherePivot('role', 'photo')->orderByPivot('position', 'desc')->first();
    expect((int) $newest->pivot->position)->toBe(1);
});

it('deletes a photo from the Inspector', function () {
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'X', 'category' => 'other']);
    $m = Media::create([
        'disk' => 'local', 'path' => 'x.jpg',
        'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'skip',
    ]);
    $item->media()->attach($m->id, ['role' => 'photo', 'position' => 0]);

    Livewire::test('inspector.inventory-form', ['id' => $item->id])
        ->call('deletePhoto', $m->id);

    expect($item->media()->count())->toBe(0);
});

it('reorders photos and treats the first position as the drill-down cover', function () {
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'Lamp', 'category' => 'other']);
    $a = Media::create(['disk' => 'local', 'path' => 'a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'skip']);
    $b = Media::create(['disk' => 'local', 'path' => 'b.jpg', 'original_name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'skip']);
    $c = Media::create(['disk' => 'local', 'path' => 'c.jpg', 'original_name' => 'c.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'ocr_status' => 'skip']);
    $item->media()->attach($a->id, ['role' => 'photo', 'position' => 0]);
    $item->media()->attach($b->id, ['role' => 'photo', 'position' => 1]);
    $item->media()->attach($c->id, ['role' => 'photo', 'position' => 2]);

    Livewire::test('inspector.inventory-form', ['id' => $item->id])
        ->call('reorderPhotos', [$c->id, $a->id, $b->id]);

    $ordered = $item->media()->wherePivot('role', 'photo')->orderByPivot('position')->get();
    expect($ordered->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);

    // Drill-down cover (first) should now be C.
    $this->get('/inventory')->assertOk()->assertSeeInOrder([
        route('media.file', $c),
    ]);
});

it('shows attached photos in the inventory Inspector form', function () {
    authedInHousehold();

    $item = InventoryItem::create(['name' => 'Captured item', 'category' => 'other']);
    $media = Media::create([
        'disk' => 'local',
        'path' => 'inventory-captures/fake.jpg',
        'original_name' => 'fake.jpg',
        'mime' => 'image/jpeg',
        'size' => 12345,
        'ocr_status' => 'skip',
    ]);
    $item->media()->attach($media->id, ['role' => 'photo']);

    Livewire::test('inspector.inventory-form', ['id' => $item->id])
        ->assertSee(__('Photos'))
        ->assertSee(route('media.file', $media), false);
});

it('bulk-creates inventory items from a newline-separated list', function () {
    authedInHousehold();

    Livewire::test('inventory-index')
        ->call('openBulk')
        ->assertSet('showBulk', true)
        ->set('bulkNames', "Hair dryer\nGray socks\n  \nPassport holder")
        ->set('bulkRoom', 'Master closet')
        ->set('bulkContainer', 'Shelf 2')
        ->call('bulkCreate')
        ->assertSet('bulkNames', '');

    expect(InventoryItem::count())->toBe(3)
        ->and(InventoryItem::where('name', 'Hair dryer')->value('room'))->toBe('Master closet')
        ->and(InventoryItem::where('name', 'Gray socks')->value('container'))->toBe('Shelf 2');
});

it('rejects empty bulk input', function () {
    authedInHousehold();

    Livewire::test('inventory-index')
        ->call('openBulk')
        ->set('bulkNames', "   \n\n")
        ->call('bulkCreate');

    expect(InventoryItem::count())->toBe(0);
});

it('processes an unprocessed row inline with quantity/category/brand/container', function () {
    authedInHousehold();

    Livewire::test('inventory-index')
        ->call('openBulk')
        ->set('bulkNames', 'Hair dryer')
        ->call('bulkCreate');

    $item = InventoryItem::firstWhere('name', 'Hair dryer');
    expect($item->processed_at)->toBeNull();

    Livewire::test('inventory-index', ['statusFilter' => 'unprocessed'])
        ->set('statusFilter', 'unprocessed')
        ->set("drafts.{$item->id}.quantity", 2)
        ->set("drafts.{$item->id}.category", 'electronic')
        ->set("drafts.{$item->id}.brand", 'Dyson')
        ->set("drafts.{$item->id}.container", 'Bathroom shelf')
        ->call('processRow', $item->id);

    $fresh = $item->fresh();
    expect($fresh->processed_at)->not->toBeNull()
        ->and((int) $fresh->quantity)->toBe(2)
        ->and($fresh->category)->toBe('electronic')
        ->and($fresh->brand)->toBe('Dyson')
        ->and($fresh->container)->toBe('Bathroom shelf');
});

it('bulk-deletes selected inventory rows', function () {
    authedInHousehold();

    $a = InventoryItem::create(['name' => 'Toaster', 'category' => 'appliance', 'processed_at' => now()]);
    $b = InventoryItem::create(['name' => 'Kettle', 'category' => 'appliance', 'processed_at' => now()]);
    $c = InventoryItem::create(['name' => 'Blender', 'category' => 'appliance', 'processed_at' => now()]);

    Livewire::test('inventory-index')
        ->set('selected', [$a->id, $c->id])
        ->call('deleteSelected')
        ->assertSet('selected', []);

    expect(InventoryItem::pluck('name')->all())->toBe(['Kettle']);
});

it('bulk-edits selected rows by flipping them into inline edit mode', function () {
    authedInHousehold();

    $a = InventoryItem::create(['name' => 'Toaster', 'category' => 'appliance', 'processed_at' => now()]);
    $b = InventoryItem::create(['name' => 'Kettle', 'category' => 'appliance', 'processed_at' => now()]);

    $component = Livewire::test('inventory-index')
        ->set('selected', [$a->id])
        ->call('editSelected')
        ->assertSet('selected', [])
        ->assertSet('editingIds', [$a->id])
        ->set("drafts.{$a->id}.quantity", 5)
        ->set("drafts.{$a->id}.brand", 'Breville')
        ->call('processRow', $a->id);

    $component->assertSet('editingIds', []);

    $fresh = $a->fresh();
    expect((int) $fresh->quantity)->toBe(5)
        ->and($fresh->brand)->toBe('Breville')
        ->and($fresh->processed_at)->not->toBeNull();
});

it('filters out processed items when statusFilter is unprocessed', function () {
    authedInHousehold();

    InventoryItem::create(['name' => 'FreshFromCloset', 'category' => 'other']);
    InventoryItem::create(['name' => 'FiledAlready', 'category' => 'other', 'processed_at' => now()]);

    $this->get('/inventory?status=unprocessed')
        ->assertSee('FreshFromCloset')
        ->assertDontSee('FiledAlready');

    $this->get('/inventory?status=processed')
        ->assertSee('FiledAlready')
        ->assertDontSee('FreshFromCloset');
});
