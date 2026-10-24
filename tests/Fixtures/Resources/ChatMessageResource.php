<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Workbench\App\Models\ChatMessage;

/**
 * A host app's resource with a list page only: no page for one record.
 */
class ChatMessageResource extends Resource
{
    protected static ?string $model = ChatMessage::class;

    protected static ?string $slug = 'chat-messages';

    public static function getPages(): array
    {
        return [
            'index' => ListChatMessages::route('/'),
        ];
    }
}
