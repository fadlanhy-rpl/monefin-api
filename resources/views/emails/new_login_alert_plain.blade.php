{{ config('app.name') }} - Peringatan Keamanan
===============================================

Halo {{ $userName }},

Akun MoneFin Anda ({{ $userEmail }}) baru saja digunakan untuk masuk dari perangkat atau sesi baru.

DETAIL LOGIN:
- Waktu: {{ $loginTime }}
- Perangkat / Browser: {{ $deviceName }}
- Alamat IP: {{ $ipAddress }}

Jika ini memang Anda, Anda tidak perlu melakukan tindakan apa pun.

Bukan Anda? Segera amankan akun Anda melalui tautan resmi berikut:
{{ $secureUrl }}

Pusat Keamanan: {{ config('app.frontend_url') }}/security
Kebijakan Privasi: {{ config('app.frontend_url') }}/privacy
Ketentuan Layanan: {{ config('app.frontend_url') }}/terms

Hubungi bantuan: monefin.techapp@gmail.com
(c) {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
