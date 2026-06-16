<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * TokenController — Sanctum API Token Management
 *
 * Issues personal access tokens for use with the /api/ask and /api/vector-store/*
 * endpoints. This is the only unauthenticated API endpoint.
 *
 * Sanctum API Tokens vs Session Auth:
 *   - Tokens are stateless: each request sends the token in the Authorization header.
 *   - No cookies or CSRF needed — ideal for REST clients, curl, and SSE.
 *   - Tokens can be scoped and revoked per-user.
 *
 * Usage:
 *   # 1. Create a user (one-time setup via tinker or seeder)
 *   php artisan tinker
 *   > User::factory()->create(['email' => 'dev@example.com', 'password' => bcrypt('secret')])
 *
 *   # 2. Get a token
 *   curl -X POST http://localhost:8000/api/tokens/create \
 *        -H 'Content-Type: application/json' \
 *        -d '{"email":"dev@example.com","password":"secret","device_name":"dev"}'
 *
 *   # 3. Use the token
 *   curl -X POST http://localhost:8000/api/ask \
 *        -H 'Authorization: Bearer <token>' \
 *        -H 'Content-Type: application/json' \
 *        -d '{"question":"What is our PTO policy?"}'
 */
class TokenController extends Controller
{
    /**
     * POST /api/tokens/create
     *
     * Validates credentials and issues a new personal access token.
     * The token is returned only once — store it securely.
     *
     * Body:
     *   {
     *     "email": "dev@example.com",
     *     "password": "secret",
     *     "device_name": "dev-machine"   // optional, defaults to "api"
     *   }
     *
     * Returns:
     *   { "token": "1|abc123..." }
     *
     * Errors:
     *   422 if credentials are invalid
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Create a new Sanctum personal access token.
        // The token is hashed in the database; only the plaintext is returned here.
        $token = $user->createToken(
            name: $request->input('device_name', 'api'),
        )->plainTextToken;

        return response()->json(['token' => $token]);
    }
}
