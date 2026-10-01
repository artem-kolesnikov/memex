<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The glyphs a saved filter can carry in the sidebar. Its own list rather than
 * {@see AgentIcons}: that one names assistants and machines, this one names
 * what a person is looking for.
 */
final class PresetIcons
{
    public const DEFAULT = 'filter';

    private const CATALOGUE = [
        'filter' => ['icon' => 'fa-solid fa-filter', 'label' => 'Filter', 'group' => 'Marks'],
        'bookmark' => ['icon' => 'fa-solid fa-bookmark', 'label' => 'Bookmark', 'group' => 'Marks'],
        'star' => ['icon' => 'fa-solid fa-star', 'label' => 'Star', 'group' => 'Marks'],
        'flag' => ['icon' => 'fa-solid fa-flag', 'label' => 'Flag', 'group' => 'Marks'],
        'heart' => ['icon' => 'fa-solid fa-heart', 'label' => 'Heart', 'group' => 'Marks'],
        'circle-check' => ['icon' => 'fa-solid fa-circle-check', 'label' => 'Check', 'group' => 'Marks'],
        'circle-question' => ['icon' => 'fa-solid fa-circle-question', 'label' => 'Question', 'group' => 'Marks'],
        'triangle-exclamation' => ['icon' => 'fa-solid fa-triangle-exclamation', 'label' => 'Warning', 'group' => 'Marks'],
        'fire' => ['icon' => 'fa-solid fa-fire', 'label' => 'Fire', 'group' => 'Marks'],
        'lightbulb' => ['icon' => 'fa-solid fa-lightbulb', 'label' => 'Idea', 'group' => 'Marks'],
        'bell' => ['icon' => 'fa-solid fa-bell', 'label' => 'Bell', 'group' => 'Marks'],
        'thumbtack' => ['icon' => 'fa-solid fa-thumbtack', 'label' => 'Pin', 'group' => 'Marks'],

        'inbox' => ['icon' => 'fa-solid fa-inbox', 'label' => 'Inbox', 'group' => 'Work'],
        'clock' => ['icon' => 'fa-solid fa-clock', 'label' => 'Clock', 'group' => 'Work'],
        'hourglass' => ['icon' => 'fa-solid fa-hourglass-half', 'label' => 'Waiting', 'group' => 'Work'],
        'calendar' => ['icon' => 'fa-solid fa-calendar-days', 'label' => 'Calendar', 'group' => 'Work'],
        'list-check' => ['icon' => 'fa-solid fa-list-check', 'label' => 'Checklist', 'group' => 'Work'],
        'briefcase' => ['icon' => 'fa-solid fa-briefcase', 'label' => 'Briefcase', 'group' => 'Work'],
        'folder' => ['icon' => 'fa-solid fa-folder', 'label' => 'Folder', 'group' => 'Work'],
        'box-archive' => ['icon' => 'fa-solid fa-box-archive', 'label' => 'Archive', 'group' => 'Work'],
        'tag' => ['icon' => 'fa-solid fa-tag', 'label' => 'Tag', 'group' => 'Work'],
        'book' => ['icon' => 'fa-solid fa-book', 'label' => 'Book', 'group' => 'Work'],
        'pen' => ['icon' => 'fa-solid fa-pen', 'label' => 'Pen', 'group' => 'Work'],
        'magnifying-glass' => ['icon' => 'fa-solid fa-magnifying-glass', 'label' => 'Search', 'group' => 'Work'],
        'chart-line' => ['icon' => 'fa-solid fa-chart-line', 'label' => 'Chart', 'group' => 'Work'],
        'money-bill' => ['icon' => 'fa-solid fa-money-bill', 'label' => 'Money', 'group' => 'Work'],
        'code' => ['icon' => 'fa-solid fa-code', 'label' => 'Code', 'group' => 'Work'],
        'bug' => ['icon' => 'fa-solid fa-bug', 'label' => 'Bug', 'group' => 'Work'],
        'wrench' => ['icon' => 'fa-solid fa-wrench', 'label' => 'Wrench', 'group' => 'Work'],
        'flask' => ['icon' => 'fa-solid fa-flask', 'label' => 'Flask', 'group' => 'Work'],
        'graduation-cap' => ['icon' => 'fa-solid fa-graduation-cap', 'label' => 'Learning', 'group' => 'Work'],
        'shield' => ['icon' => 'fa-solid fa-shield-halved', 'label' => 'Security', 'group' => 'Work'],
        'key' => ['icon' => 'fa-solid fa-key', 'label' => 'Key', 'group' => 'Work'],
        'server' => ['icon' => 'fa-solid fa-server', 'label' => 'Server', 'group' => 'Work'],
        'robot' => ['icon' => 'fa-solid fa-robot', 'label' => 'Assistant', 'group' => 'Work'],

        'house' => ['icon' => 'fa-solid fa-house', 'label' => 'Home', 'group' => 'Life'],
        'user' => ['icon' => 'fa-solid fa-user', 'label' => 'Person', 'group' => 'Life'],
        'users' => ['icon' => 'fa-solid fa-users', 'label' => 'People', 'group' => 'Life'],
        'comment' => ['icon' => 'fa-solid fa-comment', 'label' => 'Conversation', 'group' => 'Life'],
        'envelope' => ['icon' => 'fa-solid fa-envelope', 'label' => 'Mail', 'group' => 'Life'],
        'globe' => ['icon' => 'fa-solid fa-globe', 'label' => 'World', 'group' => 'Life'],
        'location-dot' => ['icon' => 'fa-solid fa-location-dot', 'label' => 'Place', 'group' => 'Life'],
        'plane' => ['icon' => 'fa-solid fa-plane', 'label' => 'Travel', 'group' => 'Life'],
        'car' => ['icon' => 'fa-solid fa-car', 'label' => 'Car', 'group' => 'Life'],
        'utensils' => ['icon' => 'fa-solid fa-utensils', 'label' => 'Food', 'group' => 'Life'],
        'seedling' => ['icon' => 'fa-solid fa-seedling', 'label' => 'Garden', 'group' => 'Life'],
        'heart-pulse' => ['icon' => 'fa-solid fa-heart-pulse', 'label' => 'Health', 'group' => 'Life'],
        'dumbbell' => ['icon' => 'fa-solid fa-dumbbell', 'label' => 'Fitness', 'group' => 'Life'],
        'paw' => ['icon' => 'fa-solid fa-paw', 'label' => 'Pets', 'group' => 'Life'],
        'gift' => ['icon' => 'fa-solid fa-gift', 'label' => 'Gift', 'group' => 'Life'],
        'music' => ['icon' => 'fa-solid fa-music', 'label' => 'Music', 'group' => 'Life'],
        'camera' => ['icon' => 'fa-solid fa-camera', 'label' => 'Photos', 'group' => 'Life'],
        'gamepad' => ['icon' => 'fa-solid fa-gamepad', 'label' => 'Games', 'group' => 'Life'],
        'film' => ['icon' => 'fa-solid fa-film', 'label' => 'Film', 'group' => 'Life'],
        'cart-shopping' => ['icon' => 'fa-solid fa-cart-shopping', 'label' => 'Shopping', 'group' => 'Life'],
    ];

    /** @return list<array{key: string, icon: string, label: string, group: string}> */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $entry) {
            $out[] = ['key' => $key, ...$entry];
        }

        return $out;
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::CATALOGUE[$key]);
    }

    public static function classesFor(string $key): string
    {
        return (self::CATALOGUE[$key] ?? self::CATALOGUE[self::DEFAULT])['icon'];
    }
}
