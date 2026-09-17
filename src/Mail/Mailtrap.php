<?php

declare(strict_types=1);

namespace TripBuilder\Mail;

use RuntimeException;
use TripBuilder\Env;
use TripBuilder\EnvKey;
use TripBuilder\Settings;

/**
 * The one Mailtrap call this project makes: send a single transactional
 * email (C6, #155).
 *
 * Deliberately not a client for Mailtrap. Templates, attachments, batch
 * sending and the bulk/marketing stream are all absent because nothing here
 * needs them -- the same reasoning `src/Aws/S3.php` gives for not being a
 * client for S3. If two of those ever arrive, this is the file to delete in
 * favour of `mailtrap/mailtrap-php`.
 */
final readonly class Mailtrap
{
    private const string ENDPOINT = 'https://send.api.mailtrap.io/api/send';
    private const int TIMEOUT_SECONDS = 30;
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Mailtrap's own edge protection blocks a request carrying no
     * `User-Agent`, the way a browser or `curl` always would -- documented,
     * not guessed, on the endpoint's own reference page.
     */
    private const string USER_AGENT = 'fh-trip-builder';

    public function __construct(
        private string $apiToken,
        private string $fromEmail,
        private string $fromName,
    ) {}

    /**
     * Built from the environment, which is where a write credential belongs.
     *
     * Missing keys are an error and not an empty string, the same reasoning
     * `S3::fromEnvironment()` gives: a caller that forgot to set them should
     * see why nothing sent, not a silent no-op.
     */
    public static function fromEnvironment(): self
    {
        $missing = array_values(array_filter(
            [EnvKey::MailtrapApiToken, EnvKey::MailtrapFromEmail],
            static fn(EnvKey $key): bool => Env::get($key) === '',
        ));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Not set in the environment: %s.',
                implode(', ', array_map(static fn(EnvKey $key): string => $key->value, $missing)),
            ));
        }

        return new self(
            Env::get(EnvKey::MailtrapApiToken),
            Env::get(EnvKey::MailtrapFromEmail),
            (string) Settings::get('app.name'),
        );
    }

    /**
     * Send one plain-text email. Throws on anything other than Mailtrap
     * accepting it, so a caller's own try/catch decides what a failed send
     * means to it -- `alerts:check` logs and moves to the next watch rather
     * than losing every remaining one to a single bad address.
     */
    public function send(string $toEmail, string $subject, string $text): void
    {
        $payload = json_encode([
            'from' => ['email' => $this->fromEmail, 'name' => $this->fromName],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'text' => $text,
        ]);

        // Only false on a value this array literal cannot produce -- an
        // unencodable string, a resource, a cycle. Guarded so curl is never
        // handed the false a failed encode would otherwise pass through as
        // "no body".
        if ($payload === false) {
            throw new RuntimeException('Could not encode the request to Mailtrap.');
        }

        $handle = curl_init(self::ENDPOINT);

        if ($handle === false) {
            throw new RuntimeException('Could not start a request to Mailtrap.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Api-Token: ' . $this->apiToken,
                'Content-Type: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ],
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($error !== '') {
            throw new RuntimeException('The request to Mailtrap failed: ' . $error);
        }

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                'Mailtrap answered %d sending to %s%s',
                $status,
                $toEmail,
                self::explain(is_string($body) ? $body : ''),
            ));
        }
    }

    /** Mailtrap puts the useful half of a refusal in `errors`, not in the status. */
    private static function explain(string $body): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return '.';
        }

        return ' (' . implode('; ', array_map(strval(...), $decoded['errors'])) . ').';
    }
}
