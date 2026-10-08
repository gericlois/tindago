<?php
// Read-only display of a member's Store Partner application (sections 1–6
// of the form). Expects $sp_row: a basics_members row joined with the
// basics_users fields full_name, contact_number and address.
$sp_hours = ($sp_row['store_hours_open'] && $sp_row['store_hours_close'])
    ? date('g:i A', strtotime($sp_row['store_hours_open'])) . ' – ' . date('g:i A', strtotime($sp_row['store_hours_close']))
    : '—';
$sp_top = json_decode((string) $sp_row['top_products'], true) ?: [];
$sp_products = tindago_store_multi_labels('products_interested', $sp_row['products_interested'], $sp_row['products_interested_other']);
$sp_days = tindago_store_multi_labels('delivery_days', $sp_row['delivery_days']);
$sp_lines = [
    'Store / Business Name' => $sp_row['employer_name'],
    'Store Owner / Proprietor' => $sp_row['full_name'],
    'Mobile Number' => $sp_row['contact_number'],
    'Alternative Contact Number' => $sp_row['employer_contact'] ?: '—',
    'Complete Store Address' => $sp_row['address'],
    'Type of Store' => tindago_store_choice_label('store_type', $sp_row['store_type'], $sp_row['store_type_other']),
    'Years in Business' => tindago_store_choice_label('years_in_business', $sp_row['years_in_business']),
    'Operating Hours' => $sp_hours,
    'Estimated Daily Sales' => tindago_store_choice_label('est_daily_sales', $sp_row['est_daily_sales']),
    'Estimated Monthly Purchases' => tindago_store_choice_label('est_monthly_purchases', $sp_row['est_monthly_purchases']),
    'Current Main Suppliers' => $sp_row['current_suppliers'] ?: '—',
    'Current Ordering Method' => tindago_store_choice_label('ordering_method', $sp_row['ordering_method'], $sp_row['ordering_method_other']),
    'Products Interested In' => $sp_products ? implode(', ', $sp_products) : '—',
    'Top Products Reordered' => $sp_top ? implode(', ', $sp_top) : '—',
    'Preferred Delivery Days' => $sp_days ? implode(', ', $sp_days) : '—',
    'Preferred Payment Method' => tindago_store_choice_label('preferred_payment_method', $sp_row['preferred_payment_method'], $sp_row['preferred_payment_other']),
    'Declaration Accepted' => $sp_row['declaration_accepted_at'] ? date('M j, Y g:i A', strtotime($sp_row['declaration_accepted_at'])) : '—',
];
?>
<dl class="row small mb-0">
  <?php foreach ($sp_lines as $sp_label => $sp_value): ?>
    <dt class="col-sm-5 fw-semibold"><?= sanitize($sp_label) ?></dt>
    <dd class="col-sm-7 mb-1"><?= sanitize($sp_value) ?></dd>
  <?php endforeach; ?>
</dl>
