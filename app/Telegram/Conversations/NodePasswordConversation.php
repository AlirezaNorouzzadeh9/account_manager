<?php

namespace App\Telegram\Conversations;

use App\Jobs\InstallPasarguardNodeJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\ServerSecret;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Asks for the server's current root password (because none was stored yet,
 * or the stored one turned out to be wrong/changed), saves it, then (re)tries
 * whichever action asked for it ("install" the node, or just "update_wireguards").
 */
class NodePasswordConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $panelId = null;
    protected ?string $serverId = null;
    protected string $action = 'install';

    public function start(Nutgram $bot, int $panelId, string $serverId, string $action = 'install'): void
    {
        $this->panelId = $panelId;
        $this->serverId = $serverId;
        $this->action = $action;

        $bot->sendMessage('پسورد فعلی روت این سرور را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receivePassword');
    }

    public function receivePassword(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            ServerListMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->panelId, $this->serverId]);
            return;
        }

        $password = trim((string) $bot->message()?->text);

        if ($password === '') {
            $bot->sendMessage('پسورد نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        ServerSecret::updateOrCreate(
            ['panel_id' => $this->panelId, 'provider_server_id' => $this->serverId],
            ['root_password' => $password]
        );

        if ($this->action === 'update_wireguards') {
            UpdateWireguardsJob::dispatch($this->panelId, $this->serverId, $bot->chatId());
            $bot->sendMessage('⏳ درخواست بروزرسانی وایرگاردها ثبت شد.');
        } else {
            InstallPasarguardNodeJob::dispatch($this->panelId, $this->serverId, $bot->chatId());
            $bot->sendMessage('⏳ درخواست نود کردن سرور ثبت شد. به محض اتمام نتیجه را برایتان ارسال می‌کنم.');
        }

        $this->end();
    }
}
