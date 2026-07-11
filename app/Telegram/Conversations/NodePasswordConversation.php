<?php

namespace App\Telegram\Conversations;

use App\Jobs\InstallPasarguardNodeJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\Panel;
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

    /**
     * The WireGuard profile chosen right before we found out the password
     * was missing/wrong: 'none', a numeric profile id, or null when this
     * conversation was reached via the "retry" button (in which case the
     * server's previously saved profile is left untouched).
     */
    protected ?string $profile = null;

    public function start(Nutgram $bot, int $panelId, string $serverId, string $action = 'install', ?string $profile = null): void
    {
        if (! Panel::ownedBy($bot->userId())->whereKey($panelId)->exists()) {
            $bot->sendMessage('⛔️ این پنل متعلق به شما نیست.');
            $this->end();

            return;
        }

        $this->panelId = $panelId;
        $this->serverId = $serverId;
        $this->action = $action;
        $this->profile = $profile;

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

        $attributes = ['root_password' => $password];

        if ($this->profile !== null) {
            $attributes['wireguard_profile_id'] = $this->profile === 'none' ? null : (int) $this->profile;
        }

        ServerSecret::updateOrCreate(
            ['panel_id' => $this->panelId, 'provider_server_id' => $this->serverId],
            $attributes
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
