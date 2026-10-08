<?php
// Sections 2–6 of the Store Partner application form. Expects $sp (from
// tindago_store_profile_defaults() / tindago_store_profile_from_post()).
$sp_options = tindago_store_options();

// One radio/checkbox grid. $multi = checkboxes posted as name[].
$sp_choices = function ($name, $selected, $multi = false, $cols = 'row-cols-1 row-cols-sm-2') use ($sp_options) {
    echo '<div class="row ' . $cols . ' g-1 mb-2">';
    foreach ($sp_options[$name] as $value => $label) {
        $id = 'sp_' . $name . '_' . $value;
        $checked = $multi ? in_array($value, (array) $selected, true) : $selected === $value;
        echo '<div class="col"><div class="form-check">'
            . '<input class="form-check-input" type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . $name . ($multi ? '[]' : '') . '"'
            . ' id="' . $id . '" value="' . sanitize($value) . '"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . sanitize($label) . '</label>'
            . '</div></div>';
    }
    echo '</div>';
};
?>
<div class="form-section">
  <h2 class="form-section-title"><span>2</span>Type of Store</h2>
  <p class="form-text mb-2">Check one.</p>
  <?php $sp_choices('store_type', $sp['store_type']); ?>
  <input type="text" name="store_type_other" class="fctrl mb-3" value="<?= sanitize($sp['store_type_other']) ?>" placeholder="If Other, please specify">

  <label class="flbl">Years in Business</label>
  <?php $sp_choices('years_in_business', $sp['years_in_business']); ?>
</div>

<div class="form-section">
  <h2 class="form-section-title"><span>3</span>Store Operating Information</h2>
  <label class="flbl">Store Operating Hours</label>
  <div class="d-flex align-items-center gap-2 mb-3">
    <input type="time" name="store_hours_open" class="fctrl" value="<?= sanitize($sp['store_hours_open']) ?>" aria-label="Opening time">
    <span>to</span>
    <input type="time" name="store_hours_close" class="fctrl" value="<?= sanitize($sp['store_hours_close']) ?>" aria-label="Closing time">
  </div>

  <label class="flbl">Estimated Daily Sales</label>
  <?php $sp_choices('est_daily_sales', $sp['est_daily_sales'], false, 'row-cols-1 row-cols-sm-2 row-cols-lg-3'); ?>

  <label class="flbl mt-2">Estimated Monthly Purchases</label>
  <?php $sp_choices('est_monthly_purchases', $sp['est_monthly_purchases'], false, 'row-cols-1 row-cols-sm-2 row-cols-lg-3'); ?>

  <div class="mb-3 mt-2">
    <label class="flbl">Current Main Suppliers (optional)</label>
    <input type="text" name="current_suppliers" class="fctrl" value="<?= sanitize($sp['current_suppliers']) ?>">
  </div>

  <label class="flbl">Current Ordering Method</label>
  <?php $sp_choices('ordering_method', $sp['ordering_method']); ?>
  <input type="text" name="ordering_method_other" class="fctrl" value="<?= sanitize($sp['ordering_method_other']) ?>" placeholder="If Other, please specify">
</div>

<div class="form-section">
  <h2 class="form-section-title"><span>4</span>Products You Are Interested In</h2>
  <p class="form-text mb-2">Check all that apply.</p>
  <?php $sp_choices('products_interested', $sp['products_interested'], true, 'row-cols-2 row-cols-lg-3'); ?>
  <input type="text" name="products_interested_other" class="fctrl mb-3" value="<?= sanitize($sp['products_interested_other']) ?>" placeholder="If Other, please specify">

  <div class="form-subpanel">
    <label class="flbl">Top 5 Products You Frequently Reorder (optional)</label>
    <div class="row g-2">
      <?php for ($i = 0; $i < 5; $i++): ?>
        <div class="col-sm-6">
          <div class="d-flex align-items-center gap-2">
            <span class="small fw-semibold"><?= $i + 1 ?>.</span>
            <input type="text" name="top_products[]" class="fctrl" value="<?= sanitize($sp['top_products'][$i] ?? '') ?>">
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<div class="form-section">
  <h2 class="form-section-title"><span>5</span>Preferred Delivery Days</h2>
  <p class="form-text mb-2">Check all that apply.</p>
  <?php $sp_choices('delivery_days', $sp['delivery_days'], true, 'row-cols-2 row-cols-sm-3'); ?>
</div>

<div class="form-section">
  <h2 class="form-section-title"><span>6</span>Preferred Payment Method</h2>
  <p class="form-text mb-2">Check one.</p>
  <?php $sp_choices('preferred_payment_method', $sp['preferred_payment_method'], false, 'row-cols-2 row-cols-sm-4'); ?>
  <input type="text" name="preferred_payment_other" class="fctrl" value="<?= sanitize($sp['preferred_payment_other']) ?>" placeholder="If Other, please specify">
</div>
