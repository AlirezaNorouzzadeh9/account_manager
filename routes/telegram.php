<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Conversations\AddPanelConversation;
use App\Telegram\Conversations\CreateServerConversation;
use App\Telegram\Conversations\PanelsMenu;
use App\Telegram\Conversations\ServerListMenu;
use App\Telegram\Handlers\CancelCommand;
use App\Telegram\Handlers\StartCommand;
use App\Telegram\Middleware\AdminOnly;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Every incoming update passes the AdminOnly middleware first, then gets
| dispatched to the matching command / callback handler below.
|
*/

$bot->middleware(AdminOnly::class);

$bot->onCommand('start', StartCommand::class)->description('نمایش منوی اصلی');
$bot->onCommand('cancel', CancelCommand::class)->description('لغو عملیات جاری');

$bot->onCallbackQueryData('panels:menu', fn (Nutgram $bot) => PanelsMenu::begin($bot));
$bot->onCallbackQueryData('panels:add', fn (Nutgram $bot) => AddPanelConversation::begin($bot));
$bot->onCallbackQueryData('server:create', fn (Nutgram $bot) => CreateServerConversation::begin($bot));
$bot->onCallbackQueryData('server:list', fn (Nutgram $bot) => ServerListMenu::begin($bot));

$bot->onCallbackQueryData(
    'view_server:{panelId}:{serverId}',
    fn (Nutgram $bot, int $panelId, string $serverId) => ServerListMenu::begin(
        $bot,
        $bot->userId(),
        $bot->chatId(),
        [$panelId, $serverId]
    )
);
