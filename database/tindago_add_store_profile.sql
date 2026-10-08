-- =====================================================================
-- Store Partner application form (assets/img/form.jpg): store profile
-- fields for sections 2–6 and 10, plus the two store-photo document types.
-- Already folded into database/tindago_schema.sql — run this only on a
-- database created before that.
-- =====================================================================

ALTER TABLE basics_members
  ADD COLUMN store_type VARCHAR(30) DEFAULT NULL AFTER employer_address,
  ADD COLUMN store_type_other VARCHAR(100) DEFAULT NULL AFTER store_type,
  ADD COLUMN years_in_business VARCHAR(20) DEFAULT NULL AFTER store_type_other,
  ADD COLUMN store_hours_open TIME DEFAULT NULL AFTER years_in_business,
  ADD COLUMN store_hours_close TIME DEFAULT NULL AFTER store_hours_open,
  ADD COLUMN est_daily_sales VARCHAR(20) DEFAULT NULL AFTER store_hours_close,
  ADD COLUMN est_monthly_purchases VARCHAR(20) DEFAULT NULL AFTER est_daily_sales,
  ADD COLUMN current_suppliers VARCHAR(255) DEFAULT NULL AFTER est_monthly_purchases,
  ADD COLUMN ordering_method VARCHAR(30) DEFAULT NULL AFTER current_suppliers,
  ADD COLUMN ordering_method_other VARCHAR(100) DEFAULT NULL AFTER ordering_method,
  ADD COLUMN products_interested VARCHAR(500) DEFAULT NULL AFTER ordering_method_other,
  ADD COLUMN products_interested_other VARCHAR(150) DEFAULT NULL AFTER products_interested,
  ADD COLUMN top_products VARCHAR(600) DEFAULT NULL AFTER products_interested_other,
  ADD COLUMN delivery_days VARCHAR(100) DEFAULT NULL AFTER top_products,
  ADD COLUMN preferred_payment_method VARCHAR(30) DEFAULT NULL AFTER delivery_days,
  ADD COLUMN preferred_payment_other VARCHAR(100) DEFAULT NULL AFTER preferred_payment_method,
  ADD COLUMN assigned_agent VARCHAR(150) DEFAULT NULL AFTER preferred_payment_other,
  ADD COLUMN territory VARCHAR(150) DEFAULT NULL AFTER assigned_agent,
  ADD COLUMN declaration_accepted_at TIMESTAMP NULL DEFAULT NULL AFTER territory;

ALTER TABLE basics_kyc_documents
  MODIFY doc_type ENUM('valid_id_1','valid_id_2','barangay_clearance','membership_application_form',
                       'membership_application_form_back','certificate_of_employment',
                       'store_photo_front','store_photo_inside') NOT NULL;
