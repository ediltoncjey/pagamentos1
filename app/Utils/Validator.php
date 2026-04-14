<?php

declare(strict_types=1);

namespace App\Utils;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array{valid:bool,errors:array<string, string>}
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleList) {
            $value = $data[$field] ?? null;
            $ruleItems = explode('|', $ruleList);

            foreach ($ruleItems as $rule) {
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name === 'required' && ($value === null || $value === '')) {
                    $errors[$field] = 'Field is required.';
                    break;
                }

                if (($value === null || $value === '') && $name !== 'required') {
                    continue;
                }

                if ($name === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = 'Invalid email format.';
                    break;
                }

                if ($name === 'numeric' && !is_numeric($value)) {
                    $errors[$field] = 'Field must be numeric.';
                    break;
                }

                if ($name === 'min' && is_string($parameter)) {
                    if (is_numeric($value) && (float) $value < (float) $parameter) {
                        $errors[$field] = sprintf('Minimum value is %s.', $parameter);
                        break;
                    }

                    if (is_string($value) && mb_strlen($value) < (int) $parameter) {
                        $errors[$field] = sprintf('Minimum length is %s.', $parameter);
                        break;
                    }
                }

                if ($name === 'max' && is_string($parameter)) {
                    if (is_numeric($value) && (float) $value > (float) $parameter) {
                        $errors[$field] = sprintf('Maximum value is %s.', $parameter);
                        break;
                    }

                    if (is_string($value) && mb_strlen($value) > (int) $parameter) {
                        $errors[$field] = sprintf('Maximum length is %s.', $parameter);
                        break;
                    }
                }

                if ($name === 'in' && is_string($parameter)) {
                    $allowed = explode(',', $parameter);
                    if (!in_array((string) $value, $allowed, true)) {
                        $errors[$field] = 'Invalid value selected.';
                        break;
                    }
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
