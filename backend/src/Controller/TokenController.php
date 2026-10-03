<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Service\AgentIcons;
use App\Service\BearerTokens;
use App\Service\ConnectionSecrets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API-token management (settings screen). Session-only: a bearer token must not
 * be able to mint or revoke tokens.
 *
 * **Who may grant the curator role — ruled 2026-08-22, and the code here was
 * the ruling.** PRODUCT.md and CLAUDE.md both said "role assignment is
 * operator-only", and `setRole()` has never checked `is_operator`; it checks a
 * browser session. The audit filed the disagreement (L-6) without saying which
 * side was wrong, and the answer is that the documents were.
 *
 * Every account on this box gets a vault of its own
 * (`SocialAccounts::createAccount()` creates one unconditionally), so the
 * person at the other end of that session is the owner of the notes in
 * question. The review gate protects a knowledge base from its owner's own
 * assistant — it is not a boundary between tenants, and the tenancy boundary is
 * the vault file the request is bound to. Making the box operator adjudicate
 * whether somebody else's assistant may write without review would put a
 * stranger's workflow in his inbox and give him no basis on which to answer.
 *
 * What stays true either way, and is the half that matters: **an agent may not
 * promote itself.** `assertSessionAuth()` is what enforces it — every connected
 * assistant holds a token that authenticates AS its owner, so without that line
 * a review-gated token could lift its own gate in one request.
 */
