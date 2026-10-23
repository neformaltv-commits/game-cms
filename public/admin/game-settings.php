<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Http\Response;
use App\Settings\SettingsService;
use App\Validation\SettingsValidator;

$user = require_admin($app);
csrf_guard();

$games = $app['games'];
$genres = $app['genres'];
/** @var SettingsService $settings */
$settings = $app['settings'];

$gameId = (string)($_GET['id'] ?? $_POST['game_id'] ?? '');
$game = $gameId !== '' ? $games->find($gameId) : null;
if ($game === null) {
    flash_set('error', 'Игра не найдена.');
    Response::redirect('games.php');
}
$genre = $genres->find((string)$game['genre_id']);
$engine = (string)($genre['engine'] ?? 'static-screen');

// ---------- POST-действия ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back = 'game-settings.php?id=' . urlencode((string)$game['id']) . '&tab=' . urlencode((string)($_POST['tab'] ?? 'controls'));

    if ($action === 'save-controls') {
        $input = [];
        foreach ((array)($_POST['controls'] ?? []) as $act => $keys) {
            if (is_array($keys)) {
                $input[$act] = array_values(array_filter(array_map('trim', $keys), static fn ($k) => $k !== ''));
            }
        }
        $res = $settings->saveControls((string)$game['id'], $input);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Управление сохранено.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }

    if ($action === 'reset-controls') {
        $res = $settings->resetControls((string)$game['id']);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Управление сброшено к дефолтам жанра.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }

    if ($action === 'save-sounds') {
        $rows = [];
        foreach ((array)($_POST['sounds'] ?? []) as $s) {
            if (!is_array($s) || trim((string)($s['event'] ?? '')) === '') {
                continue;
            }
            $end = trim((string)($s['frequency_end_hz'] ?? ''));
            $rows[] = [
                'event' => trim((string)$s['event']),
                'wave' => trim((string)($s['wave'] ?? '')),
                'frequency_hz' => trim((string)($s['frequency_hz'] ?? '')),
                'frequency_end_hz' => $end === '' ? null : $end,
                'duration_ms' => trim((string)($s['duration_ms'] ?? '')),
                'volume' => trim((string)($s['volume'] ?? '')),
                'envelope' => trim((string)($s['envelope'] ?? '')),
            ];
        }
        $res = $settings->saveSounds((string)$game['id'], ['sounds' => $rows]);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Звуки сохранены.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }

    if ($action === 'reset-sounds') {
        $res = $settings->resetSounds((string)$game['id']);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Звуки сброшены к дефолтам жанра.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }

    if ($action === 'save-overrides') {
        $overrides = [];
        $simple = [
            'speed_scale', 'sprite_flicker_intensity', 'input_responsiveness', 'sound_volume',
            'fps', 'animation_speed_scale', 'camera_lerp', 'scroll_speed',
            'hole_duration_ms', 'trap_duration_ms',
        ];
        foreach ($simple as $f) {
            if (array_key_exists($f, $_POST) && trim((string)$_POST[$f]) !== '') {
                $overrides[$f] = is_numeric($_POST[$f]) ? $_POST[$f] + 0 : trim((string)$_POST[$f]);
            }
        }
        foreach (['sprite_flicker', 'sound_enabled', 'show_hud'] as $f) {
            $overrides[$f] = array_key_exists($f, $_POST);
        }
        foreach (['movement_type', 'view_mode', 'camera_mode', 'scroll_direction', 'world_generation'] as $f) {
            if (array_key_exists($f, $_POST) && trim((string)$_POST[$f]) !== '') {
                $overrides[$f] = trim((string)$_POST[$f]);
            }
        }
        $physics = [];
        foreach ((array)($_POST['physics'] ?? []) as $k => $v) {
            if (is_numeric($v) && trim((string)$v) !== '') {
                $physics[$k] = $v + 0;
            }
        }
        if ($physics !== []) {
            $overrides['physics'] = $physics;
        }
        $combat = [];
        foreach ((array)($_POST['combat'] ?? []) as $k => $v) {
            if (is_numeric($v) && trim((string)$v) !== '') {
                $combat[$k] = $v + 0;
            }
        }
        if ($combat !== []) {
            $overrides['combat'] = $combat;
        }
        $player = [];
        foreach (['fuel_capacity', 'fuel_consumption_per_sec'] as $k) {
            if (array_key_exists($k, $_POST) && is_numeric($_POST[$k])) {
                $player[$k] = $_POST[$k] + 0;
            }
        }
        if ($player !== []) {
            $overrides['player'] = $player;
        }
        $res = $settings->saveOverrides((string)$game['id'], ['engine_overrides' => $overrides]);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Настройки движка сохранены.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }

    if ($action === 'reset-overrides') {
        $res = $settings->resetOverridesToGenre((string)$game['id']);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Настройки сброшены: engine_overrides очищен, действуют значения жанра.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }

    if ($action === 'copy-from') {
        $fields = array_values(array_intersect(
            (array)($_POST['fields'] ?? []),
            ['controls', 'sounds', 'engine_overrides'],
        ));
        $res = $settings->copyFrom((string)$game['id'], (string)($_POST['source_id'] ?? ''), $fields);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Настройки скопированы.' : implode(' ', $res['errors']));
        Response::redirect($back);
    }
}

