<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\ForumPost;

/**
 * A host app's resource for a moderated model, with view and edit pages.
 * Tests switch what the user may do through the static flags.
 */
class ForumPostResource extends Resource
{
    public static bool $canView = true;

    public static bool $canEdit = true;

    protected static ?string $model = ForumPost::class;

    protected static ?string $slug = 'forum-posts';

    public static function canView(Model $record): bool
    {
        return self::$canView;
    }

    public static function canEdit(Model $record): bool
    {
        return self::$canEdit;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForumPosts::route('/'),
            'view' => ViewForumPost::route('/{record}'),
            'edit' => EditForumPost::route('/{record}/edit'),
        ];
    }
}
