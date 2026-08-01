<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit\Idl;

use JsonException;

final class IdlCompiler
{
    /** @return array{php: string, kotlin: string, swift: string} */
    public function compileFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new IdlException("Cannot read IDL: {$path}");
        }
        try {
            $idl = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new IdlException('Invalid IDL JSON: '.$exception->getMessage(), previous: $exception);
        }
        if (!is_array($idl) || array_is_list($idl)) {
            throw new IdlException('IDL root must be an object.');
        }

        return $this->compile($idl);
    }

    /**
     * @param array<string, mixed> $idl
     * @return array{php: string, kotlin: string, swift: string}
     */
    public function compile(array $idl): array
    {
        if (($idl['version'] ?? null) !== 1) {
            throw new IdlException('IDL version must be integer 1.');
        }
        $namespace = $idl['namespace'] ?? null;
        if (!is_string($namespace) || !$this->qualifiedName($namespace)) {
            throw new IdlException('IDL namespace must be a qualified identifier.');
        }
        $enums = $idl['enums'] ?? [];
        if (!is_array($enums) || ($enums !== [] && array_is_list($enums))) {
            throw new IdlException('IDL enums must be an object.');
        }
        $records = $idl['records'] ?? [];
        if (!is_array($records) || ($records !== [] && array_is_list($records))) {
            throw new IdlException('IDL records must be an object.');
        }

        $normalizedEnums = $this->enums($enums);
        $normalizedRecords = $this->records($records, array_keys($normalizedEnums));

        return [
            'php' => $this->php($namespace, $normalizedEnums, $normalizedRecords),
            'kotlin' => $this->kotlin($namespace, $normalizedEnums, $normalizedRecords),
            'swift' => $this->swift($namespace, $normalizedEnums, $normalizedRecords),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, array<string, int>>
     */
    private function enums(array $values): array
    {
        $result = [];
        foreach ($values as $name => $cases) {
            if (!is_string($name) || !$this->identifier($name) || !is_array($cases) || array_is_list($cases) || $cases === []) {
                throw new IdlException("Enum {$name} must be a non-empty case object.");
            }
            $expected = 1;
            $normalized = [];
            foreach ($cases as $case => $value) {
                if (!is_string($case) || !$this->identifier($case) || $value !== $expected) {
                    throw new IdlException("Enum {$name} values must be sequential integers starting at 1; expected {$expected}.");
                }
                $normalized[$case] = $value;
                ++$expected;
            }
            $result[$name] = $normalized;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $enumNames
     * @return array<string, array<string, string>>
     */
    private function records(array $values, array $enumNames): array
    {
        $result = [];
        $scalarTypes = ['string', 'int', 'float', 'bool', 'bytes'];
        foreach ($values as $name => $fields) {
            if (!is_string($name) || !$this->identifier($name) || !is_array($fields) || array_is_list($fields)) {
                throw new IdlException("Record {$name} must be a field object.");
            }
            $normalized = [];
            foreach ($fields as $field => $type) {
                if (!is_string($field) || !$this->fieldName($field) || !is_string($type)) {
                    throw new IdlException("Record {$name} contains an invalid field.");
                }
                $base = str_ends_with($type, '?') ? substr($type, 0, -1) : $type;
                if (!in_array($base, $scalarTypes, true) && !in_array($base, $enumNames, true)) {
                    throw new IdlException("Record {$name}.{$field} uses unknown type {$type}.");
                }
                $normalized[$field] = $type;
            }
            $result[$name] = $normalized;
        }
        return $result;
    }

    /** @param array<string, array<string, int>> $enums @param array<string, array<string, string>> $records */
    private function php(string $namespace, array $enums, array $records): string
    {
        $phpNamespace = str_replace('.', '\\', $namespace).'\\Generated';
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$phpNamespace};\n";
        foreach ($enums as $name => $cases) {
            $source .= "\nenum {$name}: int\n{\n";
            foreach ($cases as $case => $value) {
                $source .= "    case {$case} = {$value};\n";
            }
            $source .= "}\n";
        }
        foreach ($records as $name => $fields) {
            $source .= "\nfinal readonly class {$name}\n{\n    public function __construct(\n";
            foreach ($fields as $field => $type) {
                $source .= '        public '.$this->phpType($type).' $'.$field.",\n";
            }
            $source .= "    ) {\n    }\n}\n";
        }
        return $source;
    }

    /** @param array<string, array<string, int>> $enums @param array<string, array<string, string>> $records */
    private function kotlin(string $namespace, array $enums, array $records): string
    {
        $source = 'package '.strtolower($namespace).".generated\n";
        foreach ($enums as $name => $cases) {
            $source .= "\nenum class {$name}(val value: Int) {\n";
            $lines = [];
            foreach ($cases as $case => $value) {
                $lines[] = "    ".strtoupper($case)."({$value})";
            }
            $source .= implode(",\n", $lines).";\n}\n";
        }
        foreach ($records as $name => $fields) {
            $source .= "\ndata class {$name}(\n";
            foreach ($fields as $field => $type) {
                $source .= "    val {$field}: ".$this->kotlinType($type).",\n";
            }
            $source .= ")\n";
        }
        return $source;
    }

    /** @param array<string, array<string, int>> $enums @param array<string, array<string, string>> $records */
    private function swift(string $namespace, array $enums, array $records): string
    {
        $source = "import Foundation\n";
        foreach ($enums as $name => $cases) {
            $source .= "\npublic enum {$name}: Int, Sendable {\n";
            foreach ($cases as $case => $value) {
                $source .= "    case ".lcfirst($case)." = {$value}\n";
            }
            $source .= "}\n";
        }
        foreach ($records as $name => $fields) {
            $source .= "\npublic struct {$name}: Sendable {\n";
            foreach ($fields as $field => $type) {
                $source .= "    public let {$field}: ".$this->swiftType($type)."\n";
            }
            $source .= "\n    public init(";
            $parameters = [];
            foreach ($fields as $field => $type) {
                $parameters[] = "{$field}: ".$this->swiftType($type);
            }
            $source .= implode(', ', $parameters).") {\n";
            foreach (array_keys($fields) as $field) {
                $source .= "        self.{$field} = {$field}\n";
            }
            $source .= "    }\n}\n";
        }
        return $source;
    }

    private function phpType(string $type): string
    {
        $nullable = str_ends_with($type, '?');
        $base = $nullable ? substr($type, 0, -1) : $type;
        $mapped = match ($base) {
            'string', 'bytes' => 'string',
            'int' => 'int',
            'float' => 'float',
            'bool' => 'bool',
            default => $base,
        };
        return ($nullable ? '?' : '').$mapped;
    }

    private function kotlinType(string $type): string
    {
        $nullable = str_ends_with($type, '?');
        $base = $nullable ? substr($type, 0, -1) : $type;
        $mapped = match ($base) {
            'string' => 'String', 'bytes' => 'ByteArray', 'int' => 'Long',
            'float' => 'Double', 'bool' => 'Boolean', default => $base,
        };
        return $mapped.($nullable ? '?' : '');
    }

    private function swiftType(string $type): string
    {
        $nullable = str_ends_with($type, '?');
        $base = $nullable ? substr($type, 0, -1) : $type;
        $mapped = match ($base) {
            'string' => 'String', 'bytes' => 'Data', 'int' => 'Int64',
            'float' => 'Double', 'bool' => 'Bool', default => $base,
        };
        return $mapped.($nullable ? '?' : '');
    }

    private function qualifiedName(string $value): bool
    {
        return count(explode('.', $value)) >= 2
            && array_all(explode('.', $value), $this->identifier(...));
    }

    private function identifier(string $value): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9_]*$/D', $value) === 1;
    }

    private function fieldName(string $value): bool
    {
        return preg_match('/^[a-z][A-Za-z0-9_]*$/D', $value) === 1;
    }
}
