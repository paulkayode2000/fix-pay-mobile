import axios from 'axios'

export const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true,
})

function generateUUID() {
  if (typeof self !== 'undefined' && self.crypto && self.crypto.randomUUID) {
    return self.crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

api.interceptors.request.use((config) => {
  if (config.method && ['post', 'put', 'patch', 'delete'].includes(config.method.toLowerCase())) {
    if (!config.headers['X-Idempotency-Key']) {
      config.headers['X-Idempotency-Key'] = generateUUID()
    }
  }
  return config
})

// Intercept responses for global error handling
api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      // Could trigger global logout event here if needed
    }
    return Promise.reject(err)
  }
)
