<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\EmbeddingModel;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The only thing the test suite is allowed to answer an outbound HTTP call
 * with. Bound into MlClient by config/services_test.yaml, so a test cannot
 * reach OpenAI even by accident: MockHttpClient opens no socket, and an
 * unrecognised URL comes back 503 rather than falling through to the network.
 *
 * The responses are shaped like ml-processor's real ones, on purpose. Stubbing
 * MlClient itself would skip its status-code and payload handling, which is
 * where the "degrade the feature, never break the request" contract lives.
 *
 * Deterministic, never random: the embedding is a fixed ramp at the width of
 * the model the request names, so a test that asserts on a stored vector is
 * asserting on something stable.
 *
 * It also answers the three PROVIDER model endpoints, because key verification
 * calls OpenAI, Anthropic and Google directly from the backend. A key
 * containing `invalid` comes back 401 with that provider's own error shape, so
 * the "a rejected key is never stored" property can be tested without anyone
 * owning a real bad key.
 */
final class MockMlResponder
{
    /**
     * @var array<int, array{method: string, url: string, body: array<string, mixed>, raw_body: ?string, tx: int}>
     *
     * `tx` is the DBAL transaction nesting level at the moment of the call, and
     * it is what makes audit A-3b testable at all: anything above 0 means an
     * apply path is holding the vault's write lock while waiting on a
     * provider. Asserting on a call's timing is otherwise impossible from out
     * here.
     */
    public array $calls = [];

    /**
     * Make an endpoint behave as if ml-processor were broken.
     *
     * Keyed by URL fragment; the value is an HTTP status, or the string
     * `throw` for a transport failure (unreachable, timed out). Added
     * 2026-08-22 for the cluster B work, where the property under test is
     * exactly the one this suite could not previously express: what memex does
     * when the thing it calls is DOWN, as opposed to answering unhelpfully.
     * Every stub here answered 201 forever, so "an outage is indistinguishable
     * from empty data" (audit B-2) was as true of the tests as of the code.
     *
     * @var array<string, int|string>
     */
    public array $failWith = [];

    /**
     * The token `services_test.yaml` gives the backend; the config stub accepts
     * only this one. Any other value is answered 401, which is what the real
     * service does when the copies of the token drift apart.
     */
    public const ML_CONFIG_TOKEN = 'test-ml-config-token';

    /**
     * Whether ml-processor reports an OpenAI key on disk.
     *
     * An instance property, deliberately, like every other switch on this
     * class: the container is rebuilt per test so instance state starts clean,
     * whereas a static would persist across every test in the process and turn
     * one test's setup into another test's silent precondition.
     */
    public bool $openAiKeySet = true;

    /**
     * Words the model "invents" for a note — names that are not already in the
     * team's vocabulary.
     *
     * Empty by default, because most tests do not care and a suggestion
     * arriving unasked would be noise in them. Set it where the question IS
     * what happens to a suggestion: a name the owner retired must not come
     * back through this door.
     *
     * @var string[]
     */
    public array $suggestedNewTags = [];

    /**
     * Ids from the team's OWN vocabulary that the model "picks" for a note.
     *
     * Empty by default so no existing test acquires tags it never asked for.
     * Set it where the question is what happens to a pick: since 2026-08-23
     * these are APPLIED to the note rather than handed back, so this is the
     * only way to exercise the write.
     *
     * @var int[]
     */
    public array $suggestedTagIds = [];

    /**
     * Answer 201 with a vector of the wrong length. Not a failure mode of the
     * transport but of the CONTRACT — 1536 or the row cannot be stored — and
     * the only 2xx that MlClient treats as the service being broken.
     */
    public bool $shortVector = false;

    /** Answer 201 with an empty summary: an answer, not an outage. */
    public bool $emptySummary = false;

    /**
     * Give different content different vectors, for the tests that are about
     * DISTANCE rather than about storage.
     *
     * The default ramp is one fixed vector for every note on the box, which
     * makes everything a perfect duplicate of everything — exactly right for a
     * tenancy test (if the team filter were missing, the other team's note
     * would be the top match) and useless for a threshold test, where every
     * pair sits at distance 0 and no cutoff can ever be wrong.
     *
     * Set this to a closure taking the embedded text and returning 1536
     * floats. `MockMlResponder::spread()` builds a family of vectors at
     * chosen angles for that purpose.
     *
     * @var (\Closure(string): array<int, float>)|null
     */
    public ?\Closure $vectorFor = null;

