import { useAuthStore } from '@/store/auth.store'

/**
 * True when the signed-in user holds the backend's platform-admin role.
 *
 * The server is the authority: `AppUser` uses Spatie roles and every
 * `/api/admin/*` route is guarded by
 * `App\Http\Middleware\AdminMiddleware` (`$user->hasRole('admin')`).
 * `/api/auth/login` (and `/api/user/profile`) echo `roles`, so the client reads
 * that list instead of guessing.
 *
 * NOTE: there is deliberately no Keycloak / JWT claim decoding here — this
 * stack authenticates with Sanctum, whose tokens are opaque (not JWTs), and no
 * `realm_access` claim exists.
 *
 * Falls back to {@code false} when the user is absent or carries no roles.
 */
export function useIsPlatformAdmin(): boolean {
  const roles = useAuthStore(s => s.user?.roles)

  return Array.isArray(roles) && roles.includes('admin')
}
