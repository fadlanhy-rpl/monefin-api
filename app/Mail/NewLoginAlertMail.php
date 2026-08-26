<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewLoginAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $userName;
    public $userEmail;
    public $deviceName;
    public $ipAddress;
    public $loginTime;
    public $actionToken;
    public $secureUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(string $userName, string $userEmail, string $deviceName, string $ipAddress, string $loginTime, string $actionToken)
    {
        $this->userName    = $userName;
        $this->userEmail   = $userEmail;
        $this->deviceName  = $deviceName;
        $this->ipAddress   = $ipAddress;
        $this->loginTime   = $loginTime;
        $this->actionToken = $actionToken;

        $frontendUrl     = env('FRONTEND_URL', 'http://localhost:3000');
        $this->secureUrl = rtrim($frontendUrl, '/') . '/secure-account?token=' . urlencode($actionToken);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Peringatan Keamanan: Login Baru Terdeteksi – ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.new_login_alert',
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
