<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VaultSettings;
use App\Service\AccountDeletion;
use App\Service\ActorView;
use App\Service\AgentIcons;
use App\Service\Appearance;
use App\Service\Locales;
use App\Service\MapSettings;
use App\Service\PublicAddress;
use App\Service\SystemTags;
use App\Service\WelcomeProgress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends ApiController
{
    /** Matches the `accounts.name` and `accounts.memex_name` columns, so the database can never be the thing that refuses. */
    private const NAME_MAX = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly PublicAddress $publicAddress,
    ) {
    }

    /**
     * Rename yourself. The one part of the account a person can rewrite, and
     * the only writable field on /api/me.
     *
     * Session-only: a leaked API token must not be able to change what the
     * operator sees at the top of every screen, which is the cheapest possible
     * cover for a token that should not be there.
     *
     * A new person whose Google profile reads "jane d", or whose GitHub
     * login is `octocat`, would otherwise carry that forever.
     */
    #[Route('/api/me', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'Your name');

        $data = $request->toArray();
        if (!array_key_exists('name', $data) && !array_key_exists('icon_key', $data)) {
            return $this->json($this->json400('Nothing to change'), Response::HTTP_BAD_REQUEST);
        }

        // A person's face is an uploaded image now, so the only value this
        // accepts is null — the way back to the default silhouette. The bytes
        // arrive at /api/me/icon.
        if (array_key_exists('icon_key', $data) && $data['icon_key'] !== null) {
            return $this->json(
                $this->json400('Upload an image instead — POST it to /api/me/icon'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (array_key_exists('name', $data)) {
            // Trimmed before measuring, so a name of three spaces is empty rather
            // than valid, and normalised for inner whitespace so the nav cannot be
            // stretched by a name that is mostly padding.
            $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');
            if ($name === '') {
                return $this->json($this->json400('Your name cannot be empty'), Response::HTTP_BAD_REQUEST);
            }
            if (mb_strlen($name) > self::NAME_MAX) {
                return $this->json($this->json400('Your name must be at most '.self::NAME_MAX.' characters'), Response::HTTP_BAD_REQUEST);
            }
            $account->setName($name);
            $this->directoryEntityManager->flush();
        }

        if (array_key_exists('icon_key', $data)) {
            $this->settings()->clearUploadedIcon();
            $this->em->flush();
        }

        return $this->me($request);
    }

    /**
     * Rename the knowledge base itself, not the person.
     *
     * Session-only for the same reason the person's name is: an assistant that
     * may file notes must not be able to rename the thing it files them into,
     * and the name is what every connected assistant is told it is writing to.
     */
    #[Route('/api/me/memex', methods: ['PATCH'])]
    public function updateMemexName(Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'The name of your memex');

        $data = $request->toArray();
        if (!array_key_exists('name', $data)) {
            return $this->json($this->json400('Nothing to change'), Response::HTTP_BAD_REQUEST);
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');
        if ($name === '') {
            return $this->json($this->json400('Your memex needs a name'), Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return $this->json($this->json400('That name must be at most '.self::NAME_MAX.' characters'), Response::HTTP_BAD_REQUEST);
        }

        $account->setMemexName($name);
        $this->directoryEntityManager->flush();

        return $this->me($request);
    }

    /**
     * Leave. Everything goes, now.
     *
     * Session-only, and the strictest case of that rule in the codebase: a
     * connected assistant holds a token that authenticates as its owner, and
     * "you may read and file notes" must never have quietly included "you may
     * destroy the knowledge base". The confirmation is the account's own
     * address, typed, for the ordinary reason a destructive form asks for
     * something that cannot be produced by a stray click.
     */
    #[Route('/api/me', methods: ['DELETE'])]
    public function deleteAccount(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'Deleting your account');

        $typed = trim((string) ($request->toArray()['confirm_email'] ?? ''));
        if (mb_strtolower($typed) !== mb_strtolower($account->getEmail())) {
            return $this->json(
                $this->json400('Type the e-mail address of this account to confirm.'),
                Response::HTTP_BAD_REQUEST
            );
        }

        $deletion->delete($account);

        // Every session and its stored data are already gone; this drops what
        // this request still holds of its own. Not assertable in the suite —
        // the test env uses mock_file storage.
        $request->getSession()->invalidate();

        return $this->json(['deleted' => true]);
    }

    /**
     * The first-run wizard: whether this person is past it, and how far the
     * account has actually got.
     *
     * The facts are read live on every call because the wizard polls this
     * while the person is away in their assistant's settings — connecting
     * happens in another application, and the only way memex learns it
     * worked is a request arriving from that assistant.
     */
    #[Route('/api/me/welcome', methods: ['GET'])]
    public function welcome(Request $request, WelcomeProgress $progress): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'The welcome wizard');

        return $this->json([
            'completed' => $this->settings()->hasFinishedWelcome(),
            'facts' => $progress->for(),
        ]);
    }

    /**
     * Stop sending this person to the wizard on sign-in.
     *
     * Written by finishing it AND by leaving it, and the two are deliberately
     * the same write: a wizard that only lets you out by completing it is a
     * wall, and somebody who connected their assistant last week has nothing
     * to complete. It decides nothing about the account — every step it
     * offered is still reachable in Settings, which also opens the wizard
     * again.
     */
    #[Route('/api/me/welcome/done', methods: ['POST'])]
    public function finishWelcome(Request $request): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'The welcome wizard');

        $this->settings()->finishWelcome();
        $this->em->flush();

        return $this->json(['completed' => true]);
    }

    /**
     * Accent and background, so the choice follows the person rather than the
     * machine. Partial: send only the theme that changed, and null to clear it.
     *
     * Session-only like every other account-shaped write. Nothing here is
     * dangerous on its own, but a connected assistant has no business
     * repainting somebody's screen, and the exception would be the one that
     * makes the rule arguable.
     */
    #[Route('/api/me/appearance', methods: ['PATCH'])]
    public function updateAppearance(Request $request): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'Your appearance settings');

        $settings = $this->settings();
        [$next, $refusal] = Appearance::merge($settings->getAppearance(), $request->toArray());
        if ($refusal !== null) {
            return $this->json($this->json400($refusal), Response::HTTP_BAD_REQUEST);
        }

        $settings->setAppearance($next);
        $this->em->flush();

        return $this->json(['appearance' => Appearance::read($next)]);
    }

    /**
     * How the map is drawn — renderer, node shape, link style, what a label
     * says. Session-only for the same reason the appearance picker is: a
     * connected assistant has no business deciding what the owner's map looks
     * like.
     */
    #[Route('/api/me/map', methods: ['PATCH'])]
    public function updateMapSettings(Request $request): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'Your map settings');

        $settings = $this->settings();
        [$next, $refusal] = MapSettings::merge($settings->getMapSettings(), $request->toArray());
        if ($refusal !== null) {
            return $this->json($this->json400($refusal), Response::HTTP_BAD_REQUEST);
        }

        $settings->setMapSettings($next);
        $this->em->flush();

        return $this->json(['map' => MapSettings::read($next)]);
    }

    /**
     * Which language the screen speaks. English is bundled; anything else has
     * to be a file the operator installed, or the choice would name words the
     * SPA cannot fetch. Null puts the account back on English.
     */
    #[Route('/api/me/locale', methods: ['PATCH'])]
    public function updateLocale(Request $request, Locales $locales): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'Your language');

        $data = $request->toArray();
        if (!array_key_exists('locale', $data)) {
            return $this->json($this->json400('Nothing to change'), Response::HTTP_BAD_REQUEST);
        }
        $locale = $data['locale'];
        if ($locale !== null && (!is_string($locale) || !$locales->isAvailable($locale))) {
            return $this->json($this->json400('That language is not installed on this server'), Response::HTTP_BAD_REQUEST);
        }

        $this->settings()->setLocale($locale === Locales::BUNDLED ? null : $locale);
        $this->em->flush();

        return $this->me($request);
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $settings = $this->settings();
        $token = $this->requestToken($request);

        return $this->json([
            'email' => $account->getEmail(),
            'name' => $account->getName(),
            // `handle` is the vault's segment in a note URL — see Account::$handle.
            // The SPA needs it to build an address that names WHICH knowledge
            // base a note number belongs to.
            'team' => [
                'name' => $account->getMemexName(),
                'handle' => $account->getHandle(),
            ],
            'auth' => $token !== null ? 'token' : 'session',
            'token_name' => $token?->getName(),
            // Whether sign-in should hand this person to the first-run wizard.
            // On `me` rather than behind its own request because the router
            // has to decide before the first view renders, and a second round
            // trip there is a blank screen or a flash of the app behind it.
            // Absent for a token: a connection has no screen to be sent to.
            ...($token === null ? ['welcome_completed' => $settings->hasFinishedWelcome()] : []),
            // The tags memex itself reads, with the reason each is held. Rides
            // on `me` because it is the one payload every screen already has
            // before it renders a tag chip, and a chip appears on the note,
            // the search list, the review inbox and the tag screen — four
            // components that would otherwise each need their own fetch, or a
            // hardcoded copy of a list the server owns. Installation-wide, not
            // per-account: see App\Service\SystemTags.
            'system_tags' => SystemTags::all(),
            // Whether ChatGPT, Claude and Gemini can reach this memex at all,
            // which decides what Settings and the wizard offer to connect.
            'web_assistants' => $this->publicAddress->reachableFromTheWeb(),
            // How this person wants memex to look, on whichever device they
            // have just opened. Empty until they choose something.
            'appearance' => Appearance::read($settings->getAppearance()),
            'locale' => $settings->getLocale() ?? Locales::BUNDLED,
            // Empty until they choose; an absent key is that control's default,
            // which the SPA owns so a first paint needs nothing from here.
            'map' => MapSettings::read($settings->getMapSettings()),
            // The face, in the shape every byline already renders, plus the
            // key itself so the picker knows which tile is the current one.
            'icon_key' => $settings->getIconKey(),
            ...ActorView::personMark($account->getName(), $account->getEmail(), $settings->getIconKey()),
        ]);
    }

    /**
     * Upload the picture that stands for this person.
     *
     * Session-only, like the name beside it: a leaked token must not be able to
     * change what a byline looks like. Re-encoded before it is stored — see
     * {@see AgentIcons::normaliseUpload()} — so nothing a browser is asked to
     * render is a file somebody else chose the bytes of.
     */
    #[Route('/api/me/icon', methods: ['POST'])]
    public function uploadIcon(Request $request): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'Your picture');

        $file = $request->files->get('icon');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            // A file over the server's own ceiling never reaches PHP as a
            // readable upload, and "no file arrived" is the wrong thing to tell
            // somebody who watched one leave.
            $tooBig = $file instanceof UploadedFile
                && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return $this->json(
                $this->json400($tooBig
                    ? 'That file is larger than this server accepts. Try a smaller one.'
                    : 'No file arrived. Choose a PNG or JPEG.'),
                Response::HTTP_BAD_REQUEST,
            );
        }
        try {
            $jpeg = AgentIcons::normalisePhoto(
                (string) file_get_contents($file->getPathname()),
                VaultSettings::ICON_SIZE,
                VaultSettings::MAX_ICON_BYTES,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }
        $this->settings()->setUploadedIcon($jpeg);
        $this->em->flush();

        return $this->me($request);
    }

    /** Back to the default silhouette. */
    #[Route('/api/me/icon', methods: ['DELETE'])]
    public function deleteIcon(Request $request): JsonResponse
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'Your picture');
        $this->settings()->clearUploadedIcon();
        $this->em->flush();

        return $this->me($request);
    }

    /**
     * Serve the stored JPEG.
     *
     * Session-scoped, and scoped to the CALLER rather than to an id in the
     * path: there is no id space to guess because the only picture this route
     * can answer with is the one belonging to whoever is asking.
     *
     * `nosniff` because the bytes are a JPEG memex wrote itself.
     *
     * `no-cache` means "revalidate", not "do not store": the URL never changes
     * when the picture does, so a stored copy with any freshness lifetime is a
     * stale face nobody can flush. The ETag makes the revalidation a 304 in the
     * ordinary case, which is what pays for it.
     */
    #[Route('/api/me/icon', methods: ['GET'])]
    public function icon(Request $request): Response
    {
        $this->currentAccount();
        $this->assertSessionAuth($request, 'Your picture');

        $bytes = $this->settings()->getIconBytes();
        if ($bytes === null) {
            throw $this->createNotFoundException('You have not uploaded a picture');
        }

        $response = new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
        $response->setEtag(substr(hash('sha256', $bytes), 0, 16));
        $response->isNotModified($request);

        return $response;
    }

    private function settings(): VaultSettings
    {
        return $this->em->getRepository(VaultSettings::class)->current();
    }
}
