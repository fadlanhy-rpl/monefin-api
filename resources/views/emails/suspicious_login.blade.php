<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="format-detection" content="telephone=no"/>
    <meta name="x-apple-disable-message-reformatting"/>
    <title>Peringatan Keamanan - {{ config('app.name') }}</title>
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
        .btn-secure {
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
            .btn-secure {
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
                <!-- Main Container (600px Max) -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; width: 100%;">
                    
                    <!-- Top Branding Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <table border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" valign="middle">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background: linear-gradient(135deg, #DC2626 0%, #991B1B 100%); border-radius: 12px; width: 44px; height: 44px; vertical-align: middle; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);">
                                                    <span style="font-size: 22px; line-height: 44px;">🚨</span>
                                                </td>
                                                <td style="padding-left: 12px; vertical-align: middle;">
                                                    <span style="font-size: 22px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">{{ config('app.name') }}</span>
                                                    <span style="display: block; font-size: 11px; font-weight: 700; color: #DC2626; letter-spacing: 0.5px; text-transform: uppercase;">Security Alert System</span>
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
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 20px; border: 1px solid #FEE2E2; box-shadow: 0 10px 30px -5px rgba(220, 38, 38, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.02); overflow: hidden;">
                                
                                <!-- Top Red Accent Bar -->
                                <tr>
                                    <td style="background: linear-gradient(90deg, #DC2626 0%, #EF4444 50%, #DC2626 100%); height: 5px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                </tr>

                                <!-- Inner Content Area -->
                                <tr>
                                    <td style="padding: 40px 40px 36px 40px;" class="content-card">
                                        
                                        <!-- Header Badge & Title Section -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td>
                                                    <span style="display: inline-block; background-color: #FEF2F2; color: #DC2626; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #FECACA;">
                                                        ⚠️ Aktivitas Mencurigakan
                                                    </span>
                                                    <h1 style="margin: 0 0 12px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                        Percobaan Masuk Tidak Sah Terdeteksi
                                                    </h1>
                                                    <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                        Halo, sistem keamanan kami mendeteksi adanya upaya masuk berulang kali ke akun <strong>{{ config('app.name') }}</strong> Anda (<span style="color: #0F172A; font-weight: 600;">{{ $email }}</span>) dengan kode OTP yang salah secara berturut-turut.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Threat Summary Card -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FEF2F2; border-radius: 14px; border: 1px solid #FCA5A5; margin-bottom: 28px;">
                                            <tr>
                                                <td style="padding: 20px 24px;">
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td style="padding-bottom: 10px; border-bottom: 1px solid #FECACA;">
                                                                <span style="font-size: 11px; font-weight: 700; color: #991B1B; text-transform: uppercase; letter-spacing: 0.5px;">Status Pengamanan Akun:</span>
                                                                <div style="font-size: 14px; font-weight: 700; color: #7F1D1D; margin-top: 2px;">
                                                                    🔒 Permintaan OTP Telah Dibekukan Sementara
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding-top: 10px;">
                                                                <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #991B1B;">
                                                                    Untuk mencegah serangan <em>brute-force</em> atau pembobolan akun, sistem kami secara otomatis menonaktifkan kode OTP sebelumnya dan membatasi akses login.
                                                                </p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Call to Action Section -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 28px;">
                                            <tr>
                                                <td align="center">
                                                    <p style="margin: 0 0 16px 0; font-size: 15px; font-weight: 600; color: #0F172A; text-align: center;">
                                                        Jika ini bukan Anda, segera ubah kata sandi akun Anda:
                                                    </p>
                                                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/forgot-password?email={{ urlencode($email) }}" class="btn-secure" style="display: inline-block;">
                                                        Amankan Akun & Ganti Password →
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Fallback Link Container -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; margin-bottom: 24px;">
                                            <tr>
                                                <td style="padding: 14px 16px;">
                                                    <span style="display: block; font-size: 11px; font-weight: 600; color: #64748B; margin-bottom: 4px;">
                                                        Atau salin tautan pemulihan berikut ke browser Anda:
                                                    </span>
                                                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/forgot-password?email={{ urlencode($email) }}" style="font-size: 12px; color: #00685F; font-weight: 600; word-break: break-all; text-decoration: underline;">
                                                        {{ env('FRONTEND_URL', 'http://localhost:3000') }}/forgot-password?email={{ urlencode($email) }}
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Security Recommendations -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border-left: 4px solid #0F172A; border-radius: 0 12px 12px 0; border-top: 1px solid #F1F5F9; border-right: 1px solid #F1F5F9; border-bottom: 1px solid #F1F5F9;">
                                            <tr>
                                                <td style="padding: 16px 20px;">
                                                    <strong style="display: block; font-size: 13px; color: #0F172A; margin-bottom: 6px;">Langkah Pencegahan Tambahan:</strong>
                                                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #475569; line-height: 1.6;">
                                                        <li>Pastikan kata sandi Anda unik dan tidak digunakan di aplikasi lain.</li>
                                                        <li>Periksa daftar sesi login aktif di menu <strong>Pengaturan &rsaquo; Keamanan</strong>.</li>
                                                        <li>Jangan pernah membagikan kredensial login kepada siapa pun.</li>
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
                                Anda menerima email ini karena ada aktivitas keamanan pada akun Anda. Mohon tidak membalas email ini secara langsung.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
