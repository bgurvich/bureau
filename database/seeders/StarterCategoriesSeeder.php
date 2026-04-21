<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Friendly starter-set of expense/income categories every new household
 * gets out of the box. Idempotent — uses firstOrCreate keyed on
 * (household_id, kind, slug) so re-running on an existing household
 * doesn't duplicate or overwrite user renames.
 *
 * Kept distinct from SystemCategoriesSeeder, which adds the handful of
 * slugs Bureau's engines look up by name (e.g. interest-paid). Re-seeding
 * one shouldn't disturb the other.
 *
 * Usage:
 *   php artisan db:seed --class=StarterCategoriesSeeder
 * Runs automatically from DatabaseSeeder on the default household.
 */
class StarterCategoriesSeeder extends Seeder
{
    private const EXPENSES = [
        'Housing' => ['Rent / Mortgage', 'Utilities', 'Maintenance', 'Furnishings'],
        'Transport' => ['Fuel', 'Public Transit', 'Parking', 'Tolls', 'Vehicle Service'],
        'Food' => ['Groceries', 'Dining Out', 'Coffee'],
        'Health' => ['Medical', 'Dental', 'Vision', 'Pharmacy', 'Fitness'],
        'Insurance' => ['Auto', 'Home', 'Health', 'Life'],
        'Subscriptions' => ['Software', 'Media', 'News'],
        'Personal' => ['Clothing', 'Grooming', 'Hobbies'],
        'Travel' => ['Flights', 'Lodging', 'Activities'],
        'Kids' => ['School', 'Activities', 'Clothing'],
        'Pets' => ['Food', 'Vet', 'Grooming', 'Supplies'],
        'Taxes' => ['Federal', 'State', 'Property', 'Other'],
        'Gifts & Donations' => [],
        'Fees & Bank' => [],
        'Other' => [],
    ];

    private const INCOME = [
        'Salary' => [],
        'Freelance' => [],
        'Investments' => ['Dividends', 'Interest', 'Capital Gains'],
        'Rental' => [],
        'Gifts Received' => [],
        'Refunds' => [],
        'Other Income' => [],
    ];

    public function run(?int $householdId = null): void
    {
        $households = $householdId
            ? Household::whereKey($householdId)->get()
            : Household::query()->get();

        foreach ($households as $household) {
            $this->seedHousehold((int) $household->id);
        }
    }

    private function seedHousehold(int $householdId): void
    {
        foreach (self::EXPENSES as $parent => $children) {
            $parentCat = Category::firstOrCreate(
                ['household_id' => $householdId, 'kind' => 'expense', 'slug' => Str::slug($parent)],
                ['name' => $parent]
            );
            foreach ($children as $child) {
                Category::firstOrCreate(
                    ['household_id' => $householdId, 'kind' => 'expense', 'slug' => Str::slug($parent).'/'.Str::slug($child)],
                    ['name' => $child, 'parent_id' => $parentCat->id]
                );
            }
        }

        foreach (self::INCOME as $parent => $children) {
            $parentCat = Category::firstOrCreate(
                ['household_id' => $householdId, 'kind' => 'income', 'slug' => Str::slug($parent)],
                ['name' => $parent]
            );
            foreach ($children as $child) {
                Category::firstOrCreate(
                    ['household_id' => $householdId, 'kind' => 'income', 'slug' => Str::slug($parent).'/'.Str::slug($child)],
                    ['name' => $child, 'parent_id' => $parentCat->id]
                );
            }
        }

        Category::firstOrCreate(
            ['household_id' => $householdId, 'kind' => 'transfer', 'slug' => 'transfer'],
            ['name' => 'Transfer']
        );
    }
}
