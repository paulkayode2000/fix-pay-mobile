import { http, HttpResponse, delay } from 'msw'

// ── NOTE: Auth handlers REMOVED — all auth now routes through real backend.
// Only keep CSRF cookie handler since MSW mode needs it for browser state.

export const authHandlers = [
  // CSRF cookie — always succeed in mock/dev mode
  http.get('/api/sanctum/csrf-cookie', async () => {
    await delay(100)
    return HttpResponse.json({ message: 'CSRF cookie set' }, {
      status: 200,
      headers: { 'Set-Cookie': 'XSRF-TOKEN=mock-xsrf-token; Path=/' },
    })
  }),

  http.get('/sanctum/csrf-cookie', async () => {
    await delay(100)
    return HttpResponse.json({ message: 'CSRF cookie set' }, {
      status: 200,
      headers: { 'Set-Cookie': 'XSRF-TOKEN=mock-xsrf-token; Path=/' },
    })
  }),

  // ── All other auth endpoints (register, login, logout, pin/*) route
  // through Vite proxy → real Laravel backend. MSW does not intercept them.
]