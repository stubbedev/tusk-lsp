<?php

declare(strict_types=1);

/**
 * PHP code, run in each build, that starts its dump: the version, whether the
 * build is thread-safe (ZTS), the loaded extensions, and the static ones,
 * which a run without any ini file (-n) still loads.
 */
function dumpHeaderCode(): string
{
    return <<<'PHP'
$static = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('echo json_encode(array_map("strtolower", get_loaded_extensions()));')), true);
if (!is_array($static)) {
    fwrite(STDERR, "Can't list the static extensions of " . PHP_BINARY . "\n");
    exit(1);
}
$out = ['version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'zts' => (bool) PHP_ZTS, 'extensions' => array_map('strtolower', get_loaded_extensions()), 'static' => $static];

PHP;
}

function classLikeDumpCode(): string
{
    return dumpHeaderCode() . <<<'PHP'
$type = function ($t) use (&$type) {
    if ($t === null) {
        return '';
    }
    if ($t instanceof ReflectionNamedType) {
        $name = $t->getName();
        return ($t->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
    }
    return (string) $t;
};
$params = function ($f) use ($type) {
    $out = [];
    foreach ($f->getParameters() as $p) {
        $default = null;
        try {
            if ($p->isDefaultValueAvailable()) {
                $default = $p->isDefaultValueConstant() ? ['constant' => $p->getDefaultValueConstantName()] : ['value' => $p->getDefaultValue()];
            }
        } catch (ReflectionException $e) {
        }
        $out[] = ['$' . $p->getName(), $type($p->getType()), $default, $p->isVariadic(), $p->isPassedByReference()];
    }
    return $out;
};
$visibility = function ($m) {
    return $m->isProtected() ? 'protected' : 'public';
};
$names = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
$out['classes'] = [];
foreach ($names as $name) {
    $c = new ReflectionClass($name);
    if (!$c->isInternal()) {
        continue;
    }
    $kind = $c->isInterface() ? 'interface' : ($c->isTrait() ? 'trait' : (method_exists($c, 'isEnum') && $c->isEnum() ? 'enum' : 'class'));
    $parent = $c->getParentClass();
    $entry = [
        'name' => $c->getName(),
        'kind' => $kind,
        'extension' => strtolower((string) $c->getExtensionName()),
        'abstract' => $c->isAbstract() && $kind === 'class',
        'final' => $c->isFinal(),
        'extends' => $parent ? $parent->getName() : '',
        'implements' => $c->getInterfaceNames(),
        'methods' => [],
        'properties' => [],
        'constants' => [],
        'cases' => [],
    ];
    foreach ($c->getMethods() as $m) {
        if ($m->isPrivate() || $m->getDeclaringClass()->getName() !== $c->getName()) {
            continue;
        }
        $return = $m->hasReturnType() ? $m->getReturnType() : (method_exists($m, 'hasTentativeReturnType') && $m->hasTentativeReturnType() ? $m->getTentativeReturnType() : null);
        $entry['methods'][$m->getName()] = [$visibility($m), $m->isStatic(), $m->isAbstract(), $m->isFinal(), $params($m), $type($return)];
    }
    foreach ($c->getProperties() as $p) {
        if ($p->isPrivate() || $p->getDeclaringClass()->getName() !== $c->getName()) {
            continue;
        }
        $entry['properties']['$' . $p->getName()] = [$visibility($p), $p->isStatic(), method_exists($p, 'isReadOnly') && $p->isReadOnly(), method_exists($p, 'getType') ? $type($p->getType()) : ''];
    }
    $isCase = function ($rc) {
        return method_exists($rc, 'isEnumCase') && $rc->isEnumCase();
    };
    foreach ($c->getReflectionConstants() as $k) {
        if ($k->isPrivate() || $k->getDeclaringClass()->getName() !== $c->getName()) {
            continue;
        }
        if ($isCase($k)) {
            $entry['cases'][$k->getName()] = true;
            continue;
        }
        $v = $k->getValue();
        $entry['constants'][$k->getName()] = [$visibility($k), gettype($v), is_scalar($v) || is_array($v) || $v === null ? ['value' => $v] : null];
    }
    $out['classes'][$c->getName()] = $entry;
}
echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
PHP;
}

/**
 * The version that added something first seen in $first: that version, when an
 * earlier build had the extension it belongs to without it, or when the
 * extension came with a later PHP (an introduced extension: random with 8.2).
 * Something of another extension no earlier build had is of unknown age: ''.
 *
 * @param array<string, true> $introducedExtensions
 */
function addedIn(string $first, string $extension, array $dumps, array $introducedExtensions): string
{
    if ($first === $dumps[0]['version']) {
        return '';
    }
    if (isset($introducedExtensions[$extension])) {
        return $first;
    }
    foreach ($dumps as $dump) {
        if (version_compare($dump['version'], $first, '>=')) {
            break;
        }
        if ($extension === '' || in_array($extension, $dump['extensions'], true)) {
            return $first;
        }
    }
    return '';
}

/**
 * @param list<array{version:string, extensions:list<string>, classes:array<string, array>}> $defaultDumps
 * @param list<array{version:string, extensions:list<string>, classes:array<string, array>}> $extensionDumps
 * @param list<array{version:string, extensions:list<string>, classes:array<string, array>}> $peclDumps
 */
function generateGoClassLikes(array $defaultDumps, array $extensionDumps, array $peclDumps, string $extensionStubsDir): string
{
    $defaultExtensions = loadedExtensions($defaultDumps);
    $introducedExtensions = introducedExtensions($defaultDumps);
    $phpSourceExtensions = loadedExtensions(array_merge($defaultDumps, $extensionDumps));
    $stubClasses = extensionStubClassLikes($extensionStubsDir, $defaultExtensions);
    $stubExtensions = array_flip(array_column($stubClasses, 1));
    $peclExtensions = array_diff_key(loadedExtensions($peclDumps), $phpSourceExtensions) + $stubExtensions;
    $dumps = array_merge($defaultDumps, $extensionDumps, $peclDumps);
    usort($dumps, static fn(array $a, array $b): int => version_compare($a['version'], $b['version']));

    $first = [];
    $latest = [];
    $memberFirst = [];
    $memberLast = [];
    $classVersions = [];
    foreach ($dumps as $dump) {
        foreach ($dump['classes'] as $name => $class) {
            if (isset($stubExtensions[$class['extension']])) {
                continue;
            }
            $first[$name] ??= $dump['version'];
            $classVersions[$name][$dump['version']] = true;
            $merged = $class;
            foreach (['methods', 'properties', 'constants', 'cases'] as $group) {
                $merged[$group] = $class[$group] + ($latest[$name][$group] ?? []);
                foreach (array_keys($class[$group]) as $member) {
                    $memberFirst[$name][$group][$member] ??= $dump['version'];
                    $memberLast[$name][$group][$member] = $dump['version'];
                }
            }
            $latest[$name] = $merged;
        }
    }

    foreach ($stubClasses as $name => [$kind, $extension]) {
        $latest[$name] ??= ['kind' => $kind, 'extension' => $extension, 'abstract' => false, 'final' => false, 'extends' => '', 'implements' => [],
            'methods' => [], 'properties' => [], 'constants' => [], 'cases' => []];
        $first[$name] ??= $dumps[0]['version'];
    }
    ksort($latest, SORT_STRING);

    $lines = [];
    foreach ($latest as $name => $class) {
        $since = static function (string $group, string $member) use ($memberFirst, $name, $first, $class, $dumps, $introducedExtensions): ?string {
            $at = $memberFirst[$name][$group][$member];
            return $at === $first[$name] ? null : goStringOrNull(addedIn($at, $class['extension'], $dumps, $introducedExtensions));
        };
        $removed = static function (string $group, string $member) use ($memberLast, $name, $classVersions): ?string {
            $last = $memberLast[$name][$group][$member];
            foreach (array_keys($classVersions[$name]) as $version) {
                if (version_compare((string) $version, $last, '>')) {
                    return renderGoString((string) $version);
                }
            }
            return null;
        };
        $members = [];
        foreach (sortedKeys($class['methods']) as $method) {
            [$visibility, $static, $abstract, $final, $params, $return] = $class['methods'][$method];
            $members[] = renderGoStruct([
                'Name' => renderGoString($method),
                'Kind' => 'KindMethod',
                'Visibility' => renderGoString($visibility),
                'IsStatic' => goTrueOrNull($static),
                'IsAbstract' => goTrueOrNull($abstract),
                'IsFinal' => goTrueOrNull($final),
                'ReturnType' => goStringOrNull($return),
                'Params' => $params === [] ? null : '[]ParamInfo{' . implode(', ', array_map('renderGoParam', $params)) . '}',
                'Since' => $since('methods', $method),
                'Removed' => $removed('methods', $method),
            ]);
        }
        foreach (sortedKeys($class['properties']) as $property) {
            [$visibility, $static, $readonly, $type] = $class['properties'][$property];
            $members[] = renderGoStruct([
                'Name' => renderGoString($property),
                'Kind' => 'KindProperty',
                'Visibility' => renderGoString($visibility),
                'IsStatic' => goTrueOrNull($static),
                'IsReadonly' => goTrueOrNull($readonly),
                'Type' => goStringOrNull($type),
                'Since' => $since('properties', $property),
                'Removed' => $removed('properties', $property),
            ]);
        }
        foreach (sortedKeys($class['constants']) as $constant) {
            [$visibility, $type, $value] = $class['constants'][$constant];
            $members[] = renderGoStruct([
                'Name' => renderGoString($constant),
                'Kind' => 'KindConstant',
                'Visibility' => renderGoString($visibility),
                'Type' => renderGoString(phpTypeName($type)),
                'Value' => $value === null ? null : renderGoString(renderPhpValue($value['value'])),
                'Since' => $since('constants', $constant),
                'Removed' => $removed('constants', $constant),
            ]);
        }
        foreach (sortedKeys($class['cases']) as $case) {
            $members[] = renderGoStruct(['Name' => renderGoString($case), 'Kind' => 'KindEnumCase', 'Since' => $since('cases', $case), 'Removed' => $removed('cases', $case)]);
        }
        $lines[] = "\t" . renderGoStruct([
            'Name' => renderGoString($name),
            'Kind' => match ($class['kind']) { 'interface' => 'KindInterface', 'trait' => 'KindTrait', 'enum' => 'KindEnum', default => 'KindClass' },
            'IsAbstract' => goTrueOrNull($class['abstract']),
            'IsFinal' => goTrueOrNull($class['final']),
            'Extends' => goStringOrNull($class['extends']),
            'Implements' => $class['implements'] === [] ? null : '[]string{' . implode(', ', array_map('renderGoString', $class['implements'])) . '}',
            'Since' => goStringOrNull(addedIn($first[$name], $class['extension'], $dumps, $introducedExtensions)),
            'Extension' => goStringOrNull(isset($peclExtensions[$class['extension']]) ? $class['extension'] : ''),
            'Members' => $members === [] ? null : "[]builtinMember{\n" . implode('', array_map(static fn(string $m): string => "\t\t" . $m . ",\n", $members)) . "\t}",
        ]) . ',';
    }

    $versions = implode(', ', array_unique(array_map(static fn(array $d): string => $d['version'], $dumps)));

    return implode("\n", [
        'package symbols',
        '',
        '// Code generated from PHP runtime builtin symbols; DO NOT EDIT.',
        "// Generated by scripts/generate-builtins.php classes from reflection of PHP {$versions}.",
        '',
        'var generatedBuiltinClassLikes = []builtinClassLike{',
        ...$lines,
        '}',
        '',
    ]);
}

/**
 * Extensions PHP gained after the oldest default build: ones compiled into
 * the first default build that has them, so every build of that version has
 * them and no earlier one did. An extension that first shows up as a shared
 * one (opcache in the 8.1 images) may simply not have been enabled before.
 *
 * @param list<array{version:string, extensions:list<string>, static:list<string>}> $defaultDumps
 * @return array<string, true>
 */
function introducedExtensions(array $defaultDumps): array
{
    usort($defaultDumps, static fn(array $a, array $b): int => version_compare($a['version'], $b['version']));
    $seen = [];
    $introduced = [];
    foreach ($defaultDumps as $i => $dump) {
        foreach ($dump['extensions'] as $extension) {
            if (isset($seen[$extension])) {
                continue;
            }
            $seen[$extension] = true;
            if ($i > 0 && in_array($extension, $dump['static'], true)) {
                $introduced[$extension] = true;
            }
        }
    }
    return $introduced;
}

/**
 * @param list<array{version:string, extensions:list<string>, classes:array<string, array>}> $dumps
 * @return array<string, true>
 */
function loadedExtensions(array $dumps): array
{
    $out = [];
    foreach ($dumps as $dump) {
        foreach ($dump['extensions'] as $extension) {
            $out[$extension] = true;
        }
    }
    return $out;
}

/**
 * The class-likes the embedded stubs of an optional extension declare, each
 * needing that extension: internal/stubs/php/extensions/<extension>/. The
 * stubs are those classes' source; a build's reflection of them is not used.
 *
 * @param array<string, true> $defaultExtensions
 * @return array<string, array{0:string, 1:string}> kind and extension by name
 */
function extensionStubClassLikes(string $dir, array $defaultExtensions): array
{
    $out = [];
    foreach (extensionStubSources($dir, $defaultExtensions) as [$extension, $source]) {
        foreach (declaredClassLikes($source) as $name => $kind) {
            $out[$name] = [$kind, $extension];
        }
    }
    return $out;
}

/**
 * The PHP files under internal/stubs/php/extensions/<extension>/, in path
 * order, with their extension, leaving out extensions of the default builds.
 *
 * @param array<string, true> $defaultExtensions
 * @return list<array{0:string, 1:string}>
 */
function extensionStubSources(string $dir, array $defaultExtensions): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
    $paths = [];
    foreach ($files as $info) {
        if ($info->getExtension() === 'php') {
            $paths[] = $info->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    $out = [];
    foreach ($paths as $file) {
        $extension = strtolower(explode('/', substr($file, strlen($dir) + 1))[0]);
        if (isset($defaultExtensions[$extension])) {
            continue;
        }
        $source = file_get_contents($file);
        if ($source === false) {
            fwrite(STDERR, "Can't read {$file}\n");
            exit(1);
        }
        $out[] = [$extension, $source];
    }
    return $out;
}

function declaredClassLikes(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $namespace = '';
    $names = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $next = $tokens[$i + 1] ?? null;
            $namespace = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) ? $next[1] . '\\' : '';
            continue;
        }
        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }
        $previous = $tokens[$i - 1] ?? null;
        if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if (is_array($next) && $next[0] === T_STRING) {
            $names[$namespace . $next[1]] = match ($token[0]) { T_INTERFACE => 'interface', T_TRAIT => 'trait', T_ENUM => 'enum', default => 'class' };
        }
    }
    return $names;
}

