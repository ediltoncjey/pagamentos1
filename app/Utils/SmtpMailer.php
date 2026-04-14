<?php

declare(strict_types=1);

namespace App\Utils;

use RuntimeException;

final class SmtpMailer
{
    /**
     * @param array<string, mixed> $config
     * @return array{success:bool,provider_message:?string,error:?string}
     */
    public function send(array $config, string $toEmail, string $subject, string $htmlBody): array
    {
        $transport = strtolower(trim((string) ($config['transport'] ?? 'smtp')));
        if ($transport === 'mail') {
            return $this->sendWithPhpMail($config, $toEmail, $subject, $htmlBody);
        }

        return $this->sendWithSmtp($config, $toEmail, $subject, $htmlBody);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success:bool,provider_message:?string,error:?string}
     */
    private function sendWithPhpMail(array $config, string $toEmail, string $subject, string $htmlBody): array
    {
        $fromAddress = trim((string) ($config['from_address'] ?? ''));
        $fromName = trim((string) ($config['from_name'] ?? 'SISTEM_PAY'));
        if ($fromAddress === '') {
            return [
                'success' => false,
                'provider_message' => null,
                'error' => 'EMAIL_FROM_ADDRESS nao definido.',
            ];
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->headerMailbox($fromAddress, $fromName),
        ];

        $ok = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
        if (!$ok) {
            return [
                'success' => false,
                'provider_message' => null,
                'error' => 'Falha no envio via mail().',
            ];
        }

        return [
            'success' => true,
            'provider_message' => 'mail() accepted',
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success:bool,provider_message:?string,error:?string}
     */
    private function sendWithSmtp(array $config, string $toEmail, string $subject, string $htmlBody): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 587);
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        $fromAddress = trim((string) ($config['from_address'] ?? ''));
        $fromName = trim((string) ($config['from_name'] ?? 'SISTEM_PAY'));
        $timeout = max(5, (int) ($config['timeout_seconds'] ?? 20));
        $allowInsecure = (bool) ($config['allow_insecure'] ?? false);

        if ($host === '' || $fromAddress === '') {
            return [
                'success' => false,
                'provider_message' => null,
                'error' => 'Configuracao SMTP incompleta.',
            ];
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $contextOptions = [
            'ssl' => [
                'verify_peer' => !$allowInsecure,
                'verify_peer_name' => !$allowInsecure,
                'allow_self_signed' => $allowInsecure,
            ],
        ];
        $context = stream_context_create($contextOptions);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            return [
                'success' => false,
                'provider_message' => null,
                'error' => 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')',
            ];
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO localhost', [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Falha ao ativar STARTTLS.');
                }
                $this->command($socket, 'EHLO localhost', [250]);
            }

            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . $this->headerMailbox($fromAddress, $fromName),
                'To: <' . $toEmail . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
            ];

            $body = quoted_printable_encode($htmlBody);
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
            fwrite($socket, $message . "\r\n");
            $last = $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);

            return [
                'success' => true,
                'provider_message' => trim($last),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'provider_message' => null,
                'error' => $exception->getMessage(),
            ];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * @param list<int> $allowedCodes
     */
    private function command($socket, string $command, array $allowedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $allowedCodes);
    }

    /**
     * @param list<int> $allowedCodes
     */
    private function expect($socket, array $allowedCodes): string
    {
        $response = '';
        while (is_resource($socket) && !feof($socket)) {
            $line = fgets($socket, 515);
            if (!is_string($line)) {
                break;
            }

            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $allowedCodes, true)) {
            throw new RuntimeException('SMTP unexpected response: ' . trim($response));
        }

        return $response;
    }

    private function headerMailbox(string $email, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '<' . $email . '>';
        }

        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
