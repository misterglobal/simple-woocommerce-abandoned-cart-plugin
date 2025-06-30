# WooCommerce Abandoned Cart to Make

A WordPress plugin for WooCommerce that detects and records abandoned carts, captures contact details, and sends all data to Make (Integromat) via webhook for follow-up workflows. Includes an admin dashboard for analytics and management.

## Features
- Detects abandoned carts after a configurable timeout
- Captures email (required), phone (optional), customer name, product IDs, quantities, cart total, and currency
- Stores abandoned cart data in a custom database table
- Sends cart data to Make (Integromat) via webhook
- Admin panel with table view, filters, and management actions
- Manual "Resend to Make" and "Mark as Recovered" actions
- Settings for webhook URL, timeout, logging, and auto-expiry
- GDPR notice and minimal data storage
- Exit-intent popup to collect guest emails
- Auto-expires records after X days

## Installation
1. Upload the plugin folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress admin.
3. Go to **Abandoned Carts > Settings** to configure the webhook URL, timeout, and other options.

## Usage
- The plugin automatically tracks carts and fires webhooks when a cart is abandoned.
- Use the **Abandoned Carts** menu in the admin to view, filter, and manage records.
- Use the **Resend to Make** and **Mark as Recovered** buttons for manual actions.
- The exit-intent popup will prompt guests for their email if they attempt to leave with items in their cart.

## Webhook Payload Example
```
{
  "email": "customer@example.com",
  "name": "John Doe",
  "phone": "1234567890",
  "cart": [
    {"product_id": 123, "quantity": 2},
    {"product_id": 456, "quantity": 1}
  ],
  "cart_total": "99.99",
  "abandonment_timestamp": "2025-06-30 12:34:56",
  "cart_id": 1
}
```

## Analytics
- The admin dashboard provides a table of all abandoned carts with status and actions.
- Analytics and filtering by date/status are available in the admin panel.

## Security & GDPR
- Shows a notice: “If you don’t complete your purchase, we may contact you about your cart.”
- Stores only essential contact information.
- Allows data deletion from the admin panel.

## Support
For issues or feature requests, please open an issue on the plugin repository or contact the author.
