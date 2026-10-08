<?php
// ---------------------------------------------------------------
// Store Partner application profile — sections 2–6 of the paper
// application form (assets/img/form.jpg): type of store, years in
// business, store operating info, products of interest, preferred
// delivery days and payment method. Shared by basics/apply.php (applicant)
// and basics/admin/register_member.php (staff registering on someone's
// behalf), and displayed by basics/admin/includes/store_profile_view.php.
// Stored on basics_members (database/tindago_add_store_profile.sql).
// ---------------------------------------------------------------

function tindago_store_options() {
    return [
        'store_type' => [
            'sari_sari' => 'Sari-Sari Store',
            'mini_grocery' => 'Mini Grocery',
            'convenience' => 'Convenience Store',
            'carinderia' => 'Carinderia / Food Stall',
            'school_canteen' => 'School Canteen',
            'reseller' => 'Reseller',
            'other' => 'Other',
        ],
        'years_in_business' => [
            'lt1' => 'Less than 1 year',
            '1_3' => '1 – 3 years',
            '4_6' => '4 – 6 years',
            '7plus' => '7 years and above',
        ],
        'est_daily_sales' => [
            'below_1000' => 'Below ₱1,000',
            '1000_2500' => '₱1,000 – ₱2,500',
            '2501_5000' => '₱2,501 – ₱5,000',
            '5001_10000' => '₱5,001 – ₱10,000',
            'above_10000' => 'Above ₱10,000',
        ],
        'est_monthly_purchases' => [
            'below_5000' => 'Below ₱5,000',
            '5000_10000' => '₱5,000 – ₱10,000',
            '10001_20000' => '₱10,001 – ₱20,000',
            '20001_50000' => '₱20,001 – ₱50,000',
            'above_50000' => 'Above ₱50,000',
        ],
        'ordering_method' => [
            'walk_in' => 'Walk-in / Wholesale Market',
            'sales_agent' => 'Sales Agent',
            'facebook' => 'Facebook / Messenger',
            'online_app' => 'Online App',
            'direct_supplier' => 'Direct Supplier',
            'other' => 'Other',
        ],
        'products_interested' => [
            'rice' => 'Rice',
            'coffee' => 'Coffee',
            'instant_noodles' => 'Instant Noodles',
            'canned_goods' => 'Canned Goods',
            'biscuits_snacks' => 'Biscuits / Snacks',
            'beverages' => 'Beverages',
            'cooking_oil' => 'Cooking Oil',
            'condiments' => 'Condiments',
            'sugar_salt' => 'Sugar / Salt',
            'laundry' => 'Laundry Products',
            'household' => 'Household Products',
            'personal_care' => 'Personal Care Products',
            'frozen' => 'Frozen Products',
            'wellness' => 'Wellness Products',
            'other' => 'Other',
        ],
        'delivery_days' => [
            'mon' => 'Monday',
            'tue' => 'Tuesday',
            'wed' => 'Wednesday',
            'thu' => 'Thursday',
            'fri' => 'Friday',
            'sat' => 'Saturday',
        ],
        'preferred_payment_method' => [
            'cod' => 'Cash on Delivery',
            'bank_transfer' => 'Bank Transfer',
            'ewallet' => 'E-Wallet',
            'other' => 'Other',
        ],
    ];
}

// Blank profile — the form's initial values.
function tindago_store_profile_defaults() {
    return [
        'store_type' => '', 'store_type_other' => '',
        'years_in_business' => '',
        'store_hours_open' => '', 'store_hours_close' => '',
        'est_daily_sales' => '', 'est_monthly_purchases' => '',
        'current_suppliers' => '',
        'ordering_method' => '', 'ordering_method_other' => '',
        'products_interested' => [], 'products_interested_other' => '',
        'top_products' => ['', '', '', '', ''],
        'delivery_days' => [],
        'preferred_payment_method' => '', 'preferred_payment_other' => '',
    ];
}

