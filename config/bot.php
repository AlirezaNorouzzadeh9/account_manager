<?php

return [
    // Telegram numeric user IDs allowed to use this bot
    'admins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_TELEGRAM_IDS', ''))
    ))),
];
