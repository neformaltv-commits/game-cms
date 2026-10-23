#!/bin/bash
# HTTP smoke для этапа 5: настройки игры (управление, звуки, настройки движка).
# Проверки: логин → GET game-settings.php → сохранение controls → ошибка валидации
# (данные не изменились) → сброс к дефолтам жанра → звуки → overrides → копирование → 403 без CSRF.
set -u
BASE="http://127.0.0.1:8095"
ROOT="/workspace"
PASS='Adm1n-Str0ng!Pass'
CJ=$(mktemp)
trap 'rm -f "$CJ"' EXIT
fail=0
check() { if [ "$2" = "$3" ]; then echo "PASS: $1 ($3)"; else echo "FAIL: $1 (ожидалось $2, получено $3)"; fail=1; fi; }
token() { curl -s -b "$1" -c "$1" "$BASE/admin/login.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
page_token() { curl -s -b "$CJ" -c "$CJ" "$1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

cd "$ROOT" || exit 1
rm -f data/throttle.json

echo "== 1. Логин =="
T=$(token "$CJ")
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" --data-urlencode "username=admin" --data-urlencode "password=$PASS" --data-urlencode "_csrf=$T" "$BASE/admin/login.php")
check "POST login -> 302" 302 "$CODE"

echo "== 2. Создание тестовой игры =="
GENRE_ID=$(php -r '$d=json_decode(file_get_contents("data/genres.json"),true);echo $d[0]["id"];')
T=$(page_token "$BASE/admin/games.php")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=create" \
  --data-urlencode "title=Smoke Stage5 Game" \
  --data-urlencode "description=Smoke test game for stage 5 settings checks" \
  --data-urlencode "genre_id=$GENRE_ID" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/games.php")
check "POST create -> 302" 302 "$RES"
GAME_ID=$(php -r '$fs=glob("data/games/*.json");foreach($fs as $f){$d=json_decode(file_get_contents($f),true);if(($d["title"]??"")==="Smoke Stage5 Game"){echo $d["id"];exit;}}')
if [ -z "$GAME_ID" ]; then echo "FAIL: игра не найдена в data/games"; exit 1; fi
echo "PASS: игра создана ($GAME_ID)"

echo "== 3. GET game-settings.php (три вкладки) =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" "$BASE/admin/game-settings.php?id=$GAME_ID")
check "GET game-settings -> 200" 200 "$CODE"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/game-settings.php?id=$GAME_ID")
check "GET game-settings без сессии -> 302" 302 "$CODE"
BODY=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/game-settings.php?id=$GAME_ID&tab=sounds")
if echo "$BODY" | grep -q "frequency_hz\|Частота"; then echo "PASS: вкладка звуков рендерится"; else echo "FAIL: вкладка звуков не отрендерилась"; fail=1; fi

echo "== 4. Сохранение controls =="
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-controls" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=controls" \
  --data-urlencode "controls[left][]=KeyH" \
  --data-urlencode "controls[right][]=ArrowRight" --data-urlencode "controls[right][]=KeyL" \
  --data-urlencode "controls[up][]=ArrowUp" --data-urlencode "controls[down][]=ArrowDown" \
  --data-urlencode "controls[pause][]=Escape" --data-urlencode "controls[start][]=Enter" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST save-controls -> 302" 302 "$RES"
LEFT=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo implode(',', \$g['controls']['left'] ?? []);")
check "controls.left = KeyH" "KeyH" "$LEFT"

echo "== 5. Ошибка валидации: обязательное left без клавиши — данные не меняются =="
BEFORE=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo json_encode(\$g['controls'], JSON_UNESCAPED_UNICODE);")
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-controls" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=controls" \
  --data-urlencode "controls[left]=" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST save-controls (пустой left) -> 302 (flash error)" 302 "$RES"
AFTER=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo json_encode(\$g['controls'], JSON_UNESCAPED_UNICODE);")
check "controls не изменились после ошибки" "$BEFORE" "$AFTER"

echo "== 6. Сброс controls к дефолтам жанра =="
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=reset-controls" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=controls" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST reset-controls -> 302" 302 "$RES"
LEFT=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo implode(',', \$g['controls']['left'] ?? []);")
check "controls.left сброшен к ArrowLeft,KeyA" "ArrowLeft,KeyA" "$LEFT"

echo "== 7. Сохранение звука =="
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID&tab=sounds")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-sounds" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=sounds" \
  --data-urlencode "sounds[0][event]=pickup" --data-urlencode "sounds[0][wave]=square" \
  --data-urlencode "sounds[0][frequency_hz]=880" --data-urlencode "sounds[0][duration_ms]=120" \
  --data-urlencode "sounds[0][volume]=0.2" --data-urlencode "sounds[0][envelope]=decay" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST save-sounds -> 302" 302 "$RES"
