<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP — {{ config('app.name') }}</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; color: #333; }
        .container { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #00685F 0%, #004d46 100%); color: white; padding: 36px 40px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
        .header p { margin: 8px 0 0; font-size: 14px; opacity: 0.85; }
        .body { padding: 32px 40px; }
        .body p { line-height: 1.6; color: #555; font-size: 15px; margin: 0 0 16px; }
        .otp-box { background: #f0faf9; border: 2px dashed #00685F; border-radius: 12px; text-align: center; padding: 28px 24px; margin: 24px 0; }
        .otp-code { font-size: 44px; font-weight: 800; letter-spacing: 14px; color: #00685F; font-family: 'Courier New', monospace; }
        .otp-note { font-size: 13px; color: #888; margin-top: 10px; }
        .divider { height: 1px; background: #eee; margin: 24px 0; }
        .warning { font-size: 13px; color: #999; margin: 0; }
        .footer { background: #f9fafb; padding: 20px 40px; text-align: center; font-size: 12px; color: #aaa; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                @if($type === 'verification')
                    ✅ Verifikasi Email Anda
                @else
                    🔐 Reset Password
                @endif
            </h1>
            <p>{{ config('app.name') }}</p>
        </div>
        <div class="body">
            <p>Halo,</p>
            @if($type === 'verification')
                <p>Terima kasih telah mendaftar di <strong>{{ config('app.name') }}</strong>. Masukkan kode berikut untuk memverifikasi alamat email Anda dan mengaktifkan akun:</p>
            @else
                <p>Kami menerima permintaan reset password untuk akun yang terdaftar dengan email ini. Gunakan kode berikut untuk mereset password Anda:</p>
            @endif

            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-note">⏱ Kode berlaku selama <strong>10 menit</strong></div>
            </div>

            <div class="divider"></div>
            <p class="warning">⚠️ Jika Anda tidak merasa melakukan permintaan ini, abaikan email ini. Akun Anda tetap aman.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.
        </div>
    </div>
</body>
</html>
