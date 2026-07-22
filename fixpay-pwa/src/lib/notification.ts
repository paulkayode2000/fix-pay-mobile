/**
 * Notification abstraction layer.
 *
 * In development mode: sends OTP via the local DevEmailProvider
 * which logs to console and stores codes for the DevEmailPanel.
 *
 * In production: calls the backend /auth/resend-otp endpoint which
 * sends real email via the configured provider (SendGrid/Mailgun/etc.)
 * or SMS via Twilio/AfricasTalking.
 */

import { api } from '@/lib/api'
import { emailService } from '@/lib/email-service'

/**
 * Request an OTP to be sent to the given identifier (email or phone).
 * The backend or dev service determines the delivery channel.
 *
 * @param identifier - email address or phone number
 * @param channel - preferred channel hint ('email' | 'sms')
 * @returns the OTP code in dev mode (for auto-fill), undefined in production
 */
export async function requestOTP(
  identifier: string,
  channel: 'email' | 'sms' = 'email'
): Promise<string | undefined> {
  if (import.meta.env.DEV) {
    // Dev mode: generate a deterministic test code and store it locally
    const code = generateDevOTP(identifier)
    await emailService.sendOTP(identifier, code)
    return code
  }

  // Production: backend handles real email/SMS delivery
  await api.post('/auth/resend-otp', { identifier, channel })
  return undefined // code is delivered externally, not returned
}

/**
 * Generate a deterministic 4-digit OTP for dev/testing purposes.
 * Based on the identifier so it's predictable during testing,
 * but varies per user to simulate real behavior.
 */
function generateDevOTP(identifier: string): string {
  // Simple hash of the identifier to produce a consistent 4-digit code
  let hash = 0
  for (let i = 0; i < identifier.length; i++) {
    hash = ((hash << 5) - hash) + identifier.charCodeAt(i)
    hash |= 0 // Convert to 32-bit integer
  }
  const code = Math.abs(hash % 10000)
  return code.toString().padStart(4, '0')
}