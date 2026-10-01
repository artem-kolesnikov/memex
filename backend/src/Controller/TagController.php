<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tag;
use App\Service\SystemTags;
use App\Service\TagAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The vault's tag vocabulary, and the two ways to change its shape.
 *
 * Reading is open to any authenticated caller — an assistant choosing words
 * should see the vocabulary before inventing one. **Changing it is
 * session-only**, and that is not a formality. Removing a tag rewrites every
 * note carrying it, outside the review gate and with no limbo to restore
 * from: it is the one bulk edit in memex that cannot be undone. A review-gated
 * agent token, whose every ordinary write waits for a person to read it, must
 * not be able to reach past that gate by editing the vocabulary instead. The
 * same reasoning closed import to bearer tokens (audit L-1).
 *
 * Two names are refused outright, to anyone: memex reads `skill` and
 * `live-state` itself, so removing either changes what assistants are served
 * rather than only what the vocabulary looks like. See {@see SystemTags} for
 * the rule and its limits — membership is not locked, only the word.
 */
class TagController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TagAdmin $tags,
    ) {
    }

    #[Route('/api/tags', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $rows = $this->em->getConnection()->executeQuery(
            'SELECT t.id, t.name, COUNT(nt.note_id) AS note_count
             FROM tags t LEFT JOIN note_tag nt ON nt.tag_id = t.id
             GROUP BY t.id, t.name ORDER BY '.SystemTags::sqlRank('t.name').', t.name'
        )->fetchAllAssociative();

        return $this->json([
            'tags' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'note_count' => (int) $r['note_count'],
                // Whether memex itself reads this word, and what breaks
                // without it. Served per row rather than left to the client to
                // match against a list, so the tag screen can never disagree
                // with the endpoint that enforces it.
                'system' => SystemTags::isSystem($r['name']),
                'system_reason' => SystemTags::reason($r['name']),
            ], $rows),
            // Names the owner took OUT of the vocabulary. Served beside it
            // because they are part of what the vocabulary means: a word that
            // is absent because nobody thought of it and a word that is absent
            // because it was rejected are different facts, and only one of
            // them should stop an assistant reaching for it.
            'retired' => $this->tags->retired(),
        ]);
    }

    /**
     * Take a tag off every note that carries it, or move those notes onto
     * another tag.
     *
     * One endpoint for both, because they are one decision made in one
     * dialog: the question is "what happens to the notes", and "nothing keeps
     * this word" and "they get that word instead" are its two answers.
     */
    #[Route('/api/tags/{id}', methods: ['DELETE'])]
    public function remove(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Changing the tag vocabulary');

        $tag = $this->em->getRepository(Tag::class)->find($id);
        if ($tag === null) {
            throw $this->createNotFoundException('No such tag');
        }

        // Refused before either branch, because both retire the tag in the
        // path. 409 rather than 403: nothing is wrong with who is asking — the
        // request conflicts with what this tag is.
        if (SystemTags::isSystem($tag->getName())) {
            return $this->json(
                $this->json400((string) SystemTags::reason($tag->getName()))
                    + ['system_tag' => $tag->getName()],
                Response::HTTP_CONFLICT,
            );
        }

        $intoId = $request->query->getInt('merge_into');
        if ($intoId === 0) {
            $name = $tag->getName();

            return $this->json([
                'removed' => $name,
                'notes_changed' => $this->tags->remove($tag, $this->currentAccount()->getName()),
            ]);
        }

        $into = $this->em->getRepository(Tag::class)->find($intoId);
        if ($into === null) {
            // Not a 404: the tag in the path exists, and answering "not found"
            // about the one that does would send the reader to the wrong half.
            return $this->json($this->json400('No such tag to merge into'), Response::HTTP_BAD_REQUEST);
        }
        if ($into->getId() === $tag->getId()) {
            return $this->json($this->json400('A tag cannot be merged into itself'), Response::HTTP_BAD_REQUEST);
        }

        $names = ['removed' => $tag->getName(), 'merged_into' => $into->getName()];

        return $this->json($names + ['notes_changed' => $this->tags->merge($tag, $into, $this->currentAccount()->getName())]);
    }

    /**
     * Drop a name from the retired list, so memex may suggest it again.
     *
     * The name is in the path rather than an id because a retired tag has none
     * — retiring deletes the row and records the WORD, which is the whole point
     * of the list.
     */
    #[Route('/api/tags/retired/{name}', methods: ['DELETE'], requirements: ['name' => '.+'])]
    public function restore(string $name, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Changing the tag vocabulary');

        $wanted = trim($name);
        if ($wanted === '') {
            return $this->json($this->json400('No tag named'), Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($wanted, $this->tags->retiredNames(), true)) {
            throw $this->createNotFoundException('That tag is not on the removed list');
        }

        $this->tags->unretire($wanted);

        return $this->json(['restored' => $wanted]);
    }
}
