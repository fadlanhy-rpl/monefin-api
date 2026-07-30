<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Kode OTP - {{ config('app.name') }}</title>
    <style>
        .container {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
        }
        .header {
            background-color: #00685F;
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 6px 6px 0 0;
        }
        .header h2 {
            margin: 0;
            font-size: 22px;
        }
        .content {
            padding: 30px 20px;
            text-align: center;
            color: #333333;
        }
        .content p {
            line-height: 1.5;
            margin-bottom: 15px;
        }
        .otp {
            font-size: 36px;
            font-weight: bold;
            color: #00685F;
            letter-spacing: 8px;
            margin: 25px 0;
            padding: 15px;
            background-color: #f4f6f8;
            border-radius: 6px;
        }
        .footer {
            font-size: 12px;
            color: #888888;
            text-align: center;
            margin-top: 30px;
            border-top: 1px solid #eeeeee;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>{{ config('app.name') }}</h2>
        </div>
        <div class="content">
            <h3>{{ $type === 'verification' ? 'Verifikasi Email Anda' : 'Reset Password' }}</h3>
            <p>Halo,</p>
            
            @if($type === 'verification')
                <p>Terima kasih telah mendaftar di {{ config('app.name') }}. Gunakan kode OTP di bawah ini untuk memverifikasi alamat email Anda.</p>
            @else
                <p>Kami menerima permintaan reset password. Gunakan kode OTP di bawah ini untuk mereset password akun Anda.</p>
            @endif
            
            <div class="otp">{{ $otp }}</div>
            
            <p>Kode ini berlaku selama 10 menit. Jika Anda tidak melakukan permintaan ini, silakan abaikan email ini.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
