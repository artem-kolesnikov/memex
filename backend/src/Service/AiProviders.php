<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The providers a team can bring a key for, and everything the settings screen
 * needs to say about each one.
 *
 * The instructions are here rather than in the Vue component because they have
 * to be right, and a wrong one wastes somebody's afternoon: each `key_url` is
 * the page that actually issues a key for that provider, and `key_steps` is
 * what a person does when they get there. Checked 2026-08-20; if a provider
 * moves its console, this is the one place to fix.
 *
 * `key_prefix` is a courtesy check before we spend a round trip — every key
 * is verified against the provider's own API regardless, and that verification,
 * not the prefix, is what the green check means.
 *
 * The one embedding model a key buys is OpenAI's text-embedding-3-large, so an
 * embedding key is an OpenAI key. Anthropic sells no embeddings, and mixing
 * vector spaces would silently corrupt search rather than fail.
 */
final class AiProviders
{
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';
    public const GOOGLE = 'google';

    /** @var array<string, array<string, mixed>> */
    private const PROVIDERS = [
        self::OPENAI => [
            'label' => 'OpenAI',
            'key_url' => 'https://platform.openai.com/api-keys',
            'key_steps' => 'Sign in, choose Create new secret key, copy it once. It starts with sk-. You need billing set up on the account; a key from a project with no credit answers every call with a quota error.',
            'key_prefix' => 'sk-',
            'default_model' => 'gpt-4o-mini',
        ],
        self::ANTHROPIC => [
            'label' => 'Anthropic',
            'key_url' => 'https://console.anthropic.com/settings/keys',
            'key_steps' => 'Sign in, open Settings then API keys, choose Create key, copy it once. It starts with sk-ant-. API credit is separate from a Claude subscription: a Pro or Max plan does not pay for API calls.',
            'key_prefix' => 'sk-ant-',
            'default_model' => 'claude-haiku-4-5-20251001',
        ],
        self::GOOGLE => [
            'label' => 'Google',
            'key_url' => 'https://aistudio.google.com/apikey',
            'key_steps' => 'Sign in to Google AI Studio, choose Create API key, and pick a project. It starts with AIza. The free tier is rate limited rather than unpaid, so a busy day can start returning quota errors.',
            'key_prefix' => 'AIza',
            'default_model' => 'gemini-2.0-flash',
        ],
    ];

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? $provider;
    }

    public static function defaultModel(string $provider): ?string
    {
        return self::PROVIDERS[$provider]['default_model'] ?? null;
    }

    /** What a settings screen renders: one card per provider. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $id => $p) {
            $out[] = [
                'id' => $id,
                'label' => $p['label'],
                'key_url' => $p['key_url'],
                'key_steps' => $p['key_steps'],
                'key_prefix' => $p['key_prefix'],
                'default_model' => $p['default_model'],
            ];
        }

        return $out;
    }

    /** A shape check before the round trip, not a substitute for verifying. */
    public static function looksLikeKey(string $provider, string $key): bool
    {
        return str_starts_with($key, (string) self::PROVIDERS[$provider]['key_prefix']);
    }
}
