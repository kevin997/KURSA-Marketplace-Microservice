<?php

namespace App\Http\Middleware;

use App\Models\Seller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InternalServiceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->header('X-Internal-Secret');
        $expectedSecret = env('INTERNAL_SERVICE_SECRET');

        if (!$secret || !$expectedSecret || !hash_equals($expectedSecret, $secret)) {
            return response()->json(['message' => 'Unauthorized internal request'], 401);
        }

        $userData = json_decode($request->header('X-Remote-User'), true);

        if (!is_array($userData) || empty($userData['id'])) {
            return response()->json(['message' => 'Missing remote user data'], 400);
        }

        $this->syncSellerData($userData);
        $request->merge(['remote_user' => $userData]);

        return $next($request);
    }

    private function syncSellerData(array $user): void
    {
        try {
            $userId = $user['id'] ?? null;
            if (!$userId) return;

            $role = $user['role'] ?? '';
            $isTeacher = in_array($role, ['individual_teacher', 'company_teacher', 'company_team_member']);

            $seller = Seller::where('user_id', $userId)->first();

            $updateData = [
                'email' => $user['email'] ?? null,
                'company_name' => $user['company_name'] ?? $user['name'] ?? null,
            ];

            if ($seller) {
                $seller->update($updateData);
                if (!$seller->is_verified && $isTeacher) {
                    $seller->update(['is_verified' => true, 'verified_at' => now()]);
                }
            } else {
                Seller::create(array_merge($updateData, [
                    'user_id' => $userId,
                    'is_verified' => $isTeacher,
                    'verified_at' => $isTeacher ? now() : null,
                ]));
            }
        } catch (\Exception $e) {
            Log::warning('Internal auth seller sync failed', [
                'user_id' => $user['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
