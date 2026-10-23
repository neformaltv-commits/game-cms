#!/bin/bash
# HTTP smoke для блока 10: боевая система и триггеры.
# Проверки: логин → GET game-combat.php (все вкладки) → сохранение attacks →
# создание 2 триггеров → условия победы/поражения → ошибка валидации не сохраняется →
# аудит level.trigger_* → удаление триггера → 403 без CSRF → cleanup.
set -u
BASE="http://127.0.0.1:8099"
ROOT="/workspace"
PASS='Adm1n-Str0ng!Pass'
CJ=$(mktemp)
trap 'rm -f "$CJ"' EXIT
fail=0
check() { if [ "$2" = "$3" ]; then echo "PASS: $1 ($3)"; else echo "FAIL: $1 (ожидалось $2, получено $3)"; fail=1; fi; }
page_token() { curl -s -b "$CJ" -c "$CJ" "$1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

cd "$ROOT" || exit 1
rm -f data/throttle.json

echo "== 1. Логин =="
T=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/login.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" --data-urlencode "username=admin" --data-urlencode "password=$PASS" --data-urlencode "_csrf=$T" "$BASE/admin/login.php")
check "POST login -> 302" 302 "$CODE"

echo "== 2. Тестовая игра + уровень (как в smoke-stage4) =="
GENRE_ID=$(php -r '$d=json_decode(file_get_contents("data/genres.json"),true);foreach($d as $g){if(($g["engine"]??"")==="static-screen"){echo $g["id"];exit;}}')
T=$(page_token "$BASE/admin/games.php")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=create" \
  --data-urlencode "title=Smoke Block10 Game" \
  --data-urlencode "description=Smoke test game for block 10 combat and triggers" \
  --data-urlencode "genre_id=$GENRE_ID" \
  --data-urlencode "_csrf=$T" "$BASE/admin/games.php")
check "POST create game -> 302" 302 "$RES"
GAME_ID=$(php -r "\$fs=glob('data/games/*.json');foreach(\$fs as \$f){\$d=json_decode(file_get_contents(\$f),true);if((\$d['title']??'')==='Smoke Block10 Game'){echo \$d['id'];exit;}}")
if [ -z "$GAME_ID" ]; then echo "FAIL: игра не найдена"; exit 1; fi
T=$(page_token "$BASE/admin/game-edit.php?id=$GAME_ID")
curl -s -o /dev/null -b "$CJ" -c "$CJ" \
  --data-urlencode "action=level-create" --data-urlencode "game_id=$GAME_ID" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-edit.php?id=$GAME_ID"
LEVEL_ID=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['levels'][0]['id'] ?? '';")
if [ -n "$LEVEL_ID" ]; then echo "PASS: уровень создан ($LEVEL_ID)"; else echo "FAIL: уровень не создан"; exit 1; fi

echo "== 3. Страница «Бой и триггеры»: все вкладки 200 =="
for TAB in attacks player scoring triggers; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" "$BASE/admin/game-combat.php?id=$GAME_ID&tab=$TAB")
  check "GET tab=$TAB -> 200" 200 "$CODE"
done
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/game-combat.php?id=$GAME_ID")
check "GET game-combat без сессии -> 302" 302 "$CODE"

echo "== 4. Сохранение атак игрока (melee включён) =="
# назначаем клавишу действию fire (жанр loderunner её не имеет по умолчанию) — иначе melee запрещён валидатором
# форма с пустыми значениями обязательных действий (см. smoke-stage5): сохранённые controls остаются валидными
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID&tab=controls")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-controls" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=controls" \
  --data-urlencode "controls[fire][]=Space" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST save-controls (fire=Space) -> 302" 302 "$RES"
FIREKEY=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo implode(',', \$g['controls']['fire'] ?? []);")
check "controls.fire = Space записан" "Space" "$FIREKEY"
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=attacks")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-attacks" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=attacks" \
  --data-urlencode "player_attacks[melee][enabled]=1" \
  --data-urlencode "player_attacks[melee][damage]=2" \
  --data-urlencode "player_attacks[melee][range_tiles]=3" \
  --data-urlencode "player_attacks[melee][cooldown_ms]=400" \
  --data-urlencode "player_attacks[melee][shape]=forward" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
check "POST save-attacks -> 302" 302 "$RES"
MELEE=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); \$pa=\$g['engine_overrides']['player_attacks']['melee']??[]; echo (\$pa['enabled']??false)?'on':'off';")
check "melee.enabled записан" "on" "$MELEE"

