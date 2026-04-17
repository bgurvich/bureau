<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $household = Household::firstOrCreate(
                ['name' => 'Bureau'],
                ['default_currency' => 'USD']
            );

            $user = User::firstOrCreate(
                ['email' => 'boris@gurvich.me'],
                [
                    'name' => 'Boris Gurvich',
                    'password' => Hash::make('change-me'),
                    'default_household_id' => $household->id,
                ]
            );

            if ($user->default_household_id !== $household->id) {
                $user->forceFill(['default_household_id' => $household->id])->save();
            }

            $household->users()->syncWithoutDetaching([
                $user->id => ['role' => 'owner', 'joined_at' => now()],
            ]);

            CurrentHousehold::set($household);

            $this->seedCategories($household->id);
        });
    }

    protected function seedCategories(int $householdId): void
    {
        $expenses = [
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

        $income = [
            'Salary' => [],
            'Freelance' => [],
            'Investments' => ['Dividends', 'Interest', 'Capital Gains'],
            'Rental' => [],
            'Gifts Received' => [],
            'Refunds' => [],
            'Other Income' => [],
        ];

        foreach ($expenses as $parent => $children) {
            $parentCat = Category::firstOrCreate(
                [
                    'household_id' => $householdId,
                    'kind' => 'expense',
                    'slug' => Str::slug($parent),
                ],
                ['name' => $parent]
            );
            foreach ($children as $child) {
                Category::firstOrCreate(
                    [
                        'household_id' => $householdId,
                        'kind' => 'expense',
                        'slug' => Str::slug($parent).'/'.Str::slug($child),
                    ],
                    ['name' => $child, 'parent_id' => $parentCat->id]
                );
            }
        }

        foreach ($income as $parent => $children) {
            $parentCat = Category::firstOrCreate(
                [
                    'household_id' => $householdId,
                    'kind' => 'income',
                    'slug' => Str::slug($parent),
                ],
                ['name' => $parent]
            );
            foreach ($children as $child) {
                Category::firstOrCreate(
                    [
                        'household_id' => $householdId,
                        'kind' => 'income',
                        'slug' => Str::slug($parent).'/'.Str::slug($child),
                    ],
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