class TokenController extends ApiController
{
    /** What OAuthController names a token it mints, and the only use of the prefix. */
    private const OAUTH_PREFIX = 'oauth: ';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BearerTokens $tokens,
        private readonly ConnectionSecrets $secrets,
    ) {
    }

    #[Route('/api/tokens', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $tokens = $this->em->getRepository(ApiToken::class)->findBy([], ['id' => 'ASC']);

        // How a token came to exist, and what to call it on screen. Both are
        // derived from the name because that is where the information is: an
        // OAuth grant names the token after the client that asked
        // (OAuthController), and nothing else uses that prefix. Deriving it
        // once here keeps the frontend from parsing names of its own.
        $auth = static fn (string $name): string => str_starts_with($name, self::OAUTH_PREFIX) ? 'oauth' : 'manual';
        $label = static fn (string $name): string => str_starts_with($name, self::OAUTH_PREFIX)
            ? substr($name, strlen(self::OAUTH_PREFIX))
            : $name;

        return $this->json([
            'tokens' => array_map(fn (ApiToken $t) => [
                'id' => $t->getId(),
                'name' => $t->getName(),
                // What the client called itself, cleaned of the oauth prefix.
                // Still shown when a token has been renamed, because "this is
                // the one that registered as Claude" is what makes a rename
                // checkable.
                'label' => $label($t->getName()),
                'auth' => $auth($t->getName()),
                // Null unless the owner has renamed it. The screen shows
                // `display_name ?? label`, and having both lets it say which.
                'display_name' => $t->getDisplayName(),
                'description' => $t->getDescription(),
                'icon_key' => $t->getIconKey(),
                // Resolved server-side for every kind of mark — glyph, upload
                // or shipped logo — by the one producer, so this table and the
                // bylines cannot disagree about what a connection looks like.
                ...AgentIcons::markFor(
                    $t->getIconKey(),
                    $t->hasUploadedIcon() ? '/api/tokens/'.$t->getId().'/icon' : null
                ),
                'role' => $t->getRole(),
                'created_at' => $t->getCreatedAt()->format(DATE_ATOM),
                'last_used_at' => $t->getLastUsedAt()?->format(DATE_ATOM),
                'revoked' => $t->isRevoked(),
                // Readable, not merely stored: a vault restored without its
                // secret.env holds tokens nobody can decrypt, and those get
                // Make a new token in place of a copy that cannot work.
                'token_kept' => !$t->isRevoked() && $this->secrets->reveal($t) !== null,
            ], $tokens),
            // Shipped with the list so the picker needs no second request, and
            // so a mark removed from the catalogue disappears from the picker
            // and from every screen at the same moment.
            'icons' => AgentIcons::catalogue(),
            // The provider marks, as a second list rather than more entries in
            // the first: they are chosen from a tab of their own and they are
            // a pair of image URLs where a glyph is a class name.
            'logos' => AgentIcons::logos(),
        ]);
    }

    #[Route('/api/tokens', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $account = $this->currentAccount();
        $name = trim((string) ($request->toArray()['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->json($this->json400('name is required (max 120 chars)'), Response::HTTP_BAD_REQUEST);
        }

        [$token, $plaintext] = $this->tokens->issue($account, $name, keep: true);

        return $this->json([
            'id' => $token->getId(),
            'name' => $token->getName(),
            'token' => $plaintext,
        ], Response::HTTP_CREATED);
    }

    /** The connection's token, for the owner to copy again. */
    #[Route('/api/tokens/{id}/token', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function secret(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $token = $this->token($id);
        $plaintext = $token->isRevoked() ? null : $this->secrets->reveal($token);
        if ($plaintext === null) {
            return $this->json($this->json400('memex does not hold this token. Make a new one in Edit connection.'), Response::HTTP_CONFLICT);
        }

        $response = $this->json(['token' => $plaintext]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * A new token for a connection the owner made; the old one stops working.
     * An OAuth client's token is its client's to renew.
     */
    #[Route('/api/tokens/{id}/token', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reissue(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $token = $this->token($id);
        if ($token->isRevoked() || str_starts_with($token->getName(), self::OAUTH_PREFIX)) {
            return $this->json($this->json400('Only a live connection made in Settings gets a new token.'), Response::HTTP_CONFLICT);
        }

        $response = $this->json(['token' => $this->tokens->reissue($this->currentAccount(), $token)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Grant or revoke the curator role (Phase 2). Session-only, like all token
     * management: the role decides whether the review gate applies, so an
     * agent must never be able to set it.
     */
    #[Route('/api/tokens/{id}/role', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function setRole(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $token = $this->token($id);
        $role = (string) ($request->toArray()['role'] ?? '');
        try {
            $token->setRole($role);
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }
        $this->em->flush();

        return $this->json(['id' => $token->getId(), 'role' => $token->getRole()]);
    }

    /**
     * Rename a connection, describe it, give it a face.
     *
     * Everything here is the OWNER's annotation of a token, never the token's
     * own: `assertSessionAuth()` for the same reason the role endpoint has it —
     * a connected assistant authenticates as its owner, so without that line an
     * agent could rename itself to look like a different one, which is a worse
     * version of the problem this feature exists to solve.
     *
     * Fields are optional and absent means unchanged; an explicit null clears.
     * That distinction is the whole reason this reads `array_key_exists`
     * rather than `??`.
     */
    #[Route('/api/tokens/{id}', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $token = $this->token($id);
        $data = $request->toArray();

        if (array_key_exists('display_name', $data)) {
            $name = $data['display_name'];
            if ($name !== null && !is_string($name)) {
                return $this->json($this->json400('display_name must be a string or null'), Response::HTTP_BAD_REQUEST);
            }
            $token->setDisplayName($name);
        }
        if (array_key_exists('description', $data)) {
            $description = $data['description'];
            if ($description !== null && !is_string($description)) {
                return $this->json($this->json400('description must be a string or null'), Response::HTTP_BAD_REQUEST);
            }
            $token->setDescription($description);
        }
        if (array_key_exists('icon_key', $data)) {
            $key = $data['icon_key'];
            if ($key === ApiToken::ICON_UPLOAD) {
                // `upload` is the value this endpoint RETURNS, and so does GET
                // /api/tokens and the upload route. So the ordinary shape of a
                // client — read the identity, change the name, write the whole
                // thing back — sends it straight back here, and refusing it
                // meant Save failed on every connection with a custom image
                // (reported 2026-08-23; the dialog's own Save was doing exactly
                // this). An API that will not accept the value it just handed
                // out is the defect, not the client.
                //
                // The guard it replaces still stands, narrowed to what it was
                // actually for: a key claiming an upload that never happened
                // renders as a broken picture, so `upload` is accepted only
                // when the bytes are really there. There is nothing to assign
                // in that case — the upload route already set both halves.
                if (!$token->hasUploadedIcon()) {
                    return $this->json($this->json400('icon_key cannot be set to `upload` — upload an image first'), Response::HTTP_BAD_REQUEST);
                }
            } elseif ($key !== null && (!is_string($key) || !AgentIcons::isShipped($key))) {
                return $this->json($this->json400('icon_key must be one of the built-in icons, or null'), Response::HTTP_BAD_REQUEST);
            } else {
                $token->setBuiltinIcon($key);
            }
        }
        $this->em->flush();

        return $this->json([
            'id' => $token->getId(),
            'display_name' => $token->getDisplayName(),
            'description' => $token->getDescription(),
            'icon_key' => $token->getIconKey(),
            ...AgentIcons::markFor(
                $token->getIconKey(),
                $token->hasUploadedIcon() ? '/api/tokens/'.$token->getId().'/icon' : null
            ),
        ]);
    }

    /**
     * Upload an icon. Re-encoded before it is stored — see AgentIcons.
     *
     * Multipart rather than a base64 field: a 128 KB image inside a JSON body
     * is a third larger and has to be decoded before anything can be checked.
     */
    #[Route('/api/tokens/{id}/icon', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadIcon(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $token = $this->token($id);

        $file = $request->files->get('icon');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json($this->json400('No file arrived. Choose a PNG or JPEG.'), Response::HTTP_BAD_REQUEST);
        }
        try {
            $png = AgentIcons::normaliseUpload((string) file_get_contents($file->getPathname()));
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }
        $token->setUploadedIcon($png);
        $this->em->flush();

        return $this->json([
            'id' => $token->getId(),
            'icon_key' => $token->getIconKey(),
            ...AgentIcons::markFor($token->getIconKey(), '/api/tokens/'.$token->getId().'/icon'),
        ]);
    }

    /**
     * Serve the stored PNG.
     *
     * Session-scoped like the rest of this controller.
     *
     * `nosniff` and an immutable cache are both about the same thing: the bytes
     * are a PNG we wrote ourselves and they never change for a given upload,
     * because a new upload is a new write to the same row and the ETag moves
     * with it.
     */
    #[Route('/api/tokens/{id}/icon', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function icon(int $id, Request $request): Response
    {
        $this->assertSessionAuth($request, 'Token management');
        $token = $this->token($id);
        $png = $token->getIconPng();
        if ($png === null) {
            throw $this->createNotFoundException('This connection has no uploaded icon');
        }

        $response = new Response($png, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=60',
        ]);
        $response->setEtag(substr(hash('sha256', $png), 0, 16));
        $response->isNotModified($request);

        return $response;
    }

    private function token(int $id): ApiToken
    {
        return $this->em->find(ApiToken::class, $id) ?? throw $this->createNotFoundException('Token not found');
    }

    #[Route('/api/tokens/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function revoke(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Token management');
        $this->tokens->revoke($this->currentAccount(), $this->token($id));

        return $this->json(['revoked' => true]);
    }
}
