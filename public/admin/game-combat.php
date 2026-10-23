<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Http\Response;
use App\LevelService;
use App\Settings\SettingsService;
use App\Validation\CombatValidator;
use App\Validation\TriggerValidator;

$user = require_admin($app);
csrf_guard();

/**
 * Типовой помощник: <select> эмодзи из легенды (опционально фильтр по ролям).
 * @param array<string,mixed> $legend
 */
function emoji_select(string $name, array $legend, array $roles, string $selected, bool $allowEmpty = false): string
{
    $opts = $allowEmpty ? '<option value="">—</option>' : '<option value="">— выберите —</option>';
    foreach ($legend as $emoji => $el) {
        if (!is_array($el)) { continue; }
        if ($roles !== [] && !in_array((string)($el['role'] ?? ''), $roles, true)) { continue; }
        $sel = ((string)$emoji === $selected) ? ' selected' : '';
        $opts .= sprintf('<option value="%s"%s>%s %s</option>', e((string)$emoji), $sel, e((string)$emoji), e((string)($el['name'] ?? '')));
    }
    return sprintf('<select name="%s">%s</select>', e($name), $opts);
}

$games = $app['games'];
$genres = $app['genres'];
/** @var SettingsService $settings */
$settings = $app['settings'];
/** @var LevelService $levels */
$levels = $app['levels'];

$gameId = (string)($_GET['id'] ?? $_POST['game_id'] ?? '');
$game = $gameId !== '' ? $games->find($gameId) : null;
if ($game === null) {
    flash_set('error', 'Игра не найдена.');
    Response::redirect('games.php');
}
$genre = $genres->find((string)$game['genre_id']);
$engine = (string)($genre['engine'] ?? 'static-screen');
$legend = $games->effectiveLegend($game);
$overrides = (array)($game['engine_overrides'] ?? []);
$controls = (array)($game['controls'] ?? []);

// ---------- POST-действия ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $tab = (string)($_POST['tab'] ?? 'attacks');
    $back = 'game-combat.php?id=' . urlencode((string)$game['id']) . '&tab=' . urlencode($tab);

    if ($action === 'save-attacks') {
        $res = $settings->savePlayerAttacks((string)$game['id'], (array)($_POST['player_attacks'] ?? []));
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Атаки игрока сохранены.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }
    if ($action === 'save-player') {
        $res = $settings->savePlayer((string)$game['id'], (array)($_POST['player'] ?? []));
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Параметры игрока сохранены.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }
    if ($action === 'save-scoring') {
        $res = $settings->saveScoring((string)$game['id'], (array)($_POST['scoring'] ?? []));
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Очки сохранены.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }
    if ($action === 'trigger-save') {
        $levelId = (string)($_POST['level_id'] ?? '');
        $triggerId = (string)($_POST['trigger_id'] ?? '');
        $input = (array)($_POST['trigger'] ?? []);
        $input['enabled'] = isset($input['enabled']);
        $input['once'] = isset($input['once']);
        $res = $triggerId === ''
            ? $levels->createTrigger((string)$game['id'], $levelId, $input)
            : $levels->updateTrigger((string)$game['id'], $levelId, $triggerId, $input);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Триггер сохранён.' : implode(' ', $res['errors']));
        Response::redirect('game-combat.php?id=' . urlencode((string)$game['id']) . '&tab=triggers&level=' . urlencode($levelId));
    }
    if ($action === 'trigger-delete') {
        $levelId = (string)($_POST['level_id'] ?? '');
        $errors = $levels->deleteTrigger((string)$game['id'], $levelId, (string)($_POST['trigger_id'] ?? ''));
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Триггер удалён.' : implode(' ', $errors));
        Response::redirect('game-combat.php?id=' . urlencode((string)$game['id']) . '&tab=triggers&level=' . urlencode($levelId));
    }
    if ($action === 'winlose-save') {
        $levelId = (string)($_POST['level_id'] ?? '');
        $errors = $levels->saveWinLoseConditions((string)$game['id'], $levelId, [
            'victory_conditions' => (array)($_POST['victory_conditions'] ?? []),
            'defeat_conditions'  => (array)($_POST['defeat_conditions'] ?? []),
        ]);
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Условия победы/поражения сохранены.' : implode(' ', $errors));
        Response::redirect('game-combat.php?id=' . urlencode((string)$game['id']) . '&tab=triggers&level=' . urlencode($levelId));
    }
}

