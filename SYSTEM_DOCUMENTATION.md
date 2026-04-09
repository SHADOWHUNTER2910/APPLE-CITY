# Stock Manager & Expiry Tracker
## Complete System Documentation

---

## Overview
A fully offline desktop POS and inventory management system built with Electron, PHP, and SQLite. Designed for retail and wholesale businesses. No internet connection required.

---

## 1. Authentication
- Login with username and password (bcrypt hashed)
- Two roles: **Admin** and **User (Cashier)**
- Session-based authentication
- Inactive accounts cannot log in
- Default admin: `admin` / `Admin@123`

---

## 2. Dashboard
### Stat Cards (all clickable - navigate to their section)
| Card | Navigates To |
|---|---|
| Total Products | Product Management |
| Items in Stock | Stock Management |
| Low Stock Items | Stock Management |
| Expiring Soon + Expired | Expiry Management |
| Total Receipts | Receipt History |
| Today's Revenue | Analytics |

- Cards have hover lift effect
- Expiring Soon and Expired shown as separate counts with icons

### Dashboard Widgets
- Expiring products list (next 90 days + expired, color-coded)
- Low stock alerts (items below 10 units)
- Recent activity (last 5 receipts)
- Expiry popup notification on login (dismissed daily, per-batch acknowledgment)
- Sidebar badge showing count of critical expiry items

---

## 3. Product Management *(Admin only)*
### Add Product
- Auto-generated SKU from product name
- Duplicate name validation (case-insensitive)
- Base unit cost price (for profit tracking)
- Base unit selling price
- Expiry tracking toggle

### Edit Product
- Edit name, price, expiry flag
- Duplicate name validation (excludes self)

### Delete Product
- Single delete (requires typing "DELETE" to confirm)
- **Delete All Products** button (clears all products, stock, batches, movements)
- Deleted products preserved as placeholder for receipt history integrity

### Product List
- Pagination with total count (e.g. "Showing 1-20 of 216 products")
- Search by name or SKU (server-side)
- Filter by expiry tracking (with/without)
- Filter by units (has multiple units / no units)
- Reset filters button

### Manage Units (per product)
- Multiple units per product (e.g. Piece, Pack, Carton)
- Base unit designation
- Conversion factors (1 carton = 144 pieces)
- Different cost and selling price per unit
- Unit abbreviations
- Profit margin per unit (admin only)
- Default unit per product

### Batch Management
- Add batch with expiry date for expiry-tracked products
- Auto-generated batch number (format: BATCH-YYYYMMDD-HHMMSS-RRR)
- Manufacturing date defaults to today if not provided
- View stock movement history per product

### Bulk Import
- **CSV Upload** - upload a CSV file directly
- **Manual Entry** - type products in textarea
- Simplified 5-field format: `Name, Cost Price, Selling Price, Quantity, Expiry Date`
- Auto-generated SKUs and batch numbers
- Manufacturing date auto-set to today
- **Duplicate Resolution Modal** when duplicates found:
  - Skip - don't import
  - Update existing - update price and add stock
  - Rename & add new - import with different name
  - Skip All / Update All bulk buttons
- Preview before importing
- Import results summary (imported, updated, skipped)

---

## 4. Stock Management
### Stock Table
- Columns: ID, SKU, Name, Unit Price, Initial Qty, Current Qty, Sold, Total Received, Status
- Status badges: In Stock (green), Low Stock (yellow < 10), Out of Stock (red = 0)
- Expiry Tracked badge on products

### Filters
- Search by name or SKU (server-side)
- Filter by status (In Stock / Low Stock / Out of Stock)
- Filter by expiry tracking (with/without)
- Reset filters button
- Pagination

### Actions
- Add stock for non-expiry products
- Add batch for expiry-tracked products
- View stock movement history per product (additions, deductions, sales)

---

## 5. Create Receipt (POS)
### Customer Information
- Customer name (required)
- Customer phone (required)
- Customer address (required)

### Product Selection
- Server-side search with autocomplete (300ms debounce)
- Keyboard navigation in suggestions
- Expiry check on product selection:
  - All stock expired → blocked with alert
  - Some stock expired → warning, can proceed with valid stock
- Select unit type (retail or wholesale)
- Quantity input

### Discount
- Fixed amount (GH₵)
- Percentage (%)
- Live subtotal, discount, and total display

### Payment Methods
| Method | Behavior |
|---|---|
| **Cash** | Enter amount received, shows change |
| **Mobile Money** | Optional reference/number, no change |
| **Card** | Optional last 4 digits/reference, no change |
| **Credit** | No payment required, shows balance owed |

### Receipt Preview
- Live thermal receipt preview (80mm format)
- Shows all items, discount, total, payment method

### Create Receipt
- Validates sufficient payment (cash only)
- Deducts stock automatically (FIFO for expiry products)
- Option to print immediately after creation
- Auto-generates next invoice number

---

## 6. Receipt History
### View & Filter
- Admin sees all receipts; cashier sees own only
- Search by invoice number, customer name, phone
- Filter by date range
- Filter by cashier (admin only)
- Filter by payment method

### Actions
- View full receipt details
- Print any receipt (thermal format)
- Delete receipt (admin only, requires "DELETE" confirmation)

