<?php

/*
 * Enumerated string choices, kept out of the database as plain config rather
 * than DB ENUMs so every engine stores identical text and record hashes match.
 * Ported from shared/utils/constants.py.
 */

return [

    // This install's device prefix. Stamped on origin_device_id and on every
    // human-readable document number, so two devices can never mint the same one.
    'device_id' => env('PHARMACY_DEVICE_ID', 'WEB'),

    'transaction_types' => [
        'purchase', 'sale', 'return', 'adjustment', 'damage', 'expiry',
    ],

    // A positive qty_change is expected for these; negative for the rest.
    'positive_transaction_types' => ['purchase', 'return'],

    'discount_types' => ['fixed', 'percentage'],
    'payment_methods' => ['cash', 'mobile'],
    'user_roles' => ['staff', 'manager', 'owner'],
    'session_statuses' => ['open', 'closed'],
    'relationships' => ['self', 'wife', 'son', 'daughter', 'mother', 'father', 'other'],

    'po_statuses' => ['draft', 'ordered', 'partially_received', 'received', 'cancelled'],

    // A superset of payment_methods: suppliers are commonly paid by bank
    // transfer or cheque, unlike POS sales.
    'supplier_payment_methods' => ['cash', 'mobile', 'bank_transfer', 'cheque'],

    'pin' => [
        'length' => 4,
        'max_attempts' => 5,     // 5 wrong PINs in a row ...
        'lockout_minutes' => 15, // ... locks the user for 15 minutes
    ],

    'audit' => [
        'pin_lockout' => 'pin_lockout',
        'pin_changed' => 'pin_changed',
        'pin_reset' => 'pin_reset',
        'session_open' => 'session_open',
        'session_close' => 'session_close',
        'session_close_unsynced_override' => 'session_close_unsynced_override',
        'user_created' => 'user_created',
        'user_deactivated' => 'user_deactivated',
        'user_reactivated' => 'user_reactivated',
        'user_role_changed' => 'user_role_changed',
        'restore_detected' => 'restore_detected',
    ],

    'defaults' => [
        'discount_pin_threshold' => 100,
        'low_stock_threshold' => 10,
        'expiry_alert_days' => 30,
        'receipt_width' => '80mm',
    ],
];