// ---------- GET: контекст ----------
$tabs = ['attacks', 'player', 'scoring', 'triggers'];
$tab = (string)($_GET['tab'] ?? 'attacks');
if (!in_array($tab, $tabs, true)) {
    $tab = 'attacks';
}

$pa = (array)($overrides['player_attacks'] ?? []);
$pl = (array)($overrides['player'] ?? []);
$sc = (array)($overrides['scoring'] ?? []);

$levelList = array_values((array)$game['levels']);
$currentLevelId = (string)($_GET['level'] ?? ($levelList[0]['id'] ?? ''));
$current = null;
foreach ($levelList as $lv) {
    if (($lv['id'] ?? '') === $currentLevelId) {
        $current = $lv;
        break;
    }
}
$params = (array)($current['params'] ?? []);
$triggers = array_values(array_filter((array)($params['triggers'] ?? []), 'is_array'));
$victory = array_values(array_filter((array)($params['victory_conditions'] ?? []), 'is_array'));
$defeat = array_values(array_filter((array)($params['defeat_conditions'] ?? []), 'is_array'));

// Триггер для редактирования (?edit=trig-002) — иначе пустая форма создания
$editId = (string)($_GET['edit'] ?? '');
$editTrigger = null;
foreach ($triggers as $t) {
    if ((string)($t['id'] ?? '') === $editId) {
        $editTrigger = $t;
        break;
    }
}
$formTrigger = $editTrigger ?? ['name' => '', 'enabled' => true, 'once' => true, 'when' => ['type' => 'kill_count'], 'then' => []];
$formWhen = (array)($formTrigger['when'] ?? []);
$formThen = array_values(array_filter((array)($formTrigger['then'] ?? []), 'is_array'));

$pickupIds = [];
foreach ($legend as $emoji => $el) {
    if (is_array($el) && ($el['role'] ?? '') === 'pickup' && ($el['pickup_id'] ?? '') !== '') {
        $pickupIds[(string)$el['pickup_id']] = (string)$emoji;
    }
}

admin_header('Бой и триггеры: ' . (string)$game['title'], 'games');
?>
<p><a href="games.php">← Все игры</a> | <b><?= e($game['title']) ?></b>
   (движок: <?= e($engine) ?>)
   | <a href="game-edit.php?id=<?= e($game['id']) ?>">Редактор уровней</a>
   | <a href="game-settings.php?id=<?= e($game['id']) ?>">Управление и настройки</a></p>

<nav class="tabs">
    <a class="<?= $tab === 'attacks' ? 'active' : '' ?>" href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=attacks">Атаки игрока</a>
    <a class="<?= $tab === 'player' ? 'active' : '' ?>" href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=player">Игрок</a>
    <a class="<?= $tab === 'scoring' ? 'active' : '' ?>" href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=scoring">Очки</a>
    <a class="<?= $tab === 'triggers' ? 'active' : '' ?>" href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=triggers">Триггеры</a>
</nav>

<?php if ($tab === 'attacks'): ?>
<?php $m = (array)($pa['melee'] ?? []); $r = (array)($pa['ranged'] ?? []);
      $s = (array)($pa['stomp'] ?? []); $sp = (array)($pa['special'] ?? []);
      $ammo = (array)($r['ammo'] ?? []); $charge = (array)($r['charge'] ?? []); ?>
