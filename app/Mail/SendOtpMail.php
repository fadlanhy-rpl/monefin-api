<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public int|string $otp;
    public string $type;

    /**
     * @param int|string $otp   Kode OTP 6 digit
     * @param string     $type  'verification' | 'reset' | '2fa'
     */
    public function __construct($otp, string $type)
    {
        $this->otp  = $otp;
        $this->type = $type;
    }

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'MoneFin');

        $subject = match($this->type) {
            'verification' => "Kode Verifikasi Pendaftaran – {$appName}",
            'reset'        => "Kode Keamanan Reset Kata Sandi – {$appName}",
            '2fa'          => "Kode Keamanan Verifikasi 2 Langkah – {$appName}",
            default        => "Kode Otentikasi Keamanan – {$appName}",
        };

        $fromAddress = config('mail.from.address', 'monefin.techapp@gmail.com');
        $fromName    = config('mail.from.name', 'MoneFin');

        return new Envelope(
            subject: $subject,
            replyTo: [
                new Address($fromAddress, $fromName),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'Auto-Submitted'           => 'auto-generated',
                'X-Auto-Response-Suppress' => 'All',
                'Precedence'               => 'bulk',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            text: 'emails.otp_plain'
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
