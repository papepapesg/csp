<?php

namespace App\Http\Controllers\Api;

use App\Foundation\Errors\ErrorCode;
use App\Foundation\Http\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Sanctum token auth for the mobile PWAs (FE-APP-02/03). The Backoffice uses
 * session auth; mobile apps exchange credentials for a personal access token.
 */
class AuthTokenController
{
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error(ErrorCode::UNAUTHENTICATED, 'Invalid credentials.', 401);
        }

        $token = $user->createToken($data['device'] ?? 'mobile')->plainTextToken;

        return ApiResponse::item([
            'token' => $token,
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::item($this->profile($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::item(['status' => 'LOGGED_OUT']);
    }

    private function profile(User $user): array
    {
        return [
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'operatorCode' => $user->operator_code,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }
}
