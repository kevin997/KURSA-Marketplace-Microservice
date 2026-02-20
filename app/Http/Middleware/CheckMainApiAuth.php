<?php

namespace App\Http\Middleware;

use App\Models\Seller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckMainApiAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $response = Http::withToken($token)
                ->get(env('MAIN_API_URL') . '/api/user');

            if ($response->successful()) {
                $user = $response->json();

                if (!is_array($user) || empty($user['id'])) {
                    Log::warning('Main API auth: /api/user returned invalid data', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    return response()->json(['message' => 'Unauthenticated'], 401);
                }

                // Sync seller data on authentication to reduce future API calls
                $this->syncSellerData($user);

                // Merge user data into request for downstream controllers
                $request->merge(['remote_user' => $user]);
                return $next($request);
            }
        } catch (\Exception $e) {
            Log::error('Main API auth failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    /**
     * Sync the remote user's data into the local sellers table.
     * This reduces network requests by caching environment details locally.
     */
    private function syncSellerData(array $user): void
    {
        try {
            $userId = $user['id'] ?? null;
            if (!$userId) {
                return;
            }

            $environment = $user['environment'] ?? null;
            $role = $user['role'] ?? '';
            $isTeacher = in_array($role, ['individual_teacher', 'company_teacher', 'company_team_member']);

            $seller = Seller::where('user_id', $userId)->first();

            $updateData = [
                'email' => $user['email'] ?? null,
                'company_name' => $user['company_name'] ?? $user['name'] ?? null,
            ];

            if ($environment) {
                $updateData['environment_id'] = $environment['id'] ?? null;
                $updateData['environment_name'] = $environment['name'] ?? null;
                $updateData['environment_url'] = $environment['primary_domain'] ?? null;
                $updateData['logo_url'] = $environment['logo_url'] ?? null;
            }

            if ($seller) {
                // Update existing seller with latest data
                $seller->update($updateData);

                // Auto-verify teachers who weren't verified yet
                if (!$seller->is_verified && $isTeacher) {
                    $seller->update([
                        'is_verified' => true,
                        'verified_at' => now(),
                    ]);
                }
            } else {
                // Create seller record on first marketplace auth
                Seller::create(array_merge($updateData, [
                    'user_id' => $userId,
                    'is_verified' => $isTeacher,
                    'verified_at' => $isTeacher ? now() : null,
                ]));
            }
        } catch (\Exception $e) {
            // Don't fail auth if sync fails — just log
            Log::warning('Seller sync failed', [
                'user_id' => $user['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
