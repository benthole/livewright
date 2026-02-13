-- Migration: Create scholarship_applications table
-- Date: 2026-02-13

CREATE TABLE IF NOT EXISTS scholarship_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unique_id VARCHAR(36) NOT NULL UNIQUE,

    -- Application type
    application_type ENUM('mission_discount', 'need_scholarship') NOT NULL,
    status ENUM('pending', 'under_review', 'approved', 'denied') DEFAULT 'pending',

    -- Contact info
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    street_address VARCHAR(255),
    city VARCHAR(100),
    state_zip VARCHAR(50),
    country VARCHAR(100) DEFAULT 'United States',
    cell_phone VARCHAR(30),
    work_phone VARCHAR(30),
    other_phone VARCHAR(30),

    -- Income (need-based)
    gross_income VARCHAR(100),
    gross_income_spouse VARCHAR(100),
    other_income_sources TEXT,
    other_assets_income TEXT,

    -- Expenses (need-based)
    has_alimony TINYINT(1) DEFAULT 0,
    alimony_percent VARCHAR(50),
    has_student_loans TINYINT(1) DEFAULT 0,
    student_loan_monthly VARCHAR(50),
    has_medical_expenses TINYINT(1) DEFAULT 0,
    medical_expenses_monthly VARCHAR(50),
    has_familial_support TINYINT(1) DEFAULT 0,
    familial_support_monthly VARCHAR(50),
    has_dependent_college TINYINT(1) DEFAULT 0,
    dependent_college_count VARCHAR(50),
    has_children_under_18 TINYINT(1) DEFAULT 0,
    children_names_ages TEXT,
    additional_info TEXT,

    -- Mission info (all applicants)
    is_educator TINYINT(1) DEFAULT 0,
    is_nonprofit TINYINT(1) DEFAULT 0,
    is_coach TINYINT(1) DEFAULT 0,
    is_entrepreneur TINYINT(1) DEFAULT 0,
    employer_name VARCHAR(255),
    essay TEXT,

    -- File uploads (JSON array of file paths)
    documentation_files JSON,

    -- Keap integration
    keap_contact_id INT,

    -- Admin
    admin_notes TEXT,
    reviewed_by VARCHAR(100),
    reviewed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL
);
