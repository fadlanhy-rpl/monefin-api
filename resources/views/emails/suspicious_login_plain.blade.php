{{ config('app.name') }} - Peringatan Keamanan Akun
===============================================

Peringatan: Kami mendeteksi beberapa kali upaya login yang gagal secara berturut-turut pada akun Anda ({{ $email }}).

Sebagai langkah perlindungan preventif terhadap data finansial Anda, kami sangat menyarankan Anda untuk segera mereset kata sandi melalui tautan resmi berikut:
{{ config('app.frontend_url') }}/forgot-password?email={{ urlencode($email) }}

Pusat Keamanan: {{ config('app.frontend_url') }}/security
Kebijakan Privasi: {{ config('app.frontend_url') }}/privacy
Ketentuan Layanan: {{ config('app.frontend_url') }}/terms

Jika Anda memerlukan bantuan lebih lanjut, hubungi kami di monefin.techapp@gmail.com.
(c) {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