// ---------- Данные для форм ----------
$tab = (string)($_GET['tab'] ?? 'controls');
if (!in_array($tab, ['controls', 'sounds', 'overrides'], true)) {
    $tab = 'controls';
}

$legend = (array)($game['legend'] ?? []);
$overrides = (array)($game['engine_overrides'] ?? []);
$gcfgDefaults = (array)($genre['engine_config'] ?? []);
$controls = (array)($game['controls'] ?? ($gcfgDefaults['default_controls'] ?? []));
$sounds = (array)($game['sounds'] ?? ($gcfgDefaults['default_sounds'] ?? []));
$active = $settings->activeActions($engine, $legend, $overrides);
$required = $active['required'];
$optional = $active['optional'];

$actionNames = [
    'left' => 'Влево', 'right' => 'Вправо', 'up' => 'Вверх', 'down' => 'Вниз',
    'jump' => 'Прыжок', 'fire' => 'Стрельба', 'dig_left' => 'Копать влево',
    'dig_right' => 'Копать вправо', 'action' => 'Действие', 'pause' => 'Пауза', 'start' => 'Старт',
];

$keyLabels = [
    'ArrowLeft' => '←', 'ArrowRight' => '→', 'ArrowUp' => '↑', 'ArrowDown' => '↓',
    'Space' => 'Space', 'Escape' => 'Esc', 'Enter' => 'Enter', 'Tab' => 'Tab',
    'ShiftLeft' => 'L-Shift', 'ShiftRight' => 'R-Shift',
    'ControlLeft' => 'L-Ctrl', 'ControlRight' => 'R-Ctrl',
    'AltLeft' => 'L-Alt', 'AltRight' => 'R-Alt',
    'MetaLeft' => 'L-Meta', 'MetaRight' => 'R-Meta',
    'Backquote' => '`', 'Minus' => '-', 'Equal' => '=',
    'BracketLeft' => '[', 'BracketRight' => ']', 'Backslash' => '\\',
    'Semicolon' => ';', 'Quote' => "'", 'Comma' => ',', 'Period' => '.', 'Slash' => '/',
];
$keyLabel = static function (string $code) use ($keyLabels): string {
    if (isset($keyLabels[$code])) {
        return $keyLabels[$code];
    }
    if (str_starts_with($code, 'Key')) {
        return substr($code, 3);
    }
    if (str_starts_with($code, 'Digit')) {
        return substr($code, 5);
    }
    if (str_starts_with($code, 'Numpad')) {
        return 'Num' . substr($code, 6);
    }
    return $code;
};

// Конфликты клавиш между действиями (для предупреждений, ТЗ §2.1)
$keyUsage = [];
foreach ($controls as $act => $keys) {
    foreach ((array)$keys as $k) {
        $keyUsage[(string)$k][] = (string)$act;
    }
}

admin_header('Настройки игры: ' . $game['title'], 'games');
?>
<h2><?= e($game['title']) ?> — движение/звук/настройки</h2>
<p><a href="games.php">← Все игры</a> | <a href="game-edit.php?id=<?= e($game['id']) ?>">Редактор уровней</a> | <a href="game-combat.php?id=<?= e($game['id']) ?>">Бой и триггеры</a></p>
<p>Движок: <b><?= e($engine) ?></b> (жанр: <?= e((string)($genre['name'] ?? $game['genre_id'])) ?>)</p>

