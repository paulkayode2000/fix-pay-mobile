import { http, HttpResponse, delay } from 'msw'

// ── NOTE: All MSW payment handlers REMOVED ───────────────────────────
// Payments now route through Vite proxy → Laravel backend → VTPass API.
// The backend's VtpassService handles wallet deductions and VTPass sandbox calls.
//
// Leaf endpoints only — variations + verify stay mocked for MSW dev speed.
// Actual payment submissions (airtime, data, tv, electricity, education,
// insurance) bypass MSW entirely and hit the real backend.

export const paymentHandlers = [
  // GET variation codes (stay mocked for dev speed)
  http.get('/api/payments/variations/:serviceId', async ({ params }) => {
    await delay(400)
    const { variations } = await import('../data')
    const vars = variations[params.serviceId as string]
    if (!vars) return HttpResponse.json({ message: 'Service not found' }, { status: 404 })
    return HttpResponse.json({ serviceId: params.serviceId, variations: vars })
  }),

  // Merchant verify (stay mocked for dev speed)
  http.post('/api/payments/verify', async ({ request }) => {
    await delay(900)
    const body = await request.json() as Record<string, string>
    const { serviceId, billersCode, type } = body

    if (serviceId === 'dstv' || serviceId === 'gotv' || serviceId === 'startimes') {
      if (billersCode === '1212121212') {
        return HttpResponse.json({ code: '020', content: { Customer_Name: 'Emeka Okafor', Status: 'Active', Due_Date: '2026-06-10', Customer_Number: billersCode, Current_Bouquet: serviceId === 'dstv' ? 'DStv Compact' : 'GOtv Jolli', Renewal_Amount: serviceId === 'dstv' ? 9600 : 3300 } })
      }
      return HttpResponse.json({ code: '011', content: { message: 'Customer not found' } }, { status: 400 })
    }

    if (serviceId?.includes('electric')) {
      if (billersCode === '1111111111111' && type === 'prepaid') {
        return HttpResponse.json({ code: '020', content: { Customer_Name: 'Aisha Mohammed', Meter_Number: billersCode, Address: '24 Ikeja Way, Lagos', Customer_District: 'Ikeja', Outstanding: '0', Meter_Type: 'prepaid', Customer_Account_Type: 'NMD' } })
      }
      if (billersCode === '1010101010101' && type === 'postpaid') {
        return HttpResponse.json({ code: '020', content: { Customer_Name: 'Aisha Mohammed', Meter_Number: billersCode, Address: '24 Ikeja Way, Lagos', Customer_District: 'Ikeja', Outstanding: '4500', Meter_Type: 'postpaid', Customer_Account_Type: 'NMD' } })
      }
      return HttpResponse.json({ code: '011', content: { message: 'Meter not found' } }, { status: 400 })
    }

    if (serviceId === 'jamb') {
      if (billersCode === '0123456789') {
        return HttpResponse.json({ code: '000', content: { Customer_Name: 'Chidinma Eze', commission_details: { amount: 10.22, rate: '1.50', rate_type: 'percent' } } })
      }
      return HttpResponse.json({ code: '011', content: { message: 'Profile ID not found' } }, { status: 400 })
    }

    return HttpResponse.json({ code: '030', message: 'Biller not reachable' }, { status: 400 })
  }),

  // ── ALL PAYMENT SUBMISSIONS REMOVED ────────────────────────────────
  // airtime, data, tv, electricity, education, insurance, vtpass pay,
  // vtpass requery → all now pass through to real backend via Vite proxy.
  // The backend's VtpassService handles wallet deductions and
  // communicates with VTPass sandbox API.
]