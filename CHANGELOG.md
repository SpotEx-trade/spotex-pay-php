# Changelog

## 0.3.0 (2026-10-28)

- `createPlan` and `listPlans`; `listSubscriptions`, `retrieveSubscription` and `cancelSubscription`: recurring payments from SpotEx balances.
- Checkouts carry `subscription_id` and `subscription_period`.
- USDT and other tokens on EVM chains: `createCheckout` takes `network`, checkouts carry it, and each coin lists its `networks`.

## 0.2.0 (2026-10-28)

- Prices in your own currency: `price` with `currency` (EUR, GBP, JPY and others) when creating checkout sessions and checkouts. `amount_usd` still works for dollars.
- Checkouts carry `price`, `currency` and `currency_rate_usd`; checkout sessions carry `price` and `currency`, with `amount_usd` null for a price not in dollars.
- `createInvoice`, `retrieveInvoice`, `listInvoices` and `voidInvoice`: invoices with lines, a due date and a page of their own. Checkouts carry `invoice_id`.

## 0.1.0 (2026-10-27)

- First release: checkout sessions, checkouts (with test-mode simulate), coins, balance, payouts, and webhook verification.
