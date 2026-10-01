<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Entity\VaultSettings;
use App\Service\AgentIcons;
use App\Service\ActorView;
use App\Service\OwnerMark;

/**
 * WHO wrote a note, as every screen shows it.
 *
 * Before 2026-08-23 the answer did not exist: `notes.last_actor` holds one of
 * five words — `human`, `agent`, `curator`, `scrape`, `upload` — and
 * `Note::actorFor()` collapses a token to its role and throws the token away.
 * So this is not "a column round-trips"; it is that the association is now
 * WRITTEN on the paths a real assistant uses, and read back through the token
 * rather than copied.
 *
 * The reading half is the part with a rule behind it. The operator ruled that
 * renaming a connection renames it everywhere (2026-08-23), and that only holds
 * if nothing about the assistant is copied onto the note — one place for the
 * name, so there is no second place for a rename to fail to reach.
 *
 * Extended 2026-08-23 (later), when the operator pointed out that the feature
 * answered half the question: an assistant got a name and a face and a PERSON
 * got nothing, so the column was blank for exactly the writes he makes himself.
 * The tests below the divider are the second half — a person is an author too,
 * one shape for both, and one producer for that shape so the note page and the
 * notes list cannot disagree about a name again.
 */
final class NoteAgentTest extends ApiTestCase
{
    /** @return array<string, mixed> the row for one note in the list payload */
    private function listedNote(int $id): array
    {
        $this->sessionRequest('GET', '/api/notes?per_page=100');
        self::assertSame(200, $this->httpStatus(), $this->body());
        foreach ($this->jsonResponse()['items'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('Note '.$id.' is not in the list');
    }

    private function tokenId(string $like): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT id FROM api_tokens WHERE name LIKE :n ORDER BY id LIMIT 1',
            ['n' => $like]
        );
    }

