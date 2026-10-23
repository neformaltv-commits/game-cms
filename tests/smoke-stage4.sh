#!/bin/bash
# HTTP smoke для этапа 4: игры и редактор уровней.
# Проверки: логин → создание игры → создание уровня → сохранение сетки →
# ошибки валидации → 403 без CSRF.
set -u
BASE="http://127.0.0.1:8099"
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

echo "== 2. Страница игр =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" "$BASE/admin/games.php")
check "GET games.php -> 200" 200 "$CODE"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/games.php")
check "GET games.php без сессии -> редирект на login" 302 "$CODE"

echo "== 3. Создание игры =="
GENRE_ID=$(php -r '$d=json_decode(file_get_contents("data/genres.json"),true);echo $d[0]["id"];')
T=$(page_token "$BASE/admin/games.php")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=create" \
  --data-urlencode "title=Smoke Stage4 Game" \
  --data-urlencode "description=Smoke test game for stage 4 validation checks" \
  --data-urlencode "genre_id=$GENRE_ID" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/games.php")
check "POST create -> 302" 302 "$RES"
GAME_ID=$(php -r '$fs=glob("data/games/*.json");foreach($fs as $f){$d=json_decode(file_get_contents($f),true);if(($d["title"]??"")==="Smoke Stage4 Game"){echo $d["id"];exit;}}')
if [ -z "$GAME_ID" ]; then echo "FAIL: игра не найдена в data/games"; exit 1; fi
echo "PASS: игра создана ($GAME_ID)"

echo "== 4. Страница редактирования (редактор сетки) =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" "$BASE/admin/game-edit.php?id=$GAME_ID")
check "GET game-edit.php?id -> 200" 200 "$CODE"

echo "== 5. Создание уровня через POST =="
T=$(page_token "$BASE/admin/game-edit.php?id=$GAME_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=level-create" --data-urlencode "game_id=$GAME_ID" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-edit.php?id=$GAME_ID")
check "POST level-create -> 302" 302 "$RES"
LEVEL_ID=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['levels'][0]['id'] ?? '';")
if [ "$LEVEL_ID" != "" ]; then echo "PASS: уровень создан ($LEVEL_ID)"; else echo "FAIL: уровень не создан"; fail=1; fi

echo "== 6. Сохранение валидной сетки (24 строки x 32 колонки, '.' + старт/выход из легенды жанра) =="
GRID=$(php -r '
$rows = [];
for ($r = 0; $r < 24; $r++) {
    $cells = array_fill(0, 32, ".");
    if ($r === 20) { $cells[2] = "\xF0\x9F\x8F\x83"; $cells[29] = "\xF0\x9F\x9A\xAA"; } // 🏃 start, 🚪 exit
    $rows[] = implode(" ", $cells);
}
echo implode("\n", $rows);
')
T=$(page_token "$BASE/admin/game-edit.php?id=$GAME_ID&level=$LEVEL_ID")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=level-save" --data-urlencode "game_id=$GAME_ID" \
  --data-urlencode "level_id=$LEVEL_ID" --data-urlencode "name=Smoke Level" \
  --data-urlencode "grid=$GRID" --data-urlencode "time_limit_s=" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-edit.php?id=$GAME_ID")
check "POST level-save (валидная сетка) -> 302" 302 "$RES"
SAVED_NAME=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['levels'][0]['name'] ?? '';")
check "имя уровня сохранено" "Smoke Level" "$SAVED_NAME"

echo "== 7. Ошибка валидации: сетка неверной ширины =="
BADGRID=$(php -r '$rows=[]; for($r=0;$r<24;$r++){$rows[]=implode(" ",array_fill(0,10,"."));} echo implode("\n",$rows);')
T=$(page_token "$BASE/admin/game-edit.php?id=$GAME_ID&level=$LEVEL_ID")
BEFORE=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['levels'][0]['name'] ?? '';")
curl -s -o /dev/null -b "$CJ" -c "$CJ" \
  --data-urlencode "action=level-save" --data-urlencode "game_id=$GAME_ID" \
  --data-urlencode "level_id=$LEVEL_ID" --data-urlencode "name=Bad Grid" \
  --data-urlencode "grid=$BADGRID" --data-urlencode "time_limit_s=" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/game-edit.php?id=$GAME_ID"
AFTER=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['levels'][0]['name'] ?? '';")
check "невалидная сетка НЕ сохранилась (имя прежнее)" "$BEFORE" "$AFTER"
BODY=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/game-edit.php?id=$GAME_ID&level=$LEVEL_ID")
ERRSHOWN=$(echo "$BODY" | grep -c "ожидалось 32 ячеек")
check "ошибка валидации показана на странице" 1 "$([ "$ERRSHOWN" -gt 0 ] && echo 1 || echo 0)"

echo "== 8. 403 без CSRF =="
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=level-create" --data-urlencode "game_id=$GAME_ID" \
  "$BASE/admin/game-edit.php?id=$GAME_ID")
check "POST без CSRF -> 403" 403 "$RES"
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=delete" --data-urlencode "id=$GAME_ID" \
  "$BASE/admin/games.php")
check "POST games delete без CSRF -> 403" 403 "$RES"

echo "== 9. Публикация недоделанной игры отклоняется (draft остаётся) =="
T=$(page_token "$BASE/admin/games.php")
curl -s -o /dev/null -b "$CJ" -c "$CJ" \
  --data-urlencode "action=publish" --data-urlencode "id=$GAME_ID" \
  --data-urlencode "_csrf=$T" "$BASE/admin/games.php"
STATUS=$(php -r "(\$g=json_decode(file_get_contents(\"data/games/$GAME_ID.json\"),true)); echo \$g['status'];")
check "статус остался draft (публикация без обложки запрещена)" "draft" "$STATUS"

echo "== 10. Удаление тестовой игры =="
T=$(page_token "$BASE/admin/games.php")
RES=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" \
  --data-urlencode "action=delete" --data-urlencode "id=$GAME_ID" \
  --data-urlencode "_csrf=$T" "$BASE/admin/games.php")
check "POST delete -> 302" 302 "$RES"
EXISTS=$([ -f "data/games/$GAME_ID.json" ] && echo yes || echo no)
check "файл игры удалён" "no" "$EXISTS"

echo ""
if [ "$fail" = "0" ]; then echo "SMOKE STAGE4: ALL PASS"; else echo "SMOKE STAGE4: FAILURES PRESENT"; exit 1; fi
