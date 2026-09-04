# 9PSB Wallet-as-a-Service Integration Guide

## Overview

This document describes the 9PSB WAAS integration for FixPay. 9PSB provides NUBAN wallet accounts, debit/credit operations, inter-bank transfers, and webhook notifications for inflows.

## Environment Variables

Add these to your `.env` file:

```env
9PSB_BASE_URL=http://102.216.128.75:9090/waas
9PSB_USERNAME=payfixy
9PSB_PASSWORD=OhdOH9n7Yaj72h9e3xbjjK8FZauzQQma7jxDsuE8Sbuu9Rqn4g
9PSB_CLIENT_ID=waas
9PSB_CLIENT_SECRET=cRAwnWElcNMUZpALdnlve6PubUkCPOQR
9PSB_WEBHOOK_USERNAME=
9PSB_WEBHOOK_PASSWORD=
```

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    FixPay Laravel                        │
│                                                         │
│  NinePsbWalletController  ──►  WalletService             │
│         │                       │                       │
│         ▼                       ▼                       │
│  NinePsbAdapter  ◄────  NinePsbWalletProvider            │
│         │                                               │
│         ▼                                               │
│  NinePsbAuthService (token caching via Redis)            │
│         │                                               │
└─────────┼───────────────────────────────────────────────┘
          │
          ▼
  ┌───────────────────┐
  │  9PSB WAAS API    │
  │  102.216.128.75   │
  │  port 9090        │
  └───────────────────┘
```

## Files Created

| File | Purpose |
|------|---------|
| `app/Services/NinePsb/NinePsbAuthService.php` | Token authentication with caching |
| `app/Services/NinePsb/NinePsbAdapter.php` | HTTP adapter for all 21 WAAS endpoints |
| `app/Services/NinePsb/NinePsbWalletProvider.php` | Wallet creation via 9PSB |
| `app/Contracts/Wallet/WalletProviderInterface.php` | Wallet provider contract |
| `app/Models/NinePsbTransaction.php` | Transaction tracking model |
| `app/Http/Controllers/NinePsbWebhookController.php` | Webhook handler |
| `app/Http/Controllers/Wallet/NinePsbWalletController.php` | Wallet API controller |
| `database/migrations/2026_07_22_000000_add_9psb_fields_to_wallets_table.php` | Wallet schema changes |
| `database/migrations/2026_07_22_000001_create_ninepsb_transactions_table.php` | Transaction table |

## Files Modified

| File | Change |
|------|--------|
| `config/services.php` | Added `ninepsb` config block |
| `app/Providers/AppServiceProvider.php` | Registered 9PSB services |
| `app/Services/Wallet/WalletService.php` | Added `createNinePsbWallet()` method |
| `routes/api.php` | Added webhook + API routes |

## API Endpoints

### Public Webhook

```
POST/GET /webhooks/9psb?event=transfer
POST/GET /webhooks/9psb?event=account-upgrade
```

Protected by Basic Auth (configure `9PSB_WEBHOOK_USERNAME` / `9PSB_WEBHOOK_PASSWORD`).

### Authenticated Wallet Endpoints

All require `Authorization: Bearer {sanctum_token}`.

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/wallet/ninepsb/open` | Open a 9PSB wallet (requires BVN) |
| `GET` | `/wallet/ninepsb/enquiry` | Get wallet balance and details |
| `GET` | `/wallet/ninepsb/status` | Check wallet status |
| `GET` | `/wallet/ninepsb/transactions?from_date=&to_date=` | Transaction history |
| `POST` | `/wallet/ninepsb/upgrade` | Upgrade KYC tier (base64 payload) |
| `GET` | `/wallet/ninepsb/upgrade-status` | Check upgrade status |
| `POST` | `/wallet/ninepsb/requery` | TSQ - check transaction status |

## Key Behaviors

### Token Caching
- Access tokens are cached in Laravel's cache (Redis recommended)
- Auto-refreshed 2 minutes before expiry
- On 401, token is invalidated and re-authenticated

### Transaction Status Query (TSQ)
- Per 9PSB spec, response codes `09`, `96`, `97`, `98`, `99` require TSQ
- Use `/wallet/ninepsb/requery` to check status
- The `NinePsbTransaction` model has `requiresRequery()` helper

### Webhook Handling
1. 9PSB sends inflow notification to `/webhooks/9psb?event=transfer`
2. Basic Auth is validated
3. `notification_requery` API is called to confirm the notification
4. If confirmed (response code `00`), the local wallet is credited

### Balance Sync
- `wallet/enquiry` automatically syncs the local wallet balance with 9PSB

## Testing

### Using Postman

1. Import `specs/9psb/9PSB-WAAS.postman_collection.json` into Postman
2. Set the collection variables from `specs/9psb/test-credentials.txt`
3. Run the collection to test authentication, wallet opening, enquiry, etc.

### Test Flow

```
1. POST /auth/register          → Get user token
2. POST /auth/verify-otp        → Complete registration
3. POST /kyc/bvn                → Verify BVN
4. POST /wallet/ninepsb/open    → Open 9PSB wallet
   Body: { "bvn": "22316109918" }
5. GET  /wallet/ninepsb/enquiry → Check balance
6. GET  /wallet/ninepsb/status  → Check wallet status
7. GET  /wallet/ninepsb/transactions?from_date=2024-01-01&to_date=2024-12-31
```

## 9PSB Response Codes Reference

| Code | Description | Action |
|------|-------------|--------|
| `00` | Success | None |
| `42` | Duplicate transaction | Check status of previous call |
| `51` | Insufficient funds | Reject transaction |
| `93` | Inactive wallet | Check wallet status |
| `94` | Invalid wallet operation | Check wallet state |
| `09` | Request processing in progress | TSQ required |
| `96` | System malfunction | TSQ required |
| `97` | Timeout | TSQ required |
| `98` | Failed no response | TSQ required |
| `99` | Request processing error | TSQ required |

## Run Migrations

```bash
cd fixpay-laravel
php artisan migrate