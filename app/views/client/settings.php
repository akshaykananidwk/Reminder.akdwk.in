<?php /** @var array $settings */ /** @var array $numbers */ /** @var array $sessions */ /** @var array $timezones */ /** @var array|null $authUser */ ?>
<div class="grid grid-2">
    <form class="card" method="post" action="<?= e(url('/client/settings/profile')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3><?= t('settings.profile') ?></h3>

        <div class="field"><label for="s-name"><?= t('common.name') ?></label><input id="s-name" name="name" value="<?= e((string) $authUser['name']) ?>" maxlength="120"></div>
        <div class="field"><label for="s-email"><?= t('common.email') ?></label><input id="s-email" name="email" type="email" value="<?= e((string) $authUser['email']) ?>"></div>
        <div class="field"><label for="s-city"><?= t('common.city') ?></label><input id="s-city" name="city" value="<?= e((string) $authUser['city']) ?>"></div>

        <div class="field">
            <label for="s-lang"><?= t('common.language') ?></label>
            <select id="s-lang" name="language">
                <?php foreach (\App\Core\Lang::SUPPORTED as $code): ?>
                    <option value="<?= e($code) ?>"<?= (string) $authUser['language'] === $code ? ' selected' : '' ?>><?= e(\App\Core\Lang::nativeName($code)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="s-tz"><?= t('common.timezone') ?></label>
            <select id="s-tz" name="timezone">
                <?php foreach ($timezones as $zone): ?>
                    <option value="<?= e($zone) ?>"<?= (string) $authUser['timezone'] === $zone ? ' selected' : '' ?>><?= e($zone) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>

    <form class="card" method="post" action="<?= e(url('/client/settings')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3><?= t('settings.preferences') ?></h3>

        <div class="grid grid-2">
            <div class="field"><label for="s-morning"><?= t('settings.morning_brief_time') ?></label><input id="s-morning" name="morning_brief_time" type="time" value="<?= e(substr((string) $settings['morning_brief_time'], 0, 5)) ?>"></div>
            <div class="field"><label for="s-night"><?= t('settings.night_summary_time') ?></label><input id="s-night" name="night_summary_time" type="time" value="<?= e(substr((string) $settings['night_summary_time'], 0, 5)) ?>"></div>
        </div>

        <label class="check"><input type="checkbox" name="morning_brief_enabled" value="1"<?= (int) $settings['morning_brief_enabled'] === 1 ? ' checked' : '' ?>> <?= t('summary.morning_title') ?></label>
        <label class="check"><input type="checkbox" name="night_summary_enabled" value="1"<?= (int) $settings['night_summary_enabled'] === 1 ? ' checked' : '' ?>> <?= t('summary.night_title') ?></label>
        <label class="check"><input type="checkbox" name="dnd_enabled" value="1"<?= (int) $settings['dnd_enabled'] === 1 ? ' checked' : '' ?>> <?= t('settings.dnd') ?></label>

        <div class="grid grid-2">
            <div class="field"><label for="s-dnd1"><?= t('settings.dnd_from') ?></label><input id="s-dnd1" name="dnd_start" type="time" value="<?= e(substr((string) $settings['dnd_start'], 0, 5)) ?>"></div>
            <div class="field"><label for="s-dnd2"><?= t('settings.dnd_to') ?></label><input id="s-dnd2" name="dnd_end" type="time" value="<?= e(substr((string) $settings['dnd_end'], 0, 5)) ?>"></div>
        </div>

        <div class="grid grid-2">
            <div class="field"><label for="s-default"><?= t('settings.default_time') ?></label><input id="s-default" name="default_time" type="time" value="<?= e(substr((string) $settings['default_time'], 0, 5)) ?>"></div>
            <div class="field"><label for="s-snooze"><?= t('settings.default_snooze') ?></label><input id="s-snooze" name="default_snooze_min" type="number" min="1" max="240" value="<?= (int) $settings['default_snooze_min'] ?>"></div>
        </div>

        <div class="grid grid-2">
            <div class="field"><label for="s-attempts"><?= t('settings.call_attempts') ?></label><input id="s-attempts" name="call_attempts" type="number" min="1" max="5" value="<?= (int) $settings['call_attempts'] ?>"></div>
            <div class="field"><label for="s-gap"><?= t('settings.call_gap') ?></label><input id="s-gap" name="call_gap_minutes" type="number" min="1" max="30" value="<?= (int) $settings['call_gap_minutes'] ?>"></div>
        </div>

        <div class="grid grid-2">
            <div class="field"><label for="s-ring"><?= t('settings.ring_seconds') ?></label><input id="s-ring" name="ring_seconds" type="number" min="10" max="120" value="<?= (int) $settings['ring_seconds'] ?>"></div>
            <div class="field">
                <label for="s-ringtone"><?= t('settings.ringtone') ?></label>
                <select id="s-ringtone" name="ringtone">
                    <?php foreach (['flute' => 'Gentle flute', 'bell' => 'Temple bell', 'classic' => 'Classic ring', 'soft' => 'Soft chime'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= (string) $settings['ringtone'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="s-speed"><?= t('settings.tts_speed') ?></label>
            <input id="s-speed" name="tts_speed" type="number" step="0.1" min="0.5" max="1.5" value="<?= e((string) $settings['tts_speed']) ?>">
        </div>

        <label class="check"><input type="checkbox" name="tts_enabled" value="1"<?= (int) $settings['tts_enabled'] === 1 ? ' checked' : '' ?>> 🗣️ Speak the reminder aloud</label>
        <label class="check"><input type="checkbox" name="whatsapp_fallback" value="1"<?= (int) $settings['whatsapp_fallback'] === 1 ? ' checked' : '' ?>> <?= t('settings.whatsapp_fallback') ?></label>
        <label class="check"><input type="checkbox" name="wa_notify_created" value="1"<?= (int) $settings['wa_notify_created'] === 1 ? ' checked' : '' ?>> WhatsApp on create</label>
        <label class="check"><input type="checkbox" name="wa_notify_due" value="1"<?= (int) $settings['wa_notify_due'] === 1 ? ' checked' : '' ?>> WhatsApp when due</label>
        <label class="check"><input type="checkbox" name="wa_notify_done" value="1"<?= (int) $settings['wa_notify_done'] === 1 ? ' checked' : '' ?>> WhatsApp on done</label>
        <label class="check"><input type="checkbox" name="wa_notify_missed" value="1"<?= (int) $settings['wa_notify_missed'] === 1 ? ' checked' : '' ?>> WhatsApp on missed</label>

        <div class="field mt-1">
            <label for="s-holiday"><?= t('settings.holiday_mode') ?></label>
            <input id="s-holiday" name="holiday_mode_until" type="date" value="<?= e((string) $settings['holiday_mode_until']) ?>">
        </div>

        <div class="field">
            <label for="s-theme"><?= t('settings.theme') ?></label>
            <select id="s-theme" name="theme" onchange="KR.setTheme(this.value)">
                <?php foreach (['auto' => __('settings.theme_auto'), 'light' => __('settings.theme_light'), 'dark' => __('settings.theme_dark')] as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= (string) $settings['theme'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn-block"><?= t('common.save') ?></button>
    </form>
</div>

<div class="grid grid-2 mt-2">
    <div class="card">
        <h3><?= t('settings.numbers') ?></h3>
        <ul class="list">
            <?php foreach ($numbers as $number): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title"><?= e(display_phone((string) $number['number'])) ?></div>
                        <div class="meta">
                            <?= e((string) $number['label']) ?>
                            <?php if ((int) $number['is_primary'] === 1): ?><span class="badge"><?= t('settings.primary') ?></span><?php endif; ?>
                            <span class="badge badge-<?= (int) $number['is_verified'] === 1 ? 'success' : 'warning' ?>">
                                <?= (int) $number['is_verified'] === 1 ? __('settings.verified') : __('settings.unverified') ?>
                            </span>
                        </div>
                    </div>
                    <?php if ((int) $number['is_primary'] !== 1): ?>
                        <form method="post" action="<?= e(url('/client/settings/numbers/' . (int) $number['id'] . '/delete')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" data-action="confirm" data-message="<?= t('common.confirm') ?>">🗑</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (count($numbers) < 4): ?>
            <form method="post" action="<?= e(url('/client/settings/numbers')) ?>" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="flex">
                    <input class="grow" name="number" type="tel" inputmode="numeric" placeholder="9978123146" required>
                    <input name="label" placeholder="Label" style="width:120px">
                    <button class="btn btn-sm"><?= t('settings.add_number') ?></button>
                </div>
                <span class="hint">Each number is verified separately with a WhatsApp OTP.</span>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?= t('pin.title') ?></h3>
        <p class="text-sm text-muted"><?= t('pin.intro') ?></p>

        <p>
            <span class="badge badge-<?= !empty($hasAppPin) ? 'success' : 'muted' ?>">
                <?= !empty($hasAppPin) ? t('pin.is_set') : t('pin.not_set') ?>
            </span>
        </p>

        <form method="post" action="<?= e(url('/client/settings/app-pin')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="grid grid-2">
                <div class="field"><label for="s-pin"><?= t('pin.label') ?></label>
                    <input id="s-pin" name="app_pin" type="password" inputmode="numeric" pattern="\d{4}"
                           maxlength="4" minlength="4" required autocomplete="off"></div>
                <div class="field"><label for="s-pin2"><?= t('pin.confirm') ?></label>
                    <input id="s-pin2" name="app_pin_confirm" type="password" inputmode="numeric" pattern="\d{4}"
                           maxlength="4" minlength="4" required autocomplete="off"></div>
            </div>

            <div class="field"><label for="s-pin-pw">Current <?= t('common.password') ?></label>
                <input id="s-pin-pw" name="current_password" type="password" required autocomplete="current-password">
                <span class="hint">Confirms it is really you changing the PIN.</span></div>

            <button class="btn btn-block"><?= t('common.save') ?></button>
        </form>

        <?php if (!empty($hasAppPin)): ?>
            <form method="post" action="<?= e(url('/client/settings/app-pin/remove')) ?>" class="mt-1"
                  onsubmit="return confirm('<?= e(t('pin.remove')) ?>?')">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-outline btn-block"><?= t('pin.remove') ?></button>
            </form>
        <?php endif; ?>

        <p class="hint mt-1"><?= t('pin.security_note') ?></p>
    </div>

    <form class="card" method="post" action="<?= e(url('/client/settings/password')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3><?= t('settings.security') ?></h3>

        <div class="field"><label for="s-cpw">Current <?= t('common.password') ?></label><input id="s-cpw" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="s-npw">New <?= t('common.password') ?></label><input id="s-npw" name="password" type="password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label for="s-npw2">Repeat</label><input id="s-npw2" name="password_confirm" type="password" required minlength="8" autocomplete="new-password"></div>

        <button class="btn btn-block"><?= t('common.save') ?></button>

        <h3 class="mt-3"><?= t('settings.sessions') ?></h3>
        <ul class="list">
            <?php foreach ($sessions as $session): ?>
                <li class="list-item">
                    <div class="body">
                        <div class="title text-sm"><?= e((string) $session['type']) ?> · <?= e((string) $session['ip']) ?></div>
                        <div class="meta"><?= e(str_limit((string) $session['user_agent'], 50)) ?> · <?= e(human_diff((string) $session['last_used_at'])) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </form>
</div>

<div class="card mt-2" style="border-left:4px solid var(--red)">
    <h3><?= t('settings.danger') ?></h3>
    <p class="text-sm text-muted"><?= t('settings.delete_warning') ?></p>

    <div class="flex-wrap">
        <a class="btn btn-ghost" href="<?= e(url('/client/account/export')) ?>">⬇ <?= t('settings.export_data') ?></a>
        <button class="btn btn-danger" data-action="open-modal" data-target="modal-delete"><?= t('settings.delete_account') ?></button>
    </div>
</div>

<div class="modal-backdrop hide" id="modal-delete">
    <form class="modal" method="post" action="<?= e(url('/client/account/delete')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="modal-head">
            <h3><?= t('settings.delete_account') ?></h3>
            <button type="button" class="icon-btn" data-action="close-modal">✕</button>
        </div>
        <p class="text-sm"><?= t('settings.delete_warning') ?></p>
        <div class="field"><label for="d-pw"><?= t('common.password') ?></label><input id="d-pw" name="password" type="password" required></div>
        <div class="field"><label for="d-confirm">Type DELETE</label><input id="d-confirm" name="confirm" required placeholder="DELETE"></div>
        <button class="btn btn-danger btn-block"><?= t('settings.delete_account') ?></button>
    </form>
</div>
