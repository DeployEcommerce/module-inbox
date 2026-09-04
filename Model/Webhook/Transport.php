<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use DeployEcommerce\Inbox\Model\Redactor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

/**
 * Performs the outbound HTTP request.
 *
 * Guzzle is used directly rather than one of Magento's HTTP wrappers. Magento's Curl
 * client exposes a single timeout and no connect timeout; its Laminas client offers no way
 * to reach curl's CURLOPT_RESOLVE, and without that there is no DNS pinning and therefore
 * no defence against rebinding between validation and connection. Guzzle is a hard
 * dependency of magento/framework, so this adds nothing to the dependency tree, and it is
 * already the house style for outbound calls in this codebase.
 */
class Transport
{
    public function __construct(
        private readonly WebhookConfig $config,
        private readonly Signer $signer,
        private readonly Redactor $redactor
    ) {
    }

    public function send(PinnedTarget $target, string $rawBody, string $deliveryUuid, int $attempt): DeliveryResult
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'DeployEcommerce-Inbox/1.0',
        ] + $this->signer->headers($rawBody, $this->config->getSigningSecret(), $deliveryUuid, $attempt);

        $authHeaderName = $this->config->getAuthHeaderName();

        if ($authHeaderName !== '' && $this->config->getAuthHeaderValue() !== '') {
            $headers[$authHeaderName] = $this->config->getAuthHeaderValue();
        }

        try {
            $response = $this->client()->post($target->getUrl(), [
                RequestOptions::HEADERS => $headers,
                RequestOptions::BODY => $rawBody,
                RequestOptions::CONNECT_TIMEOUT => $this->config->getConnectTimeout(),
                RequestOptions::TIMEOUT => $this->config->getTimeout(),
                // A redirect is a delivery failure, not a hop. Following one would leave the
                // vetted address behind and hand an attacker the SSRF the guard just closed.
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::VERIFY => true,
                'curl' => [
                    // Pin the hostname to the address the guard vetted, so curl never
                    // performs its own lookup. This is the DNS rebinding defence.
                    CURLOPT_RESOLVE => [$target->getResolveEntry()],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                ],
            ]);

            $status = $response->getStatusCode();

            // Read only enough of the response to explain a failure. Receivers echo request
            // content back in error bodies, so this is redacted before it is stored.
            $excerpt = $status >= 200 && $status < 300
                ? ''
                : $this->excerpt((string)$response->getBody());

            return new DeliveryResult($status, $excerpt);
        } catch (ConnectException $e) {
            return new DeliveryResult(null, $this->excerpt($e->getMessage()), true);
        } catch (RequestException $e) {
            return new DeliveryResult(
                $e->getResponse()?->getStatusCode(),
                $this->excerpt($e->getMessage()),
                true
            );
        } catch (\Throwable $e) {
            return new DeliveryResult(null, $this->excerpt($e->getMessage()), true);
        }
    }

    private function client(): Client
    {
        return new Client();
    }

    /**
     * Redact, strip newlines and cap. Never store or log a raw response: it may contain the
     * request we just sent, which may contain personal data.
     */
    private function excerpt(string $text): string
    {
        $text = (string)$this->redactor->redact($text);
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return mb_substr(trim($text), 0, 255);
    }
}
