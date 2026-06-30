<?php

namespace App\Services;

use Exception;

class SmsService
{
    protected string $url;
    protected string $username;
    protected string $password;
    protected string $defaultSender;
    protected int $timeout;
    protected bool $verifySsl;

    public function __construct()
    {
        $this->url = config('services.mtarget.url', 'https://api-public-2.mtarget.fr/messages');
        $this->username = config('services.mtarget.username', 'bwantech');
        $this->password = config('services.mtarget.password', 'x7jyKG0IJRNH');
        $this->defaultSender = config('services.mtarget.sender', 'TOO AUTO');
        $this->timeout = (int) config('services.mtarget.timeout', 30);
        $this->verifySsl = (bool) config('services.mtarget.verify_ssl', false);
    }

    /**
     * Garde une méthode simple pour les anciens appels.
     *
     * @throws Exception
     */
    public function send(string $message, string $receiver, ?string $sender = null): string
    {
        return $this->sendSmsMtarget($message, $receiver, $sender);
    }

    /**
     * Envoie un SMS via l'API MTarget.
     *
     * @throws Exception
     */
    public function sendSmsMtarget(string $message, string $msisdn, ?string $sender = null): string
    {
        $msisdn = $this->formatMsisdn($msisdn);

        $postData = http_build_query([
            'username' => $this->username,
            'password' => $this->password,
            'msisdn' => $msisdn,
            'msg' => $message,
            'sender' => $sender ?: $this->defaultSender,
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new Exception('Erreur cURL : ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Erreur HTTP : ' . $httpCode . ' - Réponse : ' . $response);
        }

        return (string) $response;
    }

    protected function formatMsisdn(string $msisdn): string
    {
        $msisdn = trim($msisdn);

        if (strpos($msisdn, '+') !== 0) {
            return '+' . $msisdn;
        }

        return $msisdn;
    }
}
