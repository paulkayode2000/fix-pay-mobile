import { api } from '@/lib/api'
import type { BillerVerify, ServiceVariation } from '@/types'

export interface BillPaymentResponse {
  payment_reference: string
  status: string
  amount_kobo: number    // kobo — already deducted from wallet server-side
  fee_kobo: number
  token?: string         // electricity prepaid token
  units?: string         // electricity units
  Pin?: string           // education exam PIN
  purchased_code?: string
  vtpass_code?: string   // raw vtpass response code
}

export interface AirtimePayload {
  serviceId: string
  phone: string
  amount: number
}

export interface DataPayload {
  serviceId: string
  billersCode: string   // subscriber phone number
  variationCode: string
  amount: number        // bundle price in Naira (from variation)
}

export interface TvPayload {
  serviceId: string
  billersCode: string
  variationCode: string
  subscriptionType: 'renew' | 'change'
  amount: number          // required — backend enforces min:100
}

export interface ElectricityPayload {
  serviceId: string
  billersCode: string
  variationCode: 'prepaid' | 'postpaid'
  amount: number
}

export interface EducationPayload {
  serviceId: string
  billersCode: string
  variationCode: string
  phone?: string
  amount: number        // variation price in Naira (required by backend min:100)
}

export interface InsurancePayload {
  serviceId: string
  billersCode: string
  variationCode: string
  phone?: string
  amount: number        // variation price in Naira (required by backend min:100)
}

export interface VerifyPayload {
  serviceId: string
  billersCode: string
  type?: string
}

/**
 * Non-wallet rail (Payfixy / card / transfer / USSD) via the Payfixy Gateway.
 * The gateway settles the bill — no wallet or wallet hold is involved, so a
 * user without a wallet can pay.
 */
export interface AlternativePaymentResponse {
  payment_reference: string
  gateway_reference: string
  /** PENDING | PROCESSING | COMPLETED | FAILED | REVERSED */
  status: string
  payment_method?: string
  amount_kobo?: number
  fee_kobo?: number
  provider_code?: string
  vtpass_code?: string
  token?: string
  units?: string
  message?: string | null
}

export type AlternativePaymentMethod = 'payfixy' | 'card' | 'bank_transfer' | 'ussd'

// VTpass variations response shape (real backend forwards VTpass JSON after camelCase normalisation)
interface VtpassVariationsResponse {
  responseDescription?: string
  content?: { variations?: ServiceVariation[] }
  // MSW mock returns flat { variations: [] }
  variations?: ServiceVariation[]
}

// VTpass verify response (real backend returns Map<String, Object> from VTpass)
interface VtpassVerifyResponse {
  code?: string
  content?: BillerVerify
  // MSW mock returns BillerVerify directly
  customerName?: string
}

// Helper — builds the unified VTpass payload the backend expects
function vtpassPayload(
  serviceId: string,
  amountNaira: number,
  phone: string,
  billersCode?: string,
  variationCode?: string,
) {
  return {
    service_id:     serviceId,
    amount_kobo:    Math.round(amountNaira * 100),
    phone,
    billers_code:   billersCode  ?? undefined,
    variation_code: variationCode ?? undefined,
  }
}

function mapResponse(d: any, defaultAmountNaira: number): BillPaymentResponse {
  const statusStr = (d.status || d.paymentStatus || d.providerStatus || '').toLowerCase()
  return {
    payment_reference: d.payment_reference || d.paymentReference || d.requestId || '',
    status: statusStr === 'completed' || d.code === '000' || statusStr === 'delivered' ? 'delivered' : (statusStr === 'pending' || statusStr === 'processing' || d.code === '099' ? 'pending' : 'failed'),
    amount_kobo: d.amount_kobo || (d.amount ? Math.round(Number(d.amount) * 100) : Math.round(defaultAmountNaira * 100)),
    fee_kobo: d.fee_kobo || 0,
    token: d.token,
    units: d.units,
    Pin: d.Pin || d.pin || d.token,
    purchased_code: d.purchased_code || d.token,
    vtpass_code: d.provider_code || d.providerCode || d.code || (statusStr === 'completed' || statusStr === 'delivered' ? '000' : (statusStr === 'pending' || statusStr === 'processing' ? '099' : '016')),
  }
}

