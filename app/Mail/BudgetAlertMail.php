<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BudgetAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $userName;
    public $categoryName;
    public $spentPercent;
    public $spentAmount;
    public $limitAmount;
    public $isCritical;

    /**
     * Create a new message instance.
     */
    public function __construct($userName, $categoryName, $spentPercent, $spentAmount, $limitAmount)
    {
        $this->userName = $userName;
        $this->categoryName = $categoryName;
        $this->spentPercent = $spentPercent;
        $this->spentAmount = $spentAmount;
        $this->limitAmount = $limitAmount;
        $this->isCritical = $spentPercent >= 100;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isCritical 
            ? "⚠️ Peringatan Kritis: Anggaran $this->categoryName Habis!" 
            : "⚠️ Peringatan: Pengeluaran $this->categoryName Mendekati Batas";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.budget_alert',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