    public function testANoteAnAgentWroteCarriesThatAgentIntoTheList(): void
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Filed by an assistant',
            'body_md' => 'Something it believes.',
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $id = $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        $agent = $this->listedNote($id)['edited_by'];
        self::assertNotNull($agent, 'A note written over MCP must say which assistant wrote it');
        self::assertSame('assistant', $agent['kind']);
        self::assertStringContainsString('agent-', $agent['name']);
    }

    public function testRenamingTheConnectionRenamesItOnEveryNoteItWrote(): void
    {
        // The ruling, asserted: one place for the name, so a rename cannot fail
        // to reach a note. If the name were ever copied onto the note at write
        // time, this is the test that would fail.
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Filed before the rename',
            'body_md' => 'Body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$this->tokenId('agent-%'), [
            'display_name' => 'Claude · research',
            'icon_key' => 'builtin:brain',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $agent = $this->listedNote($id)['edited_by'];
        self::assertSame('Claude · research', $agent['name'], 'A note written BEFORE the rename must show the new name');
        self::assertSame('fa-solid fa-brain', $agent['icon']);
        self::assertNull($agent['icon_url'], 'and no upload URL when the mark is a built-in');
    }

    public function testAProviderLogoReachesTheBylineAsTwoUrls(): void
    {
        // The byline is the second consumer of a mark, and the one that has
        // already been wrong once by building its own answer. A logo has to
        // arrive here as the same two URLs the settings table gets — the light
        // one and the dark one — or an assistant wearing OpenAI's mark would
        // be an invisible black glyph for every reader in dark mode.
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Filed by something with a face',
            'body_md' => 'Body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$this->tokenId('agent-%'), ['icon_key' => 'logo:openai']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $agent = $this->listedNote($id)['edited_by'];
        self::assertNull($agent['initial'], 'An assistant was turned into a person initial');
        self::assertNull($agent['icon'], 'A logo is an image, never a glyph class');
        self::assertSame('/logos/openai-on-light.svg', $agent['icon_url']);
        self::assertSame('/logos/openai-on-dark.svg', $agent['icon_url_dark']);

        // The note page reads through the ORM path rather than the raw-SQL one.
        // Two producers for one shape is exactly the trap this feature already
        // fell into, so both are asserted.
        $this->sessionRequest('GET', '/api/notes/'.$id);
        $addedBy = $this->jsonResponse()['added_by'];
        self::assertSame('/logos/openai-on-light.svg', $addedBy['icon_url']);
        self::assertSame('/logos/openai-on-dark.svg', $addedBy['icon_url_dark']);
    }

    public function testAPersonsOwnEditClearsTheAssistantRatherThanLeavingItAttached(): void
    {
        // The direction that matters. A note an agent wrote in January and the
        // operator rewrote in August belongs to the operator — `last_actor`
        // already says so, and leaving the token attached would put an
        // assistant's face on somebody else's work.
        $this->request('POST', '/api/notes', $this->kb->a->curatorBearer, [
            'title' => 'Written by the curator',
            'body_md' => 'First body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        self::assertSame('assistant', $this->listedNote($id)['edited_by']['kind'], 'Precondition: the curator is recorded');

        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'The operator rewrote this.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $editedBy = $this->listedNote($id)['edited_by'];
        self::assertSame('person', $editedBy['kind'], "A person's edit must take the assistant's name off the note");
        self::assertStringNotContainsString('curator-', $editedBy['name']);
    }

    public function testANoteFromBeforeThisExistedSimplyHasNoAssistant(): void
    {
        // Not a defect: the association was never recorded, so there is nothing
        // to backfill and every existing note reports none. The operator asked
        // for missing rather than guessed.
        $note = $this->kb->a->note('Written by a person', 'Body.');
        $id = (int) $note->getId();
        // The assistant column cleared: this is what a row written before it
        // existed looks like, and the point of the test is that the list says
        // nothing rather than inventing somebody.
        $this->em->getConnection()->executeStatement(
            "UPDATE notes SET last_actor = 'agent', last_actor_token_id = NULL WHERE id = :id",
            ['id' => $id]
        );

        $this->loginAs($this->kb->a);
        $row = $this->listedNote($id);
        self::assertSame('agent', $row['last_actor'], 'The category is still there');
        self::assertNull($row['edited_by'], 'but there is nobody to name');
    }


    // ------------------------------------------------- a person is an author

    /** A real PNG, made the same way GD will read it. */
    private function png(int $w = 900, int $h = 600): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /**
     * Noise, which is what a photograph looks like to a compressor: no two
     * neighbouring pixels agree, so PNG cannot shrink it at all.
     */
    private function photograph(int $w = 900, int $h = 900): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7 + $y * 13) % 256, ($x * 29 + $y * 3) % 256, ($x * 17 + $y * 23) % 256));
            }
        }
        ob_start();
        imagejpeg($im, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** One flat colour, which is what makes a huge picture a small file. */
    private function flatPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** A JPEG carrying an EXIF orientation, written by hand into an APP1 segment. */
    private function orientedJpeg(int $w, int $h, int $orientation): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 30, 90, 160));
        ob_start();
        imagejpeg($im, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);

        // One little-endian TIFF header with a single Orientation entry.
        $ifd = pack('v', 1)
            .pack('vvVv', 0x0112, 3, 1, $orientation).pack('v', 0)
            .pack('V', 0);
        $tiff = "II".pack('v', 42).pack('V', 8).$ifd;
        $app1 = "Exif\0\0".$tiff;
        $segment = pack('n', 0xFFE1).pack('n', strlen($app1) + 2).$app1;

        // After SOI, before everything else.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function uploadFace(string $bytes, string $filename = 'me.png'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'face');
        file_put_contents($path, $bytes);
        $this->sessionUpload('POST', '/api/me/icon', 'icon', $path, $filename);
    }

    public function testThePersonsUploadedFaceReachesBothBylineProducers(): void
    {
        // Two producers for one shape is the trap this feature already fell
        // into: the notes list is raw SQL and the note page is the ORM path, so
        // a face chosen once has to arrive on both or the same edit shows two
        // different people.
        $note = $this->kb->a->note('Written by a person', 'Body.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Edited in the browser.']);
        self::assertNull(
            $this->listedNote($id)['edited_by']['icon_url'],
            'Precondition: nobody has uploaded a picture, so it is the silhouette'
        );

        $this->uploadFace($this->png());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $edited = $this->listedNote($id)['edited_by'];
        self::assertSame('/api/me/icon', $edited['icon_url'], 'The notes list still draws the silhouette');
        self::assertSame(
            'fa-solid fa-circle-user',
            $edited['icon'],
            'The fallback glyph travels with the picture, for a client that cannot load it'
        );

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(
            '/api/me/icon',
            $this->jsonResponse()['added_by']['icon_url'],
            'The note page still draws the silhouette'
        );
    }

    /** The bytes come back, re-encoded, and only to the person they belong to. */
    public function testTheUploadedFaceIsServedAsAJpegAndBoundedInSize(): void
    {
        $this->loginAs($this->kb->a);
        $this->uploadFace($this->png(2000, 1200));
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/me/icon');
        self::assertSame(200, $this->httpStatus());
        $bytes = $this->client->getResponse()->getContent();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));

        $size = getimagesizefromstring((string) $bytes);
        self::assertNotFalse($size, 'What came back is not an image');
        self::assertSame(IMAGETYPE_JPEG, $size[2], 'The re-encode was skipped, or stored a PNG');
        self::assertLessThanOrEqual(
            VaultSettings::ICON_SIZE,
            max($size[0], $size[1]),
            'A 2000px upload was stored at its own size'
        );
        self::assertLessThanOrEqual(
            VaultSettings::MAX_ICON_BYTES,
            strlen((string) $bytes),
            'Stored over the byte ceiling it exists to enforce'
        );
    }

    /**
     * The quality stepping, tested where it can actually be reached.
     *
     * `VaultSettings::MAX_ICON_BYTES` is generous enough that no 500px JPEG approaches
     * it, so an upload through HTTP can never exercise the ceiling — the first
     * two attempts at a byte assertion here were both green with the ceiling
     * removed, which is exactly the vacuous shape this repository keeps hitting.
     * The bound is a parameter, so it is given one a photograph does exceed.
     */
    public function testAPhotographIsCompressedHarderRatherThanRefused(): void
    {
        $detailed = $this->photograph(1200, 1200);
        $atBestQuality = AgentIcons::normalisePhoto($detailed, 500, 10 * 1024 * 1024);
        $ceiling = (int) (strlen($atBestQuality) * 0.6);

        $stepped = AgentIcons::normalisePhoto($detailed, 500, $ceiling);

        self::assertLessThanOrEqual(
            $ceiling,
            strlen($stepped),
            'The ceiling was not enforced: quality never stepped down'
        );
        self::assertLessThan(
            strlen($atBestQuality),
            strlen($stepped),
            'The same bytes came back, so nothing was re-encoded'
        );
        $size = getimagesizefromstring($stepped);
        self::assertNotFalse($size, 'Stepping produced something that is not an image');
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
    }

    /** A ceiling no quality can meet is a refusal, never silent mush. */
    public function testAPictureThatCannotBeMadeSmallEnoughIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgentIcons::normalisePhoto($this->photograph(1200, 1200), 500, 512);
    }

    /**
     * A tiny file can still be an enormous picture.
     *
     * The compressed-size gate does not bound the decode: one flat colour at
     * 10,000x10,000 is under 100 KB and a hundred million pixels, and GD wants
     * four bytes for each. This was a worker exhausted, not an upload refused.
     */
    public function testAnImageWithTooManyPixelsIsRefusedBeforeItIsDecoded(): void
    {
        $this->loginAs($this->kb->a);

        $bomb = $this->flatPng(10000, 10000);
        self::assertLessThan(
            1024 * 1024,
            strlen($bomb),
            'Precondition: this has to be small enough to pass the byte gate'
        );

        $this->uploadFace($bomb);
        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertStringContainsString('megapixels', (string) $this->body());

        $this->sessionRequest('GET', '/api/me');
        self::assertNull($this->jsonResponse()['icon_key'], 'A refused upload still landed');
    }

    /** A portrait photograph must not be stored on its side. */
    public function testAnEXIFRotatedPhotographIsStoredUpright(): void
    {
        $this->loginAs($this->kb->a);

        // Orientation 6: the pixels are landscape, the picture is portrait.
        $this->uploadFace($this->orientedJpeg(80, 40, 6), 'me.jpg');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/me/icon');
        $size = getimagesizefromstring((string) $this->client->getResponse()->getContent());
        self::assertNotFalse($size);
        self::assertGreaterThan(
            $size[0],
            $size[1],
            'Stored '.$size[0].'x'.$size[1].' — the EXIF rotation was not applied'
        );
    }

    /**
     * A photograph is the ordinary case for a face and must be accepted.
     *
     * It was refused, because the stored form was PNG: lossless, so a picture
     * with real detail in it re-encoded to several hundred KB and tripped the
     * ceiling with "that image is too detailed". The format was the problem,
     * never the picture.
     */
    public function testAPhotographIsAcceptedAsAFace(): void
    {
        $this->loginAs($this->kb->a);

        $photo = $this->photograph();
        self::assertGreaterThan(
            50 * 1024,
            strlen($photo),
            'Precondition: this fixture has to have real detail in it'
        );

        $this->uploadFace($photo, 'me.jpg');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/me/icon');
        $stored = (string) $this->client->getResponse()->getContent();
        self::assertLessThanOrEqual(
            VaultSettings::MAX_ICON_BYTES,
            strlen($stored),
            'Stored over the ceiling it is supposed to enforce'
        );
    }

    /** Not an image is refused before anything is stored. */
    public function testAFaceThatIsNotAnImageIsRefused(): void
    {
        $this->loginAs($this->kb->a);
        $this->uploadFace('this is not a png at all', 'me.png');
        self::assertSame(400, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/me');
        self::assertNull($this->jsonResponse()['icon_key'], 'A refused upload still landed');
    }

    /**
     * A person's face is an uploaded image, so `icon_key` takes null and
     * nothing else. A key from the connection catalogue is refused rather than
     * quietly ignored: worn by a person, a provider's logo would make a byline
     * claim something untrue about who wrote the note.
     */
    public function testAPersonMayNotWearAKeyOfAnyKind(): void
    {
        $this->loginAs($this->kb->a);

        foreach (['logo:openai', 'builtin:feather', 'builtin:nonesuch', 'upload', 'builtin:', 42] as $key) {
            $this->sessionRequest('PATCH', '/api/me', ['icon_key' => $key]);
            self::assertSame(400, $this->httpStatus(), 'Accepted '.var_export($key, true).' as a face');
        }

        $this->sessionRequest('GET', '/api/me');
        self::assertNull($this->jsonResponse()['icon_key'], 'A refused face still landed');
        self::assertSame('fa-solid fa-circle-user', $this->jsonResponse()['icon']);
    }

    public function testTheFaceIsClearedByNullAndSurvivesARename(): void
    {
        $this->loginAs($this->kb->a);

        $this->uploadFace($this->png());
        self::assertSame(VaultSettings::ICON_UPLOAD, $this->jsonResponse()['icon_key']);

        // A rename sends only the name, and must not take the face with it.
        $this->sessionRequest('PATCH', '/api/me', ['name' => 'Somebody Else']);
        self::assertSame(VaultSettings::ICON_UPLOAD, $this->jsonResponse()['icon_key'], 'Renaming cleared the face');

        $this->sessionRequest('PATCH', '/api/me', ['icon_key' => null]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertNull($this->jsonResponse()['icon_key']);
        self::assertSame('fa-solid fa-circle-user', $this->jsonResponse()['icon']);
        self::assertNull($this->jsonResponse()['icon_url'], 'The picture outlived the key that named it');

        $this->sessionRequest('GET', '/api/me/icon');
        self::assertSame(404, $this->httpStatus(), 'The bytes are still served after the face was cleared');
    }

    /** An assistant has no business choosing what its owner looks like. */
    public function testABearerTokenCannotChooseTheFace(): void
    {
        $this->client->request(
            'PATCH',
            '/api/me',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->kb->a->curatorBearer,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['icon_key' => 'builtin:star'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(403, $this->httpStatus());
    }

    public function testAPersonsOwnEditNamesThePersonRatherThanNobody(): void
    {
        // The operator's report, in one assertion: "should display not just
        // agents, but also users if last saved by user (currently doesnt)".
        // Before a person could be named here this cell was empty for every
        // note he had ever written or edited himself.
        $note = $this->kb->a->note('Written by a person', 'Body.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Edited in the browser.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $editedBy = $this->listedNote($id)['edited_by'];
        self::assertNotNull($editedBy, 'A note the operator edited must say so');
        self::assertSame('person', $editedBy['kind']);
        self::assertSame('O', $editedBy['initial'], 'The person initial is missing');
        self::assertSame('fa-solid fa-circle-user', $editedBy['icon'], 'The neutral fallback glyph was lost');
        self::assertNull($editedBy['icon_url']);

        $fromEmail = ActorView::fromRow([], 'agent_', new OwnerMark('   ', 'reader@example.test', null), Note::ACTOR_HUMAN);
        self::assertSame('R', $fromEmail['initial']);

        $anonymous = ActorView::fromRow([], 'agent_', new OwnerMark('', '', null), Note::ACTOR_HUMAN);
        self::assertNull($anonymous['initial']);
        self::assertSame('fa-solid fa-circle-user', $anonymous['icon']);
    }

    public function testTheNotePageNamesTheConnectionTheWayTheOperatorRenamedIt(): void
    {
        // The exact defect he saw on the note page: "Added — shows unnamed
        // agent". `created_by` was built from the token's REGISTERED name, so
        // a connection he had renamed to "Claude · research" still read as
        // `agent-…` here while the notes list — a different serialiser for the
        // same fact — showed the name he chose.
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Filed by an assistant',
            'body_md' => 'Something it believes.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$this->tokenId('agent-%'), [
            'display_name' => 'Claude · research',
            'icon_key' => 'builtin:brain',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $addedBy = $this->jsonResponse()['added_by'];
        self::assertSame('Claude · research', $addedBy['name'], 'The note page shows the name he chose, not the registered one');
        self::assertSame('fa-solid fa-brain', $addedBy['icon'], 'and the mark he chose, where there had been none at all');
        self::assertSame('assistant', $addedBy['kind']);
    }

    public function testANotePersonSomebodyAddedIsAttributedToThemOnThePage(): void
    {
        $note = $this->kb->a->note('Typed into the editor', 'Body.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/notes/'.$note->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());
        $addedBy = $this->jsonResponse()['added_by'];
        self::assertSame('person', $addedBy['kind'], 'A note a person typed was added by that person');
        self::assertNotSame('', $addedBy['name']);
    }

    public function testAnAssistantFromAnotherKnowledgeBaseIsNeverNamed(): void
    {
        // The list joins api_tokens by id, and connection ids restart per
        // vault: my note is written by the connection whose id B's first
        // connection also has.
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Mine',
            'body_md' => 'Body.',
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $id = $this->jsonResponse()['note']['id'];
        $this->in($this->kb->b);
        $theirToken = (int) $this->em->getConnection()->fetchOne('SELECT id FROM api_tokens ORDER BY id LIMIT 1');
        self::assertSame($this->kb->a->agentTokenId, $theirToken, 'Precondition: the join key is one both vaults hold');
        $this->em->getConnection()->executeStatement(
            'UPDATE api_tokens SET display_name = :n WHERE id = :id',
            ['n' => 'THE OTHER TEAM', 'id' => $theirToken]
        );

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/notes?per_page=100');
        $mineListedForThem = array_filter($this->jsonResponse()['items'], static fn (array $r): bool => $r['id'] === $id);
        self::assertSame([], $mineListedForThem, 'Precondition: they cannot see my note at all');

        $this->loginAs($this->kb->a);
        self::assertStringContainsString('agent-', $this->listedNote($id)['edited_by']['name']);
        self::assertStringNotContainsString(
            'THE OTHER TEAM',
            $this->body(),
            'and no other team\'s connection name may appear in my listing'
        );
    }

    public function testTheCuratorLogShowsWhatAConnectionIsCalledNow(): void
    {
        // Same ruling, second surface. `curator_log.token_name` is a string
        // copied at write time and cannot follow a rename; the row now carries
        // the token id and the display resolves through it.
        $note = $this->kb->a->note('A note to edit', 'First body.');
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $note->getId(),
                'body_md' => 'Second body.',
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$this->tokenId('curator-%'), [
            'display_name' => 'Memex Desk · nightly',
        ]);

        $this->sessionRequest('GET', '/api/curator-log');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $names = array_column($this->jsonResponse()['entries'], 'by');
        self::assertContains('Memex Desk · nightly', $names, 'The log shows the name the connection has now');
    }
}
