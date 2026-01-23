# LiveWright PDP - Administrator Guide

## Accessing the Admin Panel

**URL:** `https://checkout.livewright.com/personal-development-plan/admin/`

**Default Credentials:**
- Username: `admin`
- Password: `admin123`

---

## Dashboard Overview

The admin dashboard displays all Personal Development Plans in a table format:

| Column | Description |
|--------|-------------|
| First Name | Client's first name |
| Last Name | Client's last name |
| Email | Client's email address |
| Date Created | When the plan was created |
| Signed | Checkmark if client has signed |
| Link | View and Copy buttons for client URL |
| Actions | Edit and Delete buttons |

---

## Creating a New Plan

### Method 1: From Scratch

1. Click **"Add New Plan"** button
2. Fill out the form (details below)
3. Click **"Save"**

### Method 2: Using a Preset

1. Click **"Add New Plan"** button
2. In the "Quick Start with Presets" section, click a preset button
3. Form will be pre-filled with the preset configuration
4. Modify as needed
5. Click **"Save"**

---

## Plan Form Fields

### Client Information

| Field | Required | Description |
|-------|----------|-------------|
| First Name | Yes | Client's first name |
| Last Name | Yes | Client's last name |
| Email | Yes | Client's email (must be unique) |

### Content Sections (Rich Text)

| Field | Description |
|-------|-------------|
| FROM - Present State Challenges and Opportunities | Describes where the client is now |
| TOWARD - Ideal State Outcomes | Describes where the client wants to be |
| Included in Each Option | Items/services included with all pricing options |

### Pricing Options (1-3)

Each option can have:

**Simple Pricing:**
- Description (rich text)
- Monthly price (manually entered)
- Quarterly price (auto-calculated: monthly * 3 - 5%)
- Yearly price (auto-calculated: monthly * 12 - 10%)
- Minimum commitment months

**Sub-Option Pricing:**
For plans with variations (e.g., different coaches):
1. Click **"+ Add Sub-Option"**
2. Enter sub-option name (e.g., "Elizabeth", "Judith")
3. Enter monthly price (quarterly/yearly auto-calculated)
4. Repeat for additional sub-options

---

## Pricing Calculation

The system automatically calculates discounted pricing:

| Period | Calculation | Discount |
|--------|-------------|----------|
| Monthly | Manual entry | None |
| Quarterly | Monthly * 3 * 0.95 | 5% off |
| Yearly | Monthly * 12 * 0.90 | 10% off |

**Example:**
- Monthly: $100
- Quarterly: $285.00 ($300 - 5%)
- Yearly: $1,080.00 ($1,200 - 10%)

---

## Editing a Plan

1. Find the plan in the dashboard
2. Click **"Edit"** button
3. Modify any fields
4. Click **"Save"**

**Note:** When you save an edited plan, all existing pricing options are soft-deleted and recreated. This ensures clean data but means the IDs change.

---

## Deleting a Plan

1. Find the plan in the dashboard
2. Click **"Delete"** button
3. Confirm the deletion

**Note:** Deletions are "soft deletes" - the data is preserved in the database with a `deleted_at` timestamp. Plans can be recovered by a database administrator.

---

## Sharing a Plan with a Client

### Copy Link

1. Find the plan in the dashboard
2. Click **"Copy"** button in the Link column
3. Paste the URL in an email or message

### View Link

1. Click **"View"** button to open the client-facing page in a new tab
2. Review the plan as the client will see it

**URL Format:**
```
https://checkout.livewright.com/personal-development-plan/?uid=UNIQUE_ID
```

---

## Managing Presets

### Accessing Presets

Click **"Manage Presets"** button from the dashboard.

### Creating a Preset

1. Click **"Add New Preset"**
2. Enter a preset name
3. Fill out all sections you want pre-configured
4. Click **"Save Preset"**

### Editing a Preset

1. Find the preset in the list
2. Click **"Edit"**
3. Modify any fields
4. Click **"Save Preset"**

### Deleting a Preset

1. Find the preset in the list
2. Click **"Delete"**
3. Confirm the deletion

**Warning:** Preset deletions are permanent (hard delete). They cannot be recovered.

---

## What Clients See

When a client visits their plan URL:

1. **Header:** Plan title and their name
2. **Contract Details:** Their name, email, creation date
3. **FROM/TOWARD Sections:** Present challenges and ideal outcomes
4. **What's Included:** Items included with all options
5. **Available Options:** Pricing cards organized by:
   - Option number (1, 2, 3)
   - Billing period (Monthly, Quarterly, Yearly columns)
   - Sub-options (if applicable)
6. **Selection Form:** Choose option and continue to checkout

### Client Checkout Flow

1. Client clicks on a pricing card to select it
2. Card highlights, "Continue to Checkout" button enables
3. Client clicks "Continue to Checkout"
4. Confirmation page shows selection summary
5. Client clicks "Confirm & Sign Plan"
6. Success page displays with plan details

---

## Viewing Signed Plans

After a client signs:

1. The "Signed" column shows a checkmark
2. The selected option ID is stored in the database
3. The client-facing page shows a "Signed" badge

---

## Troubleshooting

### "Email already exists" Error

- Each email can only have one active plan
- Either delete the existing plan or use a different email

### Pricing Not Calculating

- Quarterly and yearly are auto-calculated from monthly
- Enter the monthly price first
- Calculations happen in real-time via JavaScript

### Rich Text Editor Not Working

- The editor requires JavaScript
- Ensure JavaScript is enabled in your browser
- Try a different browser if issues persist

### Changes Not Saving

- Check for validation errors (shown in red at top)
- Ensure required fields are filled
- Try refreshing and re-entering data

---

## Best Practices

1. **Use descriptive content:** The FROM/TOWARD sections help clients understand the value
2. **Set appropriate minimums:** Use minimum commitment months for high-value options
3. **Preview before sharing:** Always click "View" to see the client experience
4. **Create presets:** Save time with presets for common plan configurations
5. **Keep emails unique:** Use client-specific emails for each plan

---

*Guide last updated: January 2026*
