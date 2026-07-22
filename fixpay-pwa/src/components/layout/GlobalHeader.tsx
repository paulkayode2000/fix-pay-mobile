import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BellIcon } from '@heroicons/react/24/outline'
import { useAuthStore } from '@/store/auth.store'
import { Logo } from '@/components/ui/Logo'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { Button } from '@/components/ui/Button'

export function GlobalHeader() {
  const navigate = useNavigate()
  const { user, kycCompleted } = useAuthStore()
  const [showNotifications, setShowNotifications] = useState(false)

  const isBvnVerified = kycCompleted
  const hasNotifications = !isBvnVerified

  return (
    <>
      <header className="pt-safe px-4 pb-4 bg-[#F2F2F7] flex items-center justify-between shrink-0">
        <Logo size="sm" />
        <div className="flex-1 min-w-0 mx-3 text-right">
          <h1 className="text-[16px] font-semibold text-gray-900 truncate">{user?.firstName}</h1>
        </div>
        <button 
          onClick={() => setShowNotifications(true)}
          className="relative w-10 h-10 rounded-full bg-white flex items-center justify-center shadow-sm pressable shrink-0"
        >
          <BellIcon className="w-5 h-5 text-brand" />
          {hasNotifications && (
            <span className="absolute top-2 right-2 w-2 h-2 bg-ios-red rounded-full border border-white" />
          )}
        </button>
      </header>

      <BottomSheet
        open={showNotifications}
        onClose={() => setShowNotifications(false)}
        title="Notifications"
      >
        <div className="px-4 pb-8 pt-2">
          {!isBvnVerified ? (
            <div className="bg-red-50 rounded-[16px] p-4 flex flex-col gap-2">
              <div className="flex items-start gap-3">
                <span className="text-[20px]">⚠️</span>
                <div>
                  <h3 className="text-[15px] font-semibold text-gray-900">Action Required</h3>
                  <p className="text-[13px] text-gray-600 mt-1 leading-snug">
                    Complete your BVN verification to unlock all features and secure your account.
                  </p>
                </div>
              </div>
              <Button 
                variant="outline" 
                size="sm" 
                className="mt-2 w-full bg-white text-ios-red border-red-200"
                onClick={() => {
                  setShowNotifications(false)
                  navigate('/kyc')
                }}
              >
                Verify Now
              </Button>
            </div>
          ) : (
            <div className="py-8 flex flex-col items-center justify-center text-center">
              <span className="text-[40px] opacity-50 mb-2">📭</span>
              <p className="text-[15px] text-gray-500 font-medium">No new notifications</p>
              <p className="text-[13px] text-gray-400 mt-1">You're all caught up!</p>
            </div>
          )}
        </div>
      </BottomSheet>
    </>
  )
}