<nav class="tabs">
    <a class="<?= $tab === 'controls' ? 'active' : '' ?>" href="game-settings.php?id=<?= e($game['id']) ?>&amp;tab=controls">Управление</a>
    <a class="<?= $tab === 'sounds' ? 'active' : '' ?>" href="game-settings.php?id=<?= e($game['id']) ?>&amp;tab=sounds">Настройки (звуки)</a>
    <a class="<?= $tab === 'overrides' ? 'active' : '' ?>" href="game-settings.php?id=<?= e($game['id']) ?>&amp;tab=overrides">Настройки движка</a>
    <a href="game-edit.php?id=<?= e($game['id']) ?>">К редактору</a>
</nav>

<?php if ($tab === 'controls'): ?>
<h3>Управление</h3>
<form method="post" action="game-settings.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save-controls">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="controls">
    <table class="table">
        <thead><tr><th>Действие</th><th>Клавиши (KeyboardEvent.code, до 2)</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_merge($required, $optional) as $act): ?>
            <?php $keys = array_values(array_map('strval', (array)($controls[$act] ?? []))); ?>
            <tr>
                <td><?= e($actionNames[$act] ?? $act) ?> <code><?= e($act) ?></code>
                    <?php if (in_array($act, $required, true)): ?><span class="badge badge-required">обязательное</span><?php endif; ?>
                </td>
                <td>
                    <input type="text" name="controls[<?= e($act) ?>][]" value="<?= e($keys[0] ?? '') ?>" size="14" placeholder="<?= e($keys[0] !== '' ? $keyLabel($keys[0]) : 'напр. ArrowLeft') ?>">
                    <input type="text" name="controls[<?= e($act) ?>][]" value="<?= e($keys[1] ?? '') ?>" size="14" placeholder="вторая (необяз.)">
                </td>
                <td>
                    <?php foreach ($keys as $k): ?>
                        <?php if (count($keyUsage[$k] ?? []) > 1): ?>
                            <span class="warn">⚠ «<?= e($keyLabel($k)) ?>» также: <?= e(implode(', ', array_diff($keyUsage[$k], [$act]))) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="submit">Сохранить управление</button>
</form>
<form method="post" action="game-settings.php" onsubmit="return confirm('Текущие назначения будут заменены значениями жанра. Продолжить?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset-controls">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="controls">
    <button type="submit">Сбросить к дефолтам жанра</button>
</form>
<p class="hint">Неактивные действия скрыты из списка, но сохраняются в JSON.</p>

