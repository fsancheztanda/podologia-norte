<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/admin/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        // Revocar tokens anteriores y crear uno nuevo
        $user->tokens()->delete();
        $token = $user->createToken('admin-session', ['*'], now()->addDays(30));

        return response()->json([
            'token'     => $token->plainTextToken,
            'user'      => [
                'id'   => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * POST /api/admin/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }
}
