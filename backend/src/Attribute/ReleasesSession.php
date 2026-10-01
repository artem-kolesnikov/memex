<?php

declare(strict_types=1);

namespace App\Attribute;

/**
 * The controller runs long and never writes the session, so the session is
 * saved before the controller starts rather than when it ends.
 *
 * A request saves the session it read when it finishes, and the last save
 * wins. A batch approval embeds every note it applies and runs for tens of
 * seconds; saved at its end, it would put back a session the browser's other
 * requests had changed in the meantime.
 * {@see \App\EventListener\ReleaseSessionListener}
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ReleasesSession
{
}
