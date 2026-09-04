<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class OtpCode extends Model
{
    protected $fillable = ['email', 'code', 'type', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Buat kode OTP 6-digit baru, simpan dalam bentuk Bcrypt hash di database,
     * dan kembalikan kode teks polos (plaintext) HANYA untuk dikirim via email.
     */
    public static function generateFor(string $email, string $type, int $expiresInMinutes = 5): string
    {
        // Bersihkan OTP lama untuk email dan tipe yang sama
        static::where('email', $email)->where('type', $type)->delete();

        $plainOtp = (string) rand(100000, 999999);

        static::create([
            'email'      => $email,
            'code'       => Hash::make($plainOtp),
            'type'       => $type,
            'expires_at' => Carbon::now()->addMinutes($expiresInMinutes),
        ]);

        return $plainOtp;
    }

    /**
     * Verifikasi kode OTP plaintext terhadap hash yang tersimpan di database.
     * Mengembalikan record OtpCode jika valid dan belum kadaluarsa, atau null jika gagal.
     * Memiliki fallback otomatis jika masih ada record OTP legacy yang belum kadaluarsa.
     */
    public static function verify(string $email, string $type, string $plainOtp): ?self
    {
        $record = static::where('email', $email)
            ->where('type', $type)
            ->where('expires_at', '>', Carbon::now())
            ->latest()
            ->first();

        if (!$record) {
            return null;
        }

        // Cek jika tersimpan dalam format hash Bcrypt / Argon2
        if (str_starts_with($record->code, '$2y$') || str_starts_with($record->code, '$2a$') || str_starts_with($record->code, '$argon2')) {
            if (Hash::check($plainOtp, $record->code)) {
                return $record;
            }
        } else {
            // Fallback aman untuk OTP lama yang masih berbentuk plaintext
            if (hash_equals((string) $record->code, (string) $plainOtp)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Periksa apakah OTP sudah kadaluarsa.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