<?php elseif ($tab === 'sounds'): ?>
<h3>Звуки (бипер)</h3>
<form method="post" action="game-settings.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save-sounds">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="sounds">
    <div id="sounds-rows">
    <?php foreach ($sounds as $i => $s): ?>
        <fieldset class="sound-row">
            <legend>Звук <?= (int)$i + 1 ?> <button type="button" class="link-btn danger sound-del">✕</button></legend>
            <label>Событие
                <select name="sounds[<?= (int)$i ?>][event]">
                    <?php foreach (SettingsValidator::SOUND_EVENTS as $ev): ?>
                        <option value="<?= e($ev) ?>" <?= ($s['event'] ?? '') === $ev ? 'selected' : '' ?>><?= e($ev) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Волна
                <select name="sounds[<?= (int)$i ?>][wave]">
                    <?php foreach (SettingsValidator::WAVES as $w): ?>
                        <option value="<?= e($w) ?>" <?= ($s['wave'] ?? '') === $w ? 'selected' : '' ?>><?= e($w) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Частота, Гц <input type="number" name="sounds[<?= (int)$i ?>][frequency_hz]" value="<?= e((string)($s['frequency_hz'] ?? '')) ?>" min="20" max="4000"></label>
            <label>Конец, Гц (пусто = нет) <input type="number" name="sounds[<?= (int)$i ?>][frequency_end_hz]" value="<?= e((string)($s['frequency_end_hz'] ?? '')) ?>" min="20" max="4000"></label>
            <label>Длит., мс <input type="number" name="sounds[<?= (int)$i ?>][duration_ms]" value="<?= e((string)($s['duration_ms'] ?? '')) ?>" min="10" max="1000"></label>
            <label>Громкость <input type="number" step="0.01" name="sounds[<?= (int)$i ?>][volume]" value="<?= e((string)($s['volume'] ?? '')) ?>" min="0" max="0.3"></label>
            <label>Огибающая
                <select name="sounds[<?= (int)$i ?>][envelope]">
                    <?php foreach (SettingsValidator::ENVELOPES as $env): ?>
                        <option value="<?= e($env) ?>" <?= ($s['envelope'] ?? '') === $env ? 'selected' : '' ?>><?= e($env) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="sound-play" data-play='<?= json_encode($s) ?>'>▶</button>
        </fieldset>
    <?php endforeach; ?>
    </div>
    <p>Использовано: <?= count($sounds) ?> из 10.
        <button type="button" id="sound-add">+ добавить</button>
        <template id="sound-tpl">
            <fieldset class="sound-row">
                <legend>Звук <button type="button" class="link-btn danger sound-del">✕</button></legend>
                <label>Событие <select name="sounds[__I__][event]"><?php foreach (SettingsValidator::SOUND_EVENTS as $ev): ?><option value="<?= e($ev) ?>"><?= e($ev) ?></option><?php endforeach; ?></select></label>
                <label>Волна <select name="sounds[__I__][wave]"><?php foreach (SettingsValidator::WAVES as $w): ?><option value="<?= e($w) ?>"><?= e($w) ?></option><?php endforeach; ?></select></label>
                <label>Частота, Гц <input type="number" name="sounds[__I__][frequency_hz]" value="880" min="20" max="4000"></label>
                <label>Конец, Гц <input type="number" name="sounds[__I__][frequency_end_hz]" value="" min="20" max="4000"></label>
                <label>Длит., мс <input type="number" name="sounds[__I__][duration_ms]" value="50" min="10" max="1000"></label>
                <label>Громкость <input type="number" step="0.01" name="sounds[__I__][volume]" value="0.03" min="0" max="0.3"></label>
                <label>Огибающая <select name="sounds[__I__][envelope]"><?php foreach (SettingsValidator::ENVELOPES as $env): ?><option value="<?= e($env) ?>"><?= e($env) ?></option><?php endforeach; ?></select></label>
                <button type="button" class="sound-play" data-play='{"event":"jump","wave":"square","frequency_hz":880,"frequency_end_hz":null,"duration_ms":50,"volume":0.03,"envelope":"decay"}'>▶</button>
            </fieldset>
        </template>
    </p>
    <button type="submit">Сохранить звуки</button>
</form>
<form method="post" action="game-settings.php" onsubmit="return confirm('game.sounds будет заменён на default_sounds жанра. Продолжить?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset-sounds">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="sounds">
    <button type="submit">Сбросить к дефолтам жанра</button>
</form>

<script>
(function () {
    // Динамические строки звуков: индекс перезаписывается при добавлении
    var rows = document.getElementById('sounds-rows');
    var nextIdx = rows.querySelectorAll('.sound-row').length;
    document.getElementById('sound-add').addEventListener('click', function () {
        if (rows.querySelectorAll('.sound-row').length >= 10) { alert('Максимум 10 звуков'); return; }
        var html = document.getElementById('sound-tpl').innerHTML.replaceAll('__I__', String(nextIdx++));
        var div = document.createElement('div');
        div.innerHTML = html;
        rows.appendChild(div.firstChild);
    });
    rows.addEventListener('click', function (ev) {
        if (ev.target.classList.contains('sound-del')) { ev.target.closest('.sound-row').remove(); }
    });
    // Прослушивание через Web Audio API (ТЗ §5.4)
    var ctx = null;
    rows.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.sound-play');
        if (!btn) return;
        var s = JSON.parse(btn.getAttribute('data-play') || '{}');
        ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
        var dur = Math.min(Math.max(Number(s.duration_ms) || 50, 10), 1000) / 1000;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        var f0 = Math.min(Math.max(Number(s.frequency_hz) || 440, 20), 4000);
        osc.type = (s.wave === 'noise') ? 'square' : (s.wave || 'square');
        osc.frequency.setValueAtTime(f0, ctx.currentTime);
        if (s.frequency_end_hz != null) {
            osc.frequency.linearRampToValueAtTime(Math.min(Math.max(Number(s.frequency_end_hz), 20), 4000), ctx.currentTime + dur);
        }
        var vol = Math.min(Math.max(Number(s.volume) || 0.03, 0), 0.3);
        if (s.envelope === 'pluck') {
            gain.gain.setValueAtTime(vol, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + dur);
        } else if (s.envelope === 'linear') {
            gain.gain.setValueAtTime(vol, ctx.currentTime);
            gain.gain.linearRampToValueAtTime(0, ctx.currentTime + dur);
        } else {
            gain.gain.setValueAtTime(vol, ctx.currentTime);
            gain.gain.setTargetAtTime(0.0001, ctx.currentTime, dur / 3);
        }
        osc.connect(gain).connect(ctx.destination);
        osc.start(); osc.stop(ctx.currentTime + dur);
    });
})();
</script>

