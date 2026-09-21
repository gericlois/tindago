-- One-time live migration: adds the Phase 2 member benefits module
-- (Electric Subsidy, Hospital Assistance, Burial Assistance, Baon Eskwela)
-- and the payout-account enrollment feature. Purely additive — 3 new
-- tables, no changes to any existing table. Safe to run any time.
-- Run via phpMyAdmin's SQL tab on if0_42496216_jmcfoodiespremium.

CREATE TABLE basics_benefit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    benefit_type ENUM('electric_subsidy','hospital_assistance','burial_assistance','baon_eskwela') NOT NULL,
    amount_due DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    relationship_to_deceased VARCHAR(100) DEFAULT NULL,
    deceased_address VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    admin_notes VARCHAR(255) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES basics_members(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE basics_benefit_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    doc_type ENUM('electric_bill','medical_abstract','hospital_bill','prescription','death_certificate','enrollment_form','child_id','birth_certificate') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES basics_benefit_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE basics_payout_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL UNIQUE,
    method ENUM('gotyme','gcash','bank') NOT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES basics_members(id) ON DELETE CASCADE
) ENGINE=InnoDB;
