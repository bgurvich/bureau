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
            'bank' => __('Bank'),
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
    public static function contactKinds(): array
    {
        return [
            'person' => __('Person'),
            'org' => __('Organization'),
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
}