/**
 * @param array<string, ?string> $fields Go field values, null for a field left at its zero value
 */
function renderGoStruct(array $fields): string
{
    $parts = [];
    foreach ($fields as $field => $value) {
        if ($value !== null) {
            $parts[] = $field . ': ' . $value;
        }
    }
    return '{' . implode(', ', $parts) . '}';
}

function goTrueOrNull(bool $value): ?string
{
    return $value ? 'true' : null;
}

function goStringOrNull(string $value): ?string
{
    return $value === '' ? null : renderGoString($value);
}

/**
 * @param array{0:string, 1:string, 2:?array{constant?:string, value?:mixed}, 3:bool, 4:bool} $param
 */
function renderGoParam(array $param): string
{
    [$name, $type, $default, $variadic, $byRef] = $param;
    return renderGoStruct([
        'Name' => renderGoString($name),
        'Type' => goStringOrNull($type),
        'DefaultValue' => match (true) {
            $default === null => null,
            isset($default['constant']) => renderGoString($default['constant']),
            default => renderGoString(renderPhpValue($default['value'])),
        },
        'IsVariadic' => goTrueOrNull($variadic),
        'IsReference' => goTrueOrNull($byRef),
    ]);
}

function sortedKeys(array $map): array
{
    $keys = array_map('strval', array_keys($map));
    sort($keys, SORT_STRING);
    return $keys;
}

