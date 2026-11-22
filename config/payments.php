<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform fee
    |--------------------------------------------------------------------------
    |
    | Flat fee in rupees added to every online contribution and kept in the
    | platform's Razorpay account. Shown to the payer before they pay.
    |
    */

    'platform_fee' => (float) env('PLATFORM_FEE', 2),

    /*
    |--------------------------------------------------------------------------
    | Razorpay Route
    |--------------------------------------------------------------------------
    |
    | When enabled, organisers' payout accounts are registered as Route linked
    | accounts and each captured contribution is transferred straight to the
    | organiser's bank. Online payments are only offered while this is on and
    | the organiser's linked account is active. Route must first be activated
    | on the Razorpay account.
    |
    */

    'route_enabled' => (bool) env('RAZORPAY_ROUTE_ENABLED', false),

    // Business profile sent when creating a linked account (see Razorpay's linked account docs).
    'linked_account' => [
        'business_type' => env('RAZORPAY_LINKED_BUSINESS_TYPE', 'individual'),
        'category' => env('RAZORPAY_LINKED_CATEGORY', 'others'),
        'subcategory' => env('RAZORPAY_LINKED_SUBCATEGORY', 'others'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment charges
    |--------------------------------------------------------------------------
    |
    | UPI and RuPay debit can't carry any charge (PSS Act s.10A / IT Act s.269SU),
    | so UPI is always ₹0. For cards / netbanking, set customer_fee_bearer=true
    | only once Razorpay has enabled "Customer Fee Bearer" on the account: Razorpay
    | then adds its fee at checkout. card_fee_percent (+ GST) is only used to show
    | the payer an estimate up front.
    |
    */

    'customer_fee_bearer' => (bool) env('RAZORPAY_CUSTOMER_FEE_BEARER', false),
    'card_fee_percent' => (float) env('RAZORPAY_CARD_FEE_PERCENT', 2),
    'gst_percent' => (float) env('PAYMENT_GST_PERCENT', 18),

];
