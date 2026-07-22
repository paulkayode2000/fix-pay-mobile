<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
    /** POST /api/auth/pin/set */
    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin'              => 'required|string|size:6|confirmed',
            'pin_confirmation' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $user->update(['pin_hash' => Hash::make($data['pin'])]);

        return response()->json(['message' => 'PIN set successfully.']);
    }

    /** POST /api/auth/pin/verify */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            return response()->json(['message' => 'Invalid PIN.'], 422);
        }

        return response()->json(['message' => 'PIN verified.']);
    }

    /** PUT /api/auth/pin/change */
    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_pin'          => 'required|string|size:6',
            'new_pin'              => 'required|string|size:6|confirmed',
            'new_pin_confirmation' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (! $user->pin_hash || ! Hash::check($data['current_pin'], $user->pin_hash)) {
            return response()->json(['message' => 'Current PIN is incorrect.'], 422);
        }

        $user->update(['pin_hash' => Hash::make($data['new_pin'])]);

        return response()->json(['message' => 'PIN changed successfully.']);
    }
}