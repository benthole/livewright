# LiveWright PDP - Database Schema Reference

## Connection Details

| Parameter | Value |
|-----------|-------|
| Host | `mysql.exactsite.com` |
| Database | `livewright` |
| User | `app_user_lw` |
| Driver | PDO (MySQL) |

---

## Entity Relationship Diagram

```
┌─────────────────┐
│   admin_users   │
├─────────────────┤
│ id (PK)         │
│ username        │
│ password_hash   │
└─────────────────┘

┌─────────────────────────────────┐       ┌─────────────────────────────┐
│           contracts             │       │      pricing_options        │
├─────────────────────────────────┤       ├─────────────────────────────┤
│ id (PK)                         │◄──────│ contract_id (FK)            │
│ unique_id                       │  1:N  │ id (PK)                     │
│ first_name                      │       │ option_number               │
│ last_name                       │       │ sub_option_name             │
│ email                           │       │ description                 │
│ contract_description            │       │ price                       │
│ pdp_from                        │       │ type                        │
│ pdp_toward                      │       │ deleted_at                  │
│ option_1_minimum_months         │       └─────────────────────────────┘
│ option_2_minimum_months         │
│ option_3_minimum_months         │
│ selected_option_id (FK) ────────┼───────► (references pricing_options.id)
│ signed                          │
│ created_at                      │
│ deleted_at                      │
└─────────────────────────────────┘

┌─────────────────────────────────┐
│          pdp_presets            │
├─────────────────────────────────┤
│ id (PK)                         │
│ name                            │
│ contract_description            │
│ pdp_from                        │
│ pdp_toward                      │
│ option_1_desc                   │
│ option_1_price_monthly          │
│ option_1_price_quarterly        │
│ option_1_price_yearly           │
│ option_1_sub_options (JSON)     │
│ option_1_minimum_months         │
│ option_2_desc                   │
│ option_2_price_monthly          │
│ option_2_price_quarterly        │
│ option_2_price_yearly           │
│ option_2_sub_options (JSON)     │
│ option_2_minimum_months         │
│ option_3_desc                   │
│ option_3_price_monthly          │
│ option_3_price_quarterly        │
│ option_3_price_yearly           │
│ option_3_sub_options (JSON)     │
│ option_3_minimum_months         │
└─────────────────────────────────┘
```

---

## Table Definitions

### admin_users

Stores administrator credentials for the admin panel.

```sql
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
);
```

**Sample Data:**
```sql
INSERT INTO admin_users (username, password_hash)
VALUES ('admin', '$2y$10$...'); -- password: admin123
```

---

### contracts

Main table storing Personal Development Plans.

```sql
CREATE TABLE contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unique_id VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    contract_description TEXT,
    pdp_from TEXT,
    pdp_toward TEXT,
    option_1_minimum_months INT DEFAULT 1,
    option_2_minimum_months INT DEFAULT 1,
    option_3_minimum_months INT DEFAULT 1,
    selected_option_id INT NULL,
    signed TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    INDEX idx_unique_id (unique_id),
    INDEX idx_email (email),
    INDEX idx_deleted_at (deleted_at),
    FOREIGN KEY (selected_option_id) REFERENCES pricing_options(id)
);
```

**Column Details:**

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | INT | No | Auto | Primary key |
| `unique_id` | VARCHAR(50) | No | - | Public identifier, generated via `uniqid('', true)` |
| `first_name` | VARCHAR(255) | No | - | Client first name |
| `last_name` | VARCHAR(255) | No | - | Client last name |
| `email` | VARCHAR(255) | No | - | Client email, unique per active record |
| `contract_description` | TEXT | Yes | NULL | HTML - items included in all options |
| `pdp_from` | TEXT | Yes | NULL | HTML - present state/challenges |
| `pdp_toward` | TEXT | Yes | NULL | HTML - ideal state/outcomes |
| `option_1_minimum_months` | INT | No | 1 | Minimum commitment for option 1 |
| `option_2_minimum_months` | INT | No | 1 | Minimum commitment for option 2 |
| `option_3_minimum_months` | INT | No | 1 | Minimum commitment for option 3 |
| `selected_option_id` | INT | Yes | NULL | FK to chosen pricing option |
| `signed` | TINYINT(1) | No | 0 | Boolean - plan signed by client |
| `created_at` | DATETIME | No | NOW() | Record creation timestamp |
| `deleted_at` | DATETIME | Yes | NULL | Soft delete timestamp |

---

### pricing_options

Stores pricing tiers for each contract option.

```sql
CREATE TABLE pricing_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    option_number INT NOT NULL,
    sub_option_name VARCHAR(255) DEFAULT 'Default',
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    type ENUM('Monthly', 'Quarterly', 'Yearly') NOT NULL,
    deleted_at DATETIME NULL,

    INDEX idx_contract_id (contract_id),
    INDEX idx_deleted_at (deleted_at),
    FOREIGN KEY (contract_id) REFERENCES contracts(id)
);
```

**Column Details:**

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | INT | No | Auto | Primary key |
| `contract_id` | INT | No | - | FK to contracts table |
| `option_number` | INT | No | - | Grouping: 1, 2, or 3 |
| `sub_option_name` | VARCHAR(255) | No | 'Default' | Sub-option label (e.g., coach name) |
| `description` | TEXT | Yes | - | HTML description of the option |
| `price` | DECIMAL(10,2) | No | - | Price amount |
| `type` | ENUM | No | - | 'Monthly', 'Quarterly', 'Yearly' |
| `deleted_at` | DATETIME | Yes | NULL | Soft delete timestamp |

