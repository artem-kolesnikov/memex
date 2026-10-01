<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ApiToken;
use App\Service\AgentIcons;

/**
 * Giving a connected assistant a name, a note and a face.
 *
 * The problem this answers is visible in the operator's own token table: names
 * come from whatever the client called itself when it registered, clients do
 * not coordinate, and two live rows there are both `oauth: Google`. Once the
 * notes list starts showing which assistant last touched a note, four things
 * called Claude stop being information.
 *
 * Two properties carry the weight, and both are asserted here rather than
 * argued:
 *
 *   - **an assistant cannot rename itself.** Every connected assistant holds a
 *     token that authenticates AS its owner, so a bearer token reaching this
 *     endpoint could relabel itself as a different, more trusted connection —
 *     a worse version of the confusion the feature exists to remove;
 *   - **an uploaded icon is re-encoded, not inspected.** What is stored is
 *     bytes we wrote from decoded pixels, so it is provably an image and
 *     carries none of the metadata it arrived with.
 */
final class AgentIdentityTest extends ApiTestCase
{
    private function tokenId(): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT id FROM api_tokens WHERE revoked_at IS NULL ORDER BY id LIMIT 1'
        );
    }

    /** A real PNG, made the same way GD will read it. */
    private function png(int $w = 400, int $h = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function upload(int $id, string $bytes, string $filename = 'icon.png'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'icon');
        file_put_contents($path, $bytes);
        $this->sessionUpload('POST', '/api/tokens/'.$id.'/icon', 'icon', $path, $filename);
    }

    public function testRenamingAConnectionKeepsWhatTheClientCalledItself(): void
    {
        // Both names travel: the new one is what you read, and the registered
        // one is what makes the rename checkable — "this is the one that
        // connected as Claude" is the fact a rename must not destroy.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, [
            'display_name' => 'Claude · research',
            'description' => 'The one that files papers. Reads more than it writes.',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/tokens');
        $row = $this->tokenRow($id);
        self::assertSame('Claude · research', $row['display_name']);
        self::assertSame('The one that files papers. Reads more than it writes.', $row['description']);
        self::assertNotSame(
            'Claude · research',
            $row['label'],
            'The name the client registered under must survive a rename'
        );
    }

    public function testABearerTokenCannotRenameAnything(): void
    {
        // The property that matters most here. An agent token authenticates as
        // its owner, so this endpoint is one `assertSessionAuth()` away from
        // letting an assistant relabel itself as a more trusted one.
        $id = $this->tokenId();

        $this->request('PATCH', '/api/tokens/'.$id, $this->kb->a->agentBearer, ['display_name' => 'Something else']);
        self::assertSame(403, $this->httpStatus(), $this->body());

        $this->request('POST', '/api/tokens/'.$id.'/icon', $this->kb->a->agentBearer, []);
        self::assertSame(403, $this->httpStatus());

        // And a curator token, which is the more privileged role, equally.
        $this->request('PATCH', '/api/tokens/'.$id, $this->kb->a->curatorBearer, ['display_name' => 'Nor this']);
        self::assertSame(403, $this->httpStatus());

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/tokens');
        self::assertNull($this->tokenRow($id)['display_name'], 'Nothing was renamed');
    }

    public function testAnotherKnowledgeBasesConnectionIsNotFound(): void
    {
        // 404 rather than 403, the rule the rest of the codebase follows: a
        // 403 confirms the id exists. Connection ids restart per vault, so the
        // probe is one only B holds.
        $otherId = $this->kb->b->connection('b-only', 'mxt_agent_b_only');
        $this->in($this->kb->a);
        self::assertNull($this->em->find(ApiToken::class, $otherId), 'Precondition: A holds no connection by that id');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$otherId, ['display_name' => 'Not mine']);
        self::assertSame(404, $this->httpStatus());

        $this->sessionRequest('GET', '/api/tokens/'.$otherId.'/icon');
        self::assertSame(404, $this->httpStatus());

        $this->in($this->kb->b);
        self::assertNull($this->em->find(ApiToken::class, $otherId)->getDisplayName(), "B's connection was not renamed");
    }

    public function testABuiltinIconIsAcceptedAndAnInventedOneIsNot(): void
    {
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => 'builtin:robot']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => 'builtin:not-a-real-mark']);
        self::assertSame(400, $this->httpStatus());

        // `upload` names bytes that only the upload route can produce. Setting
        // it on a connection that HAS no bytes would leave a row claiming an
        // image it does not have, which renders as a broken picture. That is
        // the whole of what this guard is for — see the test below for the
        // case it used to catch by mistake.
        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => ApiToken::ICON_UPLOAD]);
        self::assertSame(400, $this->httpStatus());

        $this->sessionRequest('GET', '/api/tokens');
        self::assertSame('builtin:robot', $this->tokenRow($id)['icon_key']);
    }

    public function testSavingAConnectionThatHasAnUploadedIconKeepsIt(): void
    {
        // The bug, as the operator met it on 2026-08-23: upload a custom image,
        // press Save, and the dialog reported "Could not save — icon_key must
        // be one of the built-in icons, or null". Nothing was wrong with the
        // image. The Save sends the whole identity back, including the
        // `icon_key` the upload route had just RETURNED, and the endpoint
        // refused its own value.
        //
        // The shape here is the dialog's, deliberately: upload, then one PATCH
        // carrying name, description and `upload` together.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);

        $this->upload($id, $this->png());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, [
            'display_name' => 'Claude · the one with the face',
            'description' => null,
            'icon_key' => ApiToken::ICON_UPLOAD,
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/tokens');
        $row = $this->tokenRow($id);
        self::assertSame('Claude · the one with the face', $row['display_name'], 'The rename in the same save landed');
        self::assertSame(ApiToken::ICON_UPLOAD, $row['icon_key'], 'and the custom image survived it');
        self::assertNotNull($row['icon_url']);

        // And the bytes are still served, so this is not a row that merely says
        // `upload` — the failure mode the guard exists to prevent.
        $this->sessionRequest('GET', '/api/tokens/'.$id.'/icon');
        self::assertSame(200, $this->httpStatus());
        self::assertNotFalse(getimagesizefromstring($this->body()));
    }

    public function testAnUploadedIconIsReEncodedRatherThanStoredAsSent(): void
    {
        // The safety property, asserted as a fact about the bytes: what comes
        // back is not what went in. A PNG comment is the simplest thing to
        // smuggle, and re-encoding is what removes it — along with everything
        // else we did not write.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);

        $original = $this->png();
        $smuggled = $original.'SECRET-TRAILING-PAYLOAD';
        $this->upload($id, $smuggled);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/tokens/'.$id.'/icon');
        self::assertSame(200, $this->httpStatus());
        $stored = $this->body();
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(
            'SECRET-TRAILING-PAYLOAD',
            $stored,
            'What is stored must be bytes memex wrote from decoded pixels, not the file as sent'
        );
        self::assertNotSame($smuggled, $stored);
        self::assertNotFalse(getimagesizefromstring($stored), 'and it must still be a readable image');
    }

    public function testAnOversizedImageIsScaledDownRatherThanRefused(): void
    {
        // A person exports a logo at whatever size their tool offers. Refusing
        // 1024px would be a rule about their image editor.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);
        $this->upload($id, $this->png(1024, 512));
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/tokens/'.$id.'/icon');
        self::assertSame(200, $this->httpStatus());
        $size = getimagesizefromstring($this->body());
        self::assertNotFalse($size);
        self::assertSame(ApiToken::ICON_SIZE, $size[0], 'Scaled to fit the long side');
        self::assertSame(ApiToken::ICON_SIZE / 2, $size[1], 'and the aspect ratio is kept, not cropped');
        self::assertSame('image/png', $size['mime']);
    }

    public function testSomethingThatIsNotAnImageIsRefusedWithAReadableReason(): void
    {
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);
        $this->upload($id, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg');

        self::assertSame(400, $this->httpStatus());
        self::assertStringContainsString('PNG', $this->jsonResponse()['error']);

        $this->sessionRequest('GET', '/api/tokens/'.$id.'/icon');
        self::assertSame(404, $this->httpStatus(), 'and nothing was stored');
    }

    public function testChoosingABuiltinDropsBytesThatAreNoLongerReachable(): void
    {
        // Otherwise an uploaded image lingers in the column forever, invisible,
        // after the person switched away from it.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);
        $this->upload($id, $this->png());
        self::assertSame(200, $this->httpStatus());

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => 'builtin:brain']);
        self::assertSame(200, $this->httpStatus());

        $this->in($this->kb->a);
        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT icon_blob FROM api_tokens WHERE id = :id', ['id' => $id]) ?: null,
            'Switching to a built-in must not leave the old upload in the row'
        );
        $this->sessionRequest('GET', '/api/tokens/'.$id.'/icon');
        self::assertSame(404, $this->httpStatus());
    }

    public function testTheIconCatalogueTravelsWithTheTokenList(): void
    {
        // One request, and a mark removed from the catalogue leaves the picker
        // and every screen at the same moment.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/tokens');
        $payload = $this->jsonResponse();
        $icons = $payload['icons'];

        self::assertNotEmpty($icons);
        foreach ($icons as $icon) {
            self::assertTrue(AgentIcons::isShipped($icon['key']), $icon['key'].' is offered but not recognised');
            self::assertNotSame('', $icon['label']);
            self::assertNotSame('', $icon['group']);
        }

        // The provider marks travel the same way, and the same rule applies:
        // anything the picker offers must be something a save will accept.
        $logos = $payload['logos'];
        self::assertNotEmpty($logos);
        foreach ($logos as $logo) {
            self::assertTrue(AgentIcons::isShipped($logo['key']), $logo['key'].' is offered but not recognised');
            self::assertNotSame('', $logo['label']);
            // Both URLs always, equal when one file serves both themes, so the
            // client renders one shape and never has to ask which kind it has.
            self::assertMatchesRegularExpression('#^/logos/[a-z0-9-]+\.(svg|png)$#', $logo['light_url']);
            self::assertMatchesRegularExpression('#^/logos/[a-z0-9-]+\.(svg|png)$#', $logo['dark_url']);
        }
    }

    public function testEveryOfferedLogoIsAFileThatWillActuallyShip(): void
    {
        // The catalogue names files by hand, and the files are static assets
        // the deploy mirrors rather than anything PHP loads — so a typo, or a
        // rename, is invisible until an icon renders as a broken box in
        // somebody's settings screen. Nothing else in the build would notice.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/tokens');
        $root = \dirname(__DIR__, 3).'/frontend/public';

        foreach ($this->jsonResponse()['logos'] as $logo) {
            foreach (['light_url', 'dark_url'] as $which) {
                self::assertFileExists($root.$logo[$which], $logo['key'].' offers a '.$which.' that is not in the repo');
            }
        }
    }

    public function testChoosingAProviderLogoStoresItAndTravelsToTheByline(): void
    {
        // The whole path for the new kind of mark: save it, read it back on
        // the connection, and confirm the byline carries the same two URLs —
        // which is the half that would silently not happen if the notes list
        // kept building its own answer, as it did once before.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => 'logo:claude']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/tokens');
        $row = $this->tokenRow($id);
        self::assertSame('logo:claude', $row['icon_key']);
        self::assertSame('/logos/claude.svg', $row['icon_url']);
        self::assertSame('/logos/claude.svg', $row['icon_url_dark'], 'A coloured mark serves both themes from one file');

        // A monochrome mark is the case the pair exists for.
        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => 'logo:openai']);
        $this->sessionRequest('GET', '/api/tokens');
        $row = $this->tokenRow($id);
        self::assertSame('/logos/openai-on-light.svg', $row['icon_url']);
        self::assertSame('/logos/openai-on-dark.svg', $row['icon_url_dark']);
        self::assertNull($row['icon'], 'A logo is an image, never a glyph class');

        // And an invented one is still refused, so the second namespace did
        // not become a hole in the first one's validation.
        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['icon_key' => 'logo:not-a-real-provider']);
        self::assertSame(400, $this->httpStatus());
    }

    public function testAnEmptyNameClearsRatherThanStoringBlank(): void
    {
        // A blank display name would render as a nameless row. Absent means
        // unchanged, empty means "go back to what the client called it".
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['display_name' => 'Temporary']);
        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['display_name' => '   ']);

        $this->sessionRequest('GET', '/api/tokens');
        self::assertNull($this->tokenRow($id)['display_name']);
    }

    public function testFieldsLeftOutAreLeftAlone(): void
    {
        // The reason the endpoint reads array_key_exists rather than `??`.
        $id = $this->tokenId();
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$id, [
            'display_name' => 'Kept',
            'description' => 'Also kept',
            'icon_key' => 'builtin:star',
        ]);

        $this->sessionRequest('PATCH', '/api/tokens/'.$id, ['description' => 'Changed']);
        self::assertSame(200, $this->httpStatus());

        $this->sessionRequest('GET', '/api/tokens');
        $row = $this->tokenRow($id);
        self::assertSame('Kept', $row['display_name']);
        self::assertSame('Changed', $row['description']);
        self::assertSame('builtin:star', $row['icon_key']);
    }

    /** @return array<string, mixed> */
    private function tokenRow(int $id): array
    {
        foreach ($this->jsonResponse()['tokens'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('Token '.$id.' is not in the list');
    }
}