export const paymentsService = {
  // GET /api/payments/vtpass/services?identifier=<id>
  getVariations: (serviceId: string): Promise<ServiceVariation[]> =>
    api.get<VtpassVariationsResponse>('/payments/vtpass/variations', {
      params: { serviceID: serviceId },
    }).then(r => {
      const d = r.data
      const rawVars = d.content?.variations || d.variations || []
      // Normalise fields and deduplicate by variationCode (VTPass sometimes returns dupes)
      const seen = new Set<string>()
      const result: ServiceVariation[] = []
      for (const v of rawVars as any[]) {
        const code = v.variationCode ?? v.variation_code ?? ''
        if (seen.has(code)) continue
        seen.add(code)
        result.push({
          variationCode: code,
          name: v.name,
          variationAmount: v.variationAmount ?? v.variation_amount ?? '0',
          fixedPrice: v.fixedPrice ?? v.fixed_price ?? 'Yes',
        })
      }
      return result
    }),

  // POST /api/payments/vtpass/verify (proxied by backend to VTpass)
  verify: (payload: VerifyPayload): Promise<BillerVerify> =>
    api.post<VtpassVerifyResponse>('/payments/verify', {
      service_id:   payload.serviceId,
      billers_code: payload.billersCode,
      type:         payload.type,
    }).then(r => {
      const d = r.data
      const content = d.content || d
      return {
        customerName: content.customerName ?? content.Customer_Name ?? content.customer_name ?? '',
        status: content.status ?? content.Status ?? '',
        currentBouquet: content.currentBouquet ?? content.Current_Bouquet ?? content.current_bouquet ?? '',
        renewalAmount: content.renewalAmount ?? content.Renewal_Amount ?? content.renewal_amount ?? undefined,
        meterType: content.meterType ?? content.Meter_Type ?? content.meter_type ?? undefined,
        accountType: content.accountType ?? content.Customer_Account_Type ?? content.customer_account_type ?? undefined,
        address: content.address ?? content.Address ?? undefined,
        meterNumber: content.meterNumber ?? content.Meter_Number ?? content.meter_number ?? undefined,
      }
    }),

  // All bill payments
  airtime: (payload: AirtimePayload): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/vtpass',
      vtpassPayload(payload.serviceId, payload.amount, payload.phone)
    ).then(r => mapResponse(r.data, payload.amount)),

  data: (payload: DataPayload): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/vtpass',
      // phone = subscriber's mobile number (billersCode); amount = bundle price in Naira
      vtpassPayload(payload.serviceId, payload.amount, payload.billersCode, payload.billersCode, payload.variationCode)
    ).then(r => mapResponse(r.data, payload.amount)),

  tv: (payload: TvPayload): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/vtpass',
      // subscription_type is required by VTPass for TV (TC-015c / TC-016c)
      {
        ...vtpassPayload(payload.serviceId, payload.amount, payload.billersCode, payload.billersCode, payload.variationCode),
        subscription_type: payload.subscriptionType,
      }
    ).then(r => mapResponse(r.data, payload.amount)),

  electricity: (payload: ElectricityPayload): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/vtpass',
      vtpassPayload(payload.serviceId, payload.amount, payload.billersCode, payload.billersCode, payload.variationCode)
    ).then(r => mapResponse(r.data, payload.amount)),

  education: (payload: EducationPayload): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/vtpass',
      // billersCode = profile/exam ID; phone = contact number; amount = variation price
      vtpassPayload(payload.serviceId, payload.amount, payload.phone ?? payload.billersCode, payload.billersCode, payload.variationCode)
    ).then(r => mapResponse(r.data, payload.amount)),

  insurance: (payload: InsurancePayload): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/vtpass',
      // billersCode = vehicle plate / reference; phone = contact number; amount = plan price
      vtpassPayload(payload.serviceId, payload.amount, payload.phone ?? payload.billersCode, payload.billersCode, payload.variationCode)
    ).then(r => mapResponse(r.data, payload.amount)),

  requery: (paymentReference: string): Promise<BillPaymentResponse> =>
    api.get<any>(`/payments/vtpass/${paymentReference}`).then(r => {
      const d = r.data?.data || r.data
      return mapResponse(d, 0)
    }),

  alternativeInitiate: (payload: any & { paymentMethod: AlternativePaymentMethod }): Promise<AlternativePaymentResponse> =>
    api.post<AlternativePaymentResponse>('/payments/alternative/initiate', {
      service_id: payload.serviceId,
      amount_kobo: Math.round(payload.amount * 100),
      phone: payload.phone || payload.billersCode || '',
      payment_method: payload.paymentMethod,
      billers_code: payload.billersCode,
      variation_code: payload.variationCode,
      subscription_type: payload.subscriptionType,
    }).then(r => r.data),

  // The gateway settles this rail synchronously, so verify() is a user-scoped
  // read of the recorded outcome — it reuses the same bill-response shape.
  alternativeVerify: (gatewayReference: string): Promise<BillPaymentResponse> =>
    api.post<any>('/payments/alternative/verify', { gateway_reference: gatewayReference })
      .then(r => mapResponse(r.data, 0)),
}
