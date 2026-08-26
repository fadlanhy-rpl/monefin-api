<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Peringatan Anggaran - {{ config('app.name') }}</title>
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
        }
        a {
            text-decoration: none;
            color: #00685F;
        }
        @media only screen and (max-width: 620px) {
            .wrapper-table { width: 100% !important; padding: 16px 12px !important; }
            .content-card { padding: 28px 20px !important; border-radius: 16px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F1F5F5; -webkit-font-smoothing: antialiased;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F1F5F5; min-height: 100vh;">
        <tr>
            <td align="center" style="padding: 40px 16px;" class="wrapper-table">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
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

                    <!-- Card Body -->
                    <tr>
                        <td>
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FFFFFF; border-radius: 20px; border: 1px solid #E2E8F0; box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05); overflow: hidden;">
                                <!-- Top Accent Color Bar -->
                                <tr>
                                    @if($isCritical)
                                        <td style="background: linear-gradient(90deg, #DC2626 0%, #EF4444 50%, #DC2626 100%); height: 5px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                    @else
                                        <td style="background: linear-gradient(90deg, #D97706 0%, #F59E0B 50%, #D97706 100%); height: 5px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                    @endif
                                </tr>

                                <tr>
                                    <td style="padding: 40px 40px 36px 40px;" class="content-card">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td>
                                                    @if($isCritical)
                                                        <span style="display: inline-block; background-color: #FEF2F2; color: #991B1B; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #FEE2E2;">
                                                            🔴 Anggaran Habis
                                                        </span>
                                                        <h1 style="margin: 0 0 10px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                            Batas Anggaran Tercapai!
                                                        </h1>
                                                        <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                            Halo <strong>{{ $userName }}</strong>, pengeluaran Anda untuk kategori <strong>{{ $categoryName }}</strong> telah mencapai 100% dari anggaran bulan ini.
                                                        </p>
                                                    @else
                                                        <span style="display: inline-block; background-color: #FFFBEB; color: #92400E; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; border: 1px solid #FEF3C7;">
                                                            ⚠️ Peringatan Anggaran
                                                        </span>
                                                        <h1 style="margin: 0 0 10px 0; font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; line-height: 1.3;">
                                                            Anggaran Mendekati Batas
                                                        </h1>
                                                        <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                                            Halo <strong>{{ $userName }}</strong>, pengeluaran Anda untuk kategori <strong>{{ $categoryName }}</strong> sudah mencapai batas peringatan (80% atau lebih).
                                                        </p>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Detail Box -->
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 8px 0 28px 0;">
                                            <tr>
                                                <td align="center" style="background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%); border: 1px solid #E2E8F0; border-radius: 16px; padding: 24px;">
                                                    
                                                    <table width="100%">
                                                        <tr>
                                                            <td style="text-align: left; padding-bottom: 12px; border-bottom: 1px solid #E2E8F0;">
                                                                <span style="font-size: 12px; color: #64748B; font-weight: 600; text-transform: uppercase;">Total Terpakai</span><br/>
                                                                <span style="font-size: 20px; color: #0F172A; font-weight: 800;">Rp {{ number_format($spentAmount, 0, ',', '.') }}</span>
                                                            </td>
                                                            <td style="text-align: right; padding-bottom: 12px; border-bottom: 1px solid #E2E8F0;">
                                                                <span style="font-size: 12px; color: #64748B; font-weight: 600; text-transform: uppercase;">Batas Anggaran</span><br/>
                                                                <span style="font-size: 20px; color: #0F172A; font-weight: 800;">Rp {{ number_format($limitAmount, 0, ',', '.') }}</span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding-top: 16px;">
                                                                <span style="font-size: 12px; color: #64748B; font-weight: 600;">Persentase: <strong style="color: {{ $isCritical ? '#DC2626' : '#D97706' }}">{{ round($spentPercent) }}%</strong></span>
                                                                
                                                                <!-- Progress Bar -->
                                                                <div style="background-color: #E2E8F0; height: 8px; border-radius: 4px; margin-top: 8px; overflow: hidden; width: 100%;">
                                                                    <div style="background-color: {{ $isCritical ? '#DC2626' : '#F59E0B' }}; height: 100%; width: {{ min(100, round($spentPercent)) }}%;"></div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>

                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #475569; text-align: center;">
                                            Silakan periksa detail transaksi Anda di aplikasi untuk mengelola sisa anggaran dengan lebih bijak.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding-top: 24px; padding-bottom: 16px;">
                            <p style="margin: 0 0 6px 0; font-size: 12px; color: #94A3B8; font-weight: 500;">
                                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