<?php else: ?>
<h3>Настройки движка (engine_overrides)</h3>
<form method="post" action="game-settings.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save-overrides">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="overrides">
    <fieldset>
        <legend>Общие</legend>
        <?php $gcfg = (array)($genre['engine_config'] ?? []); ?>
        <label>Скорость speed_scale (0.5–2.0) <input type="number" step="0.05" name="speed_scale" value="<?= e((string)($overrides['speed_scale'] ?? '')) ?>" placeholder="<?= e((string)($gcfg['speed_scale'] ?? 1)) ?>"></label>
        <label><input type="checkbox" name="sprite_flicker" value="1" <?= !empty($overrides['sprite_flicker']) ? 'checked' : '' ?>> Мерцание спрайтов</label>
        <label>Интенсивность мерцания (0–1) <input type="number" step="0.01" name="sprite_flicker_intensity" value="<?= e((string)($overrides['sprite_flicker_intensity'] ?? '')) ?>"></label>
        <label>Отзывчивость (0–1) <input type="number" step="0.05" name="input_responsiveness" value="<?= e((string)($overrides['input_responsiveness'] ?? '')) ?>"></label>
        <label>Громкость звука (0–0.3) <input type="number" step="0.01" name="sound_volume" value="<?= e((string)($overrides['sound_volume'] ?? '')) ?>"></label>
        <label><input type="checkbox" name="sound_enabled" value="1" <?= ($overrides['sound_enabled'] ?? true) ? 'checked' : '' ?>> Звук включён</label>
        <label>fps <select name="fps"><option value="">— из жанра —</option><?php foreach ([30, 60] as $f): ?><option value="<?= $f ?>" <?= (int)($overrides['fps'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select></label>
        <label><input type="checkbox" name="show_hud" value="1" <?= ($overrides['show_hud'] ?? true) ? 'checked' : '' ?>> Показывать HUD</label>
        <label>Скорость анимаций (0.5–2.0) <input type="number" step="0.05" name="animation_speed_scale" value="<?= e((string)($overrides['animation_speed_scale'] ?? '')) ?>"></label>
    </fieldset>
    <?php $ph = (array)($overrides['physics'] ?? []); ?>
    <fieldset>
        <legend>Физика</legend>
        <label>gravity (0–2) <input type="number" step="0.05" name="physics[gravity]" value="<?= e((string)($ph['gravity'] ?? '')) ?>"></label>
        <label>move_speed (0.5–10) <input type="number" step="0.1" name="physics[move_speed]" value="<?= e((string)($ph['move_speed'] ?? '')) ?>"></label>
        <label>jump_power (-10–0) <input type="number" step="0.1" name="physics[jump_power]" value="<?= e((string)($ph['jump_power'] ?? '')) ?>"></label>
        <label>climb_speed (0.5–3) <input type="number" step="0.1" name="physics[climb_speed]" value="<?= e((string)($ph['climb_speed'] ?? '')) ?>"></label>
        <label>coyote_time_ms (0–300) <input type="number" name="physics[coyote_time_ms]" value="<?= e((string)($ph['coyote_time_ms'] ?? '')) ?>"></label>
        <label>jump_buffer_ms (0–300) <input type="number" name="physics[jump_buffer_ms]" value="<?= e((string)($ph['jump_buffer_ms'] ?? '')) ?>"></label>
    </fieldset>
    <?php $cb = (array)($overrides['combat'] ?? []); ?>
    <fieldset>
        <legend>Бой (без атак игрока — блок 10)</legend>
        <label>invulnerability_ms (0–5000) <input type="number" name="combat[invulnerability_ms]" value="<?= e((string)($cb['invulnerability_ms'] ?? '')) ?>"></label>
        <label>enemy_contact_damage (0–10) <input type="number" name="combat[enemy_contact_damage]" value="<?= e((string)($cb['enemy_contact_damage'] ?? '')) ?>"></label>
    </fieldset>
    <?php if ($engine === 'static-screen'): ?>
    <fieldset>
        <legend>Специфичное для static-screen</legend>
        <label>hole_duration_ms (500–10000) <input type="number" name="hole_duration_ms" value="<?= e((string)($overrides['hole_duration_ms'] ?? '')) ?>"></label>
        <label>trap_duration_ms (500–5000) <input type="number" name="trap_duration_ms" value="<?= e((string)($overrides['trap_duration_ms'] ?? '')) ?>"></label>
        <label>movement_type <select name="movement_type"><option value="">—</option><?php foreach (['gravity', 'top_down_4dir', 'top_down_8dir', 'top_down_4dir_no_diag'] as $m): ?><option value="<?= e($m) ?>" <?= ($overrides['movement_type'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
        <label>view_mode <select name="view_mode"><option value="">—</option><?php foreach (['side', 'top'] as $v): ?><option value="<?= e($v) ?>" <?= ($overrides['view_mode'] ?? '') === $v ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    </fieldset>
    <?php endif; ?>
    <?php if ($engine === 'horizontal-scroll'): ?>
    <fieldset>
        <legend>Камера (horizontal-scroll)</legend>
        <label>camera_mode <select name="camera_mode"><option value="">—</option><?php foreach (['follow', 'room', 'fixed'] as $m): ?><option value="<?= e($m) ?>" <?= ($overrides['camera_mode'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
        <label>camera_lerp (0.05–0.5) <input type="number" step="0.01" name="camera_lerp" value="<?= e((string)($overrides['camera_lerp'] ?? '')) ?>"></label>
    </fieldset>
    <?php endif; ?>
    <?php if ($engine === 'vertical-scroll'): ?>
    <fieldset>
        <legend>Скролл и топливо (vertical-scroll)</legend>
        <label>scroll_direction <select name="scroll_direction"><option value="">—</option><?php foreach (['up', 'down'] as $d): ?><option value="<?= e($d) ?>" <?= ($overrides['scroll_direction'] ?? '') === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></label>
        <label>scroll_speed (0.5–10) <input type="number" step="0.1" name="scroll_speed" value="<?= e((string)($overrides['scroll_speed'] ?? '')) ?>"></label>
        <label>world_generation <select name="world_generation"><option value="">—</option><?php foreach (['grid', 'procedural'] as $w): ?><option value="<?= e($w) ?>" <?= ($overrides['world_generation'] ?? '') === $w ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></label>
        <?php $pl = (array)($overrides['player'] ?? []); ?>
        <label>fuel_capacity (0–1000) <input type="number" name="fuel_capacity" value="<?= e((string)($pl['fuel_capacity'] ?? '')) ?>"></label>
        <label>fuel_consumption_per_sec (0–20) <input type="number" step="0.1" name="fuel_consumption_per_sec" value="<?= e((string)($pl['fuel_consumption_per_sec'] ?? '')) ?>"></label>
    </fieldset>
    <?php endif; ?>
    <button type="submit">Сохранить настройки движка</button>
</form>
<form method="post" action="game-settings.php" onsubmit="return confirm('engine_overrides будет очищен — начнут действовать значения жанра. Продолжить?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset-overrides">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="overrides">
    <button type="submit">Сбросить к дефолтам жанра</button>
</form>

<h3>Скопировать настройки из…</h3>
<form method="post" action="game-settings.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="copy-from">
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="tab" value="overrides">
    <label>Источник (только игры того же движка)
        <select name="source_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($games->all() as $other):
                if ($other['id'] === $game['id']) { continue; }
                $og = $genres->find((string)$other['genre_id']);
                if (($og['engine'] ?? '') !== $engine) { continue; } ?>
                <option value="<?= e($other['id']) ?>"><?= e($other['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><input type="checkbox" name="fields[]" value="controls" checked> controls</label>
    <label><input type="checkbox" name="fields[]" value="sounds" checked> sounds</label>
    <label><input type="checkbox" name="fields[]" value="engine_overrides" checked> engine_overrides</label>
    <button type="submit">Копировать</button>
</form>
<p class="hint">Блоки «Атаки игрока», «Боевые свойства игрока», «Очки» — доступны на этапе боевой системы (ТЗ блок 10).</p>
<?php endif; ?>
<?php admin_footer(); ?>
