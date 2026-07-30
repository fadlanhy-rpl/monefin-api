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
     * @param string     $type  'verification' atau 'reset'
     */
    public function __construct($otp, string $type)
    {
        $this->otp  = $otp;
        $this->type = $type;
    }

    public function envelope(): Envelope
    {
        $subject = $this->type === 'verification'
            ? 'Verifikasi Email - ' . config('app.name')
            : 'Reset Password - ' . config('app.name');

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