echo "== 5. Ошибка валидации: melee без клавиши fire — не сохраняется =="
GAME_BEFORE=$(md5sum "data/games/$GAME_ID.json" | cut -d' ' -f1)
# снимаем клавишу с fire (сохраняем controls без fire) — melee тогда отклоняется валидатором
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID&tab=controls")
curl -s -o /dev/null -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-controls" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=controls" \
  --data-urlencode "controls[left][]=ArrowLeft" --data-urlencode "controls[right][]=ArrowRight" \
  --data-urlencode "controls[up][]=ArrowUp" --data-urlencode "controls[down][]=ArrowDown" \
  --data-urlencode "controls[dig_left][]=KeyA" --data-urlencode "controls[dig_right][]=KeyD" \
  --data-urlencode "controls[pause][]=KeyP" --data-urlencode "controls[start][]=Enter" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-settings.php"
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=attacks")
BODY=$(curl -s -L -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-attacks" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=attacks" \
  --data-urlencode "player_attacks[melee][enabled]=1" \
  --data-urlencode "player_attacks[melee][damage]=9" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
ERRSHOWN=$(echo "$BODY" | grep -c "должна быть назначена клавиша")
check "ошибка про клавишу fire показана" 1 "$([ "$ERRSHOWN" -gt 0 ] && echo 1 || echo 0)"
DAMAGE=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['engine_overrides']['player_attacks']['melee']['damage'] ?? '';")
check "damage=9 НЕ перезаписал сохранённое (=2)" "2" "$DAMAGE"

echo "== 6. Создание двух триггеров =="
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=triggers&level=$LEVEL_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=trigger-save" --data-urlencode "game_id=$GAME_ID" --data-urlencode "level_id=$LEVEL_ID" \
  --data-urlencode "trigger_id=" \
  --data-urlencode "trigger[name]=First" \
  --data-urlencode "trigger[enabled]=1" --data-urlencode "trigger[once]=1" \
  --data-urlencode "trigger[when][type]=player_touch_emoji" \
  --data-urlencode "trigger[when][emoji]=💰" \
  --data-urlencode "trigger[then][0][type]=open_exit" \
  --data-urlencode "trigger[then][1][type]=" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
check "POST trigger-save #1 -> 302" 302 "$RES"
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=triggers&level=$LEVEL_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=trigger-save" --data-urlencode "game_id=$GAME_ID" --data-urlencode "level_id=$LEVEL_ID" \
  --data-urlencode "trigger_id=" \
  --data-urlencode "trigger[name]=Second" \
  --data-urlencode "trigger[enabled]=1" --data-urlencode "trigger[once]=1" \
  --data-urlencode "trigger[when][type]=pickup_count" \
  --data-urlencode "trigger[when][pickup_id]=gold" \
  --data-urlencode "trigger[when][compare]=gte" \
  --data-urlencode "trigger[when][count]=2" \
  --data-urlencode "trigger[then][0][type]=show_message" \
  --data-urlencode "trigger[then][0][text]=Hello" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
check "POST trigger-save #2 -> 302" 302 "$RES"
NT=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo count(\$g['levels'][0]['params']['triggers'] ?? []);")
check "в уровне 2 триггера" "2" "$NT"
IDS=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); foreach(\$g['levels'][0]['params']['triggers'] as \$t) echo \$t['id'],' ';")
case "$IDS" in trig-001*trig-002*) echo "PASS: id последовательны ($IDS)";; *) echo "FAIL: id триггеров: $IDS"; fail=1;; esac