    public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em)
    {
    }

    /** How many transactions deep the app is right now — see {@see $calls}. */
    public function transactionDepth(): int
    {
        return $this->em->getConnection()->getTransactionNestingLevel();
    }

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        // The body is recorded because who pays is now part of the contract: a
        // team with its own provider key must have that key on the request, and
        // a team without one must not have somebody else's.
        $body = is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'body' => is_array($body) ? $body : [],
            // The bytes actually sent, kept beside the decoded array because
            // decoding erases distinctions: `{}`, `[]` and `null` all arrive
            // here as an empty array (review, 2026-08-25).
            'raw_body' => is_string($options['body'] ?? null) ? $options['body'] : null,
            'tx' => $this->transactionDepth(),
        ];

        foreach ($this->failWith as $fragment => $how) {
            if (!str_contains($url, (string) $fragment)) {
                continue;
            }

            if ($how === 'throw') {
                return new MockResponse([new TransportException(sprintf("Failed to connect to %s port %d after 0 ms: Couldn't connect to server for \"%s\".", parse_url($url, PHP_URL_HOST), parse_url($url, PHP_URL_PORT) ?? 443, $url))]);
            }

            return new MockResponse('{"error":"forced by the test"}', ['http_code' => (int) $how]);
        }

        return match (true) {
            // --- The config endpoint Admin rotates the box's keys through ---
            str_contains($url, ':8201/api/v1/config') => self::serviceConfig($method, $options, self::ML_CONFIG_TOKEN, [
                'openapi_apikey_set' => $this->openAiKeySet,
                'openapi_apikey_hint' => $this->openAiKeySet ? 'BEEF' : null,
            ]),
            str_contains($url, '/api/v1/create-embeddings') => new MockResponse(
                json_encode(['embeddings' => is_array($body['contents'] ?? null)
                    // A batch: one vector per text, in order, exactly as the
                    // real endpoint answers `contents`.
                    ? array_map(fn (string $text): array => $this->embedding($text, $body), array_values($body['contents']))
                    : $this->embedding((string) ($body['content'] ?? ''), $body),
                    // The usage block ml-processor returns beside every vector
                    // it bought. `key` is observed rather than intended: the
                    // service says `caller` when the request carried a key and
                    // `box` when it fell back to this box's own, and the ledger
                    // reads only that. A local vector is bought from nobody.
                    'usage' => ($body['model'] ?? null) === EmbeddingModel::Local->value ? null : [
                        'provider' => 'openai',
                        'model' => $body['model'] ?? EmbeddingModel::OpenAi->value,
                        'input_tokens' => 7,
                        'output_tokens' => null,
                        'key' => isset($body['api_key']) ? 'caller' : 'box',
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]
            ),
            str_contains($url, '/api/v1/summarize') => new MockResponse(
                json_encode(['summary' => $this->emptySummary ? '' : 'A summary written by the ml-processor stub.'], JSON_THROW_ON_ERROR),
                ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]
            ),
            str_contains($url, '/api/v1/suggest-title') => new MockResponse(
                json_encode(['title' => 'A title written by the ml-processor stub'], JSON_THROW_ON_ERROR),
                ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]
            ),
            str_contains($url, '/api/v1/suggest-tags') => new MockResponse(
                json_encode(['tag_ids' => $this->suggestedTagIds, 'new_tags' => $this->suggestedNewTags], JSON_THROW_ON_ERROR),
                ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]
            ),
            // The box's OpenAI key is verified against the operation it will
            // actually perform, not the free model list: a key with
            // Models=read and Embeddings=none passes the list and then cannot
            // embed anything. So this answers like the real endpoint, including
            // the vector width, which the gateway checks.
            str_contains($url, 'api.openai.com/v1/embeddings') => self::openAiEmbedding($options),
            str_contains($url, 'api.openai.com/v1/models') => self::providerModels(
                $options,
                ['data' => [
                    ['id' => 'gpt-4o-mini'],
                    ['id' => 'gpt-4.1'],
                    ['id' => 'text-embedding-3-large'],
                    ['id' => 'gpt-4o-realtime-preview'],
                ]],
                ['error' => ['message' => 'Incorrect API key provided.']]
            ),
            str_contains($url, 'api.anthropic.com/v1/models') => self::providerModels(
                $options,
                ['data' => [['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5']]],
                ['error' => ['message' => 'invalid x-api-key']]
            ),
            str_contains($url, 'generativelanguage.googleapis.com') => self::providerModels(
                $options,
                ['models' => [
                    ['name' => 'models/gemini-2.0-flash', 'displayName' => 'Gemini 2.0 Flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/text-embedding-004', 'displayName' => 'Embedding 004', 'supportedGenerationMethods' => ['embedContent']],
                ]],
                ['error' => ['message' => 'API key not valid.']]
            ),
            // --- Social sign-in (Google + GitHub + Microsoft + Apple) ---
            // The identity is carried IN the authorization code, formatted
            // `subject~email~name` (and `~upn` for Microsoft), so a test states
            // who is signing in by choosing the code it sends to the callback.
            // Nothing here decides anything: the policy is in
            // App\Service\SocialAccounts, and a stub that made choices would be
            // testing itself.
            str_contains($url, 'login.microsoftonline.com') => self::microsoftToken($options),
            str_contains($url, 'appleid.apple.com/auth/token') => self::appleToken($options),
            str_contains($url, 'oauth2.googleapis.com/token'),
            str_contains($url, 'github.com/login/oauth/access_token') => self::socialToken($options),
            str_contains($url, 'openidconnect.googleapis.com/v1/userinfo') => self::googleUserinfo($options),
            str_contains($url, 'api.github.com/user/emails') => self::githubEmails($options),
            str_contains($url, 'api.github.com/user') => self::githubUser($options),
            default => new MockResponse('{"error":"no stub for this endpoint"}', ['http_code' => 503]),
        };
    }

    /**
     * A Node service's `/api/v1/config`, gated exactly as the real ones are.
     *
     * The gate is reproduced rather than assumed because it is half of what
     * `SystemKeys` has to get right: a missing or wrong `X-Config-Token` is
     * the difference between a pane that says "this box has no token" and one
     * that silently reports every key as absent.
     *
     * @param array<string, mixed> $options
     * @param string               $expected this service's OWN token, not a shared one
     * @param array<string, mixed> $state    what a GET reports about the key
     */
    private static function serviceConfig(string $method, array $options, string $expected, array $state): MockResponse
    {
        $sent = '';
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (stripos((string) $header, 'x-config-token:') === 0) {
                $sent = trim(substr((string) $header, strlen('x-config-token:')));
            }
        }
        if ($sent === '') {
            return new MockResponse('{"error":"Config API disabled"}', ['http_code' => 503]);
        }
        if ($sent !== $expected) {
            return new MockResponse('{"error":"Unauthorized"}', ['http_code' => 401]);
        }

        return new MockResponse(
            json_encode($method === 'PUT' ? [] : $state, JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
        );
    }

    /**
     * OpenAI's embeddings endpoint, answering the way a key's PERMISSIONS make
     * it answer.
     *
     * `invalid` in the key is a dead key (401), as everywhere else here.
     * `no-embeddings` is the case the free model list cannot see: a live key
     * that this endpoint refuses, which is what a project-scoped key with
     * Embeddings=none does. `short-vector` returns a working key whose vector
     * is the wrong width for this installation.
     *
     * @param array<string, mixed> $options
     */
    private static function openAiEmbedding(array $options): MockResponse
    {
        $key = '';
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (stripos((string) $header, 'authorization: bearer ') === 0) {
                $key = trim(substr((string) $header, strlen('authorization: bearer ')));
            }
        }

        if (str_contains($key, 'invalid')) {
            // A key containing `echoed` gets it QUOTED BACK IN FULL. OpenAI
            // redacts its own messages today (`sk-defin****...`), so a stub
            // that copied that behaviour could not exercise the redaction at
            // all — and the test for it passed against code with the redaction
            // deleted, which is how this was found (review round two,
            // 2026-08-25). Nothing obliges a provider or an intermediary to
            // keep redacting, and this is the case that matters.
            $message = str_contains($key, 'echoed')
                ? 'Rejected credential: '.$key
                : 'Incorrect API key provided.';

            return new MockResponse(
                json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR),
                ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']]
            );
        }
        if (str_contains($key, 'no-embeddings')) {
            return new MockResponse(
                json_encode(['error' => ['message' => 'You have insufficient permissions for this operation. Missing scopes: model.request.']], JSON_THROW_ON_ERROR),
                ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']]
            );
        }

        $width = str_contains($key, 'short-vector') ? 8 : 1536;

        return new MockResponse(
            json_encode(['data' => [['embedding' => array_fill(0, $width, 0.5)]]], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
        );
    }

    /**
     * A provider's model list, or its own 401 when the key says `invalid`.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $ok
     * @param array<string, mixed> $rejected
     */
    private static function providerModels(array $options, array $ok, array $rejected): MockResponse
    {
        $sentKey = implode(' ', (array) ($options['headers'] ?? []));
        $accepted = !str_contains($sentKey, 'invalid');

        return new MockResponse(
            json_encode($accepted ? $ok : $rejected, JSON_THROW_ON_ERROR),
            ['http_code' => $accepted ? 200 : 401, 'response_headers' => ['content-type' => 'application/json']]
        );
    }

    /**
     * The token endpoint. Hands back the authorization code as the access
     * token, so the identity a test chose survives into the userinfo call.
     * A code of `refuse` gets the shape a provider really answers with when a
     * redirect URI does not match, which is the commonest real failure.
     *
     * @param array<string, mixed> $options
     */
    private static function socialToken(array $options): MockResponse
    {
        $code = self::codeFrom($options);
        if ($code === 'refuse') {
            return new MockResponse(
                json_encode(['error' => 'redirect_uri_mismatch', 'error_description' => 'Bad Request'], JSON_THROW_ON_ERROR),
                ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']]
            );
        }

        return self::json(['access_token' => $code, 'token_type' => 'bearer']);
    }

    /**
     * Microsoft's token endpoint, which is the ONLY call its sign-in makes:
     * everything memex needs is in the id_token, so there is no userinfo stub
     * below because there is no userinfo request.
     *
     * The token is a real three-segment JWT with a real base64url payload and a
     * signature of the word `signature`, because the code under test decodes it
     * and does not check the signature — for the reason written in
     * App\Service\SocialIdentityGateway. A stub that returned claims as plain
     * JSON would skip the half of that method most likely to be wrong.
     *
     * @param array<string, mixed> $options
     */
    private static function microsoftToken(array $options): MockResponse
    {
        $code = self::codeFrom($options);
        if ($code === 'refuse') {
            return new MockResponse(
                json_encode([
                    'error' => 'invalid_client',
                    // The real text for an expired client secret, so a test
                    // asserting on the failure asserts on what a person sees.
                    'error_description' => 'AADSTS7000222: The provided client secret keys for app are expired.',
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']]
            );
        }

        $parts = explode('~', $code);
        $subject = $parts[0] ?? '';
        $email = ($parts[1] ?? '') === '' || ($parts[1] ?? '') === '-' ? null : $parts[1];
        $name = ($parts[2] ?? '') === '' ? null : $parts[2];
        $upn = ($parts[3] ?? '') === '' ? null : $parts[3];

        // `!` in front of the address is a test asking for the case that only
        // Microsoft has: an address an administrator typed into a directory,
        // which nobody has proved belongs to this person. Then the optional
        // claim is ABSENT rather than false, because absent is what a real
        // token carries when the app registration has not been configured for
        // it, and the two must be treated the same.
        $unverified = $email !== null && str_starts_with($email, '!');

        $claims = array_filter([
            // `wrong-audience` as the subject is a test asking for an id_token
            // minted for somebody else's app registration.
            'aud' => $subject === 'wrong-audience' ? 'another-companys-client' : 'test-microsoft-client',
            'tid' => $subject === 'personal' ? '9188040d-6c67-4c5b-b112-36a304b66dad' : 'test-tenant',
            'oid' => $subject,
            'sub' => 'pairwise-'.$subject,
            'name' => $name,
            'email' => $unverified ? mb_substr($email, 1) : $email,
            'preferred_username' => $upn,
            'xms_edov' => $unverified ? null : ($email === null ? null : true),
        ], static fn (mixed $v): bool => $v !== null);

        return self::json(['token_type' => 'Bearer', 'id_token' => self::jwt($claims)]);
    }

    /**
     * Apple's token endpoint, which like Microsoft's is the only call its
     * sign-in makes — Apple has no userinfo endpoint at all.
     *
     * The code is `subject~email`, with `!` in front of an address for the case
     * where Apple has not verified it. There is no name here on purpose: Apple
     * puts the name in a form field on the callback and never in the token, so
     * a stub that returned one would be testing something that cannot happen.
     *
     * **The client secret really is checked.** Apple is the only provider whose
     * secret this server signs, so the stub refuses anything that is not a
     * three-segment JWT naming the configured Services ID — a `client_secret`
     * that was accidentally left empty would otherwise pass every test here and
     * fail only against Apple.
     *
     * @param array<string, mixed> $options
     */
    private static function appleToken(array $options): MockResponse
    {
        parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $body);
        $secret = is_string($body['client_secret'] ?? null) ? $body['client_secret'] : '';
        $segments = explode('.', $secret);
        $claims = count($segments) === 3
            ? json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true)
            : null;
        if (!is_array($claims) || ($claims['sub'] ?? null) !== 'test-apple-client' || ($claims['aud'] ?? null) !== 'https://appleid.apple.com') {
            return new MockResponse(
                json_encode(['error' => 'invalid_client'], JSON_THROW_ON_ERROR),
                ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']]
            );
        }

        $code = self::codeFrom($options);
        if ($code === 'refuse') {
            return new MockResponse(
                json_encode(['error' => 'invalid_grant', 'error_description' => 'The code has expired or has been revoked.'], JSON_THROW_ON_ERROR),
                ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']]
            );
        }

        $parts = explode('~', $code);
        $subject = $parts[0] ?? '';
        $email = ($parts[1] ?? '') === '' || ($parts[1] ?? '') === '-' ? null : $parts[1];
        $unverified = $email !== null && str_starts_with($email, '!');

        $tokenClaims = array_filter([
            'iss' => 'https://appleid.apple.com',
            'aud' => $subject === 'wrong-audience' ? 'another-companys-client' : 'test-apple-client',
            'sub' => $subject,
            'email' => $unverified ? mb_substr($email, 1) : $email,
            // Apple sends its booleans as strings, which is the shape the code
            // under test has to cope with.
            'email_verified' => $email === null ? null : ($unverified ? 'false' : 'true'),
            'is_private_email' => $email !== null && str_contains($email, 'privaterelay.appleid.com') ? 'true' : null,
        ], static fn (mixed $v): bool => $v !== null);

        return self::json(['token_type' => 'Bearer', 'id_token' => self::jwt($tokenClaims)]);
    }

    /**
     * A JWT whose payload really is base64url-encoded JSON. Header and
     * signature are stand-ins: nothing under test reads either.
     *
     * @param array<string, mixed> $claims
     */
    private static function jwt(array $claims): string
    {
        $segment = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $segment('{"alg":"RS256","typ":"JWT"}')
            .'.'.$segment(json_encode($claims, JSON_THROW_ON_ERROR))
            .'.signature';
    }

    /** @param array<string, mixed> $options */
    private static function googleUserinfo(array $options): MockResponse
    {
        [$subject, $email, $name] = self::identityFrom($options);

        // A `!` in front of the address is a test asking for the case Google
        // reports an address it has NOT verified, which we must not trust.
        $unverified = $email !== null && str_starts_with($email, '!');

        return self::json([
            'sub' => $subject,
            'name' => $name,
            'email' => $unverified ? mb_substr($email, 1) : $email,
            'email_verified' => $email !== null && !$unverified,
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function githubUser(array $options): MockResponse
    {
        [$subject, $email, $name] = self::identityFrom($options);

        return self::json([
            'id' => (int) preg_replace('/\D/', '', $subject ?: '0'),
            'login' => $name,
            'name' => $name,
            // GitHub omits a private address from /user entirely. `private` in
            // place of the address is a test asking for that.
            'email' => $email === 'private' ? null : $email,
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function githubEmails(array $options): MockResponse
    {
        [, $email] = self::identityFrom($options);

        return self::json($email === 'private'
            ? [['email' => 'private-person@example.test', 'primary' => true, 'verified' => true]]
            : [['email' => $email ?? 'nobody@example.test', 'primary' => true, 'verified' => true]]);
    }

    /**
     * The authorization code out of a form-encoded token request, or out of the
     * bearer header on the userinfo request that follows it.
     *
     * @param array<string, mixed> $options
     */
    private static function codeFrom(array $options): string
    {
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        parse_str($body, $fields);
        if (is_string($fields['code'] ?? null) && $fields['code'] !== '') {
            return $fields['code'];
        }

        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (is_string($header) && stripos($header, 'authorization: bearer ') === 0) {
                return trim(mb_substr($header, 22));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private static function identityFrom(array $options): array
    {
        $parts = explode('~', self::codeFrom($options));
        $subject = $parts[0] ?? '';
        $email = ($parts[1] ?? '') === '' || ($parts[1] ?? '') === '-' ? null : $parts[1];
        $name = ($parts[2] ?? '') === '' ? null : $parts[2];

        return [$subject, $email, $name];
    }

    /** @param array<mixed> $payload */
    private static function json(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
        );
    }

    /** @return float[] at the width of the model, text-embedding-3-large's unless named */
    public static function vector(EmbeddingModel $model = EmbeddingModel::OpenAi): array
    {
        return array_map(static fn (int $i): float => ($i % 100) / 100, range(0, $model->dimensions() - 1));
    }

    /**
     * One answer to `create-embeddings`, at the width of the model the request
     * named. A `vectorFor` vector is cut or padded to it, which keeps its angles.
     *
     * @param array<string, mixed> $body
     *
     * @return float[]
     */
    private function embedding(string $text, array $body): array
    {
        $model = EmbeddingModel::tryFrom((string) ($body['model'] ?? '')) ?? EmbeddingModel::OpenAi;
        if ($this->shortVector) {
            return [0.1, 0.2];
        }
        if ($this->vectorFor === null) {
            return self::vector($model);
        }

        return array_pad(array_slice(($this->vectorFor)($text), 0, $model->dimensions()), $model->dimensions(), 0.0);
    }

    /**
     * A unit vector at a chosen cosine DISTANCE from `spread(0.0)`.
     *
     * Two dimensions carry the whole signal: `[cos t, sin t, 0, 0, …]`, so the
     * cosine distance between `spread(0)` and `spread(d)` is exactly `d`. That
     * makes a threshold test say what it means — `spread(0.30)` is past a 0.20
     * cutoff by construction, not by a number somebody tuned until the test
     * went green.
     *
     * @return float[]
     */
    public static function spread(float $distance): array
    {
        $angle = acos(max(-1.0, min(1.0, 1.0 - $distance)));
        $v = array_fill(0, 1536, 0.0);
        $v[0] = cos($angle);
        $v[1] = sin($angle);

        return $v;
    }

    /**
     * The payload of the last call to an endpoint, for asserting on what
     * travelled with it (the provider key, the model).
     *
     * @return array<string, mixed>|null null = that endpoint was never called
     */
    public function lastBody(string $endpointFragment): ?array
    {
        $matching = array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => str_contains($c['url'], $endpointFragment)
        ));

        return $matching === [] ? null : end($matching)['body'];
    }

    /** How many times anything reached for the network during this test. */
    public function callCount(?string $endpointFragment = null): int
    {
        if ($endpointFragment === null) {
            return count($this->calls);
        }

        return count(array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], $endpointFragment)));
    }
}
