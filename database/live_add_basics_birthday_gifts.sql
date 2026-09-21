-- Birthday Grocery Gift (JMC Basics). One row per member per birthday year:
-- greeted_at = when the 6AM email/SMS greeting went out (also the dedupe
-- guard so nobody is greeted twice), claimed_at = when the member pressed
-- "Claim My Birthday Gift" on their dashboard.
-- Paste into phpMyAdmin on the live InfinityFree DB BEFORE deploying the code.
CREATE TABLE IF NOT EXISTS basics_birthday_gifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    birthday_year SMALLINT NOT NULL,
    greeted_at TIMESTAMP NULL DEFAULT NULL,
    claimed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY member_year (member_id, birthday_year),
    FOREIGN KEY (member_id) REFERENCES basics_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
