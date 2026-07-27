<?php

use App\Core\Lang;

/** @var array|null $reminder */
/** @var array $categories */
/** @var array $contacts */
/** @var string $tz */

$isEdit = $reminder !== null;
$action = $isEdit ? url('/client/reminders/' . (int) $reminder['id']) : url('/client/reminders');

$recurrence = $isEdit ? json_field($reminder['recurrence'], ['freq' => 'none']) : ['freq' => 'none', 'interval' => 1, 'by_day' => [], 'by_month_day' => null];
$alerts = $isEdit ? json_field($reminder['advance_alerts'], []) : [];

$dueDate = $isEdit ? to_user_time((string) $reminder['start_at'], 'Y-m-d', $tz) : date('Y-m-d');
$dueTime = $isEdit ? to_user_time((string) $reminder['start_at'], 'H:i', $tz) : '09:00';
$locale = Lang::locale();
?>

<form class="card" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="field">
        <label for="f-title"><?= t('reminder.title') ?></label>
        <input id="f-title" name="title" required maxlength="255" autofocus
               value="<?= $isEdit ? e($reminder['title']) : old('title') ?>"
               placeholder="<?= t('dashboard.quick_add_hint') ?>">
    </div>

    <div class="field">
        <label for="f-description"><?= t('reminder.note') ?></label>
        <textarea id="f-description" name="description" maxlength="5000"><?= $isEdit ? e($reminder['description']) : '' ?></textarea>
    </div>

    <div class="grid grid-2">
        <div class="field">
            <label for="f-date"><?= t('reminder.due_date') ?></label>
            <input id="f-date" name="due_date" type="date" required value="<?= e($dueDate) ?>">
        </div>
        <div class="field">
            <label for="f-time"><?= t('reminder.due_time') ?></label>
            <input id="f-time" name="due_time" type="time" value="<?= e($dueTime) ?>">
        </div>
    </div>

    <label class="check">
        <input type="checkbox" name="all_day" value="1"<?= $isEdit && (int) $reminder['all_day'] === 1 ? ' checked' : '' ?>>
        <?= t('reminder.all_day') ?>
    </label>

    <div class="grid grid-2 mt-2">
        <div class="field">
            <label for="f-type"><?= t('reminder.type') ?></label>
            <select id="f-type" name="type">
                <?php foreach (['task', 'payment', 'call', 'meeting', 'medicine', 'birthday', 'bill', 'other'] as $option): ?>
                    <option value="<?= e($option) ?>"<?= $isEdit && $reminder['type'] === $option ? ' selected' : '' ?>><?= t('types.' . $option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-priority"><?= t('reminder.priority') ?></label>
            <select id="f-priority" name="priority">
                <?php foreach (['low', 'normal', 'high', 'urgent'] as $option): ?>
                    <option value="<?= e($option) ?>"<?= $isEdit && $reminder['priority'] === $option ? ' selected' : '' ?>><?= t('priority.' . $option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label for="f-category"><?= t('reminder.category') ?></label>
        <select id="f-category" name="category_id">
            <option value=""><?= t('common.none') ?></option>
            <?php foreach ($categories as $category):
                $name = match ($locale) {
                    'gu' => $category['name_gu'],
                    'hi' => $category['name_hi'],
                    default => $category['name_en'],
                };
            ?>
                <option value="<?= (int) $category['id'] ?>"<?= $isEdit && (int) $reminder['category_id'] === (int) $category['id'] ? ' selected' : '' ?>>
                    <?= e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <hr>

    <h3><?= t('reminder.repeat') ?></h3>

    <div class="grid grid-2">
        <div class="field">
            <label for="f-repeat"><?= t('reminder.repeat') ?></label>
            <select id="f-repeat" name="repeat_freq" onchange="document.getElementById('repeat-extra').classList.toggle('hide', this.value === 'none')">
                <?php foreach (['none', 'daily', 'weekly', 'monthly', 'yearly'] as $option): ?>
                    <option value="<?= e($option) ?>"<?= ($recurrence['freq'] ?? 'none') === $option ? ' selected' : '' ?>>
                        <?= t('recurrence.' . ($option === 'none' ? 'none' : $option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-interval">Every N</label>
            <input id="f-interval" name="repeat_interval" type="number" min="1" max="365" value="<?= (int) ($recurrence['interval'] ?? 1) ?>">
        </div>
    </div>

    <div id="repeat-extra" class="<?= ($recurrence['freq'] ?? 'none') === 'none' ? 'hide' : '' ?>">
        <div class="field">
            <label>Weekdays</label>
            <div class="flex-wrap">
                <?php foreach (['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as $day): ?>
                    <label class="check" style="min-height:40px">
                        <input type="checkbox" name="repeat_days[]" value="<?= $day ?>"
                            <?= in_array($day, (array) ($recurrence['by_day'] ?? []), true) ? ' checked' : '' ?>>
                        <?= t('weekday.' . strtolower($day)) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="f-monthday">Day of month</label>
                <input id="f-monthday" name="repeat_month_day" type="number" min="1" max="31" value="<?= e((string) ($recurrence['by_month_day'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="f-count"><?= t('reminder.after_n') ?></label>
                <input id="f-count" name="repeat_count" type="number" min="1" max="999" value="<?= e((string) ($recurrence['count'] ?? '')) ?>">
            </div>
        </div>

        <div class="field">
            <label for="f-ends"><?= t('reminder.ends') ?> (<?= t('reminder.on_date') ?>)</label>
            <input id="f-ends" name="ends_on" type="date" value="<?= $isEdit && $reminder['end_at'] ? e(to_user_time((string) $reminder['end_at'], 'Y-m-d', $tz)) : '' ?>">
        </div>
    </div>

    <hr>

    <h3><?= t('settings.notifications') ?></h3>

    <label class="check">
        <input type="checkbox" name="call_reminder" value="1"<?= !$isEdit || (int) $reminder['call_reminder'] === 1 ? ' checked' : '' ?>>
        📞 <?= t('reminder.call_me') ?>
    </label>

    <div class="field mt-1">
        <label><?= t('reminder.advance_alerts') ?></label>
        <div class="flex-wrap">
            <?php foreach ([1440 => '1 day', 60 => '1 hour', 30 => '30 min', 10 => '10 min'] as $minutes => $label): ?>
                <label class="check" style="min-height:40px">
                    <input type="checkbox" name="advance_alerts[]" value="<?= $minutes ?>"
                        <?= in_array($minutes, array_map('intval', $alerts), true) ? ' checked' : '' ?>>
                    <?= e($label) ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="field">
        <label for="f-snooze"><?= t('reminder.snooze_default') ?> (min)</label>
        <input id="f-snooze" name="snooze_default_min" type="number" min="1" max="1440" value="<?= $isEdit ? (int) $reminder['snooze_default_min'] : 5 ?>">
    </div>

    <hr>

    <h3><?= t('types.payment') ?> / <?= t('reminder.person') ?></h3>

    <div class="grid grid-2">
        <div class="field">
            <label for="f-amount"><?= t('reminder.amount') ?></label>
            <input id="f-amount" name="amount" type="number" step="0.01" min="0" value="<?= $isEdit && $reminder['amount'] !== null ? e((string) $reminder['amount']) : '' ?>">
        </div>
        <div class="field">
            <label for="f-person"><?= t('reminder.person') ?></label>
            <input id="f-person" name="person_name" maxlength="120" value="<?= $isEdit ? e((string) $reminder['person_name']) : '' ?>">
        </div>
    </div>

    <div class="grid grid-2">
        <div class="field">
            <label for="f-location"><?= t('reminder.location') ?></label>
            <input id="f-location" name="location" maxlength="255" value="<?= $isEdit ? e((string) $reminder['location']) : '' ?>">
        </div>
        <div class="field">
            <label for="f-tags"><?= t('reminder.tags') ?> <span class="hint">comma separated</span></label>
            <input id="f-tags" name="tags" maxlength="255">
        </div>
    </div>

    <?php if ($contacts !== []): ?>
        <div class="field">
            <label for="f-assign"><?= t('reminder.assignee') ?></label>
            <select id="f-assign" name="assigned_to">
                <option value=""><?= t('common.none') ?></option>
                <?php foreach ($contacts as $contact): ?>
                    <?php if (!empty($contact['linked_user_id'])): ?>
                        <option value="<?= (int) $contact['linked_user_id'] ?>"<?= $isEdit && (int) $reminder['assigned_to'] === (int) $contact['linked_user_id'] ? ' selected' : '' ?>>
                            <?= e($contact['name']) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <span class="hint">Only contacts who are Krishna Reminder users can be assigned.</span>
        </div>
    <?php endif; ?>

    <div class="field">
        <label for="f-attachment"><?= t('reminder.attachment') ?></label>
        <input id="f-attachment" name="attachment" type="file" accept="image/*,application/pdf,audio/*">
        <span class="hint">Photo, PDF or voice note, up to 10 MB.</span>
    </div>

    <div class="flex mt-2">
        <button class="btn grow"><?= t('common.save') ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/client/reminders')) ?>"><?= t('common.cancel') ?></a>
    </div>
</form>

<?php if ($isEdit): ?>
    <form class="mt-2" method="post" action="<?= e(url('/client/reminders/' . (int) $reminder['id'] . '/delete')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="btn btn-danger btn-block" data-action="confirm" data-message="<?= t('reminder.confirm_delete') ?>">
            🗑 <?= t('common.delete') ?>
        </button>
    </form>
<?php endif; ?>
