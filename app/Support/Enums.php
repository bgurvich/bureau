<?php

namespace App\Support;

/**
 * Single source of truth for every domain enum surfaced in forms, filters,
 * and validation rules. Order inside each map is the canonical presentation
 * order — workflow-first where meaningful, most-common-first otherwise.
 *
 * Every call goes through __() so the labels translate through the same
 * lang files as the rest of the UI.
 */
class Enums
{
    /** Account type: finance accounts first, then prepaid/gift-card buckets. */
    /**
     * @return array<string, string>
     */
    public static function accountTypes(): array
    {
        return [
            'checking' => __('Checking'),
            'savings' => __('Savings'),
            'credit' => __('Credit'),
            'cash' => __('Cash'),
            'investment' => __('Investment'),
            'loan' => __('Loan'),
            'mortgage' => __('Mortgage'),
            'gift_card' => __('Gift card'),
            'prepaid' => __('Prepaid'),
        ];
    }

    /** Task state: open → waiting → done / dropped. */
    /**
     * @return array<string, string>
     */
    public static function taskStates(): array
    {
        return [
            'open' => __('Open'),
            'waiting' => __('Waiting'),
            'done' => __('Done'),
            'dropped' => __('Dropped'),
        ];
    }

    /** Transaction status: most entries are entered as cleared; pending is the edge case. */
    /**
     * @return array<string, string>
     */
    public static function transactionStatuses(): array
    {
        return [
            'cleared' => __('Cleared'),
            'pending' => __('Pending'),
        ];
    }

    /** Contract state: draft → active → expiring → ended / cancelled. */
    /**
     * @return array<string, string>
     */
    public static function contractStates(): array
    {
        return [
            'draft' => __('Draft'),
            'active' => __('Active'),
            'expiring' => __('Expiring'),
            'ended' => __('Ended'),
            'cancelled' => __('Cancelled'),
        ];
    }

    /** Contract kind: subscription is the most common personal commitment. */
    /**
     * @return array<string, string>
     */
    public static function contractKinds(): array
    {
        return [
            'subscription' => __('Subscription'),
            'insurance' => __('Insurance'),
            'lease' => __('Lease'),
            'employment' => __('Employment'),
            'loan' => __('Loan'),
            'agreement' => __('Agreement'),
            'policy' => __('Policy'),
            'other' => __('Other'),
        ];
    }

    /** Insurance coverage kind. */
    /**
     * @return array<string, string>
     */
    public static function insuranceCoverageKinds(): array
    {
        return [
            'auto' => __('Auto'),
            'home' => __('Home'),
            'health' => __('Health'),
            'life' => __('Life'),
            'disability' => __('Disability'),
            'umbrella' => __('Umbrella'),
            'travel' => __('Travel'),
            'pet' => __('Pet'),
            'renters' => __('Renters'),
            'other' => __('Other'),
        ];
    }

    /** Premium cadence: monthly is the default. */
    /**
     * @return array<string, string>
     */
    public static function insurancePremiumCadences(): array
    {
        return [
            'monthly' => __('Monthly'),
            'quarterly' => __('Quarterly'),
            'annually' => __('Annually'),
        ];
    }

    /** Bill recurrence frequency inside the Inspector. */
    /**
     * @return array<string, string>
     */
    public static function billFrequencies(): array
    {
        return [
            'monthly' => __('Monthly on this issue date'),
            'weekly' => __('Weekly on this weekday'),
            'yearly' => __('Yearly'),
            'daily' => __('Daily'),
        ];
    }