<form method="post" action="game-combat.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save-attacks">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="attacks">

    <fieldset>
        <legend>Blaster / melee</legend>
        <label><input type="checkbox" name="player_attacks[melee][enabled]" value="1" <?= ($m['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>damage (0–10) <input type="number" name="player_attacks[melee][damage]" value="<?= e((string)($m['damage'] ?? '')) ?>"></label>
        <label>range_tiles (1–8) <input type="number" name="player_attacks[melee][range_tiles]" value="<?= e((string)($m['range_tiles'] ?? '')) ?>"></label>
        <label>cooldown_ms (100–5000) <input type="number" name="player_attacks[melee][cooldown_ms]" value="<?= e((string)($m['cooldown_ms'] ?? '')) ?>"></label>
        <label>shape <select name="player_attacks[melee][shape]"><option value="">—</option><?php foreach (['forward', 'omni', 'aimed'] as $o): ?><option value="<?= e($o) ?>" <?= ($m['shape'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>knockback_tiles (0–3) <input type="number" name="player_attacks[melee][knockback_tiles]" value="<?= e((string)($m['knockback_tiles'] ?? '')) ?>"></label>
        <label>stun_ms (0–3000) <input type="number" name="player_attacks[melee][stun_ms]" value="<?= e((string)($m['stun_ms'] ?? '')) ?>"></label>
    </fieldset>

    <fieldset>
        <legend>Дальнобой (ranged)</legend>
        <label><input type="checkbox" name="player_attacks[ranged][enabled]" value="1" <?= ($r['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>projectile_emoji <?php echo emoji_select('player_attacks[ranged][projectile_emoji]', $legend, ['projectile'], (string)($r['projectile_emoji'] ?? '')); ?></label>
        <label>cooldown_ms (100–5000) <input type="number" name="player_attacks[ranged][cooldown_ms]" value="<?= e((string)($r['cooldown_ms'] ?? '')) ?>"></label>
        <label>direction <select name="player_attacks[ranged][direction]"><option value="">—</option><?php foreach (['facing', 'aimed', 'up', 'down', 'left', 'right'] as $o): ?><option value="<?= e($o) ?>" <?= ($r['direction'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>ammo.type <select name="player_attacks[ranged][ammo][type]"><?php foreach (CombatValidator::AMMO_TYPES as $o): ?><option value="<?= e($o) ?>" <?= ($ammo['type'] ?? 'infinite') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>ammo.start (0–999) <input type="number" name="player_attacks[ranged][ammo][start]" value="<?= e((string)($ammo['start'] ?? '')) ?>"></label>
        <label>ammo.max (1–999) <input type="number" name="player_attacks[ranged][ammo][max]" value="<?= e((string)($ammo['max'] ?? '')) ?>"></label>
        <label><input type="checkbox" name="player_attacks[ranged][charge][enabled]" value="1" <?= ($charge['enabled'] ?? false) ? 'checked' : '' ?>> charge включён</label>
        <label>charge_ms (200–3000) <input type="number" name="player_attacks[ranged][charge][charge_ms]" value="<?= e((string)($charge['charge_ms'] ?? '')) ?>"></label>
        <label>damage_multiplier (1–5) <input type="number" step="0.1" name="player_attacks[ranged][charge][damage_multiplier]" value="<?= e((string)($charge['damage_multiplier'] ?? '')) ?>"></label>
    </fieldset>

    <fieldset>
        <legend>Prymer (stomp)</legend>
        <label><input type="checkbox" name="player_attacks[stomp][enabled]" value="1" <?= ($s['enabled'] ?? false) ? 'checked' : '' ?>> включён (только движки с гравитацией)</label>
        <label>damage (0–10) <input type="number" name="player_attacks[stomp][damage]" value="<?= e((string)($s['damage'] ?? '')) ?>"></label>
        <label>stun_ms (0–3000) <input type="number" name="player_attacks[stomp][stun_ms]" value="<?= e((string)($s['stun_ms'] ?? '')) ?>"></label>
        <label>morph_to <?php echo emoji_select('player_attacks[stomp][morph_to]', $legend, [], (string)($s['morph_to'] ?? ''), true); ?></label>
        <label>bounce_power (0–2) <input type="number" step="0.1" name="player_attacks[stomp][bounce_power]" value="<?= e((string)($s['bounce_power'] ?? '')) ?>"></label>
    </fieldset>

    <fieldset>
        <legend>Спецприём (special)</legend>
        <label><input type="checkbox" name="player_attacks[special][enabled]" value="1" <?= ($sp['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>type <select name="player_attacks[special][type]"><option value="">—</option><?php foreach (CombatValidator::SPECIAL_TYPES as $o): ?><option value="<?= e($o) ?>" <?= ($sp['type'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>action <select name="player_attacks[special][action]"><?php foreach (\App\Validation\SettingsValidator::ACTIONS as $o): ?><option value="<?= e($o) ?>" <?= ($sp['action'] ?? 'action') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>recharge_by_pickup <select name="player_attacks[special][recharge_by_pickup]"><option value="">—</option><?php foreach ($pickupIds as $pid => $em): ?><option value="<?= e($pid) ?>" <?= ($sp['recharge_by_pickup'] ?? '') === $pid ? 'selected' : '' ?>><?= e($pid) ?> (<?= e($em) ?>)</option><?php endforeach; ?></select></label>
        <label>freeze_duration_ms (500–10000) <input type="number" name="player_attacks[special][freeze_duration_ms]" value="<?= e((string)($sp['freeze_duration_ms'] ?? '')) ?>"></label>
        <label>shield_duration_ms (500–10000) <input type="number" name="player_attacks[special][shield_duration_ms]" value="<?= e((string)($sp['shield_duration_ms'] ?? '')) ?>"></label>
    </fieldset>

    <button type="submit">Сохранить атаки</button>
</form>

<?php elseif ($tab === 'player'): ?>
<?php $dodge = (array)($pl['dodge'] ?? []); $parry = (array)($pl['parry'] ?? []); ?>
<form method="post" action="game-combat.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save-player">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="player">
    <fieldset>
        <legend>Живучесть</legend>
        <label>lives (1–9) <input type="number" name="player[lives]" value="<?= e((string)($pl['lives'] ?? '')) ?>"></label>
        <label>health_max (1–100) <input type="number" name="player[health_max]" value="<?= e((string)($pl['health_max'] ?? '')) ?>"></label>
        <label>invulnerability_ms (0–5000) <input type="number" name="player[invulnerability_ms]" value="<?= e((string)($pl['invulnerability_ms'] ?? '')) ?>"></label>
    </fieldset>
    <fieldset>
        <legend>Dodge</legend>
        <label><input type="checkbox" name="player[dodge][enabled]" value="1" <?= ($dodge['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>action <select name="player[dodge][action]"><?php foreach (\App\Validation\SettingsValidator::ACTIONS as $o): ?><option value="<?= e($o) ?>" <?= ($dodge['action'] ?? 'fire') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>duration_ms (50–1000) <input type="number" name="player[dodge][duration_ms]" value="<?= e((string)($dodge['duration_ms'] ?? '')) ?>"></label>
        <label>invulnerability_ms (0–2000) <input type="number" name="player[dodge][invulnerability_ms]" value="<?= e((string)($dodge['invulnerability_ms'] ?? '')) ?>"></label>
        <label>cooldown_ms (100–5000) <input type="number" name="player[dodge][cooldown_ms]" value="<?= e((string)($dodge['cooldown_ms'] ?? '')) ?>"></label>
    </fieldset>
    <fieldset>
        <legend>Parry</legend>
        <label><input type="checkbox" name="player[parry][enabled]" value="1" <?= ($parry['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>action <select name="player[parry][action]"><?php foreach (\App\Validation\SettingsValidator::ACTIONS as $o): ?><option value="<?= e($o) ?>" <?= ($parry['action'] ?? 'action') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>window_ms (50–500) <input type="number" name="player[parry][window_ms]" value="<?= e((string)($parry['window_ms'] ?? '')) ?>"></label>
    </fieldset>
    <button type="submit">Сохранить игрока</button>
</form>

<?php elseif ($tab === 'scoring'): ?>
<?php $combo = (array)($sc['combo'] ?? []); $tb = (array)($sc['time_bonus'] ?? []); ?>
<form method="post" action="game-combat.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save-scoring">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="scoring">
    <fieldset>
        <legend>Базовые очки</legend>
        <label>on_enemy_kill (0–100000) <input type="number" name="scoring[on_enemy_kill]" value="<?= e((string)($sc['on_enemy_kill'] ?? '')) ?>"></label>
        <label>on_pickup (0–1000) <input type="number" name="scoring[on_pickup]" value="<?= e((string)($sc['on_pickup'] ?? '')) ?>"></label>
    </fieldset>
    <fieldset>
        <legend>Combo</legend>
        <label><input type="checkbox" name="scoring[combo][enabled]" value="1" <?= ($combo['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>window_ms (500–10000) <input type="number" name="scoring[combo][window_ms]" value="<?= e((string)($combo['window_ms'] ?? '')) ?>"></label>
        <label>multiplier_per_hit (1–3) <input type="number" step="0.1" name="scoring[combo][multiplier_per_hit]" value="<?= e((string)($combo['multiplier_per_hit'] ?? '')) ?>"></label>
        <label>max_multiplier (1–16) <input type="number" step="0.1" name="scoring[combo][max_multiplier]" value="<?= e((string)($combo['max_multiplier'] ?? '')) ?>"></label>
        <label>base (1–100000) <input type="number" name="scoring[combo][base]" value="<?= e((string)($combo['base'] ?? '')) ?>"></label>
    </fieldset>
    <fieldset>
        <legend>Time bonus</legend>
        <label><input type="checkbox" name="scoring[time_bonus][enabled]" value="1" <?= ($tb['enabled'] ?? false) ? 'checked' : '' ?>> включён</label>
        <label>per_second_remaining (0–1000) <input type="number" name="scoring[time_bonus][per_second_remaining]" value="<?= e((string)($tb['per_second_remaining'] ?? '')) ?>"></label>
    </fieldset>
    <button type="submit">Сохранить очки</button>
</form>

<?php else: /* triggers */ ?>
<h2>Триггеры уровня</h2>
<?php if ($levelList === []): ?>
<p>Сначала создайте уровень в <a href="game-edit.php?id=<?= e($game['id']) ?>">редакторе</a>.</p>
<?php else: ?>
<ul class="level-tabs">
<?php foreach ($levelList as $lv): ?>
    <li><a href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=triggers&amp;level=<?= e($lv['id']) ?>"
           class="<?= ((string)$lv['id'] === $currentLevelId) ? 'active' : '' ?>"><?= e((string)$lv['name']) ?></a></li>
<?php endforeach; ?>
</ul>

<?php if ($current !== null): ?>
<table class="table">
    <thead><tr><th>ID</th><th>Название</th><th>Когда</th><th>То (типы)</th><th>Вкл</th><th>Once</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($triggers as $t): ?>
        <tr>
            <td><?= e((string)($t['id'] ?? '')) ?></td>
            <td><?= e((string)($t['name'] ?? '')) ?></td>
            <td><?= e((string)(is_array($t['when'] ?? null) ? ($t['when']['type'] ?? '?') : '?')) ?></td>
            <td><?= e(implode(', ', array_column(array_map(static fn ($a) => (array)$a, (array)($t['then'] ?? [])), 'type'))) ?></td>
            <td><?= !empty($t['enabled']) ? 'да' : 'нет' ?></td>
            <td><?= !empty($t['once']) ? 'да' : 'нет' ?></td>
            <td>
                <a href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=triggers&amp;level=<?= e($currentLevelId) ?>&amp;edit=<?= e((string)($t['id'] ?? '')) ?>">редактировать</a>
                <form method="post" action="game-combat.php" style="display:inline" onsubmit="return confirm('Удалить триггер?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="trigger-delete">
                    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
                    <input type="hidden" name="level_id" value="<?= e($currentLevelId) ?>">
                    <input type="hidden" name="trigger_id" value="<?= e((string)($t['id'] ?? '')) ?>">
                    <button type="submit">удалить</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($triggers === []): ?><tr><td colspan="7">Триггеров нет.</td></tr><?php endif; ?>
    </tbody>
</table>

<h3><?= $editTrigger !== null ? 'Редактирование ' . e($editId) : 'Новый триггер' ?></h3>
<form method="post" action="game-combat.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="trigger-save">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="level_id" value="<?= e($currentLevelId) ?>">
    <input type="hidden" name="trigger_id" value="<?= e($editId) ?>">
    <label>Название <input type="text" name="trigger[name]" value="<?= e((string)($formTrigger['name'] ?? '')) ?>"></label>
    <label><input type="checkbox" name="trigger[enabled]" value="1" <?= !empty($formTrigger['enabled']) ? 'checked' : '' ?>> включён</label>
    <label><input type="checkbox" name="trigger[once]" value="1" <?= !empty($formTrigger['once']) ? 'checked' : '' ?>> однократный</label>

    <fieldset>
        <legend>Когда (условие)</legend>
        <label>when.type <select name="trigger[when][type]"><?php foreach (TriggerValidator::WHEN_TYPES as $o): ?><option value="<?= e($o) ?>" <?= ($formWhen['type'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>target_emoji <?php echo emoji_select('trigger[when][target_emoji]', $legend, ['mob'], (string)($formWhen['target_emoji'] ?? ''), true); ?></label>
        <label>count <input type="number" name="trigger[when][count]" value="<?= e((string)($formWhen['count'] ?? '')) ?>"></label>
        <label>pickup_id <select name="trigger[when][pickup_id]"><option value="">—</option><?php foreach ($pickupIds as $pid => $em): ?><option value="<?= e($pid) ?>" <?= ($formWhen['pickup_id'] ?? '') === $pid ? 'selected' : '' ?>><?= e($pid) ?> (<?= e($em) ?>)</option><?php endforeach; ?></select></label>
        <label>value <input type="number" name="trigger[when][value]" value="<?= e((string)($formWhen['value'] ?? '')) ?>"></label>
        <label>compare <select name="trigger[when][compare]"><option value="">—</option><?php foreach (TriggerValidator::COMPARES as $o): ?><option value="<?= e($o) ?>" <?= ($formWhen['compare'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
        <label>seconds <input type="number" name="trigger[when][seconds]" value="<?= e((string)($formWhen['seconds'] ?? '')) ?>"></label>
        <label>flag_name <input type="text" name="trigger[when][flag_name]" value="<?= e((string)($formWhen['flag_name'] ?? '')) ?>"></label>
        <label>emoji <?php echo emoji_select('trigger[when][emoji]', $legend, [], (string)($formWhen['emoji'] ?? ''), true); ?></label>
        <span>player_in_zone: col <input type="number" size="2" name="trigger[when][col]" value="<?= e((string)($formWhen['col'] ?? '')) ?>"> row <input type="number" size="2" name="trigger[when][row]" value="<?= e((string)($formWhen['row'] ?? '')) ?>"> width <input type="number" size="2" name="trigger[when][width]" value="<?= e((string)($formWhen['width'] ?? '')) ?>"> height <input type="number" size="2" name="trigger[when][height]" value="<?= e((string)($formWhen['height'] ?? '')) ?>"></span>
    </fieldset>

    <fieldset>
        <legend>То (действия, по строке на действие)</legend>
        <table class="table" id="then-table">
            <thead><tr><th>type</th><th>emoji</th><th>text/event/flag/stat</th><th>col</th><th>row</th><th>count</th><th>amount/delta</th><th>duration_ms</th><th></th></tr></thead>
            <tbody>
            <?php for ($i = 0; $i < max(1, count($formThen) + 1); $i++): $a = (array)($formThen[$i] ?? []); ?>
            <tr>
                <td><select name="trigger[then][<?= $i ?>][type]"><option value="">—</option><?php foreach (TriggerValidator::THEN_TYPES as $o): ?><option value="<?= e($o) ?>" <?= ($a['type'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></td>
                <td><?php echo emoji_select("trigger[then][$i][emoji]", $legend, [], (string)($a['emoji'] ?? ''), true); ?></td>
                <td><input type="text" name="trigger[then][<?= $i ?>][text]" value="<?= e((string)($a['text'] ?? '')) ?>" placeholder="text"><input type="text" name="trigger[then][<?= $i ?>][event]" value="<?= e((string)($a['event'] ?? '')) ?>" placeholder="event"><input type="text" name="trigger[then][<?= $i ?>][flag_name]" value="<?= e((string)($a['flag_name'] ?? '')) ?>" placeholder="flag_name"><input type="text" name="trigger[then][<?= $i ?>][stat]" value="<?= e((string)($a['stat'] ?? '')) ?>" placeholder="stat"></td>
                <td><input type="number" size="2" name="trigger[then][<?= $i ?>][col]" value="<?= e((string)($a['col'] ?? '')) ?>"></td>
                <td><input type="number" size="2" name="trigger[then][<?= $i ?>][row]" value="<?= e((string)($a['row'] ?? '')) ?>"></td>
                <td><input type="number" size="3" name="trigger[then][<?= $i ?>][count]" value="<?= e((string)($a['count'] ?? '')) ?>"></td>
                <td><input type="number" size="3" name="trigger[then][<?= $i ?>][amount]" value="<?= e((string)($a['amount'] ?? '')) ?>"><input type="number" size="3" name="trigger[then][<?= $i ?>][delta]" value="<?= e((string)($a['delta'] ?? '')) ?>"></td>
                <td><input type="number" size="4" name="trigger[then][<?= $i ?>][duration_ms]" value="<?= e((string)($a['duration_ms'] ?? '')) ?>"></td>
                <td></td>
            </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </fieldset>
    <button type="submit"><?= $editTrigger !== null ? 'Сохранить изменения' : 'Создать триггер' ?></button>
    <?php if ($editTrigger !== null): ?><a href="game-combat.php?id=<?= e($game['id']) ?>&amp;tab=triggers&amp;level=<?= e($currentLevelId) ?>">отменить</a><?php endif; ?>
</form>

<h3>Условия победы / поражения уровня</h3>
<form method="post" action="game-combat.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="winlose-save">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="level_id" value="<?= e($currentLevelId) ?>">
    <fieldset>
        <legend>Победа (victory_conditions)</legend>
        <?php for ($i = 0; $i < max(1, count($victory) + 1); $i++): $vc = (array)($victory[$i] ?? []); ?>
        <div>
            <select name="victory_conditions[<?= $i ?>][type]"><option value="">—</option><?php foreach (TriggerValidator::VICTORY_TYPES as $o): ?><option value="<?= e($o) ?>" <?= ($vc['type'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <input type="number" size="4" name="victory_conditions[<?= $i ?>][value]" value="<?= e((string)($vc['value'] ?? '')) ?>" placeholder="value">
            <input type="number" size="4" name="victory_conditions[<?= $i ?>][seconds]" value="<?= e((string)($vc['seconds'] ?? '')) ?>" placeholder="seconds">
            <input type="text" size="8" name="victory_conditions[<?= $i ?>][flag_name]" value="<?= e((string)($vc['flag_name'] ?? '')) ?>" placeholder="flag_name">
        </div>
        <?php endfor; ?>
    </fieldset>
    <fieldset>
        <legend>Поражение (defeat_conditions)</legend>
        <?php for ($i = 0; $i < max(1, count($defeat) + 1); $i++): $dc = (array)($defeat[$i] ?? []); ?>
        <div>
            <select name="defeat_conditions[<?= $i ?>][type]"><option value="">—</option><?php foreach (TriggerValidator::DEFEAT_TYPES as $o): ?><option value="<?= e($o) ?>" <?= ($dc['type'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <input type="number" size="4" name="defeat_conditions[<?= $i ?>][seconds]" value="<?= e((string)($dc['seconds'] ?? '')) ?>" placeholder="seconds">
            <input type="text" size="8" name="defeat_conditions[<?= $i ?>][flag_name]" value="<?= e((string)($dc['flag_name'] ?? '')) ?>" placeholder="flag_name">
        </div>
        <?php endfor; ?>
    </fieldset>
    <button type="submit">Сохранить условия</button>
</form>
<?php endif; /* current level */ ?>
<?php endif; /* levelList */ ?>
<?php endif; /* tab triggers */ ?>
<?php admin_footer();

