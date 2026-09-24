{{ config('app.name') }} - Platform Finansial
===============================================

@if($type === 'verification')
VERIFIKASI EMAIL ANDA
Terima kasih telah mendaftar di {{ config('app.name') }}. Gunakan kode verifikasi di bawah ini untuk mengonfirmasi email Anda:
@elseif($type === 'reset')
PERMINTAAN RESET PASSWORD
Kami menerima permintaan untuk mengatur ulang kata sandi akun {{ config('app.name') }} Anda. Masukkan kode berikut pada halaman reset kata sandi:
@elseif($type === '2fa')
KODE AUTENTIKASI DUA LANGKAH (2FA)
Upaya masuk terdeteksi pada akun Anda. Masukkan kode verifikasi 2FA berikut untuk melanjutkan:
@else
KODE VERIFIKASI KEAMANAN
Gunakan kode keamanan di bawah ini untuk menyelesaikan proses autentikasi Anda:
@endif

KODE OTP ANDA: {{ $otp }}
(Berlaku selama 5 menit)

TIPS KEAMANAN:
Jangan pernah membagikan kode ini kepada siapa pun. Tim {{ config('app.name') }} tidak akan pernah meminta kode OTP atau kata sandi Anda.

Jika Anda tidak merasa melakukan permintaan ini, mohon abaikan email ini atau hubungi kami di monefin.techapp@gmail.com.

Pusat Keamanan: {{ config('app.frontend_url') }}/security
Kebijakan Privasi: {{ config('app.frontend_url') }}/privacy
Ketentuan Layanan: {{ config('app.frontend_url') }}/terms

(c) {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
Pesan ini dikirimkan secara otomatis oleh sistem keamanan {{ config('app.name') }}.
