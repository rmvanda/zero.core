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

        // Send email, or write it to a file when MAIL_CAPTURE_DIR is set
        $file = null;
        $dir = self::captureDir();
        if ($dir !== null) {
            $success = self::writeCaptured($dir, $to, $subject, $headers, $message, $file);
        } else {
            $success = mail($to, $subject, $message, $headers);
        }

        // Log the email event
        $detail = json_encode([
            'to' => $to,
            'subject' => $subject,
            'from' => $from,
            'reply_to' => $replyTo,
            'success' => $success,
            'message_length' => strlen($message)
        ] + ($dir !== null ? ['captured' => true, 'sent' => false] : []), JSON_UNESCAPED_SLASHES);

        new Event([
            'event' => 'Mail Sent',
            'detail' => $detail
        ]);

        return $success;
    }

    /**
     * The folder mail is captured into, or null when capture is off. Capture
     * is on when the constant MAIL_CAPTURE_DIR names a folder (absolute, or
     * relative to ZERO_ROOT); undefined or blank, which is the default, sends.
     */
    private static function captureDir(): ?string {
        if (!defined('MAIL_CAPTURE_DIR') || trim((string) MAIL_CAPTURE_DIR) === '') {
            return null;
        }
        $dir = trim((string) MAIL_CAPTURE_DIR);
        if ($dir[0] !== '/' && defined('ZERO_ROOT')) {
            $dir = ZERO_ROOT . $dir;
        }
        return $dir;
    }

    /**
     * Write the message to a file in $dir instead of sending it. A site that
     * must not send mail (a test, a staging copy) turns capture on in
     * app/config/config.ini. The file holds the headers, a blank line and the
     * body, exactly what mail() would have been given, plus To and Subject.
     * A captured message holds live sign-in links, so the folder is created
     * 0700 and each file is 0600. Capturing is never silent: it is logged, and
     * the "Mail Sent" event records it as captured, not sent.
     *
     * An unusable folder fails closed: nothing is written, nothing is sent,
     * an error is logged and false comes back, with no PHP warning.
     *
     * @param string|null $file Set to the path written, or null when nothing was
     * @return bool True when written, false when the folder is unusable
     */
    public static function writeCaptured(string $dir, string $to, string $subject, string $headers, string $message, ?string &$file = null): bool {
        $file = null;
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) || !@chmod($dir, 0700)) {
                if (!is_dir($dir)) {
                    \Zero\Core\Console::error("Mail capture folder $dir cannot be created; the message to $to was not captured and not sent");
                    return false;
                }
            }
        }
        $path = $dir . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $text = "To: $to\r\nSubject: $subject\r\n" . $headers . "\r\n" . $message;
        if (@file_put_contents($path, $text) === false) {
            \Zero\Core\Console::error("Mail capture file $path cannot be written; the message to $to was not captured and not sent");
            return false;
        }
        @chmod($path, 0600);
        $file = $path;
        \Zero\Core\Console::warn("Mail to $to captured in $path, not sent (MAIL_CAPTURE_DIR is set)");
        return true;
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
