<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** GET /api/user/profile */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load('wallet');

        return response()->json([
            'id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'kyc_status' => $user->kyc_status,
            'tier' => $user->tier,
            'status' => $user->status,
            // Spatie role names — kept in sync with /auth/login so a profile
            // refresh carries the same authoritative admin signal.
            'roles' => $user->getRoleNames()->values()->all(),
            'email_verified_at' => $user->email_verified_at,
            'phone_verified_at' => $user->phone_verified_at,
            'wallet' => $user->wallet ? [
                'id' => $user->wallet->id,
                'balance_kobo' => $user->wallet->balance_kobo,
                'currency' => $user->wallet->currency,
                'status' => $user->wallet->status,
                'virtual_account_number' => $user->wallet->virtual_account_number,
                'virtual_account_bank' => $user->wallet->virtual_account_bank,
            ] : null,
        ]);
    }

    /** PUT /api/user/profile */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'sometimes|string|max:80',
            'last_name'  => 'sometimes|string|max:80',
        ]);

        $request->user()->update($data);

        return response()->json(['message' => 'Profile updated.']);
    }
}