    /** Contact classification. */
    /**
     * @return array<string, string>
     */
    /**
     * Physical-mail classification — drives the filter dropdown and the
     * Inspector select. Keep the list short; unspecified mail defaults
     * to "other".
     *
     * @return array<string, string>
     */
    public static function physicalMailKinds(): array
    {
        return [
            'letter' => __('Letter'),
            'bill' => __('Bill'),
            'package_slip' => __('Package slip'),
            'legal' => __('Legal'),
            'medical' => __('Medical'),
            'ad' => __('Ad / junk'),
            'other' => __('Other'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function contactKinds(): array
    {
        return [
            'person' => __('Person'),
            'org' => __('Organization'),
        ];
    }

    /**
     * Relationship roles for a Contact. Multi-value (a contact can be
     * both friend and colleague). Grouped by category in contactRoleGroups();
     * this flat map is for the bare slug→label lookup used on lists + chips.
     *
     * @return array<string, string>
     */
    public static function contactRoles(): array
    {
        return [
            // personal
            'family' => __('Family'),
            'friend' => __('Friend'),
            'colleague' => __('Colleague'),
            'neighbor' => __('Neighbor'),
            'acquaintance' => __('Acquaintance'),
            // professional services (household-facing)
            'doctor' => __('Doctor / medical'),
            'lawyer' => __('Lawyer'),
            'accountant' => __('Accountant'),
            'financial_advisor' => __('Financial advisor'),
            'contractor' => __('Contractor / handyman'),
            'mechanic' => __('Mechanic'),
            // emergency / critical
            'emergency_contact' => __('Emergency contact'),
            // household
            'landlord' => __('Landlord'),
            'tenant' => __('Tenant'),
            'roommate' => __('Roommate'),
        ];
    }

    /**
     * Role groups for the inspector checkbox grid. Label per group,
     * slugs listed in display order inside each group.
     *
     * @return array<string, array{label: string, slugs: array<int, string>}>
     */
    public static function contactRoleGroups(): array
    {
        return [
            'personal' => ['label' => __('Personal'), 'slugs' => ['family', 'friend', 'colleague', 'neighbor', 'acquaintance']],
            'professional' => ['label' => __('Professional services'), 'slugs' => ['doctor', 'lawyer', 'accountant', 'financial_advisor', 'contractor', 'mechanic']],
            'emergency' => ['label' => __('Emergency'), 'slugs' => ['emergency_contact']],
            'household' => ['label' => __('Household'), 'slugs' => ['landlord', 'tenant', 'roommate']],
        ];
    }

    /** Property kind. */
    /**
     * @return array<string, string>
     */
    public static function propertyKinds(): array
    {
        return [
            'home' => __('Home'),
            'rental' => __('Rental'),
            'land' => __('Land'),
            'vacation' => __('Vacation'),
            'storage' => __('Storage'),
            'other' => __('Other'),
        ];
    }

    /** Property size unit. */
    /**
     * @return array<string, string>
     */
    public static function propertySizeUnits(): array
    {
        return [
            'sqft' => 'sqft',
            'sqm' => 'sqm',
            'acres' => 'acres',
        ];
    }

    /** Vehicle kind. */
    /**
     * @return array<string, string>
     */
    public static function vehicleKinds(): array
    {
        return [
            'car' => __('Car'),
            'motorcycle' => __('Motorcycle'),
            'bicycle' => __('Bicycle'),
            'boat' => __('Boat'),
            'rv' => __('RV'),
            'other' => __('Other'),
        ];
    }

    /** Vehicle odometer unit. */
    /**
     * @return array<string, string>
     */
    public static function vehicleOdometerUnits(): array
    {
        return [
            'mi' => 'mi',
            'km' => 'km',
        ];
    }

    /** Inventory item category. */
    /**
     * @return array<string, string>
     */
    public static function inventoryCategories(): array
    {
        return [
            'appliance' => __('Appliance'),
            'electronic' => __('Electronic'),
            'furniture' => __('Furniture'),
            'art' => __('Art'),
            'jewelry' => __('Jewelry'),
            'tool' => __('Tool'),
            'clothing' => __('Clothing'),
            'other' => __('Other'),
        ];
    }

    /** Identity / legal document kind. */
    /**
     * @return array<string, string>
     */
    public static function documentKinds(): array
    {
        return [
            'passport' => __('Passport'),
            'license' => __('License'),
            'id_card' => __('ID card'),
            'will' => __('Will'),
            'poa' => __('Power of attorney'),
            'advance_directive' => __('Advance directive'),
            'birth_cert' => __('Birth certificate'),
            'ssn' => __('Social security'),
            'pet_license' => __('Pet license'),
            'other' => __('Other'),
        ];
    }

    /** Media OCR status: pending is the interesting/actionable state. */
    /**
     * @return array<string, string>
     */
    public static function mediaOcrStatuses(): array
    {
        return [
            'pending' => __('Pending'),
            'done' => __('Done'),
            'failed' => __('Failed'),
            'skip' => __('Skipped'),
        ];
    }

    /** Meetings / appointments time-range filter. */
    /**
     * @return array<string, string>
     */
    public static function timeRangeFilters(): array
    {
        return [
            'upcoming' => __('Upcoming'),
            'past' => __('Past'),
        ];
    }

    /** Time-entry billable filter. */
    /**
     * @return array<string, string>
     */
    public static function timeEntryBillableFilters(): array
    {
        return [
            'yes' => __('Billable'),
            'unbilled' => __('Unbilled'),
            'no' => __('Not billable'),
        ];
    }

    /** Meeting attendee / insurance subject role. */
    /**
     * @return array<string, string>
     */
    public static function insuranceSubjectRoles(): array
    {
        return [
            'covered' => __('Covered'),
            'beneficiary' => __('Beneficiary'),
            'dependent' => __('Dependent'),
            'named_insured' => __('Named insured'),
        ];
    }

    /** Online account kind. */
    /**
     * @return array<string, string>
     */
    public static function onlineAccountKinds(): array
    {
        return [
            'email' => __('Email'),
            'financial' => __('Financial'),
            'government' => __('Government'),
            'health' => __('Health'),
            'social' => __('Social'),
            'shopping' => __('Shopping'),
            'streaming' => __('Streaming'),
            'subscription' => __('Subscription'),
            'productivity' => __('Productivity'),
            'developer' => __('Developer'),
            'storage' => __('Cloud storage'),
            'utility' => __('Utility'),
            'insurance' => __('Insurance portal'),
            'education' => __('Education'),
            'gaming' => __('Gaming'),
            'forum' => __('Forum / community'),
            'messaging' => __('Messaging'),
            'travel' => __('Travel / loyalty'),
            'other' => __('Other'),
        ];
    }

    /** Online account MFA method. */
    /**
     * @return array<string, string>
     */
    public static function mfaMethods(): array
    {
        return [
            'none' => __('None'),
            'totp' => __('Authenticator app (TOTP)'),
            'app_push' => __('App push'),
            'passkey' => __('Passkey'),
            'security_key' => __('Security key'),
            'sms' => __('SMS'),
            'email' => __('Email'),
        ];
    }

    /** Online account importance tier. */
    /**
     * @return array<string, string>
     */
    public static function importanceTiers(): array
    {
        return [
            'critical' => __('Critical'),
            'high' => __('High'),
            'medium' => __('Medium'),
            'low' => __('Low'),
        ];
    }

    /** Inventory listing platform — where the item is posted for sale. */
    /**
     * @return array<string, string>
     */
    public static function inventoryListingPlatforms(): array
    {
        return [
            'ebay' => __('eBay'),
            'craigslist' => __('Craigslist'),
            'facebook' => __('Facebook Marketplace'),
            'offerup' => __('OfferUp'),
            'poshmark' => __('Poshmark'),
            'mercari' => __('Mercari'),
            'nextdoor' => __('Nextdoor'),
            'local' => __('Local'),
            'other' => __('Other'),
        ];
    }

    /** How an asset left ownership — drives disposition fields on property/vehicle/inventory. */
    /**
     * @return array<string, string>
     */
    public static function assetDispositions(): array
    {
        return [
            'sold' => __('Sold'),
            'traded' => __('Traded'),
            'gifted' => __('Gifted'),
            'donated' => __('Donated'),
            'returned' => __('Returned'),
            'scrapped' => __('Scrapped / junked'),
            'totaled' => __('Totaled'),
            'stolen' => __('Stolen'),
            'lost' => __('Lost'),
            'other' => __('Other'),
        ];
    }

    /** Health provider specialty. */
    /**
     * @return array<string, string>
     */
    public static function healthProviderSpecialties(): array
    {
        return [
            'primary_care' => __('Primary care'),
            'dentist' => __('Dentist'),
            'optometrist' => __('Optometrist'),
            'cardiologist' => __('Cardiologist'),
            'dermatologist' => __('Dermatologist'),
            'orthopedist' => __('Orthopedist'),
            'therapist' => __('Therapist'),
            'vet' => __('Vet'),
            'other' => __('Other'),
        ];
    }

    /** Insurance premium cadence normalizer to a monthly equivalent. */
    public static function cadenceToMonthlyDivisor(string $cadence): ?float
    {
        return match ($cadence) {
            'monthly' => 1.0,
            'quarterly' => 3.0,
            'annually' => 12.0,
            default => null,
        };
    }

    /** Tax-year lifecycle state. Free-form at the schema level; this is the UI menu. */
    /** @return array<string, string> */
    public static function taxYearStates(): array
    {
        return [
            'prep' => __('In progress'),
            'filed' => __('Filed'),
            'amended' => __('Amended'),
            'extended' => __('Extended'),
        ];
    }

    /** US filing status options. Translation labels follow the IRS wording. */
    /** @return array<string, string> */
    public static function taxFilingStatuses(): array
    {
        return [
            'single' => __('Single'),
            'married_joint' => __('Married filing jointly'),
            'married_separate' => __('Married filing separately'),
            'head_of_household' => __('Head of household'),
            'qualifying_surviving_spouse' => __('Qualifying surviving spouse'),
        ];
    }

    /** Tax document kinds. Presentation order mirrors what arrives in the mail each Jan/Feb. */
    /** @return array<string, string> */
    public static function taxDocumentKinds(): array
    {
        return [
            'W-2' => __('W-2 (wages)'),
            '1099-NEC' => __('1099-NEC (nonemployee comp.)'),
            '1099-MISC' => __('1099-MISC'),
            '1099-INT' => __('1099-INT (interest)'),
            '1099-DIV' => __('1099-DIV (dividends)'),
            '1099-B' => __('1099-B (brokerage)'),
            '1099-R' => __('1099-R (retirement)'),
            '1099-G' => __('1099-G (gov. payments)'),
            '1098' => __('1098 (mortgage interest)'),
            'K-1' => __('K-1 (partnership)'),
            'receipt' => __('Receipt'),
            'schedule' => __('Schedule'),
            'other' => __('Other'),
        ];
    }

    /** Quarterly estimated-tax identifiers. */
    /** @return array<string, string> */
    public static function taxQuarters(): array
    {
        return [
            'Q1' => __('Q1'),
            'Q2' => __('Q2'),
            'Q3' => __('Q3'),
            'Q4' => __('Q4'),
        ];
    }

    /** Media-log kinds — what flavour of creative work the entry is. */
    /** @return array<string, string> */
    public static function mediaLogKinds(): array
    {
        return [
            'book' => __('Book'),
            'film' => __('Film'),
            'show' => __('TV show'),
            'podcast' => __('Podcast'),
            'article' => __('Article'),
            'game' => __('Game'),
            'other' => __('Other'),
        ];
    }

    /** Media-log status lifecycle. */
    /** @return array<string, string> */
    public static function mediaLogStatuses(): array
    {
        return [
            'wishlist' => __('Wishlist'),
            'in_progress' => __('In progress'),
            'paused' => __('Paused'),
            'done' => __('Done'),
            'dropped' => __('Dropped'),
        ];
    }

    /** Vehicle-service kinds — surfaced in the VehicleServiceLogForm
     *  picker. Free string at the schema level so one-off services
     *  ("winter storage prep", "detailing") fit without a migration. */
    /** @return array<string, string> */
    public static function vehicleServiceKinds(): array
    {
        return [
            'oil_change' => __('Oil change'),
            'tire_rotation' => __('Tire rotation'),
            'tire_replacement' => __('Tire replacement'),
            'brakes' => __('Brake service'),
            'battery' => __('Battery'),
            'inspection' => __('Inspection / smog'),
            'alignment' => __('Alignment'),
            'transmission' => __('Transmission'),
            'coolant' => __('Coolant flush'),
            'tune_up' => __('Tune-up'),
            'repair' => __('Repair'),
            'recall' => __('Recall service'),
            'detail' => __('Detail / clean'),
            'other' => __('Other'),
        ];
    }

    /** Utility-meter kinds surfaced in the MeterReadingForm picker.
     *  Schema column is free string so one-off kinds (regional sewage,
     *  cell-data caps, propane) fit without a migration. */
    /** @return array<string, string> */
    public static function meterReadingKinds(): array
    {
        return [
            'electric' => __('Electric'),
            'water' => __('Water'),
            'gas' => __('Gas'),
            'sewage' => __('Sewage'),
            'propane' => __('Propane'),
            'internet_data' => __('Internet data'),
            'other' => __('Other'),
        ];
    }

    /** Default units per meter-kind — the form auto-fills this on kind
     *  change. US-centric defaults (kWh, gallons, therms); user can
     *  override with any string. */
    /** @return array<string, string> */
    public static function meterReadingDefaultUnits(): array
    {
        return [
            'electric' => 'kWh',
            'water' => 'gal',
            'gas' => 'therm',
            'sewage' => 'gal',
            'propane' => 'gal',
            'internet_data' => 'GB',
            'other' => '',
        ];
    }

    /** Journal mood vocabulary — short tokens the picker surfaces.
     *  The schema stores free strings so adding one-off moods via
     *  typed input still works; this is only the curated menu. */
    /** @return array<string, string> */
    public static function journalMoods(): array
    {
        return [
            'good' => __('Good'),
            'great' => __('Great'),
            'neutral' => __('Neutral'),
            'low' => __('Low'),
            'anxious' => __('Anxious'),
            'tired' => __('Tired'),
            'excited' => __('Excited'),
            'grateful' => __('Grateful'),
            'frustrated' => __('Frustrated'),
            'reflective' => __('Reflective'),
        ];
    }
}
