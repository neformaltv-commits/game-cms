<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Валидация записи AI-модели / паттерна движения по ТЗ Блок 5 §2.1, §2.2, §3.1, §9.
 * Возвращает массив ошибок [поле => сообщение]; пустой массив = успешно.
 */
final class LibParamValidator
{
    public const ENGINES = ['static-screen', 'vertical-scroll', 'horizontal-scroll'];

    public const AI_CATEGORIES = ['movement', 'pursuit', 'combat', 'advanced', 'boss'];

    public const PATTERN_CATEGORIES = ['linear', 'oscillating', 'curved', 'homing', 'composite', 'bit8-classic'];

    private const ID_RE = '/^[a-z][a-z0-9_]{1,40}$/';

    private const PARAM_KEY_RE = '/^[a-z][a-z0-9_]{1,30}$/';

    /** @return array<string,string> */
    public static function validate(array $rec, bool $isPattern): array
    {
        $errors = [];

        $id = trim((string)($rec['id'] ?? ''));
        if ($id === '') {
            $errors['id'] = 'ID обязателен.';
        } elseif (!preg_match(self::ID_RE, $id)) {
            $errors['id'] = 'ID: строчные буквы, цифры, подчёркивание; ^[a-z][a-z0-9_]{1,40}$.';
        }

        $name = trim((string)($rec['name'] ?? ''));
        $nlen = mb_strlen($name);
        if ($nlen < 2 || $nlen > 80) {
            $errors['name'] = 'Название: 2–80 символов.';
        }

        $desc = trim((string)($rec['description'] ?? ''));
        $dlen = mb_strlen($desc);
        if ($dlen < 10 || $dlen > 300) {
            $errors['description'] = 'Описание: 10–300 символов.';
        }

        $cats = $isPattern ? self::PATTERN_CATEGORIES : self::AI_CATEGORIES;
        $category = (string)($rec['category'] ?? '');
        if (!in_array($category, $cats, true)) {
            $errors['category'] = 'Категория должна быть одной из: ' . implode(', ', $cats) . '.';
        }

        $ref = (string)($rec['reference'] ?? '');
        if (mb_strlen($ref) > 120) {
            $errors['reference'] = 'Референс: не более 120 символов.';
        }

        $engines = $rec['supported_engines'] ?? null;
        if (!is_array($engines) || count($engines) === 0) {
            $errors['supported_engines'] = 'Укажите хотя бы один движок.';
        } else {
            foreach ($engines as $e) {
                if (!in_array($e, self::ENGINES, true)) {
                    $errors['supported_engines'] = 'Недопустимый движок: ' . htmlspecialchars((string)$e, ENT_QUOTES) . '.';
                    break;
                }
            }
        }

        $params = $rec['params'] ?? null;
        if (!is_array($params)) {
            $errors['params'] = 'Параметры должны быть списком (можно пустым).';
            $params = [];
        }
        if (count($params) > 20) {
            $errors['params'] = 'Не более 20 параметров.';
        }
        $seenKeys = [];
        foreach (array_values($params) as $i => $p) {
            $pe = self::validateParam(is_array($p) ? $p : [], $seenKeys);
            foreach ($pe as $k => $msg) {
                $errors["params.$i.$k"] = $msg;
            }
        }

        if ($isPattern) {
            $hint = (string)($rec['trajectory_hint'] ?? '');
            if (mb_strlen($hint) > 200) {
                $errors['trajectory_hint'] = 'Формула-подсказка: не более 200 символов.';
            }
            $onFinish = (string)($rec['on_finish'] ?? '');
            if (!in_array($onFinish, ['despawn', 'straight_down', 'loop'], true)) {
                $errors['on_finish'] = 'on_finish: despawn | straight_down | loop.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,bool>  $seenKeys ссылка на уже встреченные key
     * @return array<string,string>
     */
    private static function validateParam(array $p, array &$seenKeys): array
    {
        $errors = [];
        $key = trim((string)($p['key'] ?? ''));
        if ($key === '') {
            $errors['key'] = 'key обязателен.';
        } elseif (!preg_match(self::PARAM_KEY_RE, $key)) {
            $errors['key'] = 'key: ^[a-z][a-z0-9_]{1,30}$.';
        } elseif (isset($seenKeys[$key])) {
            $errors['key'] = 'Дубликат параметра «' . $key . '».';
        } else {
            $seenKeys[$key] = true;
        }

        $type = (string)($p['type'] ?? '');
        if (!in_array($type, ['number', 'boolean', 'enum', 'string'], true)) {
            $errors['type'] = 'type: number | boolean | enum | string.';
            return $errors;
        }

        $hasDefault = array_key_exists('default', $p);
        $default = $p['default'] ?? null;

        if ($type === 'number') {
            $min = $p['min'] ?? null;
            $max = $p['max'] ?? null;
            if (!is_numeric($min) || !is_numeric($max)) {
                $errors['min'] = 'Для number обязательны min и max.';
            } elseif ((float)$min >= (float)$max) {
                $errors['min'] = 'min должен быть меньше max.';
            }
            if (!$hasDefault || !is_numeric($default)) {
                $errors['default'] = 'default обязателен и должен быть числом.';
            } elseif (is_numeric($min) && is_numeric($max)
                && ((float)$default < (float)$min || (float)$default > (float)$max)) {
                $errors['default'] = 'default вне диапазона min–max.';
            }
        } elseif ($type === 'boolean') {
            if (!$hasDefault || !is_bool($default)) {
                $errors['default'] = 'default обязателен и должен быть true/false.';
            }
        } elseif ($type === 'enum') {
            $options = $p['options'] ?? null;
            if (!is_array($options) || count($options) === 0) {
                $errors['options'] = 'Для enum нужен непустой список options.';
            } else {
                foreach ($options as $o) {
                    if (!is_string($o) || $o === '') {
                        $errors['options'] = 'options — непустые строки.';
                        break;
                    }
                }
                if (!$hasDefault || !in_array($default, $options, true)) {
                    $errors['default'] = 'default должен входить в options.';
                }
            }
        } else { // string
            if (!$hasDefault || !is_string($default)) {
                $errors['default'] = 'default обязателен и должен быть строкой.';
            }
        }

        $unit = $p['unit'] ?? null;
        if ($unit !== null && (!is_string($unit) || mb_strlen($unit) > 30)) {
            $errors['unit'] = 'unit — строка до 30 символов.';
        }

        return $errors;
    }
}
