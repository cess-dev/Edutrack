<?php
/**
 * EduTrack — Email Service
 *
 * Wraps PHPMailer to send HTML emails via SMTP.
 *
 * Enabled/disabled by the SMTP_ENABLED constant (set from .env).
 * When disabled every send() call returns false — callers fall back to the
 * admin approval queue for password resets.
 *
 * Port logic:
 *   465  → PHPMailer::ENCRYPTION_SMTPS  (SSL)
 *   587  → PHPMailer::ENCRYPTION_STARTTLS  (STARTTLS, Gmail default)
 *   other→ no encryption
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

require_once ROOT_PATH . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

class EmailService
{
    /**
     * Returns true only when SMTP sending is configured and enabled.
     */
    public static function isEnabled(): bool
    {
        return defined('SMTP_ENABLED') && SMTP_ENABLED === true;
    }

    /**
     * Send an HTML email (with auto-generated plain-text fallback).
     *
     * @param  string $to      Recipient address
     * @param  string $subject Subject line
     * @param  string $html    HTML body
     * @param  string $text    Optional plain-text fallback (auto-stripped if omitted)
     * @return bool            true on success, false if disabled or send failed
     */
    public static function send(
        string $to,
        string $subject,
        string $html,
        string $text = ''
    ): bool {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            // ── Server ────────────────────────────────────────────────────────
            $mail->isSMTP();
            $mail->Host     = SMTP_HOST;
            $mail->Port     = SMTP_PORT;
            $mail->SMTPAuth = SMTP_AUTH;

            if (SMTP_AUTH) {
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
            }

            // Encryption based on port
            if (SMTP_PORT === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif (SMTP_PORT === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            // Log SMTP conversation to PHP error log (helps diagnose auth failures)
            $mail->SMTPDebug  = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $str, int $level): void {
                error_log('[EduTrack SMTP] ' . trim($str));
            };

            // ── Content ───────────────────────────────────────────────────────
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?: strip_tags(
                str_replace(['<br>', '<br/>', '<br />'], "\n", $html)
            );

            $mail->send();
            return true;

        } catch (MailerException $e) {
            error_log('[EmailService] Send failed to ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a login OTP code.
     *
     * @param  string $to    Recipient address
     * @param  string $name  Recipient's full name
     * @param  string $otp   6-digit code (plain text)
     * @return bool
     */
    public static function sendOtp(string $to, string $name, string $otp): bool
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'EduTrack';
        $subject = "[{$appName}] Your Login Code";

        $html = self::_layout($appName, "
          <p style='margin-top:0'>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
          <p>Use the code below to complete your sign-in. It expires in <strong>10 minutes</strong>.</p>
          <div style='text-align:center;margin:28px 0'>
            <div style='background:#fff;border:2px solid #0f4c3a;
                        border-radius:8px;display:inline-block;
                        padding:16px 32px'>
              <div style='font-size:11px;letter-spacing:2px;color:#888;
                          margin-bottom:6px;text-transform:uppercase'>
                Login Code
              </div>
              <div style='font-size:36px;font-weight:700;letter-spacing:8px;
                          color:#0f4c3a;font-family:monospace'>
                {$otp}
              </div>
            </div>
          </div>
          <p style='font-size:13px;color:#666'>
            If you did not attempt to log in, your account may be at risk.
            Change your password immediately.
          </p>
        ");

        return self::send($to, $subject, $html);
    }

    /**
     * Send a password-reset email with a one-time link.
     *
     * @param  string $to       Recipient address
     * @param  string $name     Recipient's full name
     * @param  string $token    Raw (un-hashed) reset token
     * @param  string $role     User role — appended to reset URL
     * @return bool
     */
    public static function sendPasswordReset(
        string $to,
        string $name,
        string $token,
        string $role = 'student'
    ): bool {
        $appName   = defined('APP_NAME') ? APP_NAME : 'EduTrack';
        $baseUrl   = defined('BASE_URL') ? BASE_URL : '';
        $hours     = defined('PASSWORD_RESET_TOKEN_HOURS') ? PASSWORD_RESET_TOKEN_HOURS : 24;
        $resetLink = "{$baseUrl}/auth/reset-password?token=" . urlencode($token)
                   . '&role=' . urlencode($role);

        $subject = "[{$appName}] Password Reset Request";

        $html = self::_layout($appName, "
          <p style='margin-top:0'>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
          <p>We received a request to reset your password. Click the button below to choose a new one.</p>
          <div style='text-align:center;margin:32px 0'>
            <a href='" . htmlspecialchars($resetLink) . "'
               style='background:#0f4c3a;color:white;padding:12px 28px;
                      text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block'>
              Reset My Password
            </a>
          </div>
          <p style='font-size:13px;color:#666'>
            This link expires in <strong>{$hours} hour" . ($hours !== 1 ? 's' : '') . "</strong>.
            If you did not request a reset, you can safely ignore this email.
          </p>
          <p style='font-size:12px;color:#999;margin-bottom:0'>
            If the button doesn't work, copy and paste this URL:<br>
            <a href='" . htmlspecialchars($resetLink) . "' style='color:#0f4c3a;word-break:break-all'>
              " . htmlspecialchars($resetLink) . "
            </a>
          </p>
        ");

        return self::send($to, $subject, $html);
    }

    /**
     * Notify a user that an admin has approved their reset request.
     *
     * @param  string $to       Recipient address
     * @param  string $name     Full name
     * @param  string $tempPass Plain-text temporary password
     * @return bool
     */
    public static function sendPasswordResetApproved(
        string $to,
        string $name,
        string $tempPass
    ): bool {
        $appName = defined('APP_NAME') ? APP_NAME : 'EduTrack';
        $subject = "[{$appName}] Your Password Has Been Reset";

        $html = self::_layout($appName, "
          <p style='margin-top:0'>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
          <p>An administrator has approved your password reset request. Your temporary password is:</p>
          <div style='text-align:center;margin:24px 0'>
            <code style='background:#fff;border:2px solid #0f4c3a;padding:12px 24px;
                         font-size:18px;font-weight:bold;color:#0f4c3a;
                         border-radius:6px;display:inline-block;letter-spacing:2px'>
              " . htmlspecialchars($tempPass) . "
            </code>
          </div>
          <p style='font-size:13px;color:#666'>
            Please log in and change this password immediately. You will be prompted to do so on first sign-in.
          </p>
        ");

        return self::send($to, $subject, $html);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function _layout(string $appName, string $bodyContent): string
    {
        return "
<!DOCTYPE html>
<html>
<head><meta charset='utf-8'></head>
<body style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px'>
  <div style='background:#0f4c3a;padding:20px 24px;border-radius:8px 8px 0 0'>
    <h1 style='color:white;margin:0;font-size:20px'>" . htmlspecialchars($appName) . "</h1>
  </div>
  <div style='background:#f9f9f9;padding:24px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px'>
    {$bodyContent}
  </div>
  <p style='font-size:11px;color:#bbb;text-align:center;margin-top:16px'>
    &copy; " . date('Y') . " " . htmlspecialchars($appName) . ". This is an automated message — please do not reply.
  </p>
</body>
</html>";
    }
}
