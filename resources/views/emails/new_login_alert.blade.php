<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="format-detection" content="telephone=no"/>
    <meta name="x-apple-disable-message-reformatting"/>
    <title>Peringatan Keamanan Login Baru - {{ config('app.name') }}</title>
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
            background-color: #F8FAFC !important;
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
        .btn-danger {
            display: inline-block;
            background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
            color: #FFFFFF !important;
            font-size: 15px;
            font-weight: 700;
            padding: 14px 32px;
            border-radius: 12px;
            text-align: center;
            letter-spacing: 0.2px;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
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
            .btn-danger {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F8FAFC; -webkit-font-smoothing: antialiased;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; min-height: 100vh;">
        <tr>
            <td align="center" style="padding: 40px 16px;" class="wrapper-table">
                <!-- Main Container (580px Max) -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; width: 100%;">
                    
                    <!-- Top Branding Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <table border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" valign="middle">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background: linear-gradient(135deg, #00685F 0%, #004D46 100%); border-radius: 12px; width: 44px; height: 44px; vertical-align: middle; box-shadow: 0 4px 12px rgba(0, 104, 95, 0.25);">
                                                    <span style="font-size: 22px; line-height: 44px;">🛡️</span>
                                                </td>
                                                <td style="padding-left: 12px; vertical-align: middle;">
                                                    <span style="font-size: 22px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">{{ config('app.name') }}</span>
                                                    <span style="display: block; font-size: 11px; font-weight: 700; color: #00685F; letter-spacing: 0.5px; text-transform: uppercase;">Security Alert System</span>
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
                                
                                <!-- Top Amber/Teal Accent Bar -->
                                <tr>
                                    <td style="background: linear-gradient(90deg, #F59E0B 0%, #00685F 100%); height: 5px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                </tr>

                                <!-- Inner Content Area -->
                                <tr>
                                    <td style="padding: 40px 40px 36px 40px;" class="content-card">
                                        
                                        <!-- Header Badge & Title Section -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td>
                                                    <span style="display: inline-block; background-color: #FEF3C7; color: #92400E; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #FDE68A;">
                                                        🔔 Login Baru Terdeteksi
                                                    </span>
                                                    <h1 style="margin: 0 0 12px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                        Apakah ini Anda yang baru saja login?
                                                    </h1>
                                                    <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                        Halo <strong>{{ $userName }}</strong>, akun <strong>{{ config('app.name') }}</strong> Anda (<span style="color: #0F172A; font-weight: 600;">{{ $userEmail }}</span>) baru saja digunakan untuk masuk dari perangkat atau sesi baru.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Device Details Card -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border-radius: 14px; border: 1px solid #E2E8F0; margin-bottom: 28px;">
                                            <tr>
                                                <td style="padding: 20px 24px;">
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td style="padding-bottom: 12px; border-bottom: 1px solid #EDF2F7;">
                                                                <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Perangkat & Browser:</span>
                                                                <div style="font-size: 15px; font-weight: 700; color: #0F172A; margin-top: 3px;">
                                                                    💻 {{ $deviceName }}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding-top: 12px; padding-bottom: 12px; border-bottom: 1px solid #EDF2F7;">
                                                                <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Alamat IP:</span>
                                                                <div style="font-size: 14px; font-weight: 600; color: #0F172A; margin-top: 3px; font-family: monospace;">
                                                                    🌐 {{ $ipAddress }}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding-top: 12px;">
                                                                <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Waktu Masuk:</span>
                                                                <div style="font-size: 14px; font-weight: 600; color: #0F172A; margin-top: 3px;">
                                                                    🕒 {{ $loginTime }} WIB
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- If This Was You -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 24px; background-color: #F0FDF4; border-radius: 12px; border: 1px solid #BBF7D0;">
                                            <tr>
                                                <td style="padding: 14px 18px;">
                                                    <p style="margin: 0; font-size: 13px; color: #166534; line-height: 1.5;">
                                                        ✅ <strong>Jika ini adalah Anda:</strong> Anda tidak perlu melakukan tindakan apa pun. Email ini dikirimkan semata-mata untuk menjaga keamanan akun Anda.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Danger Call to Action -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FEF2F2; border-radius: 16px; border: 1px solid #FECACA; margin-bottom: 28px;">
                                            <tr>
                                                <td align="center" style="padding: 24px 20px;">
                                                    <p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 800; color: #991B1B; text-align: center;">
                                                        🚨 Merasa tidak mengenali login ini?
                                                    </p>
                                                    <p style="margin: 0 0 18px 0; font-size: 13px; color: #7F1D1D; text-align: center; max-width: 440px; line-height: 1.5;">
                                                        Segera putuskan sesi perangkat tersebut dan amankan akun Anda dengan mengganti kata sandi sekarang juga.
                                                    </p>
                                                    <a href="{{ $secureUrl }}" class="btn-danger" style="display: inline-block;">
                                                        Bukan Saya — Amankan Akun Saya →
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Fallback Link Container -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; margin-bottom: 24px;">
                                            <tr>
                                                <td style="padding: 14px 16px;">
                                                    <span style="display: block; font-size: 11px; font-weight: 600; color: #64748B; margin-bottom: 4px;">
                                                        Atau salin tautan pengamanan berikut ke browser Anda:
                                                    </span>
                                                    <a href="{{ $secureUrl }}" style="font-size: 12px; color: #DC2626; font-weight: 600; word-break: break-all; text-decoration: underline;">
                                                        {{ $secureUrl }}
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Security Recommendations -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border-left: 4px solid #00685F; border-radius: 0 12px 12px 0; border-top: 1px solid #F1F5F9; border-right: 1px solid #F1F5F9; border-bottom: 1px solid #F1F5F9;">
                                            <tr>
                                                <td style="padding: 16px 20px;">
                                                    <strong style="display: block; font-size: 13px; color: #0F172A; margin-bottom: 6px;">Tips Keamanan Akun:</strong>
                                                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #475569; line-height: 1.6;">
                                                        <li>Aktifkan <strong>Autentikasi Dua Faktor (2FA)</strong> di menu Pengaturan Keamanan.</li>
                                                        <li>Gunakan kata sandi unik dan hindari penggunaan ulang kata sandi dari situs lain.</li>
                                                        <li>Tautan pengamanan di atas berlaku selama <strong>48 jam</strong>.</li>
                                                    </ul>
                                                </td>
                                            </tr>
                                        </table>

                                    </td>
                                </tr>

                                <!-- Card Footer Divider & Quick Links -->
                                <tr>
                                    <td style="background-color: #FAFCFC; border-top: 1px solid #F1F5F9; padding: 20px 40px; text-align: center;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/security" style="color: #64748B; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 10px; display: inline-block;">Pusat Keamanan</a>
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
                                Anda menerima email ini karena ada aktivitas login baru pada akun Anda. Mohon tidak membalas email ini secara langsung.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
