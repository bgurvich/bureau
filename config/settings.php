<?php

/**
 * Declarative schema for the three-scope settings editor.
 *
 * Shape per setting:
 *   key         string — short identifier (dot-less; namespaced by scope).
 *   label       string — form label.
 *   type        bool | int | string | text | enum | url | email.
 *   default     mixed  — hard-coded fallback when neither DB nor env supplies a value.
 *   env         string — optional .env var name read if the DB bag doesn't set the key.
 *   options     array  — for type=enum only, [value => label].
 *   description string — hint shown below the field.
 *
 * Read order: DB bag → env (if declared) → default.
 * Write target: DB bag only; env stays in .env.
 */

return [
    'app' => [
        [
            'key' => 'default_theme',
            'label' => 'Default theme',
            'type' => 'enum',
            'options' => [
                'dark' => 'Dark',
                'light' => 'Light',
                'dusk' => 'Dusk',
                'dusk-comfort' => 'Dusk (comfort)',
                'retro' => 'Retro',
            ],
            'default' => 'dusk',
            'description' => 'Palette applied to new users before they pick their own.',
        ],
        [
            'key' => 'maintenance_banner',
            'label' => 'Maintenance banner',
            'type' => 'text',
            'default' => '',
            'description' => 'Site-wide banner shown above every page when non-empty. Useful for planned downtime notices.',
        ],
        [
            'key' => 'allow_registration',
            'label' => 'Allow public registration',
            'type' => 'bool',
            'default' => false,
            'description' => 'Single-user instance: leave off. Turning on exposes the /register route.',
        ],
    ],

    'household' => [
        [
            'key' => 'default_currency',
            'label' => 'Default currency',
            'type' => 'string',
            'default' => 'USD',
            'description' => 'ISO 4217 code. Used for new accounts, subscriptions, and tax rows unless overridden.',
        ],
        [
            'key' => 'week_starts_on',
            'label' => 'Week starts on',
            'type' => 'enum',
            'options' => [
                'sunday' => 'Sunday',
                'monday' => 'Monday',
            ],
            'default' => 'monday',
            'description' => 'Affects the calendar grid and the weekly-review date range.',
        ],
        [
            'key' => 'attention_radar_digest_email',
            'label' => 'Weekly digest email',
            'type' => 'bool',
            'default' => true,
            'description' => 'Send the Monday morning attention-radar summary to household members.',
        ],
    ],

    'user' => [
        [
            'key' => 'dashboard_show_birthdays',
            'label' => 'Show birthdays on dashboard',
            'type' => 'bool',
            'default' => true,
            'description' => 'Surfaces the upcoming-birthdays tile in the Relationships radar.',
        ],
        [
            'key' => 'notification_reminders_email',
            'label' => 'Email me reminders',
            'type' => 'bool',
            'default' => true,
            'description' => 'Reminder-due notifications land in your inbox as they fire.',
        ],
    ],
];
