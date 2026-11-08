<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail sem nenhum conteúdo do app: nem jogo, nem pergunta, nem resposta. */
class NeutralNotice extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Você tem novidades');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.neutral');
    }
}
