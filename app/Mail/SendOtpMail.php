<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
        $appName = config('app.name');

        $subject = match($this->type) {
            'verification' => "Verifikasi Email Anda – {$appName}",
            'reset'        => "Reset Password – {$appName}",
            '2fa'          => "Kode Keamanan Two-Factor – {$appName}",
            default        => "Kode OTP – {$appName}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }

    public function attachments(): array
    {
        return [];
    }
}
