<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="format-detection" content="telephone=no"/>
    <meta name="x-apple-disable-message-reformatting"/>
    <title>Kode Keamanan - {{ config('app.name') }}</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, h1, h2, p, a, span {font-family: Arial, sans-serif !important;}
    </style>
    <![endif]-->
    <style type="text/css">
        body {
            margin: 0 !important;
            padding: 0 !important;
            -webkit-text-size-adjust: 100% !important;
            -ms-text-size-adjust: 100% !important;
            background-color: #F1F5F5 !important;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1E293B;
        }
        table {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            mso-table-lspace: 0pt !important;
            mso-table-rspace: 0pt !important;
        }
        img {
            border: 0 !important;
            outline: none !important;
            text-decoration: none !important;
            -ms-interpolation-mode: bicubic !important;
        }
        a {
            text-decoration: none;
            color: #00685F;
        }
        .otp-digit {
            display: inline-block;
            letter-spacing: 8px;
            font-size: 38px;
            font-weight: 800;
            color: #00685F;
            font-family: 'SF Pro Display', -apple-system, 'Segoe UI', Monaco, Consolas, monospace;
        }
        @media only screen and (max-width: 620px) {
            .wrapper-table {
                width: 100% !important;
                padding: 16px 12px !important;
            }
            .content-card {
                padding: 28px 20px !important;
                border-radius: 16px !important;
            }
            .header-padding {
                padding: 24px 20px 16px 20px !important;
            }
            .otp-container-cell {
                padding: 20px 16px !important;
            }
            .otp-digit {
                font-size: 32px !important;
                letter-spacing: 6px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F1F5F5; -webkit-font-smoothing: antialiased;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F1F5F5; min-height: 100vh;">
        <tr>
            <td align="center" style="padding: 40px 16px;" class="wrapper-table">
                <!-- Main Container (600px Max) -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; width: 100%;">
                    
                    <!-- Top Branding Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <table border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" valign="middle">
                                        <!-- MoneFin Custom SVG Logo Mark -->
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background: linear-gradient(135deg, #00685F 0%, #004D46 100%); border-radius: 12px; width: 44px; height: 44px; vertical-align: middle; box-shadow: 0 4px 12px rgba(0, 104, 95, 0.25);">
                                                    <span style="font-size: 24px; font-weight: 900; color: #FFFFFF; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 44px; display: inline-block;">M</span>
                                                </td>
                                                <td style="padding-left: 12px; vertical-align: middle;">
                                                    <span style="font-size: 22px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">{{ config('app.name') }}</span>
                                                    <span style="display: block; font-size: 11px; font-weight: 600; color: #64748B; letter-spacing: 0.5px; text-transform: uppercase;">Financial Platform</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Card Body -->
                    <tr>
                        <td>
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 20px; border: 1px solid #E2E8F0; box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02); overflow: hidden;">
                                
                                <!-- Top Accent Color Bar -->
                                <tr>
                                    <td style="background: linear-gradient(90deg, #00685F 0%, #00A389 50%, #00685F 100%); height: 5px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                </tr>

                                <!-- Inner Content Area -->
                                <tr>
                                    <td style="padding: 40px 40px 36px 40px;" class="content-card">
                                        
                                        <!-- Header Badge & Title Section -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td>
                                                    @if($type === 'verification')
                                                        <span style="display: inline-block; background-color: #E6F4F1; color: #00685F; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #CCEDE7;">
                                                            ✓ Verifikasi Email
                                                        </span>
                                                        <h1 style="margin: 0 0 10px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                            Verifikasi Alamat Email Anda
                                                        </h1>
                                                        <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                            Terima kasih telah mendaftar di <strong>{{ config('app.name') }}</strong>. Gunakan kode verifikasi di bawah ini untuk mengonfirmasi email Anda dan mulai mengelola finansial dengan cerdas.
                                                        </p>
                                                    @elseif($type === 'reset')
                                                        <span style="display: inline-block; background-color: #EFF6FF; color: #1D4ED8; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #DBEAFE;">
                                                            🔑 Reset Password
                                                        </span>
                                                        <h1 style="margin: 0 0 10px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                            Permintaan Reset Password
                                                        </h1>
                                                        <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                            Kami menerima permintaan untuk mengatur ulang kata sandi akun <strong>{{ config('app.name') }}</strong> Anda. Masukkan kode berikut pada halaman reset kata sandi:
                                                        </p>
                                                    @elseif($type === '2fa')
                                                        <span style="display: inline-block; background-color: #FEF3C7; color: #92400E; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #FDE68A;">
                                                            🛡️ Keamanan 2FA
                                                        </span>
                                                        <h1 style="margin: 0 0 10px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                            Kode Autentikasi Masuk
                                                        </h1>
                                                        <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                            Upaya masuk terdeteksi pada akun Anda. Untuk melindungi data keuangan Anda, silakan masukkan kode verifikasi dua langkah (2FA) berikut:
                                                        </p>
                                                    @else
                                                        <span style="display: inline-block; background-color: #E6F4F1; color: #00685F; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #CCEDE7;">
                                                            🔒 Kode Keamanan
                                                        </span>
                                                        <h1 style="margin: 0 0 10px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                            Kode Verifikasi
                                                        </h1>
                                                        <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                            Gunakan kode keamanan di bawah ini untuk menyelesaikan proses autentikasi Anda:
                                                        </p>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- OTP Box (Modern Card Design) -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 8px 0 28px 0;">
                                            <tr>
                                                <td align="center" style="background: linear-gradient(135deg, #F0FDF9 0%, #F8FAFC 100%); border: 2px dashed #00685F33; border-radius: 16px; padding: 28px 24px;" class="otp-container-cell">
                                                    <span style="display: block; font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 12px;">
                                                        Kode Sekali Pakai (OTP)
                                                    </span>
                                                    <div class="otp-digit" style="letter-spacing: 10px; font-size: 38px; font-weight: 900; color: #00685F; text-shadow: 0 1px 2px rgba(0,104,95,0.1);">
                                                        {{ $otp }}
                                                    </div>
                                                    <!-- Expiry Pill -->
                                                    <table border="0" cellpadding="0" cellspacing="0" style="margin-top: 14px;">
                                                        <tr>
                                                            <td align="center" style="background-color: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 20px; padding: 4px 12px;">
                                                                <span style="font-size: 12px; color: #64748B; font-weight: 600;">
                                                                    ⏱️ Berlaku selama <strong>5 menit</strong>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Security Notice Card -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border-left: 4px solid #00685F; border-radius: 0 12px 12px 0; border-top: 1px solid #F1F5F9; border-right: 1px solid #F1F5F9; border-bottom: 1px solid #F1F5F9; margin-bottom: 24px;">
                                            <tr>
                                                <td style="padding: 16px 20px;">
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td valign="top" style="width: 24px; padding-right: 12px; padding-top: 2px;">
                                                                <span style="font-size: 16px;">🛡️</span>
                                                            </td>
                                                            <td valign="top">
                                                                <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #475569;">
                                                                    <strong style="color: #0F172A;">Tips Keamanan:</strong> Jangan pernah memberikan kode ini kepada siapa pun. Tim <strong>{{ config('app.name') }}</strong> tidak akan pernah meminta kode OTP atau kata sandi Anda.
                                                                </p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Help & Not You Text -->
                                        <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #94A3B8; text-align: center;">
                                            Jika Anda tidak merasa melakukan tindakan ini, mohon abaikan email ini atau segera hubungi <a href="mailto:support@monefin.com" style="color: #00685F; font-weight: 600; text-decoration: underline;">support@monefin.com</a>.
                                        </p>

                                    </td>
                                </tr>

                                <!-- Card Footer Divider & Quick Links -->
                                <tr>
                                    <td style="background-color: #FAFCFC; border-top: 1px solid #F1F5F9; padding: 20px 40px; text-align: center;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/security" style="color: #64748B; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 10px; display: inline-block;">Keamanan</a>
                                                    <span style="color: #CBD5E1; font-size: 11px;">•</span>
                                                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/privacy" style="color: #64748B; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 10px; display: inline-block;">Kebijakan Privasi</a>
                                                    <span style="color: #CBD5E1; font-size: 11px;">•</span>
                                                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/terms" style="color: #64748B; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 10px; display: inline-block;">Ketentuan Layanan</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                            </table>
                        </td>
                    </tr>

                    <!-- Email Outer Footer -->
                    <tr>
                        <td align="center" style="padding-top: 24px; padding-bottom: 16px;">
                            <p style="margin: 0 0 6px 0; font-size: 12px; color: #94A3B8; font-weight: 500;">
                                &copy; {{ date('Y') }} {{ config('app.name') }} Financial Services. All rights reserved.
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #CBD5E1; line-height: 1.4;">
                                Ini adalah pesan otomatis dari sistem keamanan {{ config('app.name') }}. Mohon tidak membalas langsung ke alamat email pengirim ini.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
