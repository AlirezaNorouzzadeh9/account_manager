<?php

namespace App\Jobs;

use App\Services\Pasarguard\PasarguardNodeInstaller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * The manual counterpart to UpdateWireguardsJob: instead of looking up a
 * server's IP/root password from a stored ServerSecret (which only exists
 * for panel-provisioned servers), this connects to an admin-supplied
 * host/username/password directly — for servers that were never created
 * through this bot's provider integrations.
 */
class ConnectServerWireguardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public function __construct(
        protected string $host,
        protected string $username,
        protected string $password,
        protected string $wireguardPrivateKey,
        protected int $chatId,
        protected ?int $ownerId = null,
    ) {
    }

    public function handle(Nutgram $bot, PasarguardNodeInstaller $installer): void
    {
        try {
            $result = $installer->updateWireguards($this->host, $this->username, $this->password, $this->wireguardPrivateKey, $this->ownerId);
        } catch (RuntimeException $e) {
            $bot->sendMessage("❌ اتصال به سرور {$this->host} ناموفق بود:\n{$e->getMessage()}", chat_id: $this->chatId);

            return;
        } catch (Throwable $e) {
            $bot->sendMessage("❌ بروزرسانی وایرگاردهای سرور {$this->host} ناموفق بود:\n{$e->getMessage()}", chat_id: $this->chatId);

            return;
        }

        $message = ($result['success'] ? '✅ ' : '❌ ')."سرور {$this->host}: ".$result['message'];

        if (! $result['success'] && $result['log'] !== '') {
            $message .= "\n\n".mb_substr($result['log'], -1500);
        }

        $bot->sendMessage($message, chat_id: $this->chatId);
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "❌ بروزرسانی وایرگاردهای سرور {$this->host} با خطای غیرمنتظره متوقف شد.",
            chat_id: $this->chatId,
        );
    }
}
