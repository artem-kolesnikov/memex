<?php

declare(strict_types=1);

namespace App\Controller;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Security\ApiTokenAuthenticator;
use App\Service\NoteJson;
use App\Service\Owner;
use App\Service\OwnerMark;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class ApiController extends AbstractController
{
    /**
     * Generous, but bounded: this is prose an operator types at review time,
     * not a document. The bound exists so an accident cannot write megabytes
     * into a log the curator re-reads every run.
     */
    protected const OPERATOR_COMMENT_MAX = 5000;

    public static function getSubscribedServices(): array
    {
        return [...parent::getSubscribedServices(), Owner::class];
    }

    protected function currentAccount(): Account
    {
        $account = $this->getUser();
        if (!$account instanceof Account) {
            throw new AccessDeniedHttpException('Authentication required');
        }

        return $account;
    }

    /** How the vault's owner appears beside a write. */
    protected function ownerMark(): OwnerMark
    {
        return $this->container->get(Owner::class)->mark();
    }

    protected function requestToken(Request $request): ?ApiToken
    {
        $token = $request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTRIBUTE);

        return $token instanceof ApiToken ? $token : null;
    }

    /**
     * Refuse a bearer token on a route only a person may reach.
     *
     * The rule every account-shaped endpoint follows: the name, the sign-in
     * methods, Settings, tokens, stats and deleting the account. Every assistant a person connects holds a token
     * that authenticates AS them and carries their roles, so without this,
     * "connected to my knowledge base" would quietly include "may change how I
     * sign in" and "may destroy my knowledge base".
     *
     * It lived as four private copies before 2026-08-21; a fifth was the point
     * at which they would start to disagree.
     */
    protected function assertSessionAuth(Request $request, string $subject): void
    {
        if ($this->requestToken($request) !== null) {
            throw $this->createAccessDeniedException($subject.' can only be reached from a browser session');
        }
    }

    /**
     * Refuse an AGENT token on a route that shows curation history.
     *
     * The rule MCP has always enforced, arriving on the REST side (codex H-5,
     * fixed 2026-08-22). `log_recent`, `last_curated`, `curation_candidates`,
     * `duplicate_candidates` and `blast_radius` all call
     * `McpServer::requireCurator()`; `/api/curator-log` serves the same
     * rows and asked only for `ROLE_USER`, so any connected assistant could
     * read the whole record of what every other assistant had proposed, had
     * approved and had rejected — with the operator's reasoning attached,
     * since a verdict comment goes into that log.
     *
     * A session passes: it is the screen this data was built for. A curator
     * token passes: curation history is that role's working material, which is
     * why MCP grants it. An agent token does not.
     */
    protected function assertSessionOrCurator(Request $request, string $subject): void
    {
        $token = $this->requestToken($request);
        if ($token !== null && !$token->isCurator()) {
            throw $this->createAccessDeniedException($subject.' is curator-role work — this token has the agent role');
        }
    }

    /**
     * A note by its public number, which is its id in this vault. Another
     * vault's notes are not in this file, so there is nothing else to check.
     */
    protected function noteByNumber(EntityManagerInterface $em, int $number): Note
    {
        return $em->find(Note::class, $number) ?? throw new NotFoundHttpException('Note not found');
    }

    /**
     * The id behind a public number, for the tables that outlive the note —
     * `note_revisions` above all, which has no foreign key so a deleted note's
     * history survives limbo. Resolving through {@see noteByNumber()} would
     * 404 exactly there.
     */
    protected function noteRowIdByNumber(EntityManagerInterface $em, int $number): int
    {
        $id = $em->getConnection()->fetchOne(
            'SELECT id FROM notes WHERE id = :id UNION ALL SELECT id FROM deleted_notes WHERE id = :id LIMIT 1',
            ['id' => $number]
        );
        if ($id === false) {
            throw new NotFoundHttpException('Note not found');
        }

        return (int) $id;
    }

    /** @return array<string, mixed> */
    protected function noteToArray(Note $note, bool $includeBody = false): array
    {
        return NoteJson::note($note, $this->ownerMark(), $includeBody);
    }

    /**
     * The keeper's own state, for a merge reviewed by what SURVIVES it.
     *
     * Its tags and description travel because the card shows the document the
     * merge LEAVES BEHIND, and that document is the keeper's — the absorbed
     * note's fields describe what is being destroyed, not the result.
     *
     * @return array{body_md: string, tags: string[], summary: ?string}
     */
    protected function keeperToArray(Note $keeper): array
    {
        return [
            'body_md' => $keeper->getBodyMd(),
            'tags' => array_map(static fn ($tag) => $tag->getName(), $keeper->getTags()->toArray()),
            'summary' => $keeper->getSummary(),
        ];
    }

    /** @return array<string, mixed> */
    protected function proposalToArray(EditProposal $proposal): array
    {
        return NoteJson::proposal($proposal);
    }

    /**
     * The operator's reasoning on an approve/reject, read from the request
     * body. Optional by construction: every client that predates this sends no
     * body at all, and an empty body must keep behaving exactly as it did.
     *
     * A body that IS present but does not parse is a 400 rather than a silent
     * default — the whole point of the field is that the operator's words reach
     * the curator, so dropping them quietly is the one failure this must not
     * have.
     *
     * @return array{comment: ?string, precedent: bool}
     */
    protected function operatorVerdict(Request $request): array
    {
        $none = ['comment' => null, 'precedent' => false];
        if (trim($request->getContent()) === '') {
            return $none;
        }
        try {
            $data = $request->toArray();
        } catch (JsonException $e) {
            // Symfony's own JsonException, which extends UnexpectedValueException
            // and NOT \JsonException — catching the SPL one compiles, reads
            // correctly, and never fires. (HttpKernel would map this to a 400
            // by itself via RequestExceptionInterface; the catch is here so the
            // behaviour is stated where it happens rather than inherited.)
            throw new BadRequestHttpException('Request body is not valid JSON: '.$e->getMessage());
        }

        $comment = isset($data['comment']) && is_string($data['comment']) ? trim($data['comment']) : '';
        if (mb_strlen($comment) > self::OPERATOR_COMMENT_MAX) {
            throw new BadRequestHttpException('comment must be at most '.self::OPERATOR_COMMENT_MAX.' characters');
        }

        return [
            'comment' => $comment === '' ? null : $comment,
            // Only meaningful alongside a comment: a precedent flag on its own
            // would mark reasoning that was never written down.
            'precedent' => $comment !== '' && ($data['is_precedent'] ?? false) === true,
        ];
    }

    /** @return array<string, mixed> */
    protected function json400(string $message): array
    {
        return ['error' => $message];
    }
}
