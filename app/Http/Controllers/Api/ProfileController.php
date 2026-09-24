<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * POST /api/auth/profile
     * Body (multipart/form-data): { name, photo?, phone?, occupation?, bio?, preferences? }
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name'        => ['required', 'string', 'max:255'],
            'photo'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'phone'       => 'nullable|string|max:50',
            'occupation'  => 'nullable|string|max:100',
            'bio'         => 'nullable|string',
            'preferences' => 'nullable|string', // Since it might be sent as JSON string in FormData
        ], [
            'photo.image' => 'File harus berupa gambar.',
            'photo.mimes' => 'Format gambar harus jpeg, png, jpg, gif, atau webp.',
            'photo.max'   => 'Ukuran gambar maksimal adalah 10MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $data = [
                'name'       => strip_tags($request->name),
                'phone'      => $request->phone ? strip_tags($request->phone) : null,
                'occupation' => $request->occupation ? strip_tags($request->occupation) : null,
                'bio'        => $request->bio ? strip_tags($request->bio) : null,
            ];

            if ($request->has('preferences') && !is_null($request->preferences)) {
                $incomingPrefs = json_decode($request->preferences, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($incomingPrefs)) {
                    // Deeply preserve existing user preferences (such as ai_enabled and ai_config)
                    $currentPrefs = $user->preferences ?? [];
                    $data['preferences'] = array_merge($currentPrefs, $incomingPrefs);
                }
            }

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');

                if (!$file->isValid()) {
                    return response()->json(['message' => 'Upload foto gagal.'], 400);
                }

                // Hapus foto lama
                if ($user->photo) {
                    // Support both old (profiles/...) and new (uploads/profiles/...) format
                    $oldPath = str_replace('uploads/', '', $user->photo);
                    Storage::disk('uploads')->exists('profiles/' . basename($user->photo))
                        && Storage::disk('uploads')->delete('profiles/' . basename($user->photo));
                }

                // Simpan ke public/uploads/profiles/ — dapat diakses langsung nginx
                $filename = $file->hashName();
                $file->move(public_path('uploads/profiles'), $filename);
                $data['photo'] = 'uploads/profiles/' . $filename;
            }

            $user->update($data);

            return response()->json([
                'data'    => ['user' => $user->fresh()],
                'message' => 'Profil berhasil diperbarui.',
            ]);

        } catch (\Exception $e) {
            Log::error('Update Profile Error: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan pada server.'], 500);
        }
    }

    /**
     * POST /api/auth/password
     * Body: { current_password?, new_password, new_password_confirmation }
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => $user->password ? 'required' : 'nullable',
            'new_password'     => 'required|min:8|confirmed',
        ]);

        if ($user->password && !Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak cocok.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json(['message' => 'Password berhasil diperbarui.']);
    }

    /**
     * DELETE /api/auth/profile
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        // Verifikasi ulang identitas sebelum aksi destruktif
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password tidak valid. Konfirmasi gagal.'],
            ]);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete the user (cascades to all related data)
        $user->delete();

        return response()->json([
            'message' => 'Akun dan seluruh data berhasil dihapus secara permanen.',
        ]);
    }
}