return [
    'name' => 'classes',
    'usage' => 'php scripts/generate-builtins.php classes --php=COMMANDS [--extensions-php=COMMANDS] [--pecl-php=COMMANDS] [--output=PATH]',
    'help' => '  classes       Run each PHP given with --php and emit the Go table of PHP\'s
                built-in classes, interfaces, traits and enums with their
                public and protected methods, properties and constants, the
                version that added each member and the one that removed it.
                A class has the members any build gives it (an extension such
                as pdo_mysql adds constants to PDO); each signature comes from
                the newest PHP that has it.
                Output defaults to internal/symbols/builtin_classes_generated.go.
',
    'run' => static function (array $options): void {
        if ($options['php'] === '') {
            fwrite(STDERR, "Classes mode needs PHP's default builds (--php).\n");
            exit(1);
        }
        writeOutput(
            generateGoClassLikes(
                runPhpDumps($options['php'], classLikeDumpCode()),
                runPhpDumps($options['extensions-php'], classLikeDumpCode()),
                runPhpDumps($options['pecl-php'], classLikeDumpCode()),
                dirname(__DIR__, 2) . '/internal/stubs/php/extensions'
            ),
            $options['output'] ?? dirname(__DIR__, 2) . '/internal/symbols/builtin_classes_generated.go'
        );
    },
];
