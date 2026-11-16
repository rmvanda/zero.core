<?php
namespace Zero\Lib\Mail;

use \Zero\Entity\Event;

/**
 * Mail - Static email sending utility with event logging
 *
 * Provides centralized email sending functionality with automatic logging
 * of all sent emails to the events table.
 */
class Mail {

    /**
     * Send email to a specific recipient
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $message Email body (can be plain text or HTML)
     * @param string|null $from Sender email address (default: sender())
     * @param string|null $replyTo Reply-to email address (default: sender())
     * @return bool True if email was sent successfully, false otherwise
     */
    public static function sendEmailTo($to, $subject, $message, $from = null, $replyTo = null) {
        $from = $from ?: self::sender();
        $replyTo = $replyTo ?: self::sender();
        // Prepare headers
        $headers = "From: $from\r\n";
        $headers .= "Reply-To: $replyTo\r\n";

        // Auto-detect if message contains HTML
        if (preg_match('/<html|<body|<div|<p|<table/i', $message)) {
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        }

        // Send email
        $success = mail($to, $subject, $message, $headers);

        // Log the email event
        $detail = json_encode([
            'to' => $to,
            'subject' => $subject,
            'from' => $from,
            'reply_to' => $replyTo,
            'success' => $success,
            'message_length' => strlen($message)
        ], JSON_UNESCAPED_SLASHES);

        new Event([
            'event' => 'Mail Sent',
            'detail' => $detail
        ]);

        return $success;
    }

    /**
     * The site's own sender address: the constant MAIL_FROM when it is
     * defined and not blank, otherwise noreply@ + PRIMARY_DOMAIN.
     */
    public static function sender(): string {
        return self::chooseSender(
            defined('MAIL_FROM') ? MAIL_FROM : null,
            defined('PRIMARY_DOMAIN') ? PRIMARY_DOMAIN : null
        );
    }

    /**
     * Pick the sender address from the two settings (either may be null or blank).
     */
    public static function chooseSender(?string $mailFrom, ?string $primaryDomain): string {
        if ($mailFrom !== null && trim($mailFrom) !== '') {
            return trim($mailFrom);
        }
        return 'noreply@' . (($primaryDomain !== null && trim($primaryDomain) !== '') ? trim($primaryDomain) : 'localhost');
    }

    /**
     * Send email to admin (NOTIFY_EMAIL)
     *
     * Convenience method to send email to the site administrator using default
     * from and reply-to addresses.
     *
     * @param string $subject Email subject line
     * @param string $message Email body (can be plain text or HTML)
     * @return bool True if email was sent successfully, false otherwise
     */
    public static function sendEmailToAdmin($subject, $message) {
        if (!defined('NOTIFY_EMAIL')) {
            error_log("Mail::sendEmailToAdmin() called but NOTIFY_EMAIL is not defined");
            return false;
        }

        return self::sendEmailTo(NOTIFY_EMAIL, $subject, $message);
    }
}