CNT=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo count(\$g['sounds'] ?? []);")
check "sounds записан (1 запись)" "1" "$CNT"

echo "== 8. Ошибка валидации звука: недопустимое событие =="
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID&tab=sounds")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-sounds" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=sounds" \
  --data-urlencode "sounds[0][event]=not_a_real_event" --data-urlencode "sounds[0][wave]=square" \
  --data-urlencode "sounds[0][frequency_hz]=880" --data-urlencode "sounds[0][duration_ms]=120" \
  --data-urlencode "sounds[0][volume]=0.2" --data-urlencode "sounds[0][envelope]=decay" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST save-sounds (bad event) -> 302" 302 "$RES"
EV=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['sounds'][0]['event'] ?? '';" )
check "старый звук сохранён (pickup), бэд не записан" "pickup" "$EV"

echo "== 9. engine_overrides: speed_scale + отклонение вне диапазона =="
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID&tab=overrides")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-overrides" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=overrides" \
  --data-urlencode "speed_scale=1.5" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST save-overrides (valid) -> 302" 302 "$RES"
SS=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['engine_overrides']['speed_scale'] ?? '';")
check "speed_scale = 1.5" "1.5" "$SS"
T=$(page_token "$BASE/admin/game-settings.php?id=$GAME_ID&tab=overrides")
curl -s -o /dev/null -b "$CJ" -c "$CJ" \
  --data-urlencode "action=save-overrides" --data-urlencode "game_id=$GAME_ID" --data-urlencode "tab=overrides" \
  --data-urlencode "speed_scale=3.0" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$GAME_ID"
SS=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['engine_overrides']['speed_scale'] ?? '';")
check "speed_scale остался 1.5 (значение 3.0 отклонено)" "1.5" "$SS"

echo "== 10. Копирование настроек между играми того же движка =="
T=$(page_token "$BASE/admin/games.php")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=create" --data-urlencode "title=Smoke Stage5 Target" \
  --data-urlencode "description=Smoke test target game for copy check stage 5" \
  --data-urlencode "genre_id=$GENRE_ID" --data-urlencode "_csrf=$T" "$BASE/admin/games.php")
check "POST create target -> 302" 302 "$RES"
TARGET_ID=$(php -r '$fs=glob("data/games/*.json");foreach($fs as $f){$d=json_decode(file_get_contents($f),true);if(($d["title"]??"")==="Smoke Stage5 Target"){echo $d["id"];exit;}}')
if [ -z "$TARGET_ID" ]; then echo "FAIL: целевая игра не создана"; fail=1; else echo "PASS: целевая игра ($TARGET_ID)"; fi
T=$(page_token "$BASE/admin/game-settings.php?id=$TARGET_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=copy-from" --data-urlencode "game_id=$TARGET_ID" \
  --data-urlencode "source_id=$GAME_ID" --data-urlencode "fields[]=controls" --data-urlencode "fields[]=sounds" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-settings.php?id=$TARGET_ID")
check "POST copy-from -> 302" 302 "$RES"
SS2=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$TARGET_ID.json\"),true)); echo \$g['engine_overrides']['speed_scale'] ?? 'none';")
check "overrides НЕ скопированы (были выбраны только controls/sounds)" "none" "$SS2"
EV2=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$TARGET_ID.json\"),true)); echo \$g['sounds'][0]['event'] ?? '';" )
check "sounds скопированы (pickup)" "pickup" "$EV2"

echo "== 11. 403 без CSRF =="
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=reset-controls" --data-urlencode "game_id=$GAME_ID" \
  "$BASE/admin/game-settings.php?id=$GAME_ID")
check "POST без CSRF -> 403" 403 "$RES"

echo "== 12. Удаление тестовых игр =="
for ID in "$GAME_ID" "$TARGET_ID"; do
  T=$(page_token "$BASE/admin/games.php")
  curl -s -o /dev/null -b "$CJ" -c "$CJ" \
    --data-urlencode "action=delete" --data-urlencode "id=$ID" \
    --data-urlencode "_csrf=$T" "$BASE/admin/games.php"
  EXISTS=$([ -f "data/games/$ID.json" ] && echo yes || echo no)
  check "файл игры $ID удалён" "no" "$EXISTS"
done

echo ""
if [ "$fail" = "0" ]; then echo "SMOKE STAGE5: ALL PASS"; else echo "SMOKE STAGE5: FAILURES PRESENT"; exit 1; fi
