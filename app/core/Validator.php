<?php

namespace App\Core;

/**
 * Small rule-based validator: `required|string|max:120`, `int|min:1`, etc.
 */
class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $validator = new self($data);
        $validator->validate($rules, $labels);

        return $validator;
    }

    public function validate(array $rules, array $labels = []): void
    {
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $value = $this->data[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $ruleList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $isRequired = in_array('required', $ruleList, true);
            $isNullable = in_array('nullable', $ruleList, true);

            if ($isRequired && ($value === null || $value === '' || $value === [])) {
                $this->addError($field, Lang::get('validation.required', ['field' => $label]));
                continue;
            }

            if (($value === null || $value === '') && !$isRequired) {
                $this->clean[$field] = $isNullable ? null : $value;
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required' || $rule === 'nullable' || $rule === '') {
                    continue;
                }

                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if (!$this->applyRule($name, $param, $field, $label, $value)) {
                    break;
                }
            }

            $this->clean[$field] = $value;
        }
    }

    private function applyRule(string $name, ?string $param, string $field, string $label, mixed &$value): bool
    {
        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    $value = (string) $value;
                }
                break;

            case 'int':
            case 'integer':
                if (!is_numeric($value)) {
                    return $this->fail($field, Lang::get('validation.integer', ['field' => $label]));
                }
                $value = (int) $value;
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->fail($field, Lang::get('validation.numeric', ['field' => $label]));
                }
                $value = (float) $value;
                break;

            case 'bool':
            case 'boolean':
                $value = in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
                break;

            case 'email':
                if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    return $this->fail($field, Lang::get('validation.email', ['field' => $label]));
                }
                break;

            case 'url':
                if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
                    return $this->fail($field, Lang::get('validation.url', ['field' => $label]));
                }
                break;

            case 'phone':
                $digits = preg_replace('/\D+/', '', (string) $value);
                if (strlen((string) $digits) < 10 || strlen((string) $digits) > 15) {
                    return $this->fail($field, Lang::get('validation.phone', ['field' => $label]));
                }
                break;

            case 'min':
                if (is_numeric($value) && !is_string($value)) {
                    if ($value < (float) $param) {
                        return $this->fail($field, Lang::get('validation.min', ['field' => $label, 'min' => $param]));
                    }
                } elseif (mb_strlen((string) $value) < (int) $param) {
                    return $this->fail($field, Lang::get('validation.min_length', ['field' => $label, 'min' => $param]));
                }
                break;

            case 'max':
                if (is_numeric($value) && !is_string($value)) {
                    if ($value > (float) $param) {
                        return $this->fail($field, Lang::get('validation.max', ['field' => $label, 'max' => $param]));
                    }
                } elseif (mb_strlen((string) $value) > (int) $param) {
                    return $this->fail($field, Lang::get('validation.max_length', ['field' => $label, 'max' => $param]));
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $param);
                if (!in_array((string) $value, $allowed, true)) {
                    return $this->fail($field, Lang::get('validation.in', ['field' => $label]));
                }
                break;

            case 'date':
                if (strtotime((string) $value) === false) {
                    return $this->fail($field, Lang::get('validation.date', ['field' => $label]));
                }
                break;

            case 'regex':
                if (!preg_match((string) $param, (string) $value)) {
                    return $this->fail($field, Lang::get('validation.regex', ['field' => $label]));
                }
                break;

            case 'confirmed':
                if (($this->data[$field . '_confirm'] ?? null) !== $value) {
                    return $this->fail($field, Lang::get('validation.confirmed', ['field' => $label]));
                }
                break;

            case 'password':
                if (mb_strlen((string) $value) < 8) {
                    return $this->fail($field, Lang::get('validation.password_short'));
                }
                if (!preg_match('/[A-Za-z]/', (string) $value) || !preg_match('/\d/', (string) $value)) {
                    return $this->fail($field, Lang::get('validation.password_weak'));
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    return $this->fail($field, Lang::get('validation.array', ['field' => $label]));
                }
                break;
        }

        return true;
    }

    private function fail(string $field, string $message): bool
    {
        $this->addError($field, $message);

        return false;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            return (string) ($messages[0] ?? '');
        }

        return '';
    }

    public function validated(): array
    {
        return $this->clean;
    }
}
