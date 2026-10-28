# Changelog

## 0.2.0 (2026-10-28)

- Prices in your own currency: `price` with `currency` (EUR, GBP, JPY and others) when creating checkout sessions and checkouts. `amount_usd` still works for dollars.
- Checkouts carry `price`, `currency` and `currency_rate_usd`; checkout sessions carry `price` and `currency`, with `amount_usd` null for a price not in dollars.

## 0.1.0 (2026-10-27)

- First release: checkout sessions, checkouts (with test-mode simulate), coins, balance, payouts, and webhook verification.
