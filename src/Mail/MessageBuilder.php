<?php

declare(strict_types=1);

namespace App\Mail;

use App\Template\Renderer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the raw RFC 5322 message (multipart/alternative HTML + text) for one recipient.
 * Templates are compiled once per campaign and rendered per recipient.
 */
final class MessageBuilder
{
    private Renderer $subject;
    private Renderer $html;
    private string $domain;

    public function __construct(private readonly SmtpSettings $smtp, string $subjectTpl, string $htmlTpl, private readonly string $campaignId)
    {
        $this->subject = new Renderer($subjectTpl);
        $this->html = new Renderer($htmlTpl);
        $this->domain = substr(strrchr($smtp->fromEmail, '@') ?: '@localhost', 1);
    }

    /** @return array{0: string, 1: string} [raw MIME, rendered subject] */
    public function build(string $to, array $data, string $id, string $subjectPrefix = ''): array
    {
        $subject = $subjectPrefix . trim(str_replace(["\r", "\n"], ' ', $this->subject->render($data, false)));
        $html = $this->html->render($data, true);

        $email = (new Email())
            ->from(new Address($this->smtp->fromEmail, $this->smtp->fromName))
            ->to(new Address($to))
            ->subject($subject)
            ->html($html)
            ->text(Renderer::htmlToText($html));

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', sprintf('%s.%s@%s', $this->campaignId, $id, $this->domain));
        $headers->addTextHeader('X-Mailer', 'BulkMailer');
        if ($this->smtp->unsubscribe !== '') {
            $headers->addTextHeader('List-Unsubscribe', '<' . $this->smtp->unsubscribe . '>');
            if (str_starts_with(strtolower($this->smtp->unsubscribe), 'http')) {
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        }
        return [$email->toString(), $subject];
    }
}
