/**
 * Pluggable email/SMS notification service.
 *
 * In development: logs to console and stores sent OTPs for retrieval
 * by the DevEmailPanel. No real emails are sent.
 *
 * In production: swap the implementation with a real provider
 * (SendGrid, Mailgun, AWS SES, Twilio for SMS, etc.) or call the
 * backend /auth/resend-otp endpoint which handles delivery.
 */

export interface EmailProvider {
  /** Send an OTP code to the given destination (email or phone). */
  sendOTP(to: string, code: string): Promise<{ success: boolean; preview?: string }>
  /** Retrieve the most recent OTP sent to this destination. */
  getLastOTP(forIdentifier: string): { code: string; timestamp: number } | null
  /** Retrieve all sent OTPs (for the dev panel). */
  getAllSent(): Array<{ to: string; code: string; timestamp: number }>
}

class DevEmailProvider implements EmailProvider {
  private sentEmails: Array<{ to: string; code: string; timestamp: number }> = []

  async sendOTP(to: string, code: string): Promise<{ success: boolean; preview?: string }> {
    const entry = { to, code, timestamp: Date.now() }
    this.sentEmails.push(entry)

    // Only keep the last 20 entries
    if (this.sentEmails.length > 20) {
      this.sentEmails = this.sentEmails.slice(-20)
    }

    const preview = `[DEV EMAIL] OTP "${code}" sent to ${to}`
    console.log(
      `%c📧 ${preview}`,
      'background: #007AFF; color: white; padding: 2px 6px; border-radius: 4px; font-size: 12px;'
    )
    return { success: true, preview }
  }

  getLastOTP(forIdentifier: string): { code: string; timestamp: number } | null {
    // Return the most recent OTP for this identifier
    const matching = this.sentEmails.filter(e => e.to === forIdentifier)
    if (matching.length === 0) return null
    return matching[matching.length - 1]
  }

  getAllSent(): Array<{ to: string; code: string; timestamp: number }> {
    return [...this.sentEmails]
  }
}

/**
 * Singleton email provider instance.
 *
 * To replace in production, create a new class implementing EmailProvider
 * that calls your real email/SMS API, and assign it here:
 *
 *   import { RealEmailProvider } from '@/lib/email-service.prod'
 *   (emailService as any) = new RealEmailProvider()
 *
 * Or use an environment variable to select the provider at startup.
 */
export const emailService: EmailProvider = new DevEmailProvider()