# Spectreman Backend: Restaurant Approval & Order Middleware

**Role:** Restaurant Approval Service & Real-Time Order Holding Middleware  
**Framework:** Laravel 11 / PHP 8.4  
**Primary Integration:** Odoo ERP Backend  
**Document Date:** 2026-08-23  

---

## 1. Architectural Overview & Boundary Split

This Laravel backend operates as the **Restaurant Approval Service & Order Middleware**. It sits between the mobile/web order creation process and the Odoo backend. Its core responsibility is holding new orders, orchestrating the 180-second restaurant approval window, broadcasting real-time alerts, and communicating the final decision (Accept/Reject/Timeout) back to Odoo.

### Core Architectural Principles
* **Auto-reject, never auto-accept:** If a restaurant does not respond within 180 seconds, the order is automatically rejected.
* **Odoo owns all finances & payments:** This middleware does not calculate prices, manage payment gateways (DPay), or maintain wallet ledgers; it simply instructs Odoo to proceed with order fulfillment or issue a customer wallet refund.
* **Decoupled Real-Time State:** The middleware holds the order state during the 180-second window, alerts the restaurant console in real-time, and hands the finalized verdict back to Odoo.

```
                           Customer Places Order
                                    │
                                    ▼
                       ┌──────────────────────────┐
                       │ Laravel Approval Service │
                       └────────────┬─────────────┘
                                    │
             ┌──────────────────────┼──────────────────────┐
             ▼                      ▼                      ▼
      [ 0s: Reverb Alert ]   [ 60s: Ops Reminder ]   [ 180s: Timeout ]
      To Restaurant Console   To Operations Team     Auto-rejects & calls Odoo
             │
             ├── If Restaurant Accepts ──► Calls Odoo POST /orders/confirm
             └── If Restaurant Rejects ──► Calls Odoo POST /orders/cancel (triggers refund)
```

---

## 2. Core Workflow

1. **Inbound Order (`POST /orders`):** Customer places an order. Laravel creates the order record, registers with Odoo, sets status `awaiting_restaurant_approval`, and sets `expires_at = now() + 180s`.
2. **Hold & Real-Time Alert:** 
   * Laravel broadcasts a real-time event via **Laravel Reverb** to `private-restaurant.{restaurant_id}`.
3. **The Countdown & Timers:**
   * *20s / 40s:* Re-alerts the restaurant terminal if unanswered.
   * *60s:* Dispatches `SendApprovalReminderJob` for operations monitoring.
   * *180s:* Dispatches `EnforceOrderTimeoutJob` under database row locking.
4. **The Verdict:**
   * **Accept:** Restaurant calls `POST /restaurant/orders/{id}/accept` with `{ "prepMinutes": 25 }`. Laravel updates status to `preparing` and calls Odoo's confirm webhook.
   * **Reject:** Restaurant calls `POST /restaurant/orders/{id}/reject` with `{ "reasonCode": "out_of_stock|too_busy|closing|other", "note": "..." }`. Laravel updates status to `rejected_by_restaurant` and calls Odoo's cancel/refund webhook.
   * **Timeout:** The 180s worker acquires row lock, sees the order is still pending, marks it as `rejected_by_restaurant` with reason `timeout`, and calls Odoo's cancel/refund webhook.

---

## 3. Database Schema Blueprint

```sql
-- Pending Orders Table
CREATE TABLE orders (
    id UUID PRIMARY KEY,
    order_number VARCHAR(32) UNIQUE NOT NULL, -- Minted by Odoo (e.g. SP-1042)
    odoo_order_id VARCHAR(64) UNIQUE NOT NULL,
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    restaurant_id VARCHAR(64) NOT NULL,
    zone_id VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'awaiting_restaurant_approval',
    rejection_reason VARCHAR(64),
    prep_minutes INTEGER,
    total_minor BIGINT NOT NULL,
    delivery_fee_minor BIGINT NOT NULL DEFAULT 5000,
    discount_minor BIGINT NOT NULL DEFAULT 0,
    payment_method VARCHAR(32) DEFAULT 'cash',
    payment_status VARCHAR(32) DEFAULT 'pending',
    expires_at TIMESTAMP WITH TIME ZONE,
    decided_at TIMESTAMP WITH TIME ZONE,
    payload JSONB NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE,
    updated_at TIMESTAMP WITH TIME ZONE
);

CREATE INDEX idx_orders_restaurant_status ON orders(restaurant_id, status);
CREATE INDEX idx_orders_expires_at ON orders(expires_at) WHERE status = 'awaiting_restaurant_approval';
```

---

## 4. API Endpoints

### 4.1 Restaurant Console APIs (Protected by JWT `role: "restaurant"`)
* **`GET /api/v1/restaurant/orders/pending`:** Fetches all waiting orders with remaining countdown seconds.
* **`GET /api/v1/restaurant/orders/{id}`:** Fetches order details.
* **`POST /api/v1/restaurant/orders/{id}/accept`:** Payload `{ "prepMinutes": 25 }`.
* **`POST /api/v1/restaurant/orders/{id}/reject`:** Payload `{ "reasonCode": "out_of_stock|too_busy|closing|other", "note": "" }`.

### 4.2 Customer & Order Endpoints
* **`POST /api/v1/orders`:** Submits order, validates idempotency, sets 180s countdown.
* **`GET /api/v1/orders`:** Lists customer orders.
* **`GET /api/v1/orders/{id}`:** Order details.
* **`POST /api/v1/orders/{id}/cancel`:** Cancels within allowed cancellation window.

---

## 5. Background Jobs & Race-Condition Locking

* **`EnforceOrderTimeoutJob` (180s delay):**
  ```php
  DB::transaction(function () use ($orderId, $odooClient) {
      $order = Order::where('id', $orderId)->lockForUpdate()->first();
      
      if (! $order || $order->status !== 'awaiting_restaurant_approval') {
          return; // Already resolved
      }

      $order->update([
          'status' => 'rejected_by_restaurant',
          'rejection_reason' => 'timeout',
          'decided_at' => now(),
      ]);

      // Call Odoo API to cancel and trigger wallet refund
      $odooClient->cancelOrder($order->odoo_order_id, 'timeout');
      
      event(new OrderApprovalResolved($order));
  });
  ```
* **`SendApprovalReminderJob` (60s delay):** Triggers secondary ops alert if order is still unconfirmed.

---

## 6. Integration Contract with Odoo

Laravel communicates with Odoo via authenticated internal REST/RPC endpoints:

1. **`POST /api/v1/internal/orders/confirm`:**
   * **When called:** After restaurant accepts the order.
   * **Sent data:** `odoo_order_id`, `prepMinutes`.
   * **Odoo action:** Advances order to fulfillment / kitchen queue.
2. **`POST /api/v1/internal/orders/cancel`:**
   * **When called:** When restaurant rejects or 180s timeout occurs.
   * **Sent data:** `odoo_order_id`, `reasonCode` (`timeout`, `out_of_stock`, etc.).
   * **Odoo action:** Cancels order and processes refund back to customer wallet ledger.