// Reads sections 2–6 from $_POST. Returns [$profile, $errors]. Values not in
// the option lists are dropped, so a tampered POST can't store arbitrary keys.
function tindago_store_profile_from_post(array $post) {
    $options = tindago_store_options();
    $p = tindago_store_profile_defaults();
    $errors = [];

    foreach (['store_type', 'years_in_business', 'est_daily_sales', 'est_monthly_purchases', 'ordering_method', 'preferred_payment_method'] as $key) {
        $value = (string) ($post[$key] ?? '');
        $p[$key] = isset($options[$key][$value]) ? $value : '';
    }
    foreach (['products_interested', 'delivery_days'] as $key) {
        $values = is_array($post[$key] ?? null) ? $post[$key] : [];
        $p[$key] = array_values(array_intersect(array_keys($options[$key]), $values));
    }
    foreach (['store_type_other', 'ordering_method_other', 'products_interested_other', 'preferred_payment_other', 'current_suppliers'] as $key) {
        $p[$key] = trim((string) ($post[$key] ?? ''));
    }
    foreach (['store_hours_open', 'store_hours_close'] as $key) {
        $value = trim((string) ($post[$key] ?? ''));
        $p[$key] = preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '';
    }
    $top = is_array($post['top_products'] ?? null) ? $post['top_products'] : [];
    for ($i = 0; $i < 5; $i++) {
        $p['top_products'][$i] = trim((string) ($top[$i] ?? ''));
    }

    if ($p['store_type'] === '') $errors[] = 'Choose your type of store.';
    if ($p['store_type'] === 'other' && $p['store_type_other'] === '') $errors[] = 'Describe your type of store.';
    if ($p['years_in_business'] === '') $errors[] = 'Choose how many years your store has been in business.';
    if ($p['est_daily_sales'] === '') $errors[] = 'Choose your estimated daily sales.';
    if ($p['est_monthly_purchases'] === '') $errors[] = 'Choose your estimated monthly purchases.';
    if ($p['ordering_method'] === '') $errors[] = 'Choose how you currently order your stock.';
    if (empty($p['products_interested'])) $errors[] = 'Check at least one product you are interested in.';
    if (empty($p['delivery_days'])) $errors[] = 'Check at least one preferred delivery day.';
    if ($p['preferred_payment_method'] === '') $errors[] = 'Choose your preferred payment method.';

    return [$p, $errors];
}

// Writes the profile onto an existing basics_members row.
function tindago_store_profile_save($conn, $member_id, array $p) {
    $nullable = function ($value) {
        return $value === '' ? null : $value;
    };
    $products = $p['products_interested'] ? implode(',', $p['products_interested']) : null;
    $days = $p['delivery_days'] ? implode(',', $p['delivery_days']) : null;
    $top = array_values(array_filter($p['top_products'], 'strlen'));
    $top_json = $top ? json_encode($top, JSON_UNESCAPED_UNICODE) : null;

    $values = [
        $nullable($p['store_type']), $nullable($p['store_type_other']), $nullable($p['years_in_business']),
        $nullable($p['store_hours_open']), $nullable($p['store_hours_close']),
        $nullable($p['est_daily_sales']), $nullable($p['est_monthly_purchases']), $nullable($p['current_suppliers']),
        $nullable($p['ordering_method']), $nullable($p['ordering_method_other']),
        $products, $nullable($p['products_interested_other']), $top_json, $days,
        $nullable($p['preferred_payment_method']), $nullable($p['preferred_payment_other']),
        $member_id,
    ];
    $stmt = $conn->prepare("UPDATE basics_members SET
        store_type = ?, store_type_other = ?, years_in_business = ?, store_hours_open = ?, store_hours_close = ?,
        est_daily_sales = ?, est_monthly_purchases = ?, current_suppliers = ?, ordering_method = ?, ordering_method_other = ?,
        products_interested = ?, products_interested_other = ?, top_products = ?, delivery_days = ?,
        preferred_payment_method = ?, preferred_payment_other = ?
        WHERE id = ?");
    $stmt->bind_param(str_repeat('s', 16) . 'i', ...$values);
    $stmt->execute();
    $stmt->close();
}

// Label for a stored single-choice value, with its "Other" text if any.
function tindago_store_choice_label($field, $value, $other = null) {
    if ($value === null || $value === '') {
        return '—';
    }
    $label = tindago_store_options()[$field][$value] ?? $value;
    return ($value === 'other' && $other) ? $label . ': ' . $other : $label;
}

// Labels for a stored comma-separated multi-choice value.
function tindago_store_multi_labels($field, $csv, $other = null) {
    $options = tindago_store_options()[$field];
    $labels = [];
    foreach (array_filter(explode(',', (string) $csv)) as $key) {
        $labels[] = ($key === 'other' && $other) ? 'Other: ' . $other : ($options[$key] ?? $key);
    }
    return $labels;
}

// Section 8 documents and how they're labelled everywhere (apply form,
// register member, admin review). 'required' applies to the online form.
function tindago_kyc_doc_types() {
    return [
        'valid_id_1' => ['label' => 'Valid Government ID (of Store Owner)', 'required' => true],
        'store_photo_front' => ['label' => 'Store Photo (Front View)', 'required' => true],
        'store_photo_inside' => ['label' => 'Store Photo (Inside View)', 'required' => true],
        'barangay_clearance' => ['label' => 'Proof of Store Address (e.g. Barangay Certificate)', 'required' => true],
        'valid_id_2' => ['label' => 'Second Valid ID', 'required' => false],
        'certificate_of_employment' => ['label' => 'Business Permit / DTI / Barangay Business Clearance', 'required' => false],
        'membership_application_form' => ['label' => 'Signed Paper Application Form - Front Page', 'required' => false],
        'membership_application_form_back' => ['label' => 'Signed Paper Application Form - Back Page', 'required' => false],
    ];
}

function tindago_kyc_doc_labels() {
    return array_map(function ($doc) {
        return $doc['label'];
    }, tindago_kyc_doc_types());
}