### Receipt Details
- Invoice number, date, cashier
- Customer name, phone, address
- All items with unit names and quantities
- Subtotal, discount, total
- Cash received, change given
- Payment method and reference
- Profit column (admin only)

---

## 7. Expiry Management *(Admin only)*
### Tabs
- **All Batches** - all batches with expiry info
- **Expiring Soon** - items expiring within 90 days (excludes expired)
- **Expired** - only past-expiry items

### Features
- Color-coded rows: Red (expired), Yellow (expiring soon)
- Days left countdown
- Delete individual batch (reduces stock)
- **Delete All Expired** button (clears all expired batches at once)
- Reset notification dismissals
- Notification status display

### Notifications
- Popup alert on dashboard load
- Shows expired and expiring soon counts separately
- Dismissed daily (reappears next day)
- Per-batch acknowledgment
- Sidebar badge with live count

---

## 8. Analytics *(Admin only)*
### Date Picker
- Defaults to most recent receipt date
- All data filtered by selected date

### Summary Cards
- Today's Revenue
- Today's Profit
- Profit Margin %
- Receipts Today
- Total Income
- Active Users

### Tabs (lazy loaded for performance)
#### Daily Overview
- Top selling products (by quantity)
- Most profitable products (by profit)
- Sales by user
- Hourly sales pattern

#### Product Performance
- All products sold in last 30 days
- Revenue, quantity, average price, times sold

#### Profit Analysis
- Full profit breakdown table
- Search by product name/SKU
- Filter by profit margin (High ≥20% / Medium 10-19% / Low 0-9% / Negative)
- Filter by sales volume (Best Sellers / Moderate / Slow / No Sales)
- Sort by multiple columns
- Active filter summary
- Clear filters button
- Export to CSV

#### User Performance
- Sales by cashier (last 30 days)
- Total receipts, revenue, average sale

#### Low Profit Products
- Products with margin < 10% or negative
- Highlights products selling below cost

### Discount-Adjusted Analytics
- All revenue and profit figures account for discounts
- Formula: `profit = (item_revenue × discount_ratio) - item_cost`
- Consistent across all tabs and cards

---

## 9. User Management *(Admin only)*
- View all users
- Add new user (username, password, role)
- Edit user: username, password, role, status
- Duplicate username validation
- Activate/Deactivate users
- Delete user (cannot delete own account)

---

## 10. System Settings *(Admin only)*
- Company name
- Services/subtitle
- Location
- Phone
- Email (optional)
- Website (optional)
- Live receipt preview
- Saved to database (persists across sessions)

---

## 11. Security
- Password hashing (bcrypt)
- Role-based access control (Admin / User)
- Admin-only features hidden from cashiers
- Cost prices and profit data visible to admin only
- Delete confirmations require typing "DELETE"
- Duplicate product name prevention
- Cannot delete own user account
- Inactive users blocked from login
- Expired stock cannot be sold

---

## 12. Desktop App (Electron)
- Standalone Windows app (64-bit)
- No XAMPP, PHP, or internet required
- Bundled PHP 8 runtime with SQLite extensions
- SQLite database stored in user's AppData
- Auto-starts PHP server on launch
- Database auto-copies to writable location on first run
- Native print dialog for receipts (80mm thermal)
- Power save blocker (prevents system sleep)
- Universal modal input focus fix (no frozen inputs)
- Keyboard shortcuts: Ctrl+R (refresh), F5 (refresh)
- App menu with refresh and force reload options
- DevTools available in development mode

### Build Outputs
| File | Description |
|---|---|
| `Stock Manager & Expiry Tracker-Setup-1.0.0.exe` | Password-protected installer |
| `Stock Manager & Expiry Tracker-Portable-1.0.0.exe` | Portable version (no install) |

### Installation Password
The Setup installer requires a password before installation proceeds. Contact your software vendor for the password.

---

## 13. Data & Performance
- Server-side pagination (20 items per page default, max 100)
- Server-side search for products and stock
- Efficient dashboard using SQL COUNT/SUM queries
- Lazy loading for analytics tabs
- Async filters (waits for data before filtering)
- Database indexes for fast lookups
- Stock totals via SQL SUM (accurate for any number of products)

---

## 14. Database
- SQLite (primary, bundled)
- MySQL (legacy support)
- Auto-migration on startup (adds new columns automatically)
- Foreign key constraints
- Transaction support for all multi-step operations
- FIFO stock deduction for expiry products

### Key Tables
| Table | Purpose |
|---|---|
| products | Product catalog |
| stock | Current stock levels |
| stock_batches | Expiry batch tracking |
| stock_movements | Full audit trail of stock changes |
| product_units | Multi-unit support |
| receipts | Sales transactions |
| receipt_items | Line items per receipt |
| users | User accounts |
| company_settings | Business information |

---

## 15. Bulk Import CSV Format
```
Product Name, Cost Price, Selling Price, Quantity, Expiry Date
Fresh Milk 1L, 8.00, 12.00, 50, 2026-04-15
Bandages Pack, 5.00, 8.50, 250,
```
- Only 5 fields required
- Expiry Date optional (leave empty for non-expiry products)
- Batch numbers and manufacturing dates auto-generated
- SKUs auto-generated from product name

---

*Stock Manager & Expiry Tracker v1.0.0*