**Data Pattern:**

For a simple option (no sub-options):
```sql
-- Option 1 with Monthly, Quarterly, Yearly pricing
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type)
VALUES
    (1, 1, 'Default', '<p>Basic coaching...</p>', 99.00, 'Monthly'),
    (1, 1, 'Default', '<p>Basic coaching...</p>', 282.15, 'Quarterly'),
    (1, 1, 'Default', '<p>Basic coaching...</p>', 1069.20, 'Yearly');
```

For an option with sub-options (multiple coaches):
```sql
-- Option 2 with sub-options for different coaches
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type)
VALUES
    (1, 2, 'Elizabeth', '<p>Premium coaching...</p>', 299.00, 'Monthly'),
    (1, 2, 'Elizabeth', '<p>Premium coaching...</p>', 851.15, 'Quarterly'),
    (1, 2, 'Elizabeth', '<p>Premium coaching...</p>', 3229.20, 'Yearly'),
    (1, 2, 'Judith', '<p>Premium coaching...</p>', 249.00, 'Monthly'),
    (1, 2, 'Judith', '<p>Premium coaching...</p>', 709.65, 'Quarterly'),
    (1, 2, 'Judith', '<p>Premium coaching...</p>', 2689.20, 'Yearly');
```

---

### pdp_presets

Template configurations for quick plan creation.

```sql
CREATE TABLE pdp_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    contract_description TEXT,
    pdp_from TEXT,
    pdp_toward TEXT,

    option_1_desc TEXT,
    option_1_price_monthly DECIMAL(10,2),
    option_1_price_quarterly DECIMAL(10,2),
    option_1_price_yearly DECIMAL(10,2),
    option_1_sub_options TEXT,
    option_1_minimum_months INT DEFAULT 1,

    option_2_desc TEXT,
    option_2_price_monthly DECIMAL(10,2),
    option_2_price_quarterly DECIMAL(10,2),
    option_2_price_yearly DECIMAL(10,2),
    option_2_sub_options TEXT,
    option_2_minimum_months INT DEFAULT 1,

    option_3_desc TEXT,
    option_3_price_monthly DECIMAL(10,2),
    option_3_price_quarterly DECIMAL(10,2),
    option_3_price_yearly DECIMAL(10,2),
    option_3_sub_options TEXT,
    option_3_minimum_months INT DEFAULT 1
);
```

**Sub-Options JSON Structure:**

The `option_X_sub_options` columns store JSON arrays:

```json
[
    {
        "name": "Elizabeth",
        "monthly": 299.00,
        "quarterly": 851.15,
        "yearly": 3229.20
    },
    {
        "name": "Judith",
        "monthly": 249.00,
        "quarterly": 709.65,
        "yearly": 2689.20
    }
]
```

---

## Common Queries

### Get Active Contracts (Dashboard)

```sql
SELECT id, unique_id, first_name, last_name, email, signed, created_at
FROM contracts
WHERE deleted_at IS NULL
ORDER BY id DESC;
```

### Get Contract by Public ID

```sql
SELECT * FROM contracts
WHERE unique_id = ? AND deleted_at IS NULL;
```

### Get Pricing Options for Contract

```sql
SELECT * FROM pricing_options
WHERE contract_id = ? AND deleted_at IS NULL
ORDER BY option_number, sub_option_name, FIELD(type, 'Monthly', 'Quarterly', 'Yearly');
```

### Check Email Uniqueness

```sql
SELECT id FROM contracts
WHERE email = ? AND deleted_at IS NULL AND id != ?;
```

### Soft Delete Contract and Options

```sql
UPDATE contracts SET deleted_at = NOW() WHERE id = ?;
UPDATE pricing_options SET deleted_at = NOW() WHERE contract_id = ?;
```

### Mark Contract as Signed

```sql
UPDATE contracts
SET selected_option_id = ?, signed = 1, first_name = ?, last_name = ?, email = ?
WHERE id = ?;
```

---

## Indexes

| Table | Index Name | Columns | Purpose |
|-------|------------|---------|---------|
| contracts | PRIMARY | id | Primary key |
| contracts | idx_unique_id | unique_id | Public URL lookup |
| contracts | idx_email | email | Email uniqueness check |
| contracts | idx_deleted_at | deleted_at | Soft delete filtering |
| pricing_options | PRIMARY | id | Primary key |
| pricing_options | idx_contract_id | contract_id | FK relationship |
| pricing_options | idx_deleted_at | deleted_at | Soft delete filtering |

---

## Data Retention

- **Contracts:** Soft-deleted (preserved indefinitely)
- **Pricing Options:** Soft-deleted (preserved indefinitely)
- **Presets:** Hard-deleted (permanently removed)
- **Admin Users:** No deletion mechanism in admin UI

---

## Migration Notes

If creating tables from scratch, execute in this order:
1. `admin_users`
2. `contracts`
3. `pricing_options`
4. `pdp_presets`

The `pricing_options` table has a foreign key to `contracts`, and `contracts.selected_option_id` has a foreign key to `pricing_options`.

---

*Schema documented: January 2026*
