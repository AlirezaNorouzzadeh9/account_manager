<?php

namespace App\Telegram\Conversations;

use App\Models\BotUser;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Owner-only: grants a Telegram user access to the bot by their numeric id
 * (see UserManagementMenu). Gated here too, not just at the menu, so a
 * crafted deep-link straight into this conversation can't bypass the check.
 */
class AddBotUserConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?string $telegramId = null;

    public function start(Nutgram $bot): void
    {
        if (! BotUser::isOwner($bot->userId())) {
            $bot->sendMessage('⛔️ این بخش فقط برای مالکین ربات است.');
            $this->end();

            return;
        }

        $bot->sendMessage('آیدی عددی تلگرام کاربر مورد نظر را بفرستید:', reply_markup: $this->backButton());
        $this->next('receiveTelegramId');
    }

    public function receiveTelegramId(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            UserManagementMenu::begin($bot);

            return;
        }

        $id = trim((string) $bot->message()?->text);

        if (! ctype_digit($id)) {
            $bot->sendMessage('آیدی نامعتبر است. یک عدد صحیح بفرستید:', reply_markup: $this->backButton());

            return;
        }

        if (BotUser::isOwner($id)) {
            $bot->sendMessage('این کاربر از قبل به عنوان مالک ربات دسترسی کامل دارد. آیدی دیگری بفرستید:', reply_markup: $this->backButton());

            return;
        }

        if (BotUser::where('telegram_id', $id)->exists()) {
            $bot->sendMessage('این کاربر از قبل به ربات دسترسی دارد. آیدی دیگری بفرستید:', reply_markup: $this->backButton());

            return;
        }

        $this->telegramId = $id;
        $bot->sendMessage(
            'یک برچسب (اختیاری، مثلاً اسم کاربر) بفرستید، یا برای رد شدن - بفرستید:',
            reply_markup: $this->backButton()
        );
        $this->next('receiveLabel');
    }

    public function receiveLabel(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            UserManagementMenu::begin($bot);

            return;
        }

        $label = trim((string) $bot->message()?->text);

        if ($label === '' || $label === '-') {
            $label = null;
        } elseif (mb_strlen($label) > 50) {
            $bot->sendMessage('برچسب خیلی طولانی است. دوباره ارسال کنید (یا - برای رد شدن):', reply_markup: $this->backButton());

            return;
        }

        $user = BotUser::create([
            'telegram_id' => $this->telegramId,
            'label' => $label,
            'added_by' => $bot->userId(),
        ]);

        $this->end();
        UserManagementMenu::begin($bot, $bot->userId(), $bot->chatId(), [$user->id, true]);
    }
}
