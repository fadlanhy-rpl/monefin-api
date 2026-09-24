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

        $frontendUrl     = config('app.frontend_url', 'https://monefin.web.id');
        $this->secureUrl = rtrim($frontendUrl, '/') . '/secure-account?token=' . urlencode($actionToken);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address', 'monefin.techapp@gmail.com');
        $fromName    = config('mail.from.name', 'MoneFin');

        return new Envelope(
            subject: 'Peringatan Keamanan: Login Baru Terdeteksi – ' . config('app.name', 'MoneFin'),
            replyTo: [
                new \Illuminate\Mail\Mailables\Address($fromAddress, $fromName),
            ],
        );
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(
            text: [
                'Auto-Submitted'           => 'auto-generated',
                'X-Auto-Response-Suppress' => 'All',
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.new_login_alert',
            text: 'emails.new_login_alert_plain',
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
