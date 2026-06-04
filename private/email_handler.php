<?php
/**
 * Email handler using PHPMailer with Resend SMTP
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendEmail($to, $subject, $body, $replyTo = null, array $options = []) {
    try {
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = intval($_ENV['SMTP_PORT'] ?? 587);

        // Use verified sending domain from Mandrill
        // MANDRILL_FROM_EMAIL should be set to a verified domain email in Mandrill
        // This is required to avoid "unsigned" rejections
        $fromEmail = $_ENV['MANDRILL_FROM_EMAIL'] ?? $_ENV['FROM_EMAIL'] ?? null;

        if (empty($fromEmail)) {
            // Fallback: try to construct from verified domain if MANDRILL_VERIFIED_DOMAIN is set
            $verifiedDomain = $_ENV['MANDRILL_VERIFIED_DOMAIN'] ?? null;
            if ($verifiedDomain) {
                $fromEmail = 'noreply@' . $verifiedDomain;
            } else {
                // Last resort: use the default (may cause unsigned rejection if domain not verified)
                $fromEmail = $_ENV['RSVP_EMAIL'] ?? 'noreply@wedding.stephens.page';
                error_log("Warning: Using unverified FROM email address. Set MANDRILL_FROM_EMAIL or MANDRILL_VERIFIED_DOMAIN in .env to use a verified domain.");
            }
        }

        $fromName = $options['fromName'] ?? ($_ENV['SMTP_FROM_NAME'] ?? 'Jacob and Melissa');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }

        // Content
        $mail->CharSet = 'UTF-8';
        $isHtml = !empty($options['isHtml']);
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body = $body;
        if ($isHtml && !empty($options['altBody'])) {
            $mail->AltBody = $options['altBody'];
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log both the exception message and PHPMailer error info if available
        $errorMsg = $e->getMessage();
        if (isset($mail) && !empty($mail->ErrorInfo)) {
            $errorMsg .= " | PHPMailer Error: " . $mail->ErrorInfo;
        }
        error_log("Email sending failed: " . $errorMsg);
        return false;
    }
}