echo "== 7. Аудит level.trigger_create =="
AUD=$(grep -c "level.trigger_create" data/audit.log 2>/dev/null || echo 0)
check "записи level.trigger_create в аудите (>0)" 1 "$([ "$AUD" -gt 0 ] && echo 1 || echo 0)"

echo "== 8. Условия победы/поражения =="
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=triggers&level=$LEVEL_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=winlose-save" --data-urlencode "game_id=$GAME_ID" --data-urlencode "level_id=$LEVEL_ID" \
  --data-urlencode "victory_conditions[0][type]=reach_exit" \
  --data-urlencode "victory_conditions[1][type]=" \
  --data-urlencode "defeat_conditions[0][type]=lives_zero" \
  --data-urlencode "defeat_conditions[1][type]=" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
check "POST winlose-save -> 302" 302 "$RES"
VC=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); \$p=\$g['levels'][0]['params']??[]; echo implode(',',array_column(\$p['victory_conditions']??[],'type')),'|',implode(',',array_column(\$p['defeat_conditions']??[],'type'));")
check "victory=[reach_exit], defeat=[lives_zero]" "reach_exit|lives_zero" "$VC"

echo "== 9. Ошибка валидации условий: неизвестный тип не сохраняется =="
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=triggers&level=$LEVEL_ID")
BODY=$(curl -s -L -b "$CJ" -c "$CJ" \
  --data-urlencode "action=winlose-save" --data-urlencode "game_id=$GAME_ID" --data-urlencode "level_id=$LEVEL_ID" \
  --data-urlencode "victory_conditions[0][type]=impossible_mode" \
  --data-urlencode "victory_conditions[1][type]=" \
  --data-urlencode "defeat_conditions[0][type]=" \
  --data-urlencode "defeat_conditions[1][type]=" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
ERRSHOWN=$(echo "$BODY" | grep -c "victory_conditions")
check "ошибка валидации условий показана" 1 "$([ "$ERRSHOWN" -gt 0 ] && echo 1 || echo 0)"
VC2=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); \$p=\$g['levels'][0]['params']??[]; echo implode(',',array_column(\$p['victory_conditions']??[],'type'));")
check "условия победы не изменились" "reach_exit" "$VC2"

echo "== 10. Удаление триггера =="
T=$(page_token "$BASE/admin/game-combat.php?id=$GAME_ID&tab=triggers&level=$LEVEL_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=trigger-delete" --data-urlencode "game_id=$GAME_ID" --data-urlencode "level_id=$LEVEL_ID" \
  --data-urlencode "trigger_id=trig-002" \
  --data-urlencode "_csrf=$T" "$BASE/admin/game-combat.php")
check "POST trigger-delete -> 302" 302 "$RES"
NT=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo count(\$g['levels'][0]['params']['triggers'] ?? []);")
check "остался 1 триггер" "1" "$NT"

echo "== 11. 403 без CSRF =="
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=trigger-save" --data-urlencode "game_id=$GAME_ID" --data-urlencode "level_id=$LEVEL_ID" \
  "$BASE/admin/game-combat.php")
check "POST game-combat без CSRF -> 403" 403 "$RES"
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-attacks" --data-urlencode "game_id=$GAME_ID" \
  "$BASE/admin/game-combat.php")
check "POST save-attacks без CSRF -> 403" 403 "$RES"

echo "== 12. Cleanup: удалить тестовую игру =="
T=$(page_token "$BASE/admin/games.php")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=delete" --data-urlencode "id=$GAME_ID" \
  --data-urlencode "_csrf=$T" "$BASE/admin/games.php")
check "POST delete game -> 302" 302 "$RES"
EXISTS=$([ -f "data/games/$GAME_ID.json" ] && echo yes || echo no)
check "файл игры удалён" "no" "$EXISTS"

echo ""
if [ "$fail" = "0" ]; then echo "SMOKE BLOCK10: ALL PASS"; else echo "SMOKE BLOCK10: FAILURES PRESENT"; exit 1; fi
