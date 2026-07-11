<?php

namespace App\Telegram\Conversations;

use App\Models\Panel;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class RenamePanelConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $panelId = null;

    public function start(Nutgram $bot, int $panelId): void
    {
        $this->panelId = $panelId;

        $bot->sendMessage('نام جدید این پنل را بفرستید:', reply_markup: $this->backButton());
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            PanelsMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->panelId]);

            return;
        }

        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. یک نام کوتاه‌تر بفرستید:', reply_markup: $this->backButton());

            return;
        }

        Panel::ownedBy($bot->userId())->whereKey($this->panelId)->update(['name' => $name]);

        $this->end();
        PanelsMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->panelId]);
    }
}
