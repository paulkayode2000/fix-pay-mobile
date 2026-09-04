export interface User {
  id: string
  phone?: string
  email?: string
  firstName: string
  lastName: string
  tier: 1 | 2 | 3
  kycStatus: 'pending' | 'partial' | 'verified' | 'rejected'
  createdAt: string
}

export interface Wallet {
  id: string
  balanceKobo: number
  currency: 'NGN'
  status: 'active' | 'frozen' | 'closed'
  virtualAccount: { accountNumber: string; bankName: string; bankCode: string }
}

export type TxType = 'bill_payment' | 'transfer_out' | 'transfer_in' | 'wallet_funding' | 'withdrawal'
export type TxStatus = 'initiated' | 'processing' | 'pending' | 'completed' | 'failed' | 'reversed'

export interface Transaction {
  id: string
  type: TxType
  amountKobo: number
  feeKobo: number
  status: TxStatus
  reference: string
  description: string
  counterpartyName?: string
  serviceId?: string
  serviceName?: string
  token?: string
  units?: string
  Pin?: string
  purchased_code?: string
  createdAt: string
}

export interface TenantConfig {
  tenantId: string
  slug: string
  appName: string
  primaryColor: string
  secondaryColor: string
  accentColor: string
  logoUrl: string | null
  faviconUrl: string | null
  supportEmail: string
  supportPhone: string
  features: {
    billPayments: boolean
    directDebit: boolean
    walletTransfers: boolean
    internationalAirtime: boolean
    disputeManagement: boolean
    nibssTransfers: boolean
  }
}

export type ValidityCategory = 'daily' | 'weekly' | 'monthly' | 'other'

export interface ServiceVariation {
  variationCode: string
  name: string
  variationAmount: string
  fixedPrice: 'Yes' | 'No'
}

export interface BillerVerify {
  customerName: string
  status?: string
  currentBouquet?: string
  renewalAmount?: number
  meterType?: string
  accountType?: string
  address?: string
  meterNumber?: string
}

export interface PaymentResult {
  transactionId: string
  reference: string
  vtpassStatus: 'initiated' | 'delivered' | 'pending' | 'failed'
  serviceName: string
  amountKobo: number
  phone: string
  token?: string
  units?: string
  pin?: string
  date: string
}

export interface Mandate {
  id: string
  accountNumber: string
  bankName: string
  bankCode: string
  amountKobo: number
  frequency: 'daily' | 'weekly' | 'monthly' | 'quarterly' | 'annually' | 'one_off'
  startDate: string
  endDate: string
  narrative: string
  status: 'pending_auth' | 'active' | 'paused' | 'cancelled' | 'expired'
  authorizationUrl?: string
  createdAt: string
}

export type DisputeCategory = 'wrong_amount' | 'not_delivered' | 'unauthorized' | 'duplicate' | 'other'
export type DisputeStatus = 'open' | 'awaiting_review' | 'under_review' | 'resolved' | 'escalated' | 'closed'

export interface Dispute {
  id: string
  transactionId: string
  category: DisputeCategory
  description: string
  status: DisputeStatus
  resolution?: string
  slaDeadline: string
  createdAt: string
  transaction?: any // VTPass or Transfer object from backend
}

export interface NipBank {
  bankCode: string
  bankName: string
}

export interface NameEnquiry {
  accountNumber: string
  accountName: string
  bankCode: string
  bankName: string
  sessionId: string
}

// ─── Backend API envelope ─────────────────────────────────────────────────────

export interface ApiResponse<T> {
  success: boolean
  message: string | null
  data: T
  errorCode: string | null
  timestamp: string
}

// ─── Backend-aligned response shapes ─────────────────────────────────────────

/** Matches WalletBalanceResponse.java. availableBalance is in NGN (not kobo). */
export interface WalletBalanceResponse {
  walletId: string
  currency: string
  availableBalance: number
  ledgerBalance: number
  status: string
  virtualAccount?: { accountNumber: string; bankName: string; bankCode: string }
}

/** Matches BillPaymentResponse.java (returned WITHOUT ApiResponse wrapper). */
export interface BillPaymentResponse {
  requestId: string
  transaction_date: string
  amount: string
  token?: string
  units?: string
  Pin?: string
  purchased_code?: string
  vtpass_code?: string
}

/** Matches MandateResponse.java. maxAmount is in NGN. */
export interface MandateResponse {
  mandateReference: string
  providerReference: string | null
  bankCode: string
  accountNumber: string
  maxAmount: number
  status: 'pending_auth' | 'active' | 'paused' | 'cancelled' | 'expired'
  startDate: string
  endDate: string | null
  providerMessage: string | null
  createdAt: string
  updatedAt: string
}
