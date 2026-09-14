<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserSessionController extends Controller
{
    /**
     * GET /api/auth/sessions
     */
    public function getSessions(Request $request): JsonResponse
    {
        $user         = $request->user();
        $currentToken = $user->currentAccessToken();

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(function ($token) use ($currentToken) {
                // Token lama (sebelum migrasi) memiliki name = 'auth_token' dan device_name = null
                // Tampilkan label yang lebih informatif
                $deviceLabel = $token->device_name;
                if (!$deviceLabel) {
                    $deviceLabel = ($token->name && $token->name !== 'auth_token')
                        ? $token->name
                        : 'Sesi Lama (Browser Tidak Diketahui)';
                }

                return [
                    'id'          => $token->id,
                    'device_name' => $deviceLabel,
                    'ip_address'  => $token->ip_address ?: 'IP tidak tersimpan',
                    'last_used_at'=> $token->last_used_at,
                    'created_at'  => $token->created_at,
                    'is_current'  => $token->id === $currentToken->id,
                    'is_legacy'   => !$token->device_name, // flag untuk token lama
                ];
            });

        return response()->json([
            'data'    => ['sessions' => $sessions],
            'message' => 'Sessions fetched successfully.',
        ]);
    }

    /**
     * DELETE /api/auth/sessions/{tokenId}
     */
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $user         = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken->id === $tokenId) {
            return response()->json(['message' => 'Tidak dapat menghapus sesi aktif Anda sendiri.'], 400);
        }

        $tokenToDelete = $user->tokens()->where('id', $tokenId)->first();

        if (!$tokenToDelete) {
            return response()->json(['message' => 'Sesi tidak ditemukan.'], 404);
        }

        // Simpan info siapa yang mencabut token ini menggunakan hash token sebagai key
        $revokerDetails = [
            'device' => $currentToken->device_name ?: 'Perangkat Tidak Diketahui',
            'ip'     => $currentToken->ip_address ?: 'IP tidak tersimpan',
            'time'   => now()->translatedFormat('d M Y, H:i')
        ];
        Cache::put('revoked_' . $tokenToDelete->token, $revokerDetails, now()->addDay());

        $tokenToDelete->delete();

        return response()->json(['message' => 'Sesi berhasil dikeluarkan.']);
    }

    /**
     * DELETE /api/auth/sessions — revoke all OTHER sessions
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user         = $request->user();
        $currentToken = $user->currentAccessToken();

        $tokensToDelete = $user->tokens()->where('id', '!=', $currentToken->id)->get();

        $revokerDetails = [
            'device' => $currentToken->device_name ?: 'Perangkat Tidak Diketahui',
            'ip'     => $currentToken->ip_address ?: 'IP tidak tersimpan',
            'time'   => now()->translatedFormat('d M Y, H:i')
        ];

        foreach ($tokensToDelete as $tokenToDel) {
            Cache::put('revoked_' . $tokenToDel->token, $revokerDetails, now()->addDay());
            $tokenToDel->delete();
        }

        return response()->json(['message' => 'Semua sesi lain berhasil dikeluarkan.']);
    }
}
