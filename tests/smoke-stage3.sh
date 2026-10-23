#!/bin/bash
# HTTP smoke для этапа 3: библиотека AI-моделей и паттернов движения.
set -u
BASE="http://127.0.0.1:8099"
ROOT="/workspace"
PASS='Adm1n-Str0ng!Pass'
CJ=$(mktemp)
trap 'rm -f "$CJ"' EXIT
fail=0
check() { if [ "$2" = "$3" ]; then echo "PASS: $1 ($3)"; else echo "FAIL: $1 (ожидалось $2, получено $3)"; fail=1; fi; }
token() { curl -s -b "$1" -c "$1" "$BASE/admin/login.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

cd "$ROOT" || exit 1
rm -f data/throttle.json

echo "== 1. Логин =="
T=$(token "$CJ")
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" --data-urlencode "username=admin" --data-urlencode "password=$PASS" --data-urlencode "_csrf=$T" "$BASE/admin/login.php")
check "POST login -> 302" 302 "$CODE"

echo "== 2. Страницы библиотек =="
for kind in ai pattern; do
  BODY=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=$kind")
  check "GET library.php?kind=$kind -> 200" 200 "$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=$kind")"
done
BODY=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai")
CNT=$(echo "$BODY" | grep -c '<tr>')
check "AI: таблица с записями (>50 строк)" 1 "$([ "$CNT" -gt 50 ] && echo 1 || echo 0)"
BODY=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=pattern")
CNT=$(echo "$BODY" | grep -c '<tr>')
check "Pattern: таблица с записями (>50 строк)" 1 "$([ "$CNT" -gt 50 ] && echo 1 || echo 0)"

echo "== 3. Seed идемпотентен через UI =="
T=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
BEFORE=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);echo count($d);')
curl -s -o /dev/null -b "$CJ" -c "$CJ" --data-urlencode "kind=ai" --data-urlencode "action=seed" --data-urlencode "_csrf=$T" "$BASE/admin/library.php?kind=ai"
AFTER=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);echo count($d);')
check "seed не удвоил записи ($BEFORE == $AFTER)" "$BEFORE" "$AFTER"

echo "== 4. Создание кастомной AI-модели =="
T=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai&new=1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
RES=$(curl -s -b "$CJ" -c "$CJ" \
  --data-urlencode "kind=ai" --data-urlencode "action=save" --data-urlencode "is_new=1" \
  --data-urlencode "id=smoke_custom_ai" --data-urlencode "name=Smoke Custom AI" \
  --data-urlencode "description=Test custom model for smoke test" \
  --data-urlencode "category=pursuit" --data-urlencode "reference=" \
  --data-urlencode "engines[]=static-screen" \
  --data-urlencode "rows[0][key]=speed" --data-urlencode "rows[0][type]=number" \
  --data-urlencode "rows[0][min]=0" --data-urlencode "rows[0][max]=10" \
  --data-urlencode "rows[0][default]=2" --data-urlencode "rows[0][unit]=" --data-urlencode "rows[0][options]=" \
  --data-urlencode "_csrf=$T" \
  "$BASE/admin/library.php?kind=ai" -o /dev/null -w '%{http_code}')
check "POST save -> 302 (редирект после успеха)" 302 "$RES"
FOUND=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);foreach($d as $r){if(($r["id"]??"")==="smoke_custom_ai"){echo "yes";exit;}}echo "no";')
check "smoke_custom_ai сохранена" "yes" "$FOUND"

echo "== 5. Встроенная read-only: edit редиректит без формы =="
FIRST_BUILTIN=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);foreach($d as $r){if(($r["custom"]??false)!==true){echo $r["id"];exit;}}')
BODY=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai&edit=$FIRST_BUILTIN")
check "форма редактирования встроенной не открылась" 0 "$(echo "$BODY" | grep -c 'Редактирование: ')"

echo "== 6. Клонирование встроенной =="
T=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$CJ" -c "$CJ" --data-urlencode "kind=ai" --data-urlencode "action=clone" --data-urlencode "id=$FIRST_BUILTIN" --data-urlencode "_csrf=$T" "$BASE/admin/library.php?kind=ai"
CLONED=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);foreach($d as $r){if(($r["id"]??"")==="'$FIRST_BUILTIN'_copy"){echo "yes";exit;}}echo "no";')
check "копия $FIRST_BUILTIN._copy создана" "yes" "$CLONED"

echo "== 7. Удаление кастомной =="
T=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$CJ" -c "$CJ" --data-urlencode "kind=ai" --data-urlencode "action=delete" --data-urlencode "id=smoke_custom_ai" --data-urlencode "_csrf=$T" "$BASE/admin/library.php?kind=ai"
GONE=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);foreach($d as $r){if(($r["id"]??"")==="smoke_custom_ai"){echo "still";exit;}}echo "gone";')
check "smoke_custom_ai удалена" "gone" "$GONE"

echo "== 8. Удаление встроенной заблокировано =="
BEFORE=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);echo count($d);')
curl -s -o /dev/null -b "$CJ" -c "$CJ" --data-urlencode "kind=ai" --data-urlencode "action=delete" --data-urlencode "id=$FIRST_BUILTIN" --data-urlencode "_csrf=$T" "$BASE/admin/library.php?kind=ai"
AFTER=$(php -r '$d=json_decode(file_get_contents("data/ai-models.json"),true);echo count($d);')
check "встроенная не удалилась ($BEFORE == $AFTER)" "$BEFORE" "$AFTER"

echo "== 9. CSRF: без токена -> 403 =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" --data-urlencode "kind=ai" --data-urlencode "action=seed" "$BASE/admin/library.php?kind=ai")
check "POST без _csrf -> 403" 403 "$CODE"

echo "== 10. Без сессии -> 302 на login =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/library.php?kind=pattern")
check "GET без сессии -> 302" 302 "$CODE"

# cleanup: удалить клон
T=$(curl -s -b "$CJ" -c "$CJ" "$BASE/admin/library.php?kind=ai" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$CJ" -c "$CJ" --data-urlencode "kind=ai" --data-urlencode "action=delete" --data-urlencode "id=${FIRST_BUILTIN}_copy" --data-urlencode "_csrf=$T" "$BASE/admin/library.php?kind=ai"

echo "---"
if [ "$fail" = 0 ]; then echo "SMOKE STAGE3: ALL PASS"; else echo "SMOKE STAGE3: FAILURES"; exit 1; fi
